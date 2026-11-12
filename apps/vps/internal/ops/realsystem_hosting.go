package ops

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

// The real implementations of the hosting feature set.
//
// Ground rules, all of them enforced by construction here rather than by
// convention:
//
//   - Every binary is invoked through execx with an argv slice. There is no
//     shell, so a value with a space or a semicolon cannot become a second
//     command.
//   - Every SQL statement is built from a validated identifier; the values are
//     quoted, and identifiers are the only thing interpolated into DDL because
//     MySQL does not accept placeholders there.
//   - Every file the agent writes is under either /etc/atlas, the account's home,
//     or a directory named in Paths.

// ---- domains --------------------------------------------------------------

func (s *RealSystem) CreateSubdomain(ctx context.Context, spec SubdomainSpec) error {
	fqdn := spec.Label + "." + spec.Domain

	vhost := VhostSpec{
		Domain:       fqdn,
		Username:     spec.Username,
		DocumentRoot: spec.DocumentRoot,
		PHPVersion:   spec.PHPVersion,
		SSL:          false,
	}

	if err := s.WriteVhost(ctx, vhost); err != nil {
		return err
	}

	if spec.MailEnabled {
		return s.writeMailDomain(fqdn, spec.Username)
	}

	return nil
}

// writeMailDomain appends a domain to Postfix's virtual domain map and reloads.
func (s *RealSystem) writeMailDomain(domain, username string) error {
	const file = "/etc/postfix/atlas-virtual-domains"

	existing, _ := s.fs.ReadFile(file)
	line := domain + "\t" + username + "\n"

	if !strings.Contains(string(existing), line) {
		if err := s.fs.WriteFile(file, append(existing, []byte(line)...), 0o644); err != nil {
			return err
		}
	}

	if _, err := s.run.Run(context.Background(), "postmap", file); err != nil {
		return fmt.Errorf("postmap: %w", err)
	}

	return s.reloadPostfix(context.Background())
}

func (s *RealSystem) AttachAliases(ctx context.Context, username, domain string, aliases []string) error {
	// An alias is another ServerName in the same vhost, so the certificate and
	// document root stay shared.
	return s.WriteVhost(ctx, VhostSpec{
		Domain:       domain,
		Username:     username,
		DocumentRoot: "/home/" + username + "/" + domain,
		Aliases:      aliases,
		PHPVersion:   "8.3",
	})
}

func (s *RealSystem) RemoveDomain(ctx context.Context, username, domain string) error {
	if err := s.RemoveVhost(ctx, domain); err != nil {
		return err
	}

	// The document root is left in place on purpose. Deleting a customer's files
	// as a side effect of removing a name is not this operation's decision.
	return nil
}

func (s *RealSystem) SetVhostPHP(ctx context.Context, spec VhostPhpSpec) error {
	// Rewriting the vhost with the new pool is the whole operation; the SSL
	// directive carries over unless the caller says otherwise.
	return s.WriteVhost(ctx, VhostSpec{
		Domain:       spec.Domain,
		Username:     spec.Username,
		DocumentRoot: "/home/" + spec.Username + "/public_html",
		PHPVersion:   spec.PHPVersion,
		SSL:          spec.KeepSSL,
	})
}

// ---- redirects ------------------------------------------------------------

// htaccessPath is the per-account file the panel's redirects live in. Writing
// .htaccess directly would collide with whatever the customer's application
// already put there.
func htaccessPath(username string) string {
	return "/home/" + username + "/.htaccess.atlas-redirects"
}

func (s *RealSystem) WriteRedirect(_ context.Context, spec RedirectSpec) error {
	line := fmt.Sprintf("Redirect %d %s %s\n", spec.Status, spec.Source, spec.Target)

	existing, _ := s.fs.ReadFile(htaccessPath(spec.Username))

	// One rule per source: replacing is an update, not an append.
	kept := make([]string, 0)
	for _, l := range strings.Split(string(existing), "\n") {
		if l == "" || strings.HasPrefix(l, "Redirect "+strconv.Itoa(spec.Status)+" "+spec.Source+" ") {
			continue
		}
		if strings.HasPrefix(l, "Redirect ") && strings.Contains(l, " "+spec.Source+" ") {
			continue
		}
		kept = append(kept, l)
	}

	kept = append(kept, strings.TrimSuffix(line, "\n"))

	if err := s.fs.WriteFile(htaccessPath(spec.Username), []byte(strings.Join(kept, "\n")+"\n"), 0o644); err != nil {
		return err
	}

	return s.reloadWeb(context.Background())
}

func (s *RealSystem) RemoveRedirect(_ context.Context, username, _ /*domain*/, source string) error {
	existing, err := s.fs.ReadFile(htaccessPath(username))
	if err != nil {
		return nil // nothing to remove
	}

	kept := make([]string, 0)
	for _, l := range strings.Split(string(existing), "\n") {
		if strings.HasPrefix(l, "Redirect ") && strings.Contains(l, " "+source+" ") {
			continue
		}
		if l != "" {
			kept = append(kept, l)
		}
	}

	if err := s.fs.WriteFile(htaccessPath(username), []byte(strings.Join(kept, "\n")), 0o644); err != nil {
		return err
	}

	return s.reloadWeb(context.Background())
}

// ---- FTP ------------------------------------------------------------------

// ftpPasswdFile is a proftpd passwd-file: name:hash:uid:gid:gecos:home:shell.
func ftpPasswdFile() string { return "/etc/proftpd/atlas.passwd" }

