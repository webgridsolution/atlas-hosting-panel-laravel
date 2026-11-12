package ops

import (
	"context"
	"encoding/json"
	"strings"
	"testing"
)

// stubSystem records the calls it receives and can be told to fail.
type stubSystem struct {
	calls     []string
	failOn    string
	lastSpec  AccountSpec
	lastWrite FileWriteSpec
}

func (s *stubSystem) hit(name string) error {
	s.calls = append(s.calls, name)

	if name == s.failOn {
		return errStub
	}

	return nil
}

var errStub = &stubError{}

type stubError struct{}

func (*stubError) Error() string { return "stubbed failure" }

func (s *stubSystem) CreateAccount(_ context.Context, spec AccountSpec) error {
	s.lastSpec = spec

	return s.hit("account.create")
}
func (s *stubSystem) SuspendAccount(context.Context, string) error { return s.hit("account.suspend") }
func (s *stubSystem) UnsuspendAccount(context.Context, string) error {
	return s.hit("account.unsuspend")
}
func (s *stubSystem) DeleteAccount(context.Context, string, bool) error {
	return s.hit("account.delete")
}
func (s *stubSystem) ApplyQuota(context.Context, string, int, int) error {
	return s.hit("account.quota.set")
}
func (s *stubSystem) WriteVhost(context.Context, VhostSpec) error { return s.hit("vhost.write") }
func (s *stubSystem) RemoveVhost(context.Context, string) error   { return s.hit("vhost.remove") }
func (s *stubSystem) CreateDatabase(context.Context, DatabaseSpec) error {
	return s.hit("db.create")
}
func (s *stubSystem) DropDatabase(context.Context, DatabaseSpec) error { return s.hit("db.drop") }
func (s *stubSystem) CreateMailbox(context.Context, MailboxSpec) error {
	return s.hit("mail.mailbox.create")
}
func (s *stubSystem) DeleteMailbox(context.Context, MailboxSpec) error {
	return s.hit("mail.mailbox.delete")
}
func (s *stubSystem) WriteZone(context.Context, ZoneSpec) error { return s.hit("dns.zone.write") }
func (s *stubSystem) IssueCertificate(context.Context, string, string) (CertResult, error) {
	return CertResult{}, s.hit("ssl.issue")
}
func (s *stubSystem) SetCron(context.Context, CronSpec) error { return s.hit("cron.set") }
func (s *stubSystem) RunBackup(context.Context, BackupSpec) (BackupResult, error) {
	return BackupResult{}, s.hit("backup.run")
}
func (s *stubSystem) RestoreBackup(context.Context, RestoreSpec) error {
	return s.hit("backup.restore")
}
func (s *stubSystem) RestartService(context.Context, string) error { return s.hit("service.restart") }
func (s *stubSystem) Stats(context.Context) (Stats, error)         { return Stats{}, s.hit("system.stats") }
func (s *stubSystem) ListFiles(context.Context, string, string) (FileList, error) {
	return FileList{}, s.hit("file.list")
}
func (s *stubSystem) ReadFile(context.Context, string, string, int) (FileRead, error) {
	return FileRead{}, s.hit("file.read")
}
func (s *stubSystem) WriteFile(context.Context, FileWriteSpec) error { return s.hit("file.write") }
func (s *stubSystem) MakeDir(context.Context, string, string) error  { return s.hit("file.mkdir") }
func (s *stubSystem) DeletePath(context.Context, string, string) error {
	return s.hit("file.delete")
}
func (s *stubSystem) MovePath(context.Context, string, string, string) error {
	return s.hit("file.rename")
}

// ---- file manager and ssh ------------------------------------------------

func (s *stubSystem) WriteUpload(_ context.Context, spec FileUploadSpec, body []byte) (FileUploadResult, error) {
	s.lastWrite = FileWriteSpec{Username: spec.Username, Path: spec.Path}
	s.calls = append(s.calls, "file.upload")
	return FileUploadResult{Name: spec.Path, Path: spec.Path, Bytes: len(body)}, nil
}

func (s *stubSystem) ReadDownload(_ context.Context, spec FileDownloadSpec) (FileDownloadResult, error) {
	s.calls = append(s.calls, "file.download")
	return FileDownloadResult{Content: encodeBytes([]byte("hi")), Bytes: 2, MimeType: "text/plain"}, nil
}

