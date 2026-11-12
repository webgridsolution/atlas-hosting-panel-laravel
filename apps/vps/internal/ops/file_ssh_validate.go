package ops

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"regexp"
	"strings"
	"unicode/utf8"
)

// Validators for the file-manager and SSH operations.
//
// These are stricter than the core file.write path on purpose. file.write is
// reached by the panel's own editor with a path it built itself; everything here
// is reached with a path a customer typed, so every one re-checks the home
// boundary and refuses anything that could become a write primitive elsewhere.

var (
	// Three or four octal digits. 0644 and 644 are both accepted because both
	// are what people type.
	modeRE = regexp.MustCompile(`^[0-7]{3,4}$`)

	// An SSH key name: no slashes, no dots at the start, nothing that could walk
	// out of ~/.ssh.
	keyNameRE = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$`)

	// A public key's first field. The sk-* variants are FIDO tokens, which are
	// perfectly valid ssh keys and which OpenSSH accepts in authorized_keys.
	keyTypeRE = regexp.MustCompile(`^(ssh-(ed25519|rsa|dss)|ecdsa-sha2-nistp(256|384|521)|sk-ssh-ed25519@openssh\.com|sk-ecdsa-sha2-nistp256@openssh\.com)$`)

	// The trash directory every account gets.
	trashDirName = ".atlas-trash"
)

func errf(format string, args ...any) error {
	return fmt.Errorf(format, args...)
}

// validMode mirrors the rule the panel shows in its hint text: an owner must
// keep read (and execute, for a directory), and a mode that is world-writable
// and group-writable at once is refused because it is how a site gets owned.
func validMode(mode string, isDir bool) (uint32, error) {
	if !modeRE.MatchString(mode) {
		return 0, errf("mode %q must be three or four octal digits, e.g. 644 or 0755", mode)
	}

	var v uint32
	for _, c := range mode {
		v = v<<3 | uint32(c-'0')
	}

	if v&0o400 == 0 {
		return 0, errf("mode %q removes owner read — refusing", mode)
	}
	if isDir && v&0o100 == 0 {
		return 0, errf("mode %q on a directory removes owner execute — the folder would be unreachable", mode)
	}
	if v&0o002 != 0 && v&0o020 != 0 {
		return 0, errf("mode %q is world-writable and group-writable at once — use 775 or 755", mode)
	}

	return v, nil
}

// validArchivePath is a destination for a new archive: inside the home, ending
// in the extension its format implies.
func validArchivePath(username, path, format string) error {
	if err := validHomePath(username, path); err != nil {
		return err
	}

	switch format {
	case "tar.gz":
		if !strings.HasSuffix(path, ".tar.gz") && !strings.HasSuffix(path, ".tgz") {
			return errf("a tar.gz archive must end in .tar.gz or .tgz")
		}
	case "zip":
		if !strings.HasSuffix(path, ".zip") {
			return errf("a zip archive must end in .zip")
		}
	default:
		return errf("format %q is not supported; use tar.gz or zip", format)
	}

	return nil
}

// validSSHName refuses the names a panel must not let a customer take: the ones
// sshd itself reads, and the ones that would shadow a private key.
func validSSHName(name string) error {
	if !keyNameRE.MatchString(name) {
		return errf("key name %q: letters, digits, dot, underscore and hyphen only", name)
	}

	switch strings.ToLower(name) {
	case "authorized_keys", "config", "known_hosts", "known_hosts.old", "environment", "rc":
		return errf("%q is reserved by ssh", name)
	}

	if strings.HasSuffix(strings.ToLower(name), ".pub") {
		return errf("a key name must not end in .pub — that half is written for you")
	}

	return nil
}

// validPublicKey checks the shape of an imported key before it reaches the disk.
func validPublicKey(public string) error {
	if public == "" {
		return errf("a public key is required")
	}
	if len(public) > 8192 {
		return errf("that public key is too long")
	}
	if strings.ContainsAny(public, "\r\n\x00") {
		return errf("a public key is one line")
	}

	fields := strings.Fields(public)
	if len(fields) < 2 {
		return errf("that is not a public key: expected \"ssh-ed25519 AAAA…\" or similar")
	}

	if !keyTypeRE.MatchString(fields[0]) {
		return errf("key type %q is not one OpenSSH accepts", fields[0])
	}

	if len(fields[1]) < 32 {
		return errf("key body is the wrong length")
	}

	return nil
}

// validSearchQuery refuses the patterns a caller might hope would be expanded.
//
// Written as explicit checks rather than one character class: a class that has
// to escape *, ?, [ and ] is a class that is easy to get subtly wrong, and this
// one was wrong until the table test caught it.
func validSearchQuery(query string) error {
	if strings.TrimSpace(query) == "" {
		return errf("search for a name, not nothing")
	}
	if strings.ContainsAny(query, "*?[]") {
		return errf("search for a name, not a pattern")
	}
	if utf8.RuneCountInString(query) > 200 {
		return errf("that search term is too long")
	}
	for _, r := range query {
		if r < 0x20 || r == 0x7f {
			return errf("that search term contains a control character")
		}
	}
	return nil
}

// validTrashPath is the one path file.restore will accept: an entry directly
// inside the account's own trash. Without this check, restore would be a
// generic "move any path to a file's recorded original location" primitive.
func validTrashPath(username, path string) error {
	prefix := trashDir(username) + "/"

	if !strings.HasPrefix(path, prefix) {
		return errf("only an entry inside %s can be restored", prefix)
	}

	rest := strings.TrimPrefix(path, prefix)
	if rest == "" || strings.ContainsAny(rest, "/\x00") {
		return errf("that is not a trash entry")
	}

	return nil
}

// homeDir is the account's home, built the same way the rest of the registry
// builds it rather than by string concatenation at each call site.
func homeDir(username string) string {
	return "/home/" + username
}

// trashDir is where an account's deleted items wait.
func trashDir(username string) string {
	return homeDir(username) + "/" + trashDirName
}

// decodeBase64 checks an upload's payload before any byte is written. The panel
// always sends base64; a request that does not is either a bug or an attempt to
// smuggle something past the size check.
func decodeBase64(s string, max int) ([]byte, error) {
	if s == "" {
		return []byte{}, nil
	}

	raw, err := base64.StdEncoding.DecodeString(s)
	if err != nil {
		return nil, errf("content must be base64: %v", err)
	}

	if len(raw) > max {
		return nil, errf("content exceeds the %d byte limit", max)
	}

	return raw, nil
}

// decodeStrict decodes into a target and refuses unknown fields, so a typo in an
// operation's arguments fails loudly instead of being silently ignored.
func decodeStrict(raw json.RawMessage, target any) error {
	dec := json.NewDecoder(strings.NewReader(string(raw)))
	dec.DisallowUnknownFields()

	return dec.Decode(target)
}

// regexpMustCompile keeps the var block above honest: a bad pattern panics at
// start-up rather than at the first request that needs it.
func regexpMustCompile(pattern string) *regexp.Regexp {
	return regexp.MustCompile(pattern)
}