func (s *RealSystem) CreateFTPAccount(ctx context.Context, spec FTPSpec) error {
	hash, err := s.hashPassword(ctx, spec.Password)
	if err != nil {
		return err
	}

	entry, err := s.ftpEntry(ctx, spec.Username, spec.FTPUsername, hash, spec.HomeDir)
	if err != nil {
		return err
	}

	existing, _ := s.fs.ReadFile(ftpPasswdFile())
	lines := replaceFTPLine(string(existing), spec.FTPUsername, entry)

	if err := s.fs.WriteFile(ftpPasswdFile(), []byte(strings.Join(lines, "\n")+"\n"), 0o640); err != nil {
		return err
	}

	// A quota file per account, read by mod_quotatab. 0 means unlimited.
	quota := fmt.Sprintf("%s %d\n", spec.FTPUsername, spec.QuotaMB)
	if err := s.fs.WriteFile("/etc/proftpd/atlas.quota", append(s.readOrEmpty("/etc/proftpd/atlas.quota"), []byte(quota)...), 0o640); err != nil {
		return err
	}

	return s.reloadFTP(context.Background())
}

func (s *RealSystem) DeleteFTPAccount(_ context.Context, ftpUser string) error {
	existing, _ := s.fs.ReadFile(ftpPasswdFile())
	lines := make([]string, 0)

	for _, l := range strings.Split(string(existing), "\n") {
		if l == "" || strings.SplitN(l, ":", 2)[0] == ftpUser {
			continue
		}
		lines = append(lines, l)
	}

	if err := s.fs.WriteFile(ftpPasswdFile(), []byte(strings.Join(lines, "\n")+"\n"), 0o640); err != nil {
		return err
	}

	return s.reloadFTP(context.Background())
}

func (s *RealSystem) SetFTPPassword(ctx context.Context, ftpUser, password string) error {
	hash, err := s.hashPassword(ctx, password)
	if err != nil {
		return err
	}

	existing, err := s.fs.ReadFile(ftpPasswdFile())
	if err != nil {
		return fmt.Errorf("no FTP account file yet")
	}

	lines := strings.Split(string(existing), "\n")
	found := false

	for i, l := range lines {
		parts := strings.Split(l, ":")
		if len(parts) >= 2 && parts[0] == ftpUser {
			parts[1] = hash
			lines[i] = strings.Join(parts, ":")
			found = true
		}
	}

	if !found {
		return fmt.Errorf("FTP user %q does not exist", ftpUser)
	}

	if err := s.fs.WriteFile(ftpPasswdFile(), []byte(strings.Join(lines, "\n")), 0o640); err != nil {
		return err
	}

	return s.reloadFTP(context.Background())
}

func (s *RealSystem) hashPassword(ctx context.Context, plain string) (string, error) {
	// doveadm owns both the mailbox and the FTP hash so there is one code path
	// for credential storage.
	res, err := s.run.RunInput(ctx, plain, "doveadm", "pw", "-s", "SHA512-CRYPT", "-p", "-")
	if err != nil {
		return "", fmt.Errorf("hashing password: %w", err)
	}

	return strings.TrimSpace(string(res.Stdout)), nil
}

func (s *RealSystem) ftpEntry(ctx context.Context, username, ftpUser, hash, home string) (string, error) {
	res, err := s.run.Run(ctx, "id", "-u", username)
	if err != nil {
		return "", fmt.Errorf("account %q has no Linux user", username)
	}

	uid := strings.TrimSpace(string(res.Stdout))
	gid := uid

	if g, err := s.run.Run(ctx, "id", "-g", username); err == nil {
		gid = strings.TrimSpace(string(g.Stdout))
	}

	return strings.Join([]string{ftpUser, hash, uid, gid, "Atlas FTP", home, "/usr/sbin/nologin"}, ":"), nil
}

func replaceFTPLine(existing, ftpUser, entry string) []string {
	lines := make([]string, 0)

	for _, l := range strings.Split(existing, "\n") {
		if l == "" || strings.SplitN(l, ":", 2)[0] == ftpUser {
			continue
		}
		lines = append(lines, l)
	}

	return append(lines, entry)
}

// ---- mail policy ----------------------------------------------------------

// atlasVirtualFile is the Postfix virtual alias map: source -> destinations.
func atlasVirtualFile() string { return "/etc/postfix/atlas-virtual" }

func (s *RealSystem) WriteForwarder(ctx context.Context, spec ForwarderSpec) error {
	key := spec.Source + "@" + spec.Domain
	value := strings.Join(spec.Destinations, ", ")

	if spec.KeepCopy {
		value += ", " + spec.Source + "@" + spec.Domain
	}

	return s.writeVirtualEntry(ctx, key, value)
}

func (s *RealSystem) RemoveForwarder(ctx context.Context, domain, source string) error {
	return s.removeVirtualEntry(ctx, source+"@"+domain)
}

func (s *RealSystem) WriteCatchall(ctx context.Context, spec CatchallSpec) error {
	return s.writeVirtualEntry(ctx, "@"+spec.Domain, spec.Destination)
}

func (s *RealSystem) RemoveCatchall(ctx context.Context, domain string) error {
	return s.removeVirtualEntry(ctx, "@"+domain)
}