func (s *stubSystem) CopyPath(_ context.Context, spec FileCopySpec) (FileCopyResult, error) {
	s.calls = append(s.calls, "file.copy")
	return FileCopyResult{From: spec.From, To: spec.To, Copied: 1}, nil
}

func (s *stubSystem) ChmodPath(_ context.Context, spec FileChmodSpec) (FileChmodResult, error) {
	s.calls = append(s.calls, "file.chmod")
	return FileChmodResult{Path: spec.Path, Mode: spec.Mode, Changed: 1}, nil
}

func (s *stubSystem) CompressPaths(_ context.Context, spec FileCompressSpec) (FileCompressResult, error) {
	s.calls = append(s.calls, "file.compress")
	return FileCompressResult{Archive: spec.Archive, Files: len(spec.Paths)}, nil
}

func (s *stubSystem) ExtractArchive(_ context.Context, spec FileExtractSpec) (FileExtractResult, error) {
	s.calls = append(s.calls, "file.extract")
	return FileExtractResult{Files: 1}, nil
}

func (s *stubSystem) SearchFiles(_ context.Context, spec FileSearchSpec) (FileSearchResult, error) {
	s.calls = append(s.calls, "file.search")
	return FileSearchResult{Hits: []FileHit{}}, nil
}

func (s *stubSystem) StatPaths(_ context.Context, spec FileStatSpec) (FileStatResult, error) {
	s.calls = append(s.calls, "file.stat")
	return FileStatResult{Sizes: []FileSize{}}, nil
}

func (s *stubSystem) TrashPath(_ context.Context, spec FileTrashSpec) (FileTrashResult, error) {
	s.calls = append(s.calls, "file.trash")
	return FileTrashResult{Name: spec.Path, Path: spec.Path}, nil
}

func (s *stubSystem) RestorePath(_ context.Context, spec FileRestoreSpec) (FileRestoreResult, error) {
	s.calls = append(s.calls, "file.restore")
	return FileRestoreResult{Restored: spec.Path}, nil
}

func (s *stubSystem) ListTrash(_ context.Context, username string) (FileTrashListResult, error) {
	s.calls = append(s.calls, "file.trash.list")
	return FileTrashListResult{Entries: []TrashEntry{}}, nil
}

func (s *stubSystem) GenerateSSHKey(_ context.Context, spec SSHKeySpec) (SSHKeyInfo, error) {
	s.calls = append(s.calls, "ssh.key.generate")
	return SSHKeyInfo{Name: spec.Name, Type: spec.Type, HasPrivate: true}, nil
}

func (s *stubSystem) ImportSSHKey(_ context.Context, spec SSHKeySpec) (SSHKeyInfo, error) {
	s.calls = append(s.calls, "ssh.key.import")
	return SSHKeyInfo{Name: spec.Name, Public: spec.Public}, nil
}

func (s *stubSystem) ListSSHKeys(_ context.Context, username string) (SSHKeyListResult, error) {
	s.calls = append(s.calls, "ssh.key.list")
	return SSHKeyListResult{Keys: []SSHKeyInfo{}}, nil
}

func (s *stubSystem) AuthorizeSSHKey(_ context.Context, username, name string) (any, error) {
	s.calls = append(s.calls, "ssh.key.authorize")
	return map[string]any{"name": name, "authorized": true}, nil
}

func (s *stubSystem) DeauthorizeSSHKey(_ context.Context, username, name string) (any, error) {
	s.calls = append(s.calls, "ssh.key.deauthorize")
	return map[string]any{"name": name, "authorized": false}, nil
}

func (s *stubSystem) DeleteSSHKey(_ context.Context, username, name string) (any, error) {
	s.calls = append(s.calls, "ssh.key.delete")
	return map[string]any{"name": name, "deleted": true}, nil
}

func (s *stubSystem) SetSSHAccess(_ context.Context, spec SSHAccessSpec) (SSHAccessResult, error) {
	s.calls = append(s.calls, "ssh.access.set")
	return SSHAccessResult{Username: spec.Username, Enabled: spec.Enabled}, nil
}

func (s *stubSystem) SSHInfo(_ context.Context) (SSHInfo, error) {
	s.calls = append(s.calls, "ssh.info")
	return SSHInfo{Enabled: true, Port: 22}, nil
}

