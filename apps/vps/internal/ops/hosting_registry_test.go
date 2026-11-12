package ops

import (
	"encoding/json"
	"strings"
	"testing"
)

// The hosting surface is 44 operations, and for every one of them the only
// thing standing between a customer form and root on the VPS is the argument
// validation. These tests are therefore written as two assertions per op:
//
//	valid input   -> the handler dispatches, and the System receives the call
//	invalid input -> an error, and the System is never reached
//
// The second half is the one that matters. An error alone could mean the
// handler ran and the system refused; recording calls proves it did not.
type hostingCase struct {
	op      string
	method  string // the System method a valid dispatch must reach
	valid   string
	invalid string
}

var hostingCases = []hostingCase{
	{
		op: "domain.subdomain.create", method: "CreateSubdomain",
		valid:   `{"username":"acme","domain":"acme.test","label":"blog","document_root":"/home/acme/blog.acme.test","php_version":"8.3"}`,
		invalid: `{"username":"acme","domain":"acme.test","label":"Blog_Main","document_root":"/home/acme/blog.acme.test"}`,
	},
	{
		op: "domain.alias.create", method: "AttachAliases",
		valid:   `{"username":"acme","domain":"acme.test","aliases":["www.acme.test","shop.acme.test"]}`,
		invalid: `{"username":"acme","domain":"acme.test","aliases":[]}`,
	},
	{
		op: "domain.remove", method: "RemoveDomain",
		valid:   `{"username":"acme","domain":"old.acme.test"}`,
		invalid: `{"username":"acme","domain":"not a domain"}`,
	},
	{
		op: "vhost.php.set", method: "SetVhostPHP",
		valid:   `{"username":"acme","domain":"acme.test","php_version":"8.3","keep_ssl":true}`,
		invalid: `{"username":"acme","domain":"acme.test","php_version":"7.4"}`,
	},
	{
		op: "htaccess.redirect.write", method: "WriteRedirect",
		valid:   `{"username":"acme","domain":"acme.test","source":"/old","target":"https://acme.test/new","status":301}`,
		invalid: `{"username":"acme","domain":"acme.test","source":"old","target":"https://acme.test/new","status":301}`,
	},
	{
		op: "htaccess.redirect.remove", method: "RemoveRedirect",
		valid:   `{"username":"acme","domain":"acme.test","source":"/old"}`,
		invalid: `{"username":"acme","domain":"acme.test","source":"/old\r\nHeader set X-Evil 1"}`,
	},
	{
		op: "ftp.account.create", method: "CreateFTPAccount",
		valid:   `{"username":"acme","ftp_username":"acme_ftp","password":"correct-horse-battery","home_dir":"/home/acme","quota_mb":1024}`,
		invalid: `{"username":"acme","ftp_username":"acme ftp","password":"correct-horse-battery","home_dir":"/home/acme","quota_mb":1024}`,
	},
	{
		op: "ftp.account.drop", method: "DeleteFTPAccount",
		valid:   `{"username":"acme","ftp_username":"acme_ftp"}`,
		invalid: `{"username":"acme","ftp_username":"acme_ftp;id"}`,
	},
	{
		op: "ftp.account.password", method: "SetFTPPassword",
		valid:   `{"username":"acme","ftp_username":"acme_ftp","password":"another-long-secret"}`,
		invalid: `{"username":"acme","ftp_username":"acme_ftp","password":"short"}`,
	},
	{
		op: "mail.forwarder.write", method: "WriteForwarder",
		valid:   `{"domain":"acme.test","source":"sales","destinations":["owner@acme.test"],"keep_copy":true}`,
		invalid: `{"domain":"acme.test","source":"sales","destinations":["not-an-address"]}`,
	},
	{
		op: "mail.forwarder.remove", method: "RemoveForwarder",
		valid:   `{"domain":"acme.test","source":"sales"}`,
		invalid: `{"domain":"acme.test","source":"Sales Team"}`,
	},
	{
		op: "mail.autoresponder.write", method: "WriteAutoresponder",
		valid:   `{"domain":"acme.test","mailbox":"info","subject":"Away","body":"Back on Monday."}`,
		invalid: `{"domain":"acme.test","mailbox":"Info Box","subject":"Away","body":"Back on Monday."}`,
	},
	{
		op: "mail.autoresponder.remove", method: "RemoveAutoresponder",
		valid:   `{"domain":"acme.test","mailbox":"info"}`,
		invalid: `{"domain":"acme.test","mailbox":"../root"}`,
	},
	{
		op: "mail.catchall.write", method: "WriteCatchall",
		valid:   `{"domain":"acme.test","destination":"catchall@acme.test"}`,
		invalid: `{"domain":"acme.test","destination":":include:/etc/passwd"}`,
	},
	{
		op: "mail.catchall.remove", method: "RemoveCatchall",
		valid:   `{"domain":"acme.test"}`,
		invalid: `{"domain":"acme"}`,
	},
	{
		op: "mail.filter.write", method: "WriteFilters",
		valid: `{"domain":"acme.test","mailbox":"info","rules":[` +
			`{"field":"subject","operator":"contains","value":"invoice","action":"move","action_arg":"Invoices"}]}`,
		invalid: `{"domain":"acme.test","mailbox":"info","rules":[` +
			`{"field":"subject","operator":"regexp","value":".*","action":"move","action_arg":"Junk"}]}`,
	},
	{
		op: "mail.spam.write", method: "WriteSpamPolicy",
		valid:   `{"domain":"acme.test","spam_threshold":"5.0","spam_action":"mark","dkim_enabled":true,"mailbox_quota_mb":1024}`,
		invalid: `{"domain":"acme.test","spam_threshold":"5.0","spam_action":"bounce"}`,
	},
	{
		op: "mail.dkim.ensure", method: "EnsureDKIM",
		valid:   `{"domain":"acme.test"}`,
		invalid: `{"domain":"acme..test"}`,
	},
	{
		op: "mail.list.write", method: "WriteMailingList",
		valid:   `{"domain":"acme.test","name":"announce","title":"Announcements","members":["a@acme.test","b@acme.test"]}`,
		invalid: `{"domain":"acme.test","name":"Announce List","members":["a@acme.test"]}`,
	},
	{
		op: "mail.list.remove", method: "RemoveMailingList",
		valid:   `{"domain":"acme.test","name":"announce"}`,
		invalid: `{"domain":"acme.test","name":"../../etc/passwd"}`,
	},
	{
		op: "ssl.csr", method: "GenerateCSR",
		valid:   `{"domain":"acme.test","organisation":"Acme Pvt Ltd"}`,
		invalid: `{"domain":"acme.test","organisation":"Acme\nCN=evil.test"}`,
	},
	{
		op: "ssl.install", method: "InstallCertificate",
		valid: `{"username":"acme","domain":"acme.test","cert_pem":"-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n",` +
			`"key_pem":"-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n"}`,
		invalid: `{"username":"acme","domain":"acme.test","cert_pem":"hello"}`,
	},
	{
		op: "ssl.info", method: "CertificateInfo",
		valid:   `{"domain":"acme.test"}`,
		invalid: `{"domain":"-acme.test"}`,
	},
	{
		op: "ip.block.write", method: "WriteIPBlock",
		valid:   `{"username":"acme","cidr":"203.0.113.0/24"}`,
		invalid: `{"username":"acme","cidr":"999.0.113.1"}`,
	},
	{
		op: "ip.block.remove", method: "RemoveIPBlock",
		valid:   `{"username":"acme","cidr":"203.0.113.7"}`,
		invalid: `{"username":"acme","cidr":"203.0.113.0/24;deny all"}`,
	},
	{
		op: "dir.protect.write", method: "WriteDirProtection",
		valid:   `{"username":"acme","path":"/home/acme/public_html/admin","realm":"Admin","user":"ops","password":"a-long-enough-secret"}`,
		invalid: `{"username":"acme","path":"/etc","realm":"Admin","user":"ops","password":"a-long-enough-secret"}`,
	},
	{
		op: "dir.protect.remove", method: "RemoveDirProtection",
		valid:   `{"username":"acme","path":"/home/acme/public_html/admin"}`,
		invalid: `{"username":"acme","path":"/home/acme/../root"}`,
	},
	{
		op: "mime.write", method: "WriteMime",
		valid:   `{"username":"acme","extension":"webp","mime_type":"image/webp"}`,
		invalid: `{"username":"acme","extension":"we.bp","mime_type":"image/webp"}`,
	},
	{
		op: "mime.remove", method: "RemoveMime",
		valid:   `{"username":"acme","extension":"webp"}`,
		invalid: `{"username":"acme","extension":"../htaccess"}`,
	},
	{
		op: "errorpage.write", method: "WriteErrorPage",
		valid:   `{"username":"acme","domain":"acme.test","status":404,"body":"<h1>Not found</h1>"}`,
		invalid: `{"username":"acme","domain":"acme.test","status":999,"body":"x"}`,
	},
	{
		op: "php.settings.write", method: "WritePHPSettings",
		valid:   `{"username":"acme","domain":"acme.test","version":"8.3","settings":{"memory_limit":"256M"}}`,
		invalid: `{"username":"acme","domain":"acme.test","version":"8.3","settings":{"auto_prepend_file":"/etc/passwd"}}`,
	},
	{
		op: "php.extensions", method: "ListPHPExtensions",
		valid:   `{"version":"8.3"}`,
		invalid: `{"version":"9.9"}`,
	},
	{
		op: "app.install", method: "InstallApp",
		valid: `{"username":"acme","slug":"wordpress","domain":"acme.test","document_root":"/home/acme/wordpress",` +
			`"path":"","db_name":"acme_wp","db_user":"acme_wp","admin_user":"admin","admin_email":"admin@acme.test"}`,
		invalid: `{"username":"acme","slug":"../../etc","domain":"acme.test","document_root":"/home/acme/wordpress",` +
			`"db_name":"acme_wp","db_user":"acme_wp","admin_user":"admin","admin_email":"admin@acme.test"}`,
	},
	{
		op: "app.remove", method: "RemoveApp",
		valid:   `{"username":"acme","path":"/home/acme/wordpress"}`,
		invalid: `{"username":"acme","path":"/var/www"}`,
	},
	{
		op: "app.info", method: "AppInfo",
		valid:   `{"slug":"wordpress","path":"/home/acme/wordpress"}`,
		invalid: `{"slug":"shellshock","path":"/home/acme/wordpress"}`,
	},
	{
		op: "git.init", method: "CreateGitRepo",
		valid:   `{"username":"acme","path":"/home/acme/repo","branch":"main"}`,
		invalid: `{"username":"acme","path":"/home/acme/repo","branch":"--upload-pack=id"}`,
	},
	{
		op: "git.pull", method: "PullGitRepo",
		valid:   `{"username":"acme","path":"/home/acme/repo"}`,
		invalid: `{"username":"acme","path":"/root"}`,
	},
	{
		op: "db.user.create", method: "CreateDatabaseUser",
		valid:   `{"username":"acme_wp","password":"a-long-enough-secret","privileges":"ALL PRIVILEGES","databases":["acme_wp"]}`,
		invalid: `{"username":"acme_wp","password":"a-long-enough-secret","privileges":"GRANT OPTION","databases":["acme_wp"]}`,
	},
	{
		op: "db.user.drop", method: "DropDatabaseUser",
		valid:   `{"username":"acme_wp"}`,
		invalid: `{"username":"acme wp"}`,
	},
	{
		op: "db.user.password", method: "SetDatabaseUserPassword",
		valid:   `{"username":"acme_wp","password":"another-long-secret"}`,
		invalid: `{"username":"acme_wp","password":"short"}`,
	},
	{
		op: "db.remote.set", method: "SetRemoteMySQL",
		valid:   `{"host":"203.0.113.5","db_user":"acme_wp","allow":true}`,
		invalid: `{"host":"%","db_user":"acme_wp","allow":true}`,
	},
	{
		op: "bandwidth.report", method: "BandwidthReport",
		valid:   `{"username":"acme","days":30}`,
		invalid: `{"username":"acme","days":4000}`,
	},
	{
		op: "disk.usage", method: "DiskUsage",
		valid:   `{"username":"acme","path":"/home/acme"}`,
		invalid: `{"username":"acme","path":"/etc"}`,
	},
	{
		op: "log.auth", method: "AuthLog",
		valid:   `{"days":7}`,
		invalid: `{"days":-1}`,
	},
}

