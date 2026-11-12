package licensing

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"strings"
	"testing"
	"time"
)

func keypair(t *testing.T) (ed25519.PublicKey, ed25519.PrivateKey) {
	t.Helper()
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}

	return pub, priv
}

func claims(now time.Time) Claims {
	return Claims{
		Version: 1, KeyID: "lic-2026-11", LicenceID: "01HQ...", CustomerID: "7",
		Edition: "professional", Fingerprint: "abc123", Status: "active",
		IssuedAt: now.Unix(), NotBefore: now.Add(-time.Minute).Unix(), Expires: now.Add(20 * 24 * time.Hour).Unix(),
		TokenID: "01J...",
		Entitlements: map[string]any{
			"max_accounts": float64(50),
			"max_domains":  "250",
			"api_access":   float64(1),
		},
	}
}

func sign(t *testing.T, priv ed25519.PrivateKey, c Claims) string {
	t.Helper()

	payload, err := json.Marshal(c)
	if err != nil {
		t.Fatal(err)
	}
	seg := base64.RawURLEncoding.EncodeToString(payload)
	sig := ed25519.Sign(priv, []byte(Prefix+"."+seg))

	return Prefix + "." + seg + "." + base64.RawURLEncoding.EncodeToString(sig)
}

func TestVerifyAcceptsAWellFormedToken(t *testing.T) {
	pub, priv := keypair(t)
	now := time.Now()
	raw := sign(t, priv, claims(now))

	tok, err := Verify(raw, map[string]ed25519.PublicKey{"lic-2026-11": pub}, now)
	if err != nil {
		t.Fatalf("verify: %v", err)
	}
	if tok.Claims.Edition != "professional" {
		t.Fatalf("wrong edition %q", tok.Claims.Edition)
	}
	if tok.Claims.Entitlement("max_accounts") != 50 {
		t.Fatalf("entitlement parse failed: %d", tok.Claims.Entitlement("max_accounts"))
	}
	if tok.Claims.Entitlement("max_domains") != 250 {
		t.Fatalf("string entitlement parse failed")
	}
	if tok.Claims.Entitlement("does_not_exist") != 0 {
		t.Fatal("missing entitlement must be 0")
	}
}

func TestTamperedPayloadFails(t *testing.T) {
	pub, priv := keypair(t)
	now := time.Now()
	raw := sign(t, priv, claims(now))

	parts := strings.Split(raw, ".")
	c := claims(now)
	c.Edition = "enterprise"
	c.Entitlements["max_accounts"] = float64(999999)
	payload, _ := json.Marshal(c)
	forged := parts[0] + "." + base64.RawURLEncoding.EncodeToString(payload) + "." + parts[2]

	if _, err := Verify(forged, map[string]ed25519.PublicKey{"lic-2026-11": pub}, now); err != ErrSignature {
		t.Fatalf("want ErrSignature, got %v", err)
	}
}

func TestUnknownKeyIDFails(t *testing.T) {
	pub, priv := keypair(t)
	now := time.Now()
	raw := sign(t, priv, claims(now))

	if _, err := Verify(raw, map[string]ed25519.PublicKey{"someone-else": pub}, now); err == nil {
		t.Fatal("unknown kid must be refused")
	}
}

func TestExpiredTokenFails(t *testing.T) {
	pub, priv := keypair(t)
	now := time.Now()
	raw := sign(t, priv, claims(now))

	if _, err := Verify(raw, map[string]ed25519.PublicKey{"lic-2026-11": pub}, now.Add(21*24*time.Hour)); err != ErrExpired {
		t.Fatalf("want ErrExpired, got %v", err)
	}
}

func TestAbsurdLifetimeFails(t *testing.T) {
	pub, priv := keypair(t)
	now := time.Now()
	c := claims(now)
	c.Expires = now.Add(5 * 365 * 24 * time.Hour).Unix()
	raw := sign(t, priv, c)

	if _, err := Verify(raw, map[string]ed25519.PublicKey{"lic-2026-11": pub}, now); err == nil {
		t.Fatal("a five-year window must be refused")
	}
}