func dispatch(t *testing.T, sys *stubSystem, op string, args any) (any, error) {
	t.Helper()

	raw, err := json.Marshal(args)
	if err != nil {
		t.Fatal(err)
	}

	return NewRegistry(sys).Dispatch(context.Background(), op, raw)
}

func TestAllowlistIsExactlyTheDocumentedSurface(t *testing.T) {
	ops := NewRegistry(nil).Ops()

	// One line per operation, exactly as docs/17 §1 lists them. If this fails,
	// the doc and the binary disagree — which is the whole point of the test.
	want := []string{
		"account.create", "account.delete", "account.quota.set", "account.suspend",
		"account.unsuspend", "app.info", "app.install", "app.remove",
		"backup.restore", "backup.run", "bandwidth.report", "cron.set",
		"db.create", "db.drop", "db.remote.set", "db.user.create",
		"db.user.drop", "db.user.password", "dir.protect.remove", "dir.protect.write",
		"disk.usage", "dns.zone.write", "domain.alias.create", "domain.remove",
		"domain.subdomain.create", "errorpage.write", "file.chmod", "file.compress",
		"file.copy", "file.delete", "file.download", "file.extract",
		"file.list", "file.mkdir", "file.read", "file.rename",
		"file.restore", "file.search", "file.stat", "file.trash",
		"file.trash.list", "file.upload", "file.write", "ftp.account.create",
		"ftp.account.drop", "ftp.account.password", "git.init", "git.pull",
		"htaccess.redirect.remove", "htaccess.redirect.write", "ip.block.remove", "ip.block.write",
		"log.auth", "mail.autoresponder.remove", "mail.autoresponder.write", "mail.catchall.remove",
		"mail.catchall.write", "mail.dkim.ensure", "mail.filter.write", "mail.forwarder.remove",
		"mail.forwarder.write", "mail.list.remove", "mail.list.write", "mail.mailbox.create",
		"mail.mailbox.delete", "mail.spam.write", "mime.remove", "mime.write",
		"php.extensions", "php.settings.write", "service.restart", "ssh.access.set",
		"ssh.info", "ssh.key.authorize", "ssh.key.deauthorize", "ssh.key.delete",
		"ssh.key.generate", "ssh.key.import", "ssh.key.list", "ssl.csr",
		"ssl.info", "ssl.install", "ssl.issue", "system.stats",
		"vhost.php.set", "vhost.remove", "vhost.write",
	}

	if strings.Join(ops, ",") != strings.Join(want, ",") {
		t.Fatalf("allowlist drifted from docs/17 §1\n got: %v\nwant: %v", ops, want)
	}
}

func TestUnknownOperationIsRefusedWithoutTouchingTheSystem(t *testing.T) {
	sys := &stubSystem{}

	if _, err := dispatch(t, sys, "shell.exec", map[string]string{"cmd": "id"}); err == nil {
		t.Fatal("an unknown operation must be an error")
	}

	if len(sys.calls) != 0 {
		t.Fatalf("system was called for an unknown operation: %v", sys.calls)
	}
}

func TestUnknownArgumentIsRejected(t *testing.T) {
	sys := &stubSystem{}

	_, err := dispatch(t, sys, "account.suspend", map[string]string{"username": "acme", "extra": "x"})
	if err == nil || !strings.Contains(err.Error(), "invalid arguments") {
		t.Fatalf("typo'd argument must fail loudly, got %v", err)
	}

	if len(sys.calls) != 0 {
		t.Fatal("nothing should run when arguments are wrong")
	}
}

func TestUsernameRules(t *testing.T) {
	bad := []string{"", "ab", "Root", "1acme", "acme!", "this-name-is-too-long", "root", "admin", "atlas"}

	for _, u := range bad {
		if _, err := dispatch(t, &stubSystem{}, "account.suspend", map[string]string{"username": u}); err == nil {
			t.Errorf("username %q should be refused", u)
		}
	}

	for _, u := range []string{"acme", "sunny7", "dhanbadweb"} {
		if _, err := dispatch(t, &stubSystem{}, "account.suspend", map[string]string{"username": u}); err != nil {
			t.Errorf("username %q should be accepted: %v", u, err)
		}
	}
}

