package ops

import (
	"context"
	"encoding/json"
	"strings"
	"testing"
)

// The nineteen file and SSH operations, each run twice: once with arguments
// that must reach the System, and once with arguments that must be refused
// before the System is touched. This table found three real gaps on its first
// run — file.restore accepted any home path, ssh.info ignored unknown fields,
// and file.upload accepted invalid base64 — which is why it exists.

type fileSSHCase struct {
	op      string
	valid   string
	invalid string
}

func fileSSHCases() []fileSSHCase {
	return []fileSSHCase{
		{
			op:      "file.upload",
			valid:   `{"username":"acme","path":"/home/acme/public_html/a.txt","content":"aGk="}`,
			invalid: `{"username":"acme","path":"/home/acme/public_html/a.txt","content":"not base64!!"}`,
		},
		{
			op:      "file.download",
			valid:   `{"username":"acme","path":"/home/acme/public_html/index.php"}`,
			invalid: `{"username":"acme","path":"/etc/passwd"}`,
		},
		{
			op:      "file.copy",
			valid:   `{"username":"acme","from":"/home/acme/a.txt","to":"/home/acme/b.txt"}`,
			invalid: `{"username":"acme","from":"/home/acme","to":"/home/acme/sub/deeper"}`,
		},
		{
			op:      "file.chmod",
			valid:   `{"username":"acme","path":"/home/acme/public_html","mode":"0755","recursive":true}`,
			invalid: `{"username":"acme","path":"/home/acme","mode":"0755","recursive":true}`,
		},
		{
			op:      "file.compress",
			valid:   `{"username":"acme","paths":["/home/acme/public_html"],"archive":"/home/acme/site.tar.gz","format":"tar.gz"}`,
			invalid: `{"username":"acme","paths":["/home/acme/public_html"],"archive":"/home/acme/site.tar.gz","format":"rar"}`,
		},
		{
			op:      "file.extract",
			valid:   `{"username":"acme","archive":"/home/acme/site.tar.gz","dest":"/home/acme/public_html"}`,
			invalid: `{"username":"acme","archive":"/home/acme/site.tar.gz","dest":"/var/www"}`,
		},
		{
			op:      "file.search",
			valid:   `{"username":"acme","path":"/home/acme","query":"wp-config"}`,
			invalid: `{"username":"acme","path":"/home/acme","query":"*.php"}`,
		},
		{
			op:      "file.stat",
			valid:   `{"username":"acme","paths":["/home/acme/public_html"]}`,
			invalid: `{"username":"acme","paths":["/home/acme/../../../etc"]}`,
		},
		{
			op:      "file.trash",
			valid:   `{"username":"acme","path":"/home/acme/public_html/old.txt"}`,
			invalid: `{"username":"acme","path":"/home/acme"}`,
		},
		{
			op:      "file.restore",
			valid:   `{"username":"acme","path":"/home/acme/.atlas-trash/20260101-000000__cHVibGljX2h0bWwvb2xkLnR4dA"}`,
			invalid: `{"username":"acme","path":"/home/acme/public_html/index.php"}`,
		},
		{
			op:      "file.trash.list",
			valid:   `{"username":"acme"}`,
			invalid: `{"username":"../etc"}`,
		},
		{
			op:      "ssh.key.generate",
			valid:   `{"username":"acme","name":"id_ed25519","type":"ed25519","comment":"laptop"}`,
			invalid: `{"username":"acme","name":"authorized_keys","type":"ed25519"}`,
		},
		{
			op:      "ssh.key.import",
			valid:   `{"username":"acme","name":"work","public":"ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl me@laptop"}`,
			invalid: `{"username":"acme","name":"work","public":"not-a-key at all"}`,
		},
		{
			op:      "ssh.key.list",
			valid:   `{"username":"acme"}`,
			invalid: `{"username":"acme;rm -rf /"}`,
		},
		{
			op:      "ssh.key.authorize",
			valid:   `{"username":"acme","name":"id_ed25519"}`,
			invalid: `{"username":"acme","name":"../authorized_keys"}`,
		},
		{
			op:      "ssh.key.deauthorize",
			valid:   `{"username":"acme","name":"id_ed25519"}`,
			invalid: `{"username":"acme","name":"config"}`,
		},
		{
			op:      "ssh.key.delete",
			valid:   `{"username":"acme","name":"old-key"}`,
			invalid: `{"username":"acme","name":""}`,
		},
		{
			op:      "ssh.access.set",
			valid:   `{"username":"acme","enabled":true}`,
			invalid: `{"username":"root","enabled":true}`,
		},
		{
			op:      "ssh.info",
			valid:   `{}`,
			invalid: `{"command":"id"}`,
		},
	}
}