func TestEveryHostingOperationIsRegisteredAndValidated(t *testing.T) {
	registered := map[string]bool{}
	for _, op := range NewRegistry(nil).Ops() {
		registered[op] = true
	}

	if len(hostingCases) != 44 {
		t.Fatalf("the table describes %d operations; the registry registers 44 hosting ops plus 24 core ones", len(hostingCases))
	}

	for _, c := range hostingCases {
		if !registered[c.op] {
			t.Errorf("%s is exercised here but is not registered", c.op)
		}
	}
}

func TestValidHostingArgumentsReachTheSystem(t *testing.T) {
	for _, c := range hostingCases {
		t.Run(c.op, func(t *testing.T) {
			sys := &stubSystem{}

			if _, err := dispatch(t, sys, c.op, json.RawMessage(c.valid)); err != nil {
				t.Fatalf("valid args were refused: %v", err)
			}

			if len(sys.calls) != 1 || sys.calls[0] != c.method {
				t.Fatalf("expected exactly one %s call, got %v", c.method, sys.calls)
			}
		})
	}
}

func TestInvalidHostingArgumentsNeverReachTheSystem(t *testing.T) {
	for _, c := range hostingCases {
		t.Run(c.op, func(t *testing.T) {
			sys := &stubSystem{}

			if _, err := dispatch(t, sys, c.op, json.RawMessage(c.invalid)); err == nil {
				t.Fatalf("invalid args were accepted: %s", c.invalid)
			}

			if len(sys.calls) != 0 {
				t.Fatalf("the system was touched before validation failed: %v", sys.calls)
			}
		})
	}
}