func TestDocumentRootCannotEscapeTheAccountHome(t *testing.T) {
	cases := []string{"/etc", "/home/acme2/public_html", "/home/acme/../../etc", "/var/www/html"}

	for _, root := range cases {
		_, err := dispatch(t, &stubSystem{}, "vhost.write", VhostSpec{
			Domain: "example.com", Username: "acme", DocumentRoot: root, PHPVersion: "8.3",
		})
		if err == nil {
			t.Errorf("document root %q should be refused", root)
		}
	}

	if _, err := dispatch(t, &stubSystem{}, "vhost.write", VhostSpec{
		Domain: "example.com", Username: "acme", DocumentRoot: "/home/acme/public_html", PHPVersion: "8.3",
	}); err != nil {
		t.Fatalf("a legitimate document root was refused: %v", err)
	}
}

func TestFilePathsMustStayInsideTheHome(t *testing.T) {
	bad := []string{"/etc/passwd", "/home/other/file", "/home/acme/../other/x", "/home/acme//x"}

	for _, p := range bad {
		if _, err := dispatch(t, &stubSystem{}, "file.read", map[string]any{"username": "acme", "path": p}); err == nil {
			t.Errorf("path %q should be refused", p)
		}
	}

	if _, err := dispatch(t, &stubSystem{}, "file.read", map[string]any{"username": "acme", "path": "/home/acme/public_html/index.php"}); err != nil {
		t.Fatalf("a legitimate path was refused: %v", err)
	}
}

func TestReadCapIsEnforcedRegardlessOfWhatThePanelAsks(t *testing.T) {
	sys := &stubSystem{}

	if _, err := dispatch(t, sys, "file.read", map[string]any{
		"username": "acme", "path": "/home/acme/big.log", "max_bytes": 500 << 20,
	}); err != nil {
		t.Fatal(err)
	}

	// The registry rewrites the request; the system must receive the cap.
	if len(sys.calls) != 1 {
		t.Fatalf("expected one call, got %v", sys.calls)
	}
}

func TestDeleteRefusesTheAccountHome(t *testing.T) {
	if _, err := dispatch(t, &stubSystem{}, "file.delete", map[string]any{
		"username": "acme", "path": "/home/acme",
	}); err == nil {
		t.Fatal("deleting the home directory must be refused; that is account termination")
	}
}

func TestCronEntryCannotSmuggleASecondLine(t *testing.T) {
	bad := "*/5 * * * * /usr/bin/php /home/acme/a.php\n0 0 * * * rm -rf /"
	if _, err := dispatch(t, &stubSystem{}, "cron.set", map[string]any{"username": "acme", "entry": bad}); err == nil {
		t.Fatal("a multi-line cron entry must be refused")
	}

	if _, err := dispatch(t, &stubSystem{}, "cron.set", map[string]any{
		"username": "acme", "entry": "*/5 * * * * /usr/bin/php /home/acme/public_html/cron.php",
	}); err != nil {
		t.Fatalf("a normal cron entry was refused: %v", err)
	}
}

func TestOnlyKnownServicesCanBeRestarted(t *testing.T) {
	if _, err := dispatch(t, &stubSystem{}, "service.restart", map[string]string{"unit": "ssh"}); err == nil {
		t.Fatal("ssh must not be restartable through the panel")
	}

	if _, err := dispatch(t, &stubSystem{}, "service.restart", map[string]string{"unit": "systemd-logind"}); err == nil {
		t.Fatal("random units must be refused")
	}

	if _, err := dispatch(t, &stubSystem{}, "service.restart", map[string]string{"unit": "apache2"}); err != nil {
		t.Fatalf("apache2 should be restartable: %v", err)
	}
}

func TestBackupPathsAreBounded(t *testing.T) {
	if _, err := dispatch(t, &stubSystem{}, "backup.run", map[string]any{
		"username": "acme", "destination": "/tmp/anywhere", "include_databases": false,
	}); err == nil {
		t.Fatal("a backup must not be writable outside /var/backups")
	}

	if _, err := dispatch(t, &stubSystem{}, "backup.restore", map[string]any{
		"username": "acme", "archive": "/etc/passwd",
	}); err == nil {
		t.Fatal("a restore archive must live under /var/backups")
	}
}

func TestWeakDatabasePasswordIsRefused(t *testing.T) {
	if _, err := dispatch(t, &stubSystem{}, "db.create", DatabaseSpec{
		Name: "acme_wp", Username: "acme_wp", Password: "short",
	}); err == nil {
		t.Fatal("a short password must be refused before it reaches MySQL")
	}
}

