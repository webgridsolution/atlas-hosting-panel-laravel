package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"strconv"
	"strings"
)

// registerHosting adds the hosting feature set to the allowlist.
//
// Split from registry.go so the original twenty-four operations stay readable:
// this file is "everything a customer panel can do", the other is "everything
// the server console can do".
func (r *Registry) registerHosting() {
	// Domains
	r.register("domain.subdomain.create", r.createSubdomain)
	r.register("domain.alias.create", r.attachAliases)
	r.register("domain.remove", r.removeDomain)
	r.register("vhost.php.set", r.setVhostPhp)

	// Redirects
	r.register("htaccess.redirect.write", r.writeRedirect)
	r.register("htaccess.redirect.remove", r.removeRedirect)

	// FTP
	r.register("ftp.account.create", r.createFTPAccount)
	r.register("ftp.account.drop", r.deleteFTPAccount)
	r.register("ftp.account.password", r.setFTPPassword)

	// Mail
	r.register("mail.forwarder.write", r.writeForwarder)
	r.register("mail.forwarder.remove", r.removeForwarder)
	r.register("mail.autoresponder.write", r.writeAutoresponder)
	r.register("mail.autoresponder.remove", r.removeAutoresponder)
	r.register("mail.catchall.write", r.writeCatchall)
	r.register("mail.catchall.remove", r.removeCatchall)
	r.register("mail.filter.write", r.writeFilters)
	r.register("mail.spam.write", r.writeSpamPolicy)
	r.register("mail.dkim.ensure", r.ensureDKIM)
	r.register("mail.list.write", r.writeMailingList)
	r.register("mail.list.remove", r.removeMailingList)

	// SSL
	r.register("ssl.csr", r.generateCSR)
	r.register("ssl.install", r.installCertificate)
	r.register("ssl.info", r.certificateInfo)

	// Security
	r.register("ip.block.write", r.writeIPBlock)
	r.register("ip.block.remove", r.removeIPBlock)
	r.register("dir.protect.write", r.writeDirProtection)
	r.register("dir.protect.remove", r.removeDirProtection)

	// Web configuration
	r.register("mime.write", r.writeMime)
	r.register("mime.remove", r.removeMime)
	r.register("errorpage.write", r.writeErrorPage)
	r.register("php.settings.write", r.writePHPSettings)
	r.register("php.extensions", r.listPHPExtensions)

	// Applications and git
	r.register("app.install", r.installApp)
	r.register("app.remove", r.removeApp)
	r.register("app.info", r.appInfo)
	r.register("git.init", r.createGitRepo)
	r.register("git.pull", r.pullGitRepo)

	// Databases (users and remote access)
	r.register("db.user.create", r.createDatabaseUser)
	r.register("db.user.drop", r.dropDatabaseUser)
	r.register("db.user.password", r.setDatabaseUserPassword)
	r.register("db.remote.set", r.setRemoteMySQL)

	// Observability
	r.register("bandwidth.report", r.bandwidthReport)
	r.register("disk.usage", r.diskUsage)
	r.register("log.auth", r.authLog)
}

// ---- domains --------------------------------------------------------------