func TestMalformedTokensAreRefused(t *testing.T) {
	pub, _ := keypair(t)
	now := time.Now()
	keys := map[string]ed25519.PublicKey{"lic-2026-11": pub}

	for _, raw := range []string{"", "atlas-lic-v1", "atlas-lic-v1.abc", "nope.abc.def", "atlas-lic-v1.!!!.!!!"} {
		if _, err := Verify(raw, keys, now); err == nil {
			t.Fatalf("%q must be refused", raw)
		}
	}
}

func TestEvaluateStates(t *testing.T) {
	now := time.Now()

	t.Run("active", func(t *testing.T) {
		st := Evaluate(Claims{Status: "active", Expires: now.Add(10 * 24 * time.Hour).Unix()}, now, 14, now)
		if st.Mode != ModeActive || !st.AllowsWrites() {
			t.Fatalf("want active, got %+v", st)
		}
	})

	t.Run("grace after expiry", func(t *testing.T) {
		st := Evaluate(Claims{Status: "active", Expires: now.Add(-2 * 24 * time.Hour).Unix()}, now, 14, now)
		if st.Mode != ModeGrace || !st.AllowsWrites() {
			t.Fatalf("want grace, got %+v", st)
		}
	})

	t.Run("read only after grace", func(t *testing.T) {
		st := Evaluate(Claims{Status: "active", Expires: now.Add(-30 * 24 * time.Hour).Unix()}, now, 14, now)
		if st.Mode != ModeReadOnly || st.AllowsWrites() {
			t.Fatalf("want read_only, got %+v", st)
		}
	})

	t.Run("revoked is read only immediately", func(t *testing.T) {
		st := Evaluate(Claims{Status: "revoked", Expires: now.Add(10 * 24 * time.Hour).Unix()}, now, 14, now)
		if st.Mode != ModeReadOnly {
			t.Fatalf("want read_only, got %+v", st)
		}
	})

	t.Run("suspended is read only", func(t *testing.T) {
		st := Evaluate(Claims{Status: "suspended", Expires: now.Add(10 * 24 * time.Hour).Unix()}, now, 14, now)
		if st.Mode != ModeReadOnly {
			t.Fatalf("want read_only, got %+v", st)
		}
	})

	t.Run("no grace configured expires hard", func(t *testing.T) {
		st := Evaluate(Claims{Status: "active", Expires: now.Add(-time.Hour).Unix()}, now, 0, now)
		if st.Mode != ModeReadOnly {
			t.Fatalf("want read_only with zero grace, got %+v", st)
		}
	})
}

func TestClockRollbackIsTreatedAsTampering(t *testing.T) {
	now := time.Now()
	lastSeen := now.Add(2 * time.Hour) // the box previously reported a later time

	st := Evaluate(Claims{Status: "active", Expires: now.Add(10 * 24 * time.Hour).Unix()}, now, 14, lastSeen)
	if st.Mode != ModeReadOnly {
		t.Fatalf("a backwards clock must drop to read_only, got %+v", st)
	}
	if !strings.Contains(st.Reason, "clock") {
		t.Fatalf("reason should mention the clock: %q", st.Reason)
	}
}

func TestFingerprintBinding(t *testing.T) {
	c := Claims{Fingerprint: "abc"}

	if !c.FingerprintMatches("abc") {
		t.Fatal("matching fingerprint must pass")
	}
	if c.FingerprintMatches("def") {
		t.Fatal("a different machine must not use this licence")
	}
	if !(Claims{}).FingerprintMatches("anything") {
		t.Fatal("an unbound licence may bind to the first machine")
	}
}

func TestCriticalInvariantStateNeverLocksTheCustomerOutOfTheirData(t *testing.T) {
	// Whatever the state, the mode must be one of three values — there is no
	// "suspended data" mode that could be mistaken for a delete instruction.
	now := time.Now()
	cases := []Claims{
		{Status: "active", Expires: now.Add(time.Hour).Unix()},
		{Status: "active", Expires: now.Add(-1000 * time.Hour).Unix()},
		{Status: "revoked", Expires: now.Add(time.Hour).Unix()},
		{Status: "suspended", Expires: now.Add(time.Hour).Unix()},
	}

	for _, c := range cases {
		st := Evaluate(c, now, 14, now)
		switch st.Mode {
		case ModeActive, ModeGrace, ModeReadOnly:
		default:
			t.Fatalf("unexpected mode %q", st.Mode)
		}
	}
}