// Every refusal must be a readable sentence, not a panic or a bare regexp
// failure; these messages end up in the panel's flash message verbatim.
func TestHostingRefusalsReadLikeSentences(t *testing.T) {
	for _, c := range hostingCases {
		t.Run(c.op, func(t *testing.T) {
			_, err := dispatch(t, &stubSystem{}, c.op, json.RawMessage(c.invalid))
			if err == nil {
				t.Skip("covered above")
			}

			msg := err.Error()
			if len(msg) < 12 || strings.HasSuffix(msg, ":") {
				t.Fatalf("unhelpful refusal: %q", msg)
			}
		})
	}
}

// Unknown fields must be refused rather than silently dropped: a typo in a
// field name would otherwise look like a successful no-op change.
func TestUnknownHostingArgFieldIsRefused(t *testing.T) {
	sys := &stubSystem{}

	_, err := dispatch(t, sys, "mime.write",
		json.RawMessage(`{"username":"acme","extension":"webp","mime_type":"image/webp","shell":"id"}`))
	if err == nil {
		t.Fatal("an unknown field must be refused")
	}

	if len(sys.calls) != 0 {
		t.Fatalf("the system was touched: %v", sys.calls)
	}
}

// A System error must surface to the panel unchanged, so the operator sees the
// real reason rather than a generic failure.
func TestHostingSystemErrorsPropagate(t *testing.T) {
	sys := &stubSystem{failOn: "WriteMime"}

	_, err := dispatch(t, sys, "mime.write",
		json.RawMessage(`{"username":"acme","extension":"webp","mime_type":"image/webp"}`))
	if err == nil {
		t.Fatal("the system error was swallowed")
	}
}