func (r *Registry) createSubdomain(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[SubdomainSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validDomain(spec.Domain); err != nil {
		return nil, err
	}
	if err := validSubdomainLabel(spec.Label); err != nil {
		return nil, err
	}
	if err := documentRootMustStayInsideHome(spec.DocumentRoot, spec.Username); err != nil {
		return nil, err
	}
	if spec.PHPVersion != "" {
		if err := validPHP(spec.PHPVersion); err != nil {
			return nil, err
		}
	}

	if err := r.system.CreateSubdomain(context.Background(), spec); err != nil {
		return nil, err
	}

	fqdn := spec.Label + "." + spec.Domain

	return map[string]any{"domain": fqdn, "document_root": spec.DocumentRoot}, nil
}

func (r *Registry) attachAliases(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[DomainSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validDomain(spec.Domain); err != nil {
		return nil, err
	}
	if len(spec.Aliases) == 0 || len(spec.Aliases) > 20 {
		return nil, fmt.Errorf("aliases: need between 1 and 20 names")
	}
	for _, a := range spec.Aliases {
		if err := validDomain(a); err != nil {
			return nil, err
		}
	}

	if err := r.system.AttachAliases(context.Background(), spec.Username, spec.Domain, spec.Aliases); err != nil {
		return nil, err
	}

	return map[string]any{"domain": spec.Domain, "aliases": spec.Aliases}, nil
}

func (r *Registry) removeDomain(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[DomainSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validDomain(spec.Domain); err != nil {
		return nil, err
	}

	// Removing a name never deletes files: the document root stays on disk so a
	// mistake here cannot cost a customer their site. Deleting the account is a
	// separate, explicit operation that says so in its name.
	if err := r.system.RemoveDomain(context.Background(), spec.Username, spec.Domain); err != nil {
		return nil, err
	}

	return map[string]any{"domain": spec.Domain, "removed": true}, nil
}

func (r *Registry) setVhostPhp(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[VhostPhpSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validDomain(spec.Domain); err != nil {
		return nil, err
	}
	if err := validPHP(spec.PHPVersion); err != nil {
		return nil, err
	}

	if err := r.system.SetVhostPHP(context.Background(), spec); err != nil {
		return nil, err
	}

	return map[string]any{"domain": spec.Domain, "php_version": spec.PHPVersion, "keep_ssl": spec.KeepSSL}, nil
}

// ---- redirects ------------------------------------------------------------

func (r *Registry) writeRedirect(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[RedirectSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validDomain(spec.Domain); err != nil {
		return nil, err
	}
	if err := validRedirectPath(spec.Source); err != nil {
		return nil, err
	}

	if !spec.Remove {
		if err := validRedirectTarget(spec.Target); err != nil {
			return nil, err
		}
		if err := validHTTPStatus(spec.Status); err != nil {
			return nil, err
		}
		if err := r.system.WriteRedirect(context.Background(), spec); err != nil {
			return nil, err
		}

		return map[string]any{"source": spec.Source, "target": spec.Target, "status": spec.Status}, nil
	}

	if err := r.system.RemoveRedirect(context.Background(), spec.Username, spec.Domain, spec.Source); err != nil {
		return nil, err
	}

	return map[string]any{"source": spec.Source, "removed": true}, nil
}

func (r *Registry) removeRedirect(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[RedirectSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validDomain(spec.Domain); err != nil {
		return nil, err
	}
	if err := validRedirectPath(spec.Source); err != nil {
		return nil, err
	}

	// A removal names only the source. Requiring a target here would make the
	// panel invent one, and the old code then wrote a redirect instead of
	// deleting it.
	if err := r.system.RemoveRedirect(context.Background(), spec.Username, spec.Domain, spec.Source); err != nil {
		return nil, err
	}

	return map[string]any{"source": spec.Source, "removed": true}, nil
}

// ---- FTP ------------------------------------------------------------------

func (r *Registry) createFTPAccount(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[FTPSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validFTPUsername(spec.FTPUsername); err != nil {
		return nil, err
	}
	if err := validHomePath(spec.Username, spec.HomeDir); err != nil {
		return nil, err
	}
	if spec.QuotaMB < 0 || spec.QuotaMB > 10_485_760 {
		return nil, fmt.Errorf("quota_mb must be between 0 and 10485760")
	}

	if err := r.system.CreateFTPAccount(context.Background(), spec); err != nil {
		return nil, err
	}

	return map[string]any{"ftp_username": spec.FTPUsername, "home_dir": spec.HomeDir}, nil
}

func (r *Registry) deleteFTPAccount(_ context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Username    string `json:"username"`
		FTPUsername string `json:"ftp_username"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(in.Username); err != nil {
		return nil, err
	}
	if err := validFTPUsername(in.FTPUsername); err != nil {
		return nil, err
	}

	if err := r.system.DeleteFTPAccount(context.Background(), in.FTPUsername); err != nil {
		return nil, err
	}

	return map[string]any{"ftp_username": in.FTPUsername, "deleted": true}, nil
}

func (r *Registry) setFTPPassword(_ context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Username    string `json:"username"`
		FTPUsername string `json:"ftp_username"`
		Password    string `json:"password"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(in.Username); err != nil {
		return nil, err
	}
	if err := validFTPUsername(in.FTPUsername); err != nil {
		return nil, err
	}
	if len(in.Password) < 12 {
		return nil, fmt.Errorf("password must be at least 12 characters")
	}

	if err := r.system.SetFTPPassword(context.Background(), in.FTPUsername, in.Password); err != nil {
		return nil, err
	}

	return map[string]any{"ftp_username": in.FTPUsername, "updated": true}, nil
}

// ---- mail policy ----------------------------------------------------------

func (r *Registry) writeForwarder(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[ForwarderSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validDomain(spec.Domain); err != nil {
		return nil, err
	}
	if err := validMailSource(spec.Source); err != nil {
		return nil, err
	}
	if len(spec.Destinations) == 0 || len(spec.Destinations) > 20 {
		return nil, fmt.Errorf("destinations: need between 1 and 20 addresses")
	}
	for _, d := range spec.Destinations {
		if !strings.Contains(d, "@") || len(d) > 190 || strings.ContainsAny(d, " \t\n\r") {
			return nil, fmt.Errorf("invalid destination address %q", d)
		}
	}

	if err := r.system.WriteForwarder(context.Background(), spec); err != nil {
		return nil, err
	}

	return map[string]any{"source": spec.Source, "destinations": len(spec.Destinations)}, nil
}

func (r *Registry) removeForwarder(_ context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Domain string `json:"domain"`
		Source string `json:"source"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validDomain(in.Domain); err != nil {
		return nil, err
	}
	if err := validMailSource(in.Source); err != nil {
		return nil, err
	}

	if err := r.system.RemoveForwarder(context.Background(), in.Domain, in.Source); err != nil {
		return nil, err
	}

	return map[string]any{"source": in.Source, "removed": true}, nil
}

func (r *Registry) writeAutoresponder(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[AutoresponderSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validDomain(spec.Domain); err != nil {
		return nil, err
	}
	if err := validMailSource(spec.Mailbox); err != nil {
		return nil, err
	}
	if spec.Subject == "" || len(spec.Subject) > 190 {
		return nil, fmt.Errorf("subject must be 1-190 characters")
	}
	if len(spec.Body) > 65_536 {
		return nil, fmt.Errorf("body is too long")
	}

	if err := r.system.WriteAutoresponder(context.Background(), spec); err != nil {
		return nil, err
	}

	return map[string]any{"mailbox": spec.Mailbox, "active": true}, nil
}

func (r *Registry) removeAutoresponder(_ context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Domain  string `json:"domain"`
		Mailbox string `json:"mailbox"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validDomain(in.Domain); err != nil {
		return nil, err
	}
	if err := validMailSource(in.Mailbox); err != nil {
		return nil, err
	}

	if err := r.system.RemoveAutoresponder(context.Background(), in.Domain, in.Mailbox); err != nil {
		return nil, err
	}

	return map[string]any{"mailbox": in.Mailbox, "removed": true}, nil
}

func (r *Registry) writeCatchall(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[CatchallSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validDomain(spec.Domain); err != nil {
		return nil, err
	}
	// Only Postfix's two safe specials are accepted. The rest of the ":...:"
	// family includes :include:, which makes Postfix read a file as a recipient
	// list — not something a hosting form may ask for.
	switch spec.Destination {
	case ":fail:", ":discard:":
	default:
		if len(spec.Destination) > 190 || !validEmailAddress(spec.Destination) {
			return nil, fmt.Errorf("invalid catch-all destination %q", spec.Destination)
		}
	}

	if err := r.system.WriteCatchall(context.Background(), spec); err != nil {
		return nil, err
	}

	return map[string]any{"domain": spec.Domain, "destination": spec.Destination}, nil
}

func (r *Registry) removeCatchall(_ context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Domain string `json:"domain"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validDomain(in.Domain); err != nil {
		return nil, err
	}

	if err := r.system.RemoveCatchall(context.Background(), in.Domain); err != nil {
		return nil, err
	}

	return map[string]any{"domain": in.Domain, "removed": true}, nil
}

func (r *Registry) writeFilters(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[FilterSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validDomain(spec.Domain); err != nil {
		return nil, err
	}
	if err := validMailSource(spec.Mailbox); err != nil {
		return nil, err
	}
	if err := validFilterRules(spec.Rules); err != nil {
		return nil, err
	}

	if err := r.system.WriteFilters(context.Background(), spec); err != nil {
		return nil, err
	}

	return map[string]any{"mailbox": spec.Mailbox, "rules": len(spec.Rules)}, nil
}

func (r *Registry) writeSpamPolicy(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[SpamSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validDomain(spec.Domain); err != nil {
		return nil, err
	}

	switch spec.SpamAction {
	case "mark", "discard", "move":
	default:
		return nil, fmt.Errorf("unsupported spam action %q", spec.SpamAction)
	}

	// The threshold is written verbatim into SpamAssassin's local.cf, so a
	// non-numeric value would break mail for the whole domain.
	score, err := strconv.ParseFloat(spec.SpamThreshold, 64)
	if err != nil || score < 0.1 || score > 100 {
		return nil, fmt.Errorf("spam_threshold must be a number between 0.1 and 100, got %q", spec.SpamThreshold)
	}

	if spec.MailboxQuotaMB < 1 || spec.MailboxQuotaMB > 1_048_576 {
		return nil, fmt.Errorf("mailbox_quota_mb must be between 1 and 1048576")
	}

	if err := r.system.WriteSpamPolicy(context.Background(), spec); err != nil {
		return nil, err
	}

	return map[string]any{"domain": spec.Domain, "spam_action": spec.SpamAction}, nil
}

func (r *Registry) ensureDKIM(_ context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Domain string `json:"domain"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validDomain(in.Domain); err != nil {
		return nil, err
	}

	record, err := r.system.EnsureDKIM(context.Background(), in.Domain)
	if err != nil {
		return nil, err
	}

	return record, nil
}

func (r *Registry) writeMailingList(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[MailingListSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validDomain(spec.Domain); err != nil {
		return nil, err
	}
	if err := validMailSource(spec.Name); err != nil {
		return nil, err
	}
	if len(spec.Members) > 1000 {
		return nil, fmt.Errorf("too many members (%d)", len(spec.Members))
	}

	if err := r.system.WriteMailingList(context.Background(), spec); err != nil {
		return nil, err
	}

	return map[string]any{"list": spec.Name, "members": len(spec.Members)}, nil
}

func (r *Registry) removeMailingList(_ context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Domain string `json:"domain"`
		Name   string `json:"name"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validDomain(in.Domain); err != nil {
		return nil, err
	}
	if err := validMailSource(in.Name); err != nil {
		return nil, err
	}

	if err := r.system.RemoveMailingList(context.Background(), in.Domain, in.Name); err != nil {
		return nil, err
	}

	return map[string]any{"list": in.Name, "removed": true}, nil
}

// ---- SSL ------------------------------------------------------------------

func (r *Registry) generateCSR(_ context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Domain       string `json:"domain"`
		Country      string `json:"country,omitempty"`
		Organisation string `json:"organisation,omitempty"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validDomain(in.Domain); err != nil {
		return nil, err
	}

	// The organisation string ends up in an openssl -subj argument, so it may
	// not contain the separators that would let it inject another field.
	if strings.ContainsAny(in.Organisation, "/,=\x00\n\r") {
		return nil, fmt.Errorf("organisation may not contain / , = or newlines")
	}

	result, err := r.system.GenerateCSR(context.Background(), in.Domain, in.Organisation)
	if err != nil {
		return nil, err
	}

	return result, nil
}

func (r *Registry) installCertificate(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[InstallCertSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validDomain(spec.Domain); err != nil {
		return nil, err
	}
	if !strings.Contains(spec.CertPEM, "BEGIN CERTIFICATE") {
		return nil, fmt.Errorf("cert_pem is not a PEM certificate")
	}
	if spec.KeyPEM != "" && !strings.Contains(spec.KeyPEM, "PRIVATE KEY") {
		return nil, fmt.Errorf("key_pem is not a PEM private key")
	}
	if len(spec.CertPEM) > 131_072 || len(spec.KeyPEM) > 131_072 || len(spec.CAPEM) > 131_072 {
		return nil, fmt.Errorf("certificate material is too large")
	}

	result, err := r.system.InstallCertificate(context.Background(), spec)
	if err != nil {
		return nil, err
	}

	return result, nil
}

func (r *Registry) certificateInfo(_ context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Domain string `json:"domain"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validDomain(in.Domain); err != nil {
		return nil, err
	}

	info, err := r.system.CertificateInfo(context.Background(), in.Domain)
	if err != nil {
		return nil, err
	}

	// The panel distinguishes "no certificate" from "could not read one", so a
	// missing certificate is a normal answer rather than an error.
	return info, nil
}

// ---- security -------------------------------------------------------------

func (r *Registry) writeIPBlock(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[IPBlockSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validCIDR(spec.CIDR); err != nil {
		return nil, err
	}

	if err := r.system.WriteIPBlock(context.Background(), spec); err != nil {
		return nil, err
	}

	return map[string]any{"cidr": spec.CIDR}, nil
}

func (r *Registry) removeIPBlock(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[IPBlockSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validCIDR(spec.CIDR); err != nil {
		return nil, err
	}

	if err := r.system.RemoveIPBlock(context.Background(), spec); err != nil {
		return nil, err
	}

	return map[string]any{"cidr": spec.CIDR, "removed": true}, nil
}

func (r *Registry) writeDirProtection(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[DirProtectSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validHomePath(spec.Username, spec.Path); err != nil {
		return nil, err
	}
	if len(spec.Password) < 12 {
		return nil, fmt.Errorf("password must be at least 12 characters")
	}
	if spec.User == "" || len(spec.User) > 32 {
		return nil, fmt.Errorf("invalid user %q", spec.User)
	}

	if err := r.system.WriteDirProtection(context.Background(), spec); err != nil {
		return nil, err
	}

	return map[string]any{"path": spec.Path, "protected": true}, nil
}

func (r *Registry) removeDirProtection(_ context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Username string `json:"username"`
		Path     string `json:"path"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(in.Username); err != nil {
		return nil, err
	}
	if err := validHomePath(in.Username, in.Path); err != nil {
		return nil, err
	}

	if err := r.system.RemoveDirProtection(context.Background(), in.Username, in.Path); err != nil {
		return nil, err
	}

	return map[string]any{"path": in.Path, "protected": false}, nil
}

// ---- web configuration ----------------------------------------------------

func (r *Registry) writeMime(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[MimeSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validExtension(spec.Extension); err != nil {
		return nil, err
	}
	if err := validMimeType(spec.MimeType); err != nil {
		return nil, err
	}

	if err := r.system.WriteMime(context.Background(), spec); err != nil {
		return nil, err
	}

	return map[string]any{"extension": spec.Extension, "mime_type": spec.MimeType}, nil
}

func (r *Registry) removeMime(_ context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Username  string `json:"username"`
		Extension string `json:"extension"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(in.Username); err != nil {
		return nil, err
	}
	if err := validExtension(in.Extension); err != nil {
		return nil, err
	}

	if err := r.system.RemoveMime(context.Background(), in.Username, in.Extension); err != nil {
		return nil, err
	}

	return map[string]any{"extension": in.Extension, "removed": true}, nil
}

func (r *Registry) writeErrorPage(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[ErrorPageSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validDomain(spec.Domain); err != nil {
		return nil, err
	}
	if err := validErrorStatus(spec.Status); err != nil {
		return nil, err
	}
	if len(spec.Body) > 262_144 {
		return nil, fmt.Errorf("error page body is too large")
	}

	if err := r.system.WriteErrorPage(context.Background(), spec); err != nil {
		return nil, err
	}

	return map[string]any{"domain": spec.Domain, "status": spec.Status}, nil
}

func (r *Registry) writePHPSettings(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[PHPSettingsSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validDomain(spec.Domain); err != nil {
		return nil, err
	}
	if spec.Version != "" {
		if err := validPHP(spec.Version); err != nil {
			return nil, err
		}
	}

	// The panel may only set what phpDirectiveAllowlist permits. An unknown key
	// is refused rather than dropped: a silent drop would show "saved" for a
	// directive that never reached the file.
	for k := range spec.Settings {
		if !phpDirectiveAllowlist[k] {
			return nil, fmt.Errorf("directive %q cannot be set from the panel", k)
		}
	}

	if len(spec.Settings) == 0 {
		return nil, fmt.Errorf("no directives to write")
	}

	spec.Settings = filterPHP(spec.Settings)

	if err := r.system.WritePHPSettings(context.Background(), spec); err != nil {
		return nil, err
	}

	return map[string]any{"domain": spec.Domain, "directives": len(spec.Settings)}, nil
}

func (r *Registry) listPHPExtensions(_ context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Version string `json:"version"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validPHP(in.Version); err != nil {
		return nil, err
	}

	extensions, err := r.system.ListPHPExtensions(context.Background(), in.Version)
	if err != nil {
		return nil, err
	}

	return map[string]any{"version": in.Version, "extensions": extensions}, nil
}

// ---- applications and git ------------------------------------------------

func (r *Registry) installApp(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[AppInstallSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validAppSlug(spec.Slug); err != nil {
		return nil, err
	}
	if err := validDomain(spec.Domain); err != nil {
		return nil, err
	}
	if err := documentRootMustStayInsideHome(spec.DocumentRoot, spec.Username); err != nil {
		return nil, err
	}
	if spec.Path != "" {
		if strings.Contains(spec.Path, "..") || strings.HasPrefix(spec.Path, "/") {
			return nil, fmt.Errorf("path %q must be relative and may not contain '..'", spec.Path)
		}
	}
	if err := validDBUser(spec.DBUser); err != nil {
		return nil, err
	}
	if !dbNameRE.MatchString(spec.DBName) {
		return nil, fmt.Errorf("invalid database name %q", spec.DBName)
	}
	if spec.AdminUser == "" || len(spec.AdminUser) > 64 {
		return nil, fmt.Errorf("invalid admin user")
	}
	if !strings.Contains(spec.AdminEmail, "@") {
		return nil, fmt.Errorf("invalid admin email")
	}

	result, err := r.system.InstallApp(context.Background(), spec)
	if err != nil {
		return nil, err
	}

	return result, nil
}

func (r *Registry) removeApp(_ context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Username string `json:"username"`
		Path     string `json:"path"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(in.Username); err != nil {
		return nil, err
	}
	if err := validHomePath(in.Username, in.Path); err != nil {
		return nil, err
	}

	if err := r.system.RemoveApp(context.Background(), in.Username, in.Path); err != nil {
		return nil, err
	}

	return map[string]any{"path": in.Path, "removed": true}, nil
}

func (r *Registry) appInfo(_ context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Slug string `json:"slug"`
		Path string `json:"path"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validAppSlug(in.Slug); err != nil {
		return nil, err
	}

	info, err := r.system.AppInfo(context.Background(), in.Slug, in.Path)
	if err != nil {
		return nil, err
	}

	return info, nil
}

func (r *Registry) createGitRepo(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[GitSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validHomePath(spec.Username, spec.Path); err != nil {
		return nil, err
	}
	if err := validRemoteURL(spec.Remote); err != nil {
		return nil, err
	}
	if spec.Branch != "" {
		if err := validBranch(spec.Branch); err != nil {
			return nil, err
		}
	}

	if err := r.system.CreateGitRepo(context.Background(), spec); err != nil {
		return nil, err
	}

	return map[string]any{"path": spec.Path, "branch": spec.Branch}, nil
}

func (r *Registry) pullGitRepo(_ context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Username string `json:"username"`
		Path     string `json:"path"`
		Branch   string `json:"branch"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(in.Username); err != nil {
		return nil, err
	}
	if err := validHomePath(in.Username, in.Path); err != nil {
		return nil, err
	}
	if in.Branch != "" {
		if err := validBranch(in.Branch); err != nil {
			return nil, err
		}
	}

	result, err := r.system.PullGitRepo(context.Background(), in.Username, in.Path)
	if err != nil {
		return nil, err
	}

	return result, nil
}

// ---- database users -------------------------------------------------------

func (r *Registry) createDatabaseUser(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[DBUserSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validDBUser(spec.Username); err != nil {
		return nil, err
	}
	if err := validDBPrivileges(spec.Privileges); err != nil {
		return nil, err
	}
	if len(spec.Password) < 12 {
		return nil, fmt.Errorf("password must be at least 12 characters")
	}
	if len(spec.Databases) == 0 || len(spec.Databases) > 50 {
		return nil, fmt.Errorf("databases: need between 1 and 50 names")
	}
	for _, d := range spec.Databases {
		if !dbNameRE.MatchString(d) {
			return nil, fmt.Errorf("invalid database name %q", d)
		}
	}

	if err := r.system.CreateDatabaseUser(context.Background(), spec); err != nil {
		return nil, err
	}

	return map[string]any{"username": spec.Username, "databases": len(spec.Databases)}, nil
}

func (r *Registry) dropDatabaseUser(_ context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Username string `json:"username"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validDBUser(in.Username); err != nil {
		return nil, err
	}

	if err := r.system.DropDatabaseUser(context.Background(), in.Username); err != nil {
		return nil, err
	}

	return map[string]any{"username": in.Username, "dropped": true}, nil
}

func (r *Registry) setDatabaseUserPassword(_ context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Username string `json:"username"`
		Password string `json:"password"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validDBUser(in.Username); err != nil {
		return nil, err
	}
	if len(in.Password) < 12 {
		return nil, fmt.Errorf("password must be at least 12 characters")
	}

	if err := r.system.SetDatabaseUserPassword(context.Background(), in.Username, in.Password); err != nil {
		return nil, err
	}

	return map[string]any{"username": in.Username, "updated": true}, nil
}

func (r *Registry) setRemoteMySQL(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[RemoteMySQLSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validDBUser(spec.DBUser); err != nil {
		return nil, err
	}
	if err := validRemoteHost(spec.Host); err != nil {
		return nil, err
	}

	if err := r.system.SetRemoteMySQL(context.Background(), spec); err != nil {
		return nil, err
	}

	return map[string]any{"host": spec.Host, "db_user": spec.DBUser, "allowed": spec.Allow}, nil
}

// ---- observability --------------------------------------------------------

func (r *Registry) bandwidthReport(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[RangeSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if spec.Days < 1 || spec.Days > 366 {
		return nil, fmt.Errorf("days must be between 1 and 366")
	}

	report, err := r.system.BandwidthReport(context.Background(), spec.Username, spec.Days)
	if err != nil {
		return nil, err
	}

	return map[string]any{
		"days":     report.Days,
		"total_mb": report.TotalMB,
	}, nil
}

func (r *Registry) diskUsage(_ context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[DiskSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if spec.Path != "" {
		if err := validHomePath(spec.Username, spec.Path); err != nil {
			return nil, err
		}
	}

	usage, err := r.system.DiskUsage(context.Background(), spec.Username, spec.Path)
	if err != nil {
		return nil, err
	}

	return usage, nil
}

func (r *Registry) authLog(_ context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Days int `json:"days"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if in.Days < 1 || in.Days > 366 {
		return nil, fmt.Errorf("days must be between 1 and 366")
	}

	entries, err := r.system.AuthLog(context.Background(), in.Days)
	if err != nil {
		return nil, err
	}

	return map[string]any{"entries": entries}, nil
}
