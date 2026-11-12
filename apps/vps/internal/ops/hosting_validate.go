package ops

import (
	"fmt"
	"net"
	"regexp"
	"strings"
)

// Validators for the hosting operations.
//
// Every one of these runs before the System implementation is reached, so a bad
// argument is refused without any file, database or process being touched.

var (
	// A MySQL user name is not a Linux user name: MySQL allows underscores and
	// caps at 32 characters. Using validUsername here would reject names the
	// panel legitimately generates.
	dbUserRE = regexp.MustCompile(`^[a-z][a-z0-9_]{2,31}$`)

	// A local mailbox part, and a forwarder source that may also be a catch-all.
	mailSourceRE = regexp.MustCompile(`^([a-z0-9][a-z0-9._-]{0,63}|\*)$`)

	// An extension override: no leading dot, lowercase, short.
	extRE = regexp.MustCompile(`^[a-z0-9]{1,16}$`)

	// A MIME type: type/subtype with an optional parameter.
	mimeRE = regexp.MustCompile(`^[a-z0-9][a-z0-9!#$&^_.+-]{0,63}/[a-z0-9][a-z0-9!#$&^_.+-]{0,63}(;.*)?$`)

	// A git remote we are willing to run `git pull` against. No local paths, no
	// ext:: transports, nothing that starts with a dash.
	remoteRE = regexp.MustCompile(`^(https://|ssh://|git@)[A-Za-z0-9._~:/?#\[\]@!$&'()*+,;=%-]+$`)

	// A branch name that cannot be a flag or a path.
	branchRE = regexp.MustCompile(`^[A-Za-z0-9._/-]{1,120}$`)

	// A Sieve field the compiler knows how to emit.
	filterFieldRE = regexp.MustCompile(`^(from|to|cc|subject|body|header|x-spam-flag)$`)
	filterOpRE    = regexp.MustCompile(`^(contains|equals|matches|starts|ends)$`)
	filterActRE   = regexp.MustCompile(`^(discard|redirect|keep|mark_spam|move)$`)
)

// Scalar validators ---------------------------------------------------------

func validDBUser(name string) error {
	if !dbUserRE.MatchString(name) {
		return fmt.Errorf("invalid database user %q: lowercase letters, digits and underscores, 3-32 chars, starting with a letter", name)
	}

	return nil
}

func validDBPrivileges(p string) error {
	switch strings.ToUpper(strings.TrimSpace(p)) {
	case "ALL PRIVILEGES", "SELECT", "SELECT, INSERT, UPDATE, DELETE", "SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER":
		return nil
	}

	return fmt.Errorf("unsupported privilege set %q", p)
}

func validRemoteHost(h string) error {
	if h == "" || len(h) > 190 {
		return fmt.Errorf("invalid remote host %q", h)
	}

	// An address or a CIDR; "%" is MariaDB's wildcard for "any host" and is far
	// too broad to accept from a customer form.
	if strings.ContainsAny(h, "% \t\n\r") {
		return fmt.Errorf("remote host %q may not contain wildcards or whitespace", h)
	}

	if ip := net.ParseIP(h); ip != nil {
		return nil
	}

	if _, _, err := net.ParseCIDR(h); err == nil {
		return nil
	}

	// A hostname is acceptable too.
	if err := validDomain(h); err == nil {
		return nil
	}

	return fmt.Errorf("remote host %q must be an address, a CIDR block or a hostname", h)
}

func validCIDR(c string) error {
	c = strings.TrimSpace(c)

	if ip := net.ParseIP(c); ip != nil {
		return nil
	}

	if _, _, err := net.ParseCIDR(c); err == nil {
		return nil
	}

	return fmt.Errorf("invalid address or range %q", c)
}

func validMailSource(s string) error {
	if !mailSourceRE.MatchString(s) {
		return fmt.Errorf("invalid mailbox or source %q", s)
	}

	return nil
}

func validExtension(e string) error {
	if !extRE.MatchString(e) {
		return fmt.Errorf("invalid extension %q: lowercase letters and digits, no dot", e)
	}

	return nil
}

func validMimeType(m string) error {
	if len(m) > 190 || !mimeRE.MatchString(m) {
		return fmt.Errorf("invalid MIME type %q", m)
	}

	return nil
}

func validRemoteURL(r string) error {
	if r == "" {
		return nil
	}
	if len(r) > 512 || !remoteRE.MatchString(r) || strings.HasPrefix(r, "-") {
		return fmt.Errorf("unsupported git remote %q: use https://, ssh:// or git@", r)
	}

	return nil
}

func validBranch(b string) error {
	if b == "" || !branchRE.MatchString(b) || strings.HasPrefix(b, "-") || strings.Contains(b, "..") {
		return fmt.Errorf("invalid branch name %q", b)
	}

	return nil
}

