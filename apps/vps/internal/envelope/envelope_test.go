package envelope

import (
	"testing"
	"time"
)

func key() []byte { return []byte("0123456789abcdef0123456789abcdef") }

func TestSignVerifyRoundTrip(t *testing.T) {
	env, err := Sign(key(), "system.stats", map[string]any{"detail": "full"}, "", time.Now())
	if err != nil {
		t.Fatalf("sign: %v", err)
	}

	if err := NewVerifier(key(), NewMemoryNonces(time.Minute)).Verify(env); err != nil {
		t.Fatalf("verify: %v", err)
	}
}

func TestWrongKeyIsRejected(t *testing.T) {
	env, _ := Sign(key(), "system.stats", map[string]any{}, "", time.Now())
	other := []byte("ffffffffffffffffffffffffffffffff")

	if err := NewVerifier(other, NewMemoryNonces(time.Minute)).Verify(env); err != ErrBadSignature {
		t.Fatalf("want ErrBadSignature, got %v", err)
	}
}

func TestTamperedOperationIsRejected(t *testing.T) {
	env, _ := Sign(key(), "system.stats", map[string]any{}, "", time.Now())
	env.Request.Op = "account.delete"

	if err := NewVerifier(key(), NewMemoryNonces(time.Minute)).Verify(env); err != ErrBadSignature {
		t.Fatalf("want ErrBadSignature, got %v", err)
	}
}

func TestTamperedArgumentsAreRejected(t *testing.T) {
	env, _ := Sign(key(), "account.create", map[string]any{"username": "alice", "quota_mb": 1024}, "", time.Now())
	env.Request.Args = []byte(`{"username":"root","quota_mb":1024}`)

	if err := NewVerifier(key(), NewMemoryNonces(time.Minute)).Verify(env); err != ErrBadSignature {
		t.Fatalf("want ErrBadSignature, got %v", err)
	}
}

func TestReplayIsRejected(t *testing.T) {
	env, _ := Sign(key(), "system.stats", map[string]any{}, "", time.Now())
	v := NewVerifier(key(), NewMemoryNonces(time.Minute))

	if err := v.Verify(env); err != nil {
		t.Fatalf("first use should pass: %v", err)
	}

	if err := v.Verify(env); err != ErrReplay {
		t.Fatalf("want ErrReplay, got %v", err)
	}
}

func TestExpiredEnvelopeIsRejected(t *testing.T) {
	now := time.Now()
	env, _ := Sign(key(), "system.stats", map[string]any{}, "", now)

	v := NewVerifier(key(), NewMemoryNonces(time.Minute)).WithClock(func() time.Time {
		return now.Add(2 * time.Minute)
	})

	if err := v.Verify(env); err != ErrExpired {
		t.Fatalf("want ErrExpired, got %v", err)
	}
}

func TestFutureEnvelopeIsRejected(t *testing.T) {
	now := time.Now()
	env, _ := Sign(key(), "system.stats", map[string]any{}, "", now.Add(time.Hour))

	v := NewVerifier(key(), NewMemoryNonces(time.Minute)).WithClock(func() time.Time { return now })

	if err := v.Verify(env); err != ErrExpired {
		t.Fatalf("want ErrExpired, got %v", err)
	}
}

func TestLongWindowIsRejected(t *testing.T) {
	now := time.Now()
	env, _ := Sign(key(), "system.stats", map[string]any{}, "", now)
	env.Request.Exp = now.Add(time.Hour).Unix()

	// Re-sign so only the window is unusual.
	canonical, _ := Canonical(env.Request)
	env.Sig = sign(key(), canonical)

	if err := NewVerifier(key(), NewMemoryNonces(time.Minute)).Verify(env); err != ErrExpired {
		t.Fatalf("want ErrExpired for a 1h window, got %v", err)
	}
}

func TestMissingNonceIsRejected(t *testing.T) {
	env, _ := Sign(key(), "system.stats", map[string]any{}, "", time.Now())
	env.Request.Nonce = ""
	canonical, _ := Canonical(env.Request)
	env.Sig = sign(key(), canonical)

	if err := NewVerifier(key(), NewMemoryNonces(time.Minute)).Verify(env); err != ErrMissingNonce {
		t.Fatalf("want ErrMissingNonce, got %v", err)
	}
}

func TestWhitespaceDoesNotChangeTheSignature(t *testing.T) {
	now := time.Now()
	env, _ := Sign(key(), "account.create", map[string]any{"username": "alice"}, "", now)

	// A client that pretty-prints its JSON must still verify.
	env.Request.Args = []byte("{\n  \"username\": \"alice\"\n}")

	if err := NewVerifier(key(), NewMemoryNonces(time.Minute)).Verify(env); err != nil {
		t.Fatalf("whitespace should be normalised, got %v", err)
	}
}

func TestNonceStoreExpires(t *testing.T) {
	now := time.Now()
	store := NewMemoryNonces(time.Second)
	store.now = func() time.Time { return now }

	if store.Seen("abc") {
		t.Fatal("first sighting must not be a replay")
	}

	store.now = func() time.Time { return now.Add(2 * time.Second) }

	if store.Seen("abc") {
		t.Fatal("expired nonces must be forgotten")
	}
}

func TestNewNonceIsUnique(t *testing.T) {
	seen := map[string]bool{}
	for i := 0; i < 1000; i++ {
		n, err := NewNonce()
		if err != nil {
			t.Fatal(err)
		}
		if seen[n] {
			t.Fatalf("duplicate nonce %s", n)
		}
		seen[n] = true
	}
}

func TestLoadKeyRejectsShortKeys(t *testing.T) {
	original := osReadFile
	t.Cleanup(func() { osReadFile = original })
	osReadFile = func(string) ([]byte, error) { return []byte("tooshort"), nil }

	if _, err := LoadKey("/etc/atlas/agent.key"); err == nil {
		t.Fatal("short key must be refused")
	}
}

func TestLoadKeyTrimsWhitespace(t *testing.T) {
	original := osReadFile
	t.Cleanup(func() { osReadFile = original })
	osReadFile = func(string) ([]byte, error) {
		return []byte("0123456789abcdef0123456789abcdef\n"), nil
	}

	got, err := LoadKey("/etc/atlas/agent.key")
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 32 {
		t.Fatalf("want 32 bytes after trim, got %d", len(got))
	}
}