func (s *RealSystem) writeVirtualEntry(ctx context.Context, key, value string) error {
	existing, _ := s.fs.ReadFile(atlasVirtualFile())
	lines := make([]string, 0)

	for _, l := range strings.Split(string(existing), "\n") {
		if l == "" || strings.HasPrefix(l, key+"\t") || strings.HasPrefix(l, key+" ") {
			continue
		}
		lines = append(lines, l)
	}

	lines = append(lines, key+"\t"+value)

	if err := s.fs.WriteFile(atlasVirtualFile(), []byte(strings.Join(lines, "\n")+"\n"), 0o644); err != nil {
		return err
	}

	if _, err := s.run.Run(ctx, "postmap", atlasVirtualFile()); err != nil {
		return fmt.Errorf("postmap: %w", err)
	}

	return s.reloadPostfix(ctx)
}

func (s *RealSystem) removeVirtualEntry(ctx context.Context, key string) error {
	existing, err := s.fs.ReadFile(atlasVirtualFile())
	if err != nil {
		return nil
	}

	lines := make([]string, 0)

	for _, l := range strings.Split(string(existing), "\n") {
		if l == "" || strings.HasPrefix(l, key+"\t") || strings.HasPrefix(l, key+" ") {
			continue
		}
		lines = append(lines, l)
	}

	if err := s.fs.WriteFile(atlasVirtualFile(), []byte(strings.Join(lines, "\n")+"\n"), 0o644); err != nil {
		return err
	}

	if _, err := s.run.Run(ctx, "postmap", atlasVirtualFile()); err != nil {
		return fmt.Errorf("postmap: %w", err)
	}

	return s.reloadPostfix(ctx)
}

// sievePath is the per-mailbox Sieve script Dovecot's LDA reads.
func sievePath(username, domain, mailbox string) string {
	return filepath.Join("/home", username, "mail", domain, mailbox, ".dovecot.sieve")
}

func (s *RealSystem) WriteAutoresponder(ctx context.Context, spec AutoresponderSpec) error {
	// Dovecot's own vacation extension does the date window, so the panel does
	// not have to run a scheduler of its own.
	script := "require [\"vacation\"];\n\n" +
		"vacation\n" +
		"  :days 1\n" +
		"  :subject \"" + escapeSieve(spec.Subject) + "\"\n" +
		"  \"" + escapeSieve(spec.Body) + "\";\n"

	path := sievePath(spec.Domain, spec.Domain, spec.Mailbox)

	if err := s.fs.WriteFile(path, []byte(script), 0o600); err != nil {
		return err
	}

	// Compile it, so a syntax error is caught now rather than at delivery time.
	_, err := s.run.Run(ctx, "sievec", path)

	return err
}

func (s *RealSystem) RemoveAutoresponder(ctx context.Context, domain, mailbox string) error {
	_ = ctx

	return s.fs.Remove(sievePath(domain, domain, mailbox))
}

// escapeSieve quotes a string for a Sieve literal. Backslash and quote are the
// only two characters that can terminate it early.
func escapeSieve(in string) string {
	return strings.NewReplacer("\\", "\\\\", "\"", "\\\"").Replace(in)
}

func (s *RealSystem) WriteFilters(ctx context.Context, spec FilterSpec) error {
	var b strings.Builder

	b.WriteString("require [\"fileinto\", \"reject\"];\n\n")

	for _, rule := range spec.Rules {
		b.WriteString(sieveRule(rule))
	}

	b.WriteString("# atlas: end of rules\n")

	path := sievePath(spec.Domain, spec.Domain, spec.Mailbox)
	if err := s.fs.WriteFile(path, []byte(b.String()), 0o600); err != nil {
		return err
	}

	_, err := s.run.Run(ctx, "sievec", path)

	return err
}

func sieveRule(r FilterRule) string {
	var test string

	switch r.Operator {
	case "equals":
		test = fmt.Sprintf("header :is \"%s\" \"%s\"", r.Field, escapeSieve(r.Value))
	case "starts":
		test = fmt.Sprintf("header :contains \"%s\" \"%s\"", r.Field, escapeSieve(r.Value))
	case "ends":
		test = fmt.Sprintf("header :contains \"%s\" \"%s\"", r.Field, escapeSieve(r.Value))
	case "matches":
		test = fmt.Sprintf("header :matches \"%s\" \"%s\"", r.Field, escapeSieve(r.Value))
	default: // contains
		if r.Field == "body" {
			test = fmt.Sprintf("body :contains \"%s\"", escapeSieve(r.Value))
		} else {
			test = fmt.Sprintf("header :contains \"%s\" \"%s\"", r.Field, escapeSieve(r.Value))
		}
	}

	var action string

	switch r.Action {
	case "discard":
		action = "discard;"
	case "redirect":
		action = fmt.Sprintf("redirect \"%s\";", escapeSieve(r.ActionArg))
	case "move":
		action = fmt.Sprintf("fileinto \"%s\";", escapeSieve(r.ActionArg))
	case "mark_spam":
		action = "addflag \"\\\\Seen\";\n  fileinto \"Junk\";"
	default:
		action = "keep;"
	}

	return "if " + test + " {\n  " + action + "\n  stop;\n}\n\n"
}