func TestEveryFileAndSSHOperationIsRegisteredAndValidated(t *testing.T) {
	sys := &stubSystem{}
	r := NewRegistry(sys)

	for _, tc := range fileSSHCases() {
		t.Run(tc.op, func(t *testing.T) {
			// The valid form must reach the system.
			sys.calls = nil

			if _, err := r.Dispatch(context.Background(), tc.op, json.RawMessage(tc.valid)); err != nil {
				t.Fatalf("valid arguments were refused: %v", err)
			}
			if len(sys.calls) == 0 {
				t.Fatal("valid arguments never reached the system")
			}

			// The invalid form must not reach it at all.
			sys2 := &stubSystem{}
			r2 := NewRegistry(sys2)

			if _, err := r2.Dispatch(context.Background(), tc.op, json.RawMessage(tc.invalid)); err == nil {
				t.Fatalf("invalid arguments were accepted: %s", tc.invalid)
			}
			if len(sys2.calls) != 0 {
				t.Fatalf("invalid arguments reached the system: %v", sys2.calls)
			}
		})
	}
}

func TestEveryFileAndSSHOperationIsRegistered(t *testing.T) {
	r := NewRegistry(&stubSystem{})

	listed := r.Ops()
	seen := map[string]bool{}
	for _, op := range listed {
		seen[op] = true
	}

	for _, tc := range fileSSHCases() {
		if !seen[tc.op] {
			t.Errorf("%s is not registered", tc.op)
		}
	}
}

func TestUploadMustBeBase64(t *testing.T) {
	sys := &stubSystem{}
	r := NewRegistry(sys)

	_, err := r.Dispatch(context.Background(), "file.upload",
		json.RawMessage(`{"username":"acme","path":"/home/acme/x.txt","content":"!!!!"}`))

	if err == nil {
		t.Fatal("invalid base64 was accepted")
	}
	if len(sys.calls) != 0 {
		t.Fatal("an invalid payload reached the file system")
	}
}

func TestRestoreOnlyAcceptsTrashEntries(t *testing.T) {
	sys := &stubSystem{}
	r := NewRegistry(sys)

	// A home path is not a trash entry. Without this refusal, restore would be a
	// general-purpose "move any path" primitive.
	_, err := r.Dispatch(context.Background(), "file.restore",
		json.RawMessage(`{"username":"acme","path":"/home/acme/public_html/index.php"}`))

	if err == nil {
		t.Fatal("a non-trash path was accepted for restore")
	}
	if !strings.Contains(err.Error(), "trash") {
		t.Fatalf("the refusal should mention the trash: %v", err)
	}
}

func TestSSHInfoRefusesUnknownFields(t *testing.T) {
	_, err := NewRegistry(&stubSystem{}).Dispatch(
		context.Background(), "ssh.info", json.RawMessage(`{"command":"id"}`))

	if err == nil {
		t.Fatal("ssh.info accepted an argument")
	}
}