// Regression: htaccess.redirect.remove used to delegate to the write handler,
// which read Remove=false, skipped the delete and wrote a redirect with an
// empty target instead. The deletion must reach RemoveRedirect, and it must not
// require a target it will never use.
func TestRedirectRemoveCallsRemoveAndNeedsNoTarget(t *testing.T) {
	sys := &stubSystem{}

	if _, err := dispatch(t, sys, "htaccess.redirect.remove",
		json.RawMessage(`{"username":"acme","domain":"acme.test","source":"/old"}`)); err != nil {
		t.Fatalf("a valid removal was refused: %v", err)
	}

	if len(sys.calls) != 1 || sys.calls[0] != "RemoveRedirect" {
		t.Fatalf("expected RemoveRedirect, got %v", sys.calls)
	}
}

// Regression: a silent drop of an unlisted directive meant the panel showed
// "saved" for a setting that never reached .user.ini.
func TestUnknownPHPDirectiveIsRefusedNotDropped(t *testing.T) {
	sys := &stubSystem{}

	_, err := dispatch(t, sys, "php.settings.write",
		json.RawMessage(`{"username":"acme","domain":"acme.test","version":"8.3","settings":{"allow_url_include":"1"}}`))
	if err == nil {
		t.Fatal("an unlisted directive must be refused")
	}

	if !strings.Contains(err.Error(), "allow_url_include") {
		t.Fatalf("the refusal must name the directive, got %q", err)
	}

	if len(sys.calls) != 0 {
		t.Fatalf("the system was touched: %v", sys.calls)
	}
}

// Regression: local.cf took the threshold verbatim, so a non-number broke
// SpamAssassin for the whole domain.
func TestSpamThresholdMustBeNumeric(t *testing.T) {
	sys := &stubSystem{}

	_, err := dispatch(t, sys, "mail.spam.write",
		json.RawMessage(`{"domain":"acme.test","spam_threshold":"required_score 0","spam_action":"mark","mailbox_quota_mb":512}`))
	if err == nil {
		t.Fatal("a non-numeric threshold must be refused")
	}

	if len(sys.calls) != 0 {
		t.Fatalf("the system was touched: %v", sys.calls)
	}
}

// Regression: ":include:" is a Postfix directive that reads a file as a list of
// recipients. Only :fail: and :discard: may come from a customer form.
func TestCatchallRefusesPostfixInclude(t *testing.T) {
	sys := &stubSystem{}

	_, err := dispatch(t, sys, "mail.catchall.write",
		json.RawMessage(`{"domain":"acme.test","destination":":include:/etc/passwd"}`))
	if err == nil {
		t.Fatal(":include: must be refused")
	}

	if len(sys.calls) != 0 {
		t.Fatalf("the system was touched: %v", sys.calls)
	}
}