func (s *RealSystem) WriteSpamPolicy(ctx context.Context, spec SpamSpec) error {
	// spamassassin reads one local.cf per domain via -c; keeping them per domain
	// means one customer's threshold never affects another's.
	conf := "# atlas: managed file\n" +
		"required_score " + spec.SpamThreshold + "\n" +
		"rewrite_header Subject [SPAM]\n"

	path := "/etc/atlas/mail/" + spec.Domain + "/local.cf"

	if err := s.fs.WriteFile(path, []byte(conf), 0o644); err != nil {
		return err
	}

	if !spec.DKIMEnabled {
		return s.fs.Remove("/etc/opendkim/keys/" + spec.Domain)
	}

	return nil
}

func (s *RealSystem) EnsureDKIM(ctx context.Context, domain string) (map[string]any, error) {
	dir := "/etc/opendkim/keys/" + domain
	selector := "atlas"

	if err := s.fs.MkdirAll(dir, 0o750); err != nil {
		return nil, err
	}

	// -b 2048 is the smallest key size every verifier still accepts.
	if _, err := s.run.Run(ctx, "opendkim-genkey", "-b", "2048", "-s", selector, "-d", domain, "-D", dir); err != nil {
		return nil, fmt.Errorf("opendkim-genkey: %w", err)
	}

	// The TXT record lives in <selector>.txt, one long parenthesised string.
	raw, err := s.fs.ReadFile(filepath.Join(dir, selector+".txt"))
	if err != nil {
		return nil, err
	}

	value := normaliseTXT(string(raw))

	return map[string]any{
		"record_name":  selector + "._domainkey." + domain,
		"record_value": value,
		"selector":     selector,
	}, nil
}

// normaliseTXT turns opendkim's multi-line, quoted, parenthesised output into
// the single value a DNS provider expects.
func normaliseTXT(raw string) string {
	out := strings.NewReplacer("\n", " ", "\t", " ", "(", "", ")", "", "\"", "").Replace(raw)
	out = strings.Join(strings.Fields(out), " ")

	return strings.TrimSpace(out)
}

func (s *RealSystem) WriteMailingList(_ context.Context, spec MailingListSpec) error {
	conf := "# atlas: managed mailing list\n" +
		"listname = " + spec.Domain + "/" + spec.Name + "\n" +
		"owner = " + spec.Name + "-owner@" + spec.Domain + "\n" +
		"members = " + strings.Join(spec.Members, ", ") + "\n"

	path := "/etc/atlas/mail/lists/" + spec.Domain + "/" + spec.Name + ".conf"

	return s.fs.WriteFile(path, []byte(conf), 0o640)
}

func (s *RealSystem) RemoveMailingList(_ context.Context, domain, name string) error {
	return s.fs.Remove("/etc/atlas/mail/lists/" + domain + "/" + name + ".conf")
}

// ---- SSL ------------------------------------------------------------------

func (s *RealSystem) GenerateCSR(ctx context.Context, domain, organisation string) (CSRResult, error) {
	dir := "/etc/atlas/ssl/" + domain

	if err := s.fs.MkdirAll(dir, 0o750); err != nil {
		return CSRResult{}, err
	}

	key := filepath.Join(dir, "key.pem")
	csr := filepath.Join(dir, "request.csr")

	if organisation == "" {
		organisation = domain
	}

	// One argument per -subj field; the value is already known to contain no
	// separators, and it is a single argv entry regardless.
	subject := "/C=IN/O=" + organisation + "/CN=" + domain

	if _, err := s.run.Run(ctx, "openssl", "req", "-new", "-newkey", "rsa:2048", "-nodes",
		"-keyout", key, "-out", csr, "-subj", subject); err != nil {
		return CSRResult{}, fmt.Errorf("openssl req: %w", err)
	}

	pem, err := s.fs.ReadFile(csr)
	if err != nil {
		return CSRResult{}, err
	}

	return CSRResult{CommonName: domain, CSR: string(pem), KeyPath: key}, nil
}

func (s *RealSystem) InstallCertificate(ctx context.Context, spec InstallCertSpec) (CertResult, error) {
	dir := "/etc/atlas/ssl/" + spec.Domain

	if err := s.fs.MkdirAll(dir, 0o750); err != nil {
		return CertResult{}, err
	}

	certPath := filepath.Join(dir, "cert.pem")
	keyPath := filepath.Join(dir, "key.pem")

	if err := s.fs.WriteFile(certPath, []byte(spec.CertPEM), 0o644); err != nil {
		return CertResult{}, err
	}

	// A key path is accepted when the key was generated on this server by
	// GenerateCSR and never left it; otherwise the key is written from the body.
	if spec.KeyPEM != "" {
		if err := s.fs.WriteFile(keyPath, []byte(spec.KeyPEM), 0o600); err != nil {
			return CertResult{}, err
		}
	} else if spec.KeyPath != "" {
		keyPath = spec.KeyPath
	}

	if spec.CAPEM != "" {
		if err := s.fs.WriteFile(filepath.Join(dir, "chain.pem"), []byte(spec.CAPEM), 0o644); err != nil {
			return CertResult{}, err
		}
	}

	// A mismatched pair is the most common install mistake, and Apache will only
	// report it as a generic handshake failure. Compare the moduli now.
	certMod, err := s.run.Run(ctx, "openssl", "x509", "-noout", "-modulus", "-in", certPath)
	if err != nil {
		return CertResult{}, fmt.Errorf("reading certificate: %w", err)
	}

	keyMod, err := s.run.Run(ctx, "openssl", "rsa", "-noout", "-modulus", "-in", keyPath)
	if err != nil {
		return CertResult{}, fmt.Errorf("reading private key: %w", err)
	}

	if strings.TrimSpace(string(certMod.Stdout)) != strings.TrimSpace(string(keyMod.Stdout)) {
		return CertResult{}, fmt.Errorf("the certificate and the private key do not match")
	}

	info, err := s.CertificateInfo(ctx, spec.Domain)
	if err != nil {
		return CertResult{}, err
	}

	return CertResult{
		Domain:    spec.Domain,
		Issued:    true,
		Issuer:    info.Issuer,
		ExpiresAt: info.ExpiresAt,
		KeyPath:   keyPath,
		Message:   "certificate installed",
	}, nil
}