func TestReservedSSHNamesAreRefusedAtEveryLayer(t *testing.T) {
	for _, name := range []string{"authorized_keys", "config", "known_hosts", "known_hosts.old", "environment", "rc"} {
		sys := &stubSystem{}
		r := NewRegistry(sys)

		raw := json.RawMessage(`{"username":"acme","name":"` + name + `","type":"ed25519"}`)

		if _, err := r.Dispatch(context.Background(), "ssh.key.generate", raw); err == nil {
			t.Errorf("%s was accepted as a key name", name)
		}
		if len(sys.calls) != 0 {
			t.Errorf("%s reached the system", name)
		}
	}
}

func TestModeRefusesTheWaysASiteGetsOwned(t *testing.T) {
	bad := []string{"0777", "0776", "0667", "0200", "0000"}
	for _, mode := range bad {
		if _, err := validMode(mode, false); err == nil {
			t.Errorf("mode %s should have been refused", mode)
		}
	}

	good := []string{"0644", "0600", "0755", "0640", "0700", "755"}
	for _, mode := range good {
		if _, err := validMode(mode, false); err != nil {
			t.Errorf("mode %s should have been accepted: %v", mode, err)
		}
	}

	// A directory must stay enterable.
	if _, err := validMode("0644", true); err == nil {
		t.Error("a directory mode without owner execute should have been refused")
	}
}

func TestRSAKeysBelow2048BitsAreRefused(t *testing.T) {
	sys := &stubSystem{}
	r := NewRegistry(sys)

	_, err := r.Dispatch(context.Background(), "ssh.key.generate",
		json.RawMessage(`{"username":"acme","name":"weak","type":"rsa","bits":1024}`))

	if err == nil {
		t.Fatal("a 1024-bit RSA key was accepted")
	}
	if len(sys.calls) != 0 {
		t.Fatal("a weak key reached the system")
	}
}

func TestArchiveFormatDecidesTheExtension(t *testing.T) {
	sys := &stubSystem{}
	r := NewRegistry(sys)

	_, err := r.Dispatch(context.Background(), "file.compress",
		json.RawMessage(`{"username":"acme","paths":["/home/acme/x"],"archive":"/home/acme/x.tar.gz","format":"zip"}`))

	if err == nil {
		t.Fatal("a zip archive with a .tar.gz name was accepted")
	}
}

func TestSearchRefusesGlobs(t *testing.T) {
	for _, q := range []string{"*.php", "file?.txt", "[abc]"} {
		if err := validSearchQuery(q); err == nil {
			t.Errorf("glob %q should have been refused", q)
		}
	}

	if err := validSearchQuery("wp-config"); err != nil {
		t.Errorf("a plain name should be accepted: %v", err)
	}
}

func TestPublicKeysAreShapeChecked(t *testing.T) {
	bad := []string{
		"", "not-a-key", "ssh-ed25519", "ssh-ed25519 short",
		"ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMq\nsecond line",
		"sh-unknown AAAA",
	}

	for _, k := range bad {
		if err := validPublicKey(k); err == nil {
			t.Errorf("bad key %q was accepted", k)
		}
	}

	good := "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl me@laptop"
	if err := validPublicKey(good); err != nil {
		t.Errorf("a valid ed25519 key was refused: %v", err)
	}
}

func TestTrashPathsAreConfinedToTheTrash(t *testing.T) {
	ok := "/home/acme/.atlas-trash/20260101-000000__cHVibGljX2h0bWwvb2xkLnR4dA"
	if err := validTrashPath("acme", ok); err != nil {
		t.Fatalf("a valid trash entry was refused: %v", err)
	}

	bad := []string{
		"/home/acme/public_html/index.php",
		"/home/acme/.atlas-trash",
		"/home/acme/.atlas-trash/",
		"/home/acme/.atlas-trash/sub/entry",
		"/home/other/.atlas-trash/entry",
	}

	for _, p := range bad {
		if err := validTrashPath("acme", p); err == nil {
			t.Errorf("%q was accepted as a trash entry", p)
		}
	}
}