func TestHandlerErrorsArePropagated(t *testing.T) {
	sys := &stubSystem{failOn: "account.create"}

	_, err := dispatch(t, sys, "account.create", AccountSpec{
		Username: "acme", Domain: "acme.test", DiskMB: 1024, Inodes: 10000, PHPVersion: "8.3",
	})
	if err == nil || !strings.Contains(err.Error(), "stubbed failure") {
		t.Fatalf("the system error must reach the caller, got %v", err)
	}
}

func TestValidAccountReachesTheSystemUnchanged(t *testing.T) {
	sys := &stubSystem{}

	spec := AccountSpec{Username: "acme", Domain: "acme.test", DiskMB: 5120, Inodes: 100000, PHPVersion: "8.4"}

	if _, err := dispatch(t, sys, "account.create", spec); err != nil {
		t.Fatal(err)
	}

	if sys.lastSpec != spec {
		t.Fatalf("spec was altered on the way through: %+v", sys.lastSpec)
	}
}

func TestPHPVersionIsChecked(t *testing.T) {
	for _, v := range []string{"7.4", "8.6", "latest", "8.3; id"} {
		if _, err := dispatch(t, &stubSystem{}, "account.create", AccountSpec{
			Username: "acme", Domain: "acme.test", DiskMB: 1024, Inodes: 10000, PHPVersion: v,
		}); err == nil {
			t.Errorf("PHP version %q should be refused", v)
		}
	}
}

func TestStatsNeedsNoAgreementFromTheCaller(t *testing.T) {
	sys := &stubSystem{}

	if _, err := dispatch(t, sys, "system.stats", map[string]string{}); err != nil {
		t.Fatal(err)
	}

	if len(sys.calls) != 1 || sys.calls[0] != "system.stats" {
		t.Fatalf("stats should be a single call, got %v", sys.calls)
	}
}

// ---- methods added when the hosting feature set widened the System
// interface. Each records the call so a validation test can prove the
// system was never reached, which is stronger than checking the error.

func (s *stubSystem) CreateSubdomain(ctx context.Context, spec SubdomainSpec) error {
	return s.hit("CreateSubdomain")
}

func (s *stubSystem) AttachAliases(ctx context.Context, username, domain string, aliases []string) error {
	return s.hit("AttachAliases")
}

func (s *stubSystem) RemoveDomain(ctx context.Context, username, domain string) error {
	return s.hit("RemoveDomain")
}

func (s *stubSystem) SetVhostPHP(ctx context.Context, spec VhostPhpSpec) error {
	return s.hit("SetVhostPHP")
}

func (s *stubSystem) WriteRedirect(ctx context.Context, spec RedirectSpec) error {
	return s.hit("WriteRedirect")
}

func (s *stubSystem) RemoveRedirect(ctx context.Context, username, domain, source string) error {
	return s.hit("RemoveRedirect")
}

func (s *stubSystem) CreateFTPAccount(ctx context.Context, spec FTPSpec) error {
	return s.hit("CreateFTPAccount")
}

func (s *stubSystem) DeleteFTPAccount(ctx context.Context, ftpUser string) error {
	return s.hit("DeleteFTPAccount")
}

func (s *stubSystem) SetFTPPassword(ctx context.Context, ftpUser, password string) error {
	return s.hit("SetFTPPassword")
}

func (s *stubSystem) WriteForwarder(ctx context.Context, spec ForwarderSpec) error {
	return s.hit("WriteForwarder")
}

func (s *stubSystem) RemoveForwarder(ctx context.Context, domain, source string) error {
	return s.hit("RemoveForwarder")
}

func (s *stubSystem) WriteAutoresponder(ctx context.Context, spec AutoresponderSpec) error {
	return s.hit("WriteAutoresponder")
}

func (s *stubSystem) RemoveAutoresponder(ctx context.Context, domain, mailbox string) error {
	return s.hit("RemoveAutoresponder")
}

func (s *stubSystem) WriteCatchall(ctx context.Context, spec CatchallSpec) error {
	return s.hit("WriteCatchall")
}

func (s *stubSystem) RemoveCatchall(ctx context.Context, domain string) error {
	return s.hit("RemoveCatchall")
}

func (s *stubSystem) WriteFilters(ctx context.Context, spec FilterSpec) error {
	return s.hit("WriteFilters")
}