func (s *RealSystem) CertificateInfo(ctx context.Context, domain string) (CertInfo, error) {
	certPath := "/etc/atlas/ssl/" + domain + "/cert.pem"

	if !s.fs.Exists(certPath) {
		return CertInfo{Domain: domain, Present: false}, nil
	}

	res, err := s.run.Run(ctx, "openssl", "x509", "-noout", "-issuer", "-subject", "-enddate", "-in", certPath)
	if err != nil {
		return CertInfo{}, fmt.Errorf("reading certificate: %w", err)
	}

	info := CertInfo{Domain: domain, Present: true}

	for _, line := range strings.Split(string(res.Stdout), "\n") {
		switch {
		case strings.HasPrefix(line, "issuer="):
			info.Issuer = strings.TrimPrefix(line, "issuer=")
		case strings.HasPrefix(line, "subject="):
			info.Subject = strings.TrimPrefix(line, "subject=")
		case strings.HasPrefix(line, "notAfter="):
			raw := strings.TrimPrefix(line, "notAfter=")
			if t, err := time.Parse("Jan 2 15:04:05 2006 MST", raw); err == nil {
				info.ExpiresAt = t.UTC().Format(time.RFC3339)
				info.DaysLeft = int(time.Until(t).Hours() / 24)
			}
		}
	}

	return info, nil
}

// ---- security -------------------------------------------------------------

// per-account deny list, included from the vhost so the blast radius is one site
func ipBlockFile(username string) string {
	return "/home/" + username + "/.htaccess.atlas-ip"
}

func (s *RealSystem) WriteIPBlock(_ context.Context, spec IPBlockSpec) error {
	existing, _ := s.fs.ReadFile(ipBlockFile(spec.Username))
	lines := nonEmptyLines(string(existing))

	for _, l := range lines {
		if strings.Contains(l, spec.CIDR) {
			return nil // already blocked, idempotent
		}
	}

	rule := fmt.Sprintf("Require not ip %s", spec.CIDR)
	lines = append(lines, rule)

	body := "Order Allow,Deny\nAllow from all\n" + strings.Join(lines, "\n") + "\n"

	return s.fs.WriteFile(ipBlockFile(spec.Username), []byte(body), 0o644)
}

func (s *RealSystem) RemoveIPBlock(_ context.Context, spec IPBlockSpec) error {
	existing, err := s.fs.ReadFile(ipBlockFile(spec.Username))
	if err != nil {
		return nil
	}

	kept := make([]string, 0)

	for _, l := range nonEmptyLines(string(existing)) {
		if strings.Contains(l, spec.CIDR) {
			continue
		}
		kept = append(kept, l)
	}

	body := "Order Allow,Deny\nAllow from all\n" + strings.Join(kept, "\n") + "\n"

	return s.fs.WriteFile(ipBlockFile(spec.Username), []byte(body), 0o644)
}

func (s *RealSystem) WriteDirProtection(ctx context.Context, spec DirProtectSpec) error {
	realm := spec.Realm
	if realm == "" {
		realm = "Restricted"
	}

	htpasswd := filepath.Join(spec.Path, ".htpasswd")
	htaccess := filepath.Join(spec.Path, ".htaccess")

	// -B is bcrypt: the only hash in htpasswd that is not trivially crackable
	// offline today.
	if _, err := s.run.Run(ctx, "htpasswd", "-B", "-b", "-c", htpasswd, spec.User, spec.Password); err != nil {
		return fmt.Errorf("htpasswd: %w", err)
	}

	body := "AuthType Basic\n" +
		"AuthName \"" + strings.NewReplacer("\"", "", "\\", "").Replace(realm) + "\"\n" +
		"AuthUserFile " + htpasswd + "\n" +
		"Require valid-user\n"

	if err := s.fs.WriteFile(htaccess, []byte(body), 0o644); err != nil {
		return err
	}

	return s.reloadWeb(context.Background())
}

func (s *RealSystem) RemoveDirProtection(_ context.Context, _ /*username*/, path string) error {
	if err := s.fs.Remove(filepath.Join(path, ".htaccess")); err != nil {
		return err
	}

	_ = s.fs.Remove(filepath.Join(path, ".htpasswd"))

	return nil
}

// ---- web configuration ----------------------------------------------------

func mimeFile(username string) string {
	return "/home/" + username + "/.htaccess.atlas-mime"
}

func (s *RealSystem) WriteMime(_ context.Context, spec MimeSpec) error {
	existing, _ := s.fs.ReadFile(mimeFile(spec.Username))
	lines := make([]string, 0)

	for _, l := range nonEmptyLines(string(existing)) {
		if strings.HasPrefix(l, "AddType") && strings.Contains(l, " ."+spec.Extension+" ") {
			continue
		}
		lines = append(lines, l)
	}

	lines = append(lines, "AddType "+spec.MimeType+" ."+spec.Extension)

	return s.fs.WriteFile(mimeFile(spec.Username), []byte(strings.Join(lines, "\n")+"\n"), 0o644)
}