func validFTPUsername(u string) error {
	if len(u) < 3 || len(u) > 32 || !dbUserRE.MatchString(u) {
		return fmt.Errorf("invalid FTP user %q: lowercase letters, digits and underscores, 3-32 chars", u)
	}

	return nil
}

func validSubdomainLabel(l string) error {
	if len(l) < 1 || len(l) > 63 {
		return fmt.Errorf("invalid subdomain label %q", l)
	}
	if strings.HasPrefix(l, "-") || strings.HasSuffix(l, "-") {
		return fmt.Errorf("subdomain label %q may not start or end with a hyphen", l)
	}
	for _, r := range l {
		if (r < 'a' || r > 'z') && (r < '0' || r > '9') && r != '-' {
			return fmt.Errorf("subdomain label %q must be lowercase letters, digits and hyphens", l)
		}
	}

	return nil
}

func validRedirectPath(p string) error {
	if !strings.HasPrefix(p, "/") {
		return fmt.Errorf("redirect source %q must start with /", p)
	}
	if len(p) > 255 || strings.Contains(p, "..") || strings.ContainsAny(p, "\n\r\x00") {
		return fmt.Errorf("invalid redirect source %q", p)
	}

	return nil
}

func validRedirectTarget(t string) error {
	if len(t) > 512 || strings.ContainsAny(t, "\n\r\x00 ") {
		return fmt.Errorf("invalid redirect target %q", t)
	}
	if !strings.HasPrefix(t, "https://") && !strings.HasPrefix(t, "http://") && !strings.HasPrefix(t, "/") {
		return fmt.Errorf("redirect target %q must be an http(s) URL or a path", t)
	}

	return nil
}

func validHTTPStatus(s int) error {
	switch s {
	case 301, 302, 307, 308:
		return nil
	}

	return fmt.Errorf("unsupported redirect status %d", s)
}

func validFilterRules(rules []FilterRule) error {
	if len(rules) > 500 {
		return fmt.Errorf("too many filter rules (%d)", len(rules))
	}

	for i, r := range rules {
		if !filterFieldRE.MatchString(r.Field) {
			return fmt.Errorf("rule %d: unsupported field %q", i, r.Field)
		}
		if !filterOpRE.MatchString(r.Operator) {
			return fmt.Errorf("rule %d: unsupported operator %q", i, r.Operator)
		}
		if !filterActRE.MatchString(r.Action) {
			return fmt.Errorf("rule %d: unsupported action %q", i, r.Action)
		}
		if len(r.Value) > 255 {
			return fmt.Errorf("rule %d: value too long", i)
		}
		if (r.Action == "move" || r.Action == "redirect") && r.ActionArg == "" {
			return fmt.Errorf("rule %d: %s needs a target", i, r.Action)
		}
	}

	return nil
}

// The catalogue is the contract between the panel and this agent: a slug that is
// not here cannot be installed, whatever the panel believes.
var appCatalog = map[string]string{
	"wordpress": "6.7",
	"joomla":    "5.2",
	"drupal":    "11.0",
	"matomo":    "5.1",
	"nextcloud": "30",
}

func validAppSlug(slug string) error {
	if _, ok := appCatalog[slug]; !ok {
		return fmt.Errorf("application %q is not in the catalogue", slug)
	}

	return nil
}

func validErrorStatus(s int) error {
	if s < 400 || s > 599 {
		return fmt.Errorf("invalid HTTP status %d", s)
	}

	return nil
}

// phpDirectiveAllowlist is the same set the panel's PhpSetting::EDITABLE holds.
// If the two drift, one side silently drops a directive the other believes it
// set, so the list is duplicated on purpose and asserted by tests.
var phpDirectiveAllowlist = map[string]bool{
	"memory_limit":        true,
	"upload_max_filesize": true,
	"post_max_size":       true,
	"max_execution_time":  true,
	"max_input_vars":      true,
	"max_input_time":      true,
	"display_errors":      true,
	"allow_url_fopen":     true,
	"date.timezone":       true,
}

func filterPHP(m map[string]string) map[string]string {
	out := make(map[string]string, len(m))

	for k, v := range m {
		if phpDirectiveAllowlist[k] {
			out[k] = v
		}
	}

	return out
}

// emailRE is deliberately plain: a local part of the characters a mailbox name
// can hold, an @, and a domain that already passes validDomain.
var emailRE = regexp.MustCompile(`^[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]{4,253}$`)

// validEmailAddress accepts an address that can hold no Postfix directive and
// no shell metacharacter.
func validEmailAddress(a string) bool {
	if !emailRE.MatchString(a) || strings.Contains(a, "..") {
		return false
	}

	at := strings.LastIndex(a, "@")

	return validDomain(strings.ToLower(a[at+1:])) == nil
}
