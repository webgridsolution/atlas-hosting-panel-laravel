package ops

import (
	"encoding/json"
	"fmt"
	"regexp"
	"strconv"
	"strings"
)

// Every validator here runs before the System sees the arguments. The rules are
// deliberately narrower than "what Linux accepts": a hosting account is not a
// place to be creative with usernames or path syntax.

var (
	usernameRE  = regexp.MustCompile(`^[a-z][a-z0-9]{2,15}$`)
	domainRE    = regexp.MustCompile(`^(?i:([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24})$`)
	phpRE       = regexp.MustCompile(`^8\.[1-5]$`)
	dbNameRE    = regexp.MustCompile(`^[a-z][a-z0-9_]{2,47}$`)
	localPartRE = regexp.MustCompile(`^[a-z0-9][a-z0-9._-]{0,63}$`)
	dnsNameRE   = regexp.MustCompile(`^(@|[a-z0-9_*]([a-z0-9._-]{0,251})?(\.)?)$`)
	recTypeRE   = regexp.MustCompile(`^(A|AAAA|CNAME|MX|TXT|NS|SRV|CAA)$`)
	cronRE      = regexp.MustCompile(`^[0-9*/,\-]{1,40}(\s+[0-9*/,\-]{1,40}){4}\s+\S`)
)

// Usernames are also used as Unix users, PHP-FPM pool names, directory names
// and MySQL user prefixes, so they are restricted to lowercase alphanumerics
// with a leading letter and no reserved names.
func validUsername(u string) error {
	if !usernameRE.MatchString(u) {
		return fmt.Errorf("invalid username %q: lowercase letters and digits, 3-16 chars, starting with a letter", u)
	}

	switch u {
	case "root", "admin", "atlas", "mysql", "postgres", "www-data", "nobody", "daemon",
		"bin", "sys", "mail", "systemd", "apache", "nginx", "ftp", "sftp":
		return fmt.Errorf("username %q is reserved", u)
	}

	return nil
}

func validDomain(d string) error {
	if len(d) > 253 || !domainRE.MatchString(d) {
		return fmt.Errorf("invalid domain %q", d)
	}

	return nil
}

func validQuota(diskMB, inodes int) error {
	if diskMB < 100 || diskMB > 10*1024*1024 {
		return fmt.Errorf("disk quota must be between 100 MB and 10 TB, got %d", diskMB)
	}
	if inodes < 1000 || inodes > 50_000_000 {
		return fmt.Errorf("inode limit must be between 1,000 and 50,000,000, got %d", inodes)
	}

	return nil
}

func validPHP(v string) error {
	if v == "" {
		return nil // server default
	}
	if !phpRE.MatchString(v) {
		return fmt.Errorf("unsupported PHP version %q (8.1–8.5)", v)
	}

	return nil
}

func validDatabase(name, user string) error {
	if !dbNameRE.MatchString(name) {
		return fmt.Errorf("invalid database name %q", name)
	}
	if !dbNameRE.MatchString(user) {
		return fmt.Errorf("invalid database user %q", user)
	}

	return nil
}

func validMailboxLocalPart(p string) error {
	if !localPartRE.MatchString(p) {
		return fmt.Errorf("invalid mailbox name %q", p)
	}

	return nil
}

func validRecord(r DNSRecord) error {
	if !recTypeRE.MatchString(r.Type) {
		return fmt.Errorf("unsupported record type %q", r.Type)
	}
	if !dnsNameRE.MatchString(r.Name) {
		return fmt.Errorf("invalid record name %q", r.Name)
	}
	if strings.ContainsAny(r.Content, "\n\r") || len(r.Content) > 512 {
		return fmt.Errorf("invalid record content for %s", r.Name)
	}
	if r.TTL < 0 || r.TTL > 604800 {
		return fmt.Errorf("TTL must be 0–604800 seconds")
	}

	return nil
}

func validCronEntry(entry string) error {
	fields := strings.Fields(entry)
	if len(fields) < 6 || !cronRE.MatchString(entry) {
		return fmt.Errorf("cron entry must be five time fields followed by a command")
	}
	// A newline would let a caller add a second job, bypassing per-job
	// validation and the UI's "one job at a time" model.
	if strings.ContainsAny(entry, "\n\r") {
		return fmt.Errorf("cron entries may not contain newlines")
	}

	return nil
}

// Paths handed to file operations are absolute and must resolve inside the
// account's home directory. ".." is rejected outright rather than resolved:
// symlink games are the classic escape, and a hosting panel has no reason to
// accept a relative path.
func validHomePath(username, path string) error {
	if !strings.HasPrefix(path, "/home/"+username+"/") && path != "/home/"+username {
		return fmt.Errorf("path %q is outside /home/%s", path, username)
	}
	if strings.Contains(path, "..") || strings.ContainsAny(path, "\x00\n\r") {
		return fmt.Errorf("path %q contains a forbidden sequence", path)
	}
	if strings.Contains(path, "//") {
		return fmt.Errorf("path %q is not normalised", path)
	}

	return nil
}

// The document root of a vhost is a security boundary: pointing it at /etc is
// how a mis-typed panel request turns into a file disclosure.
func documentRootMustStayInsideHome(root, username string) error {
	want := "/home/" + username

	if root != want && !strings.HasPrefix(root, want+"/") {
		return fmt.Errorf("document root %q must be inside /home/%s", root, username)
	}
	if strings.Contains(root, "..") {
		return fmt.Errorf("document root may not contain '..'")
	}

	return nil
}

// decodeArgs rejects unknown fields: a typo in the panel must be a loud error,
// not a silently ignored argument that changes the operation's meaning.
func decodeArgs[T any](raw json.RawMessage) (T, error) {
	var v T

	dec := json.NewDecoder(strings.NewReader(string(raw)))
	dec.DisallowUnknownFields()

	if err := dec.Decode(&v); err != nil {
		return v, fmt.Errorf("invalid arguments: %w", err)
	}

	return v, nil
}

func parseIntField(s string) (int, error) {
	n, err := strconv.Atoi(s)
	if err != nil {
		return 0, fmt.Errorf("not a number: %q", s)
	}

	return n, nil
}