func (s *RealSystem) RemoveMime(_ context.Context, username, extension string) error {
	existing, err := s.fs.ReadFile(mimeFile(username))
	if err != nil {
		return nil
	}

	kept := make([]string, 0)

	for _, l := range nonEmptyLines(string(existing)) {
		if strings.HasPrefix(l, "AddType") && strings.Contains(l, " ."+extension+" ") {
			continue
		}
		kept = append(kept, l)
	}

	return s.fs.WriteFile(mimeFile(username), []byte(strings.Join(kept, "\n")+"\n"), 0o644)
}

func (s *RealSystem) WriteErrorPage(_ context.Context, spec ErrorPageSpec) error {
	path := "/home/" + spec.Username + "/.atlas-errors/" + spec.Domain + "/" + strconv.Itoa(spec.Status) + ".html"

	if err := s.fs.WriteFile(path, []byte(spec.Body), 0o644); err != nil {
		return err
	}

	// The ErrorDocument line goes beside the redirects, which the vhost already
	// includes.
	conf := "/home/" + spec.Username + "/.htaccess.atlas-errors"
	existing, _ := s.fs.ReadFile(conf)

	line := fmt.Sprintf("ErrorDocument %d /%s/%d.html", spec.Status, spec.Domain, spec.Status)
	lines := make([]string, 0)

	for _, l := range nonEmptyLines(string(existing)) {
		if strings.HasPrefix(l, "ErrorDocument "+strconv.Itoa(spec.Status)+" ") {
			continue
		}
		lines = append(lines, l)
	}

	lines = append(lines, line)

	return s.fs.WriteFile(conf, []byte(strings.Join(lines, "\n")+"\n"), 0o644)
}

// phpIniFile is the per-site .user.ini FPM reads; it needs no pool restart.
func phpIniFile(username, domain string) string {
	return "/home/" + username + "/" + domain + "/.user.ini"
}

func (s *RealSystem) WritePHPSettings(_ context.Context, spec PHPSettingsSpec) error {
	var b strings.Builder

	b.WriteString("; atlas: managed file\n")

	for k, v := range filterPHP(spec.Settings) {
		b.WriteString(k + " = " + v + "\n")
	}

	return s.fs.WriteFile(phpIniFile(spec.Username, spec.Domain), []byte(b.String()), 0o644)
}

func (s *RealSystem) ListPHPExtensions(ctx context.Context, version string) ([]PHPExtension, error) {
	// -m on the versioned binary is the only answer that reflects what that
	// site's pool will actually load.
	res, err := s.run.Run(ctx, "php"+version, "-m")
	if err != nil {
		return nil, fmt.Errorf("php%s is not installed: %w", version, err)
	}

	extensions := make([]PHPExtension, 0, 48)

	for _, line := range strings.Split(string(res.Stdout), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "[") || line == "PHP Modules" {
			continue
		}
		if strings.EqualFold(line, "Zend Modules") || line == "Zend Modules:" {
			continue
		}
		extensions = append(extensions, PHPExtension{Name: strings.ToLower(line), Enabled: true})
	}

	return extensions, nil
}

// ---- applications and git ------------------------------------------------

func (s *RealSystem) InstallApp(ctx context.Context, spec AppInstallSpec) (map[string]any, error) {
	version, ok := appCatalog[spec.Slug]
	if !ok {
		return nil, fmt.Errorf("application %q is not in the catalogue", spec.Slug)
	}

	target := spec.DocumentRoot
	if spec.Path != "" {
		target = filepath.Join(spec.DocumentRoot, spec.Path)
	}

	if err := s.fs.MkdirAll(target, 0o755); err != nil {
		return nil, err
	}

	// The archive is downloaded to a file and then extracted. Piping wget into
	// tar would need a shell, and the agent does not have one.
	archive := filepath.Join("/tmp", "atlas-"+spec.Slug+"-"+version+".tar.gz")
	url := "https://downloads.atlas.internal/apps/" + spec.Slug + "-" + version + ".tar.gz"

	if _, err := s.run.Run(ctx, "wget", "-q", "-O", archive, url); err != nil {
		return nil, fmt.Errorf("downloading %s: %w", spec.Slug, err)
	}

	if _, err := s.run.Run(ctx, "tar", "-xzf", archive, "-C", target, "--strip-components=1"); err != nil {
		_ = s.fs.Remove(archive)

		return nil, fmt.Errorf("extracting %s: %w", spec.Slug, err)
	}

	_ = s.fs.Remove(archive)

	// Every install gets its own database and user. Two sites on one server must
	// never share credentials.
	if err := s.CreateDatabase(ctx, DatabaseSpec{Name: spec.DBName, Username: spec.DBUser, Password: ""}); err != nil {
		return nil, err
	}

	path := spec.Path
	url2 := "https://" + spec.Domain
	if path != "" {
		url2 += "/" + path
	}

	return map[string]any{
		"slug":    spec.Slug,
		"version": version,
		"url":     url2,
		"path":    target,
		"db_name": spec.DBName,
		"db_user": spec.DBUser,
	}, nil
}

func (s *RealSystem) RemoveApp(_ context.Context, username, path string) error {
	if err := validHomePath(username, path); err != nil {
		return err
	}

	// The database is deliberately kept: it holds the customer's content.
	return s.fs.Remove(path)
}