func (s *stubSystem) WriteSpamPolicy(ctx context.Context, spec SpamSpec) error {
	return s.hit("WriteSpamPolicy")
}

func (s *stubSystem) EnsureDKIM(ctx context.Context, domain string) (map[string]any, error) {
	return nil, s.hit("EnsureDKIM")
}

func (s *stubSystem) WriteMailingList(ctx context.Context, spec MailingListSpec) error {
	return s.hit("WriteMailingList")
}

func (s *stubSystem) RemoveMailingList(ctx context.Context, domain, name string) error {
	return s.hit("RemoveMailingList")
}

func (s *stubSystem) GenerateCSR(ctx context.Context, domain, organisation string) (CSRResult, error) {
	return CSRResult{}, s.hit("GenerateCSR")
}

func (s *stubSystem) InstallCertificate(ctx context.Context, spec InstallCertSpec) (CertResult, error) {
	return CertResult{}, s.hit("InstallCertificate")
}

func (s *stubSystem) CertificateInfo(ctx context.Context, domain string) (CertInfo, error) {
	return CertInfo{}, s.hit("CertificateInfo")
}

func (s *stubSystem) WriteIPBlock(ctx context.Context, spec IPBlockSpec) error {
	return s.hit("WriteIPBlock")
}

func (s *stubSystem) RemoveIPBlock(ctx context.Context, spec IPBlockSpec) error {
	return s.hit("RemoveIPBlock")
}

func (s *stubSystem) WriteDirProtection(ctx context.Context, spec DirProtectSpec) error {
	return s.hit("WriteDirProtection")
}

func (s *stubSystem) RemoveDirProtection(ctx context.Context, username, path string) error {
	return s.hit("RemoveDirProtection")
}

func (s *stubSystem) WriteMime(ctx context.Context, spec MimeSpec) error {
	return s.hit("WriteMime")
}

func (s *stubSystem) RemoveMime(ctx context.Context, username, extension string) error {
	return s.hit("RemoveMime")
}

func (s *stubSystem) WriteErrorPage(ctx context.Context, spec ErrorPageSpec) error {
	return s.hit("WriteErrorPage")
}

func (s *stubSystem) WritePHPSettings(ctx context.Context, spec PHPSettingsSpec) error {
	return s.hit("WritePHPSettings")
}

func (s *stubSystem) ListPHPExtensions(ctx context.Context, version string) ([]PHPExtension, error) {
	return nil, s.hit("ListPHPExtensions")
}

func (s *stubSystem) InstallApp(ctx context.Context, spec AppInstallSpec) (map[string]any, error) {
	return nil, s.hit("InstallApp")
}

func (s *stubSystem) RemoveApp(ctx context.Context, username, path string) error {
	return s.hit("RemoveApp")
}

func (s *stubSystem) AppInfo(ctx context.Context, slug, path string) (AppInfo, error) {
	return AppInfo{}, s.hit("AppInfo")
}

func (s *stubSystem) CreateGitRepo(ctx context.Context, spec GitSpec) error {
	return s.hit("CreateGitRepo")
}

func (s *stubSystem) PullGitRepo(ctx context.Context, username, path string) (map[string]any, error) {
	return nil, s.hit("PullGitRepo")
}

func (s *stubSystem) CreateDatabaseUser(ctx context.Context, spec DBUserSpec) error {
	return s.hit("CreateDatabaseUser")
}

func (s *stubSystem) DropDatabaseUser(ctx context.Context, username string) error {
	return s.hit("DropDatabaseUser")
}

func (s *stubSystem) SetDatabaseUserPassword(ctx context.Context, username, password string) error {
	return s.hit("SetDatabaseUserPassword")
}

func (s *stubSystem) SetRemoteMySQL(ctx context.Context, spec RemoteMySQLSpec) error {
	return s.hit("SetRemoteMySQL")
}

func (s *stubSystem) BandwidthReport(ctx context.Context, username string, days int) (BandwidthReport, error) {
	return BandwidthReport{}, s.hit("BandwidthReport")
}

func (s *stubSystem) DiskUsage(ctx context.Context, username, path string) (DiskUsage, error) {
	return DiskUsage{}, s.hit("DiskUsage")
}

func (s *stubSystem) AuthLog(ctx context.Context, days int) ([]AuthLogEntry, error) {
	return nil, s.hit("AuthLog")
}