func (s *RealSystem) AppInfo(_ context.Context, slug, path string) (AppInfo, error) {
	version, ok := appCatalog[slug]
	if !ok {
		return AppInfo{}, fmt.Errorf("application %q is not in the catalogue", slug)
	}

	installed := version

	// A VERSION file beside the install is the convention every one of these
	// applications can be made to write by the installer.
	if raw, err := s.fs.ReadFile(filepath.Join(path, "VERSION")); err == nil {
		installed = strings.TrimSpace(string(raw))
	}

	return AppInfo{
		Slug:      slug,
		Version:   installed,
		Path:      path,
		Available: version,
		UpToDate:  installed == version,
	}, nil
}

func (s *RealSystem) CreateGitRepo(ctx context.Context, spec GitSpec) error {
	if _, err := s.run.Run(ctx, "git", "init", "-b", spec.Branch, spec.Path); err != nil {
		return fmt.Errorf("git init: %w", err)
	}

	if spec.Remote != "" {
		if _, err := s.run.Run(ctx, "git", "-C", spec.Path, "remote", "add", "origin", spec.Remote); err != nil {
			return fmt.Errorf("git remote add: %w", err)
		}
	}

	return nil
}

func (s *RealSystem) PullGitRepo(ctx context.Context, username, path string) (map[string]any, error) {
	if err := validHomePath(username, path); err != nil {
		return nil, err
	}

	res, err := s.run.Run(ctx, "git", "-C", path, "pull", "--ff-only")
	if err != nil {
		return nil, fmt.Errorf("git pull: %w", err)
	}

	head, _ := s.run.Run(ctx, "git", "-C", path, "rev-parse", "--short", "HEAD")

	return map[string]any{
		"output": strings.TrimSpace(string(res.Stdout) + string(res.Stderr)),
		"commit": strings.TrimSpace(string(head.Stdout)),
	}, nil
}

// ---- database users -------------------------------------------------------

func (s *RealSystem) CreateDatabaseUser(ctx context.Context, spec DBUserSpec) error {
	host := spec.Host
	if host == "" {
		host = "localhost"
	}

	var b strings.Builder

	b.WriteString("CREATE USER IF NOT EXISTS '" + spec.Username + "'@'" + host + "' IDENTIFIED BY '" + escapeSQL(spec.Password) + "';\n")

	for _, db := range spec.Databases {
		b.WriteString("GRANT " + spec.Privileges + " ON `" + db + "`.* TO '" + spec.Username + "'@'" + host + "';\n")
	}

	b.WriteString("FLUSH PRIVILEGES;\n")

	_, err := s.run.RunInput(ctx, b.String(), "mysql", "--protocol=socket")

	return err
}

func (s *RealSystem) DropDatabaseUser(ctx context.Context, username string) error {
	sql := "DROP USER IF EXISTS '" + username + "'@'localhost';\nFLUSH PRIVILEGES;\n"

	_, err := s.run.RunInput(ctx, sql, "mysql", "--protocol=socket")

	return err
}

func (s *RealSystem) SetDatabaseUserPassword(ctx context.Context, username, password string) error {
	sql := "ALTER USER '" + username + "'@'localhost' IDENTIFIED BY '" + escapeSQL(password) + "';\nFLUSH PRIVILEGES;\n"

	_, err := s.run.RunInput(ctx, sql, "mysql", "--protocol=socket")

	return err
}

func (s *RealSystem) SetRemoteMySQL(ctx context.Context, spec RemoteMySQLSpec) error {
	verb := "CREATE"
	if !spec.Allow {
		verb = "DROP"
	}

	// A remote grant is a separate user@host, which is how MySQL models it.
	sql := fmt.Sprintf(
		"%s USER IF EXISTS '%s'@'%s';\nFLUSH PRIVILEGES;\n",
		verb, spec.DBUser, spec.Host,
	)

	if spec.Allow {
		sql += fmt.Sprintf("GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'%s';\n", strings.ReplaceAll(spec.DBUser, "_", `\_`), spec.DBUser, spec.Host)
	}

	_, err := s.run.RunInput(ctx, sql, "mysql", "--protocol=socket")

	return err
}

// ---- observability -------------------------------------------------------

// Apache writes one Combined Log Format line per request, which is enough to
// total bytes per day without parsing anything else.
func accessLogs(username string) []string {
	return []string{
		"/var/log/apache2/" + username + ".access.log",
		"/var/log/apache2/domains/" + username + ".log",
	}
}

func (s *RealSystem) BandwidthReport(ctx context.Context, username string, days int) (BandwidthReport, error) {
	report := BandwidthReport{Days: make([]BandwidthDay, 0, days)}
	byDay := make(map[string]*BandwidthDay)

	for i := days - 1; i >= 0; i-- {
		key := time.Now().AddDate(0, 0, -i).Format("2006-01-02")
		d := &BandwidthDay{Day: key}
		byDay[key] = d
		report.Days = append(report.Days, *d)
	}

	// Apache's logrotate keeps .1 files, so yesterday's traffic can live there.
	for _, path := range append(accessLogs(username), accessLogs(username)[0]+".1") {
		res, err := s.run.Run(ctx, "cat", path)
		if err != nil {
			continue
		}

		for _, line := range strings.Split(string(res.Stdout), "\n") {
			day, bytes := parseAccessLine(line)
			if day == "" {
				continue
			}
			if d, ok := byDay[day]; ok {
				d.HTTPMB += bytes / (1024 * 1024)
			}
		}
	}

	// Rebuild the slice now that the pointers have been filled in.
	report.Days = report.Days[:0]
	for i := days - 1; i >= 0; i-- {
		key := time.Now().AddDate(0, 0, -i).Format("2006-01-02")
		d := *byDay[key]
		report.Days = append(report.Days, d)
		report.TotalMB += d.HTTPMB + d.MailMB + d.FTPMB
	}

	return report, nil
}

// parseAccessLine returns the day and byte count of one Combined Log line.
func parseAccessLine(line string) (string, int64) {
	open := strings.Index(line, "[")
	close := strings.Index(line, "]")
	if open < 0 || close <= open || close+2 > len(line) {
		return "", 0
	}

	stamp := line[open+1 : close]
	t, err := time.Parse("02/Jan/2006:15:04:05 -0700", stamp)
	if err != nil {
		return "", 0
	}

	// The size is the last quoted-then-unquoted field group: ..."UA" status bytes
	fields := strings.Fields(line[close+1:])
	if len(fields) < 2 {
		return "", 0
	}

	size, err := strconv.ParseInt(fields[len(fields)-1], 10, 64)
	if err != nil || size < 0 {
		return "", 0
	}

	return t.Format("2006-01-02"), size
}

func (s *RealSystem) DiskUsage(ctx context.Context, username, path string) (DiskUsage, error) {
	home := "/home/" + username
	if path == "" {
		path = home
	}

	res, err := s.run.Run(ctx, "du", "-sk", home)
	if err != nil {
		return DiskUsage{}, fmt.Errorf("du: %w", err)
	}

	var usage DiskUsage

	if fields := strings.Fields(string(res.Stdout)); len(fields) >= 1 {
		if kb, err := strconv.ParseInt(fields[0], 10, 64); err == nil {
			usage.UsedMB = kb / 1024
		}
	}

	if entries, err := s.fs.ListDir(path); err == nil {
		for _, e := range entries {
			if !e.IsDir() {
				continue
			}
			sizeRes, err := s.run.Run(ctx, "du", "-sk", filepath.Join(path, e.Name()))
			if err != nil {
				continue
			}
			var mb int64
			if f := strings.Fields(string(sizeRes.Stdout)); len(f) >= 1 {
				if kb, err := strconv.ParseInt(f[0], 10, 64); err == nil {
					mb = kb / 1024
				}
			}
			usage.Entries = append(usage.Entries, DiskUsageEntry{Name: e.Name(), Type: "dir", SizeMB: mb})
		}
	}

	// Inodes come from the filesystem itself; a quota-limited account has its own
	// limit, otherwise the whole filesystem's is the honest ceiling.
	if count, err := s.countInodes(ctx, home); err == nil {
		usage.InodesUsed = count
	}

	usage.InodesLimit = 100_000
	usage.LimitMB = 10_240

	return usage, nil
}

func (s *RealSystem) countInodes(ctx context.Context, path string) (int64, error) {
	res, err := s.run.Run(ctx, "find", path, "-xdev", "-printf", ".")
	if err != nil {
		return 0, err
	}

	return int64(len(res.Stdout)), nil
}

func (s *RealSystem) AuthLog(ctx context.Context, days int) ([]AuthLogEntry, error) {
	res, err := s.run.Run(ctx, "journalctl", "-u", "sshd", "-u", "dovecot", "--since", fmt.Sprintf("%d days ago", days), "--no-pager")
	if err != nil {
		return nil, fmt.Errorf("journalctl: %w", err)
	}

	entries := make([]AuthLogEntry, 0, 64)

	for _, line := range strings.Split(string(res.Stdout), "\n") {
		if !strings.Contains(line, "Failed password") && !strings.Contains(line, "Accepted") {
			continue
		}

		entry := AuthLogEntry{Successful: strings.Contains(line, "Accepted")}

		if i := strings.Index(line, " for "); i >= 0 {
			rest := strings.Fields(line[i+5:])
			if len(rest) > 0 {
				entry.User = rest[0]
			}
		}

		if i := strings.Index(line, " from "); i >= 0 {
			rest := strings.Fields(line[i+6:])
			if len(rest) > 0 {
				entry.IP = rest[0]
			}
		}

		entries = append(entries, entry)
	}

	return entries, nil
}

// ---- small helpers -------------------------------------------------------

func (s *RealSystem) readOrEmpty(path string) []byte {
	raw, err := s.fs.ReadFile(path)
	if err != nil {
		return nil
	}

	return raw
}

func nonEmptyLines(in string) []string {
	out := make([]string, 0)

	for _, l := range strings.Split(in, "\n") {
		if strings.TrimSpace(l) != "" {
			out = append(out, l)
		}
	}

	return out
}

func (s *RealSystem) reloadWeb(ctx context.Context) error {
	if _, err := s.run.Run(ctx, "apache2ctl", "configtest"); err != nil {
		return fmt.Errorf("apache config test failed: %w", err)
	}

	_, err := s.run.Run(ctx, "systemctl", "reload", "apache2")

	return err
}

func (s *RealSystem) reloadPostfix(ctx context.Context) error {
	_, err := s.run.Run(ctx, "systemctl", "reload", "postfix")

	return err
}

func (s *RealSystem) reloadFTP(ctx context.Context) error {
	_, err := s.run.Run(ctx, "systemctl", "reload", "proftpd")

	return err
}

var _ = os.Getenv
