package agentserver

import (
	"bytes"
	"context"
	"encoding/hex"
	"encoding/json"
	"io"
	"log/slog"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"atlas/internal/envelope"
	"atlas/internal/journal"
	"atlas/internal/ops"
)

// testSystem implements ops.System with counters only.
type testSystem struct {
	created  []string
	vhosts   []string
	statsHit int
}

func (s *testSystem) CreateAccount(_ context.Context, spec ops.AccountSpec) error {
	s.created = append(s.created, spec.Username)

	return nil
}
func (s *testSystem) SuspendAccount(context.Context, string) error   { return nil }
func (s *testSystem) UnsuspendAccount(context.Context, string) error { return nil }
func (s *testSystem) DeleteAccount(context.Context, string, bool) error {
	return nil
}
func (s *testSystem) ApplyQuota(context.Context, string, int, int) error { return nil }
func (s *testSystem) WriteVhost(_ context.Context, v ops.VhostSpec) error {
	s.vhosts = append(s.vhosts, v.Domain)

	return nil
}
func (s *testSystem) RemoveVhost(context.Context, string) error              { return nil }
func (s *testSystem) CreateDatabase(context.Context, ops.DatabaseSpec) error { return nil }
func (s *testSystem) DropDatabase(context.Context, ops.DatabaseSpec) error   { return nil }
func (s *testSystem) CreateMailbox(context.Context, ops.MailboxSpec) error   { return nil }
func (s *testSystem) DeleteMailbox(context.Context, ops.MailboxSpec) error   { return nil }
func (s *testSystem) WriteZone(context.Context, ops.ZoneSpec) error          { return nil }
func (s *testSystem) IssueCertificate(context.Context, string, string) (ops.CertResult, error) {
	return ops.CertResult{}, nil
}
func (s *testSystem) SetCron(context.Context, ops.CronSpec) error { return nil }
func (s *testSystem) RunBackup(context.Context, ops.BackupSpec) (ops.BackupResult, error) {
	return ops.BackupResult{}, nil
}
func (s *testSystem) RestoreBackup(context.Context, ops.RestoreSpec) error { return nil }
func (s *testSystem) RestartService(context.Context, string) error         { return nil }
func (s *testSystem) Stats(context.Context) (ops.Stats, error) {
	s.statsHit++

	return ops.Stats{}, nil
}

func (t *testSystem) ListFiles(_ context.Context, u, p string) (ops.FileList, error) {
	return ops.FileList{Path: p, Entries: []ops.FileEntry{{Name: "index.php", Type: "file", Size: 12}}}, nil
}
func (t *testSystem) ReadFile(_ context.Context, u, p string, max int) (ops.FileRead, error) {
	return ops.FileRead{Path: p, Content: "<?php echo 1;", Bytes: 13}, nil
}
func (t *testSystem) WriteFile(_ context.Context, spec ops.FileWriteSpec) error { return nil }
func (t *testSystem) MakeDir(_ context.Context, u, p string) error              { return nil }
func (t *testSystem) DeletePath(_ context.Context, u, p string) error           { return nil }
func (t *testSystem) MovePath(_ context.Context, u, from, to string) error      { return nil }

type harness struct {
	t       *testing.T
	server  *Server
	sys     *testSystem
	key     []byte
	journal string
}

func newHarness(t *testing.T) *harness {
	t.Helper()

	dir := t.TempDir()
	key := []byte("0123456789abcdef0123456789abcdef") // 32 bytes
	keyPath := filepath.Join(dir, "agent.key")
	if err := os.WriteFile(keyPath, key, 0o600); err != nil {
		t.Fatal(err)
	}

	jpath := filepath.Join(dir, "journal.jsonl")

	srv, err := New(Config{
		SocketPath:  filepath.Join(dir, "agent.sock"),
		AllowedUID:  uint32(os.Getuid()),
		KeyFile:     keyPath,
		JournalPath: jpath,
		Logger:      slog.New(slog.NewTextHandler(io.Discard, nil)),
	})
	if err != nil {
		t.Fatal(err)
	}

	sys := &testSystem{}
	srv = srv.WithSystem(sys)

	return &harness{t: t, server: srv, sys: sys, key: key, journal: jpath}
}

func (h *harness) post(body []byte) *httptest.ResponseRecorder {
	h.t.Helper()

	req := httptest.NewRequest(http.MethodPost, "/dispatch", bytes.NewReader(body))
	rec := httptest.NewRecorder()
	h.server.Handler().ServeHTTP(rec, req)

	return rec
}

// send signs a well-formed request for op with args.
func (h *harness) send(op string, args any) *httptest.ResponseRecorder {
	h.t.Helper()

	env, err := envelope.Sign(h.key, op, args, "", time.Now())
	if err != nil {
		h.t.Fatal(err)
	}

	raw, err := json.Marshal(env)
	if err != nil {
		h.t.Fatal(err)
	}

	return h.post(raw)
}

func (h *harness) journalEntries() []journal.Entry {
	h.t.Helper()

	entries, err := journal.Read(h.journal)
	if err != nil {
		h.t.Fatalf("read journal: %v", err)
	}

	return entries
}

func validAccount() map[string]any {
	return map[string]any{
		"username":    "acme",
		"domain":      "acme.test",
		"disk_mb":     10240,
		"inodes":      200000,
		"php_version": "8.3",
	}
}

// ---- happy path --------------------------------------------------------

func TestValidRequestReachesTheSystem(t *testing.T) {
	h := newHarness(t)

	rec := h.post(mustJSON(t, mustSign(t, h.key, "account.create", validAccount())))

	if rec.Code != http.StatusOK {
		t.Fatalf("want 200, got %d: %s", rec.Code, rec.Body)
	}
	if len(h.sys.created) != 1 || h.sys.created[0] != "acme" {
		t.Fatalf("system was not called: %v", h.sys.created)
	}

	var body map[string]any
	_ = json.Unmarshal(rec.Body.Bytes(), &body)
	if body["ok"] != true {
		t.Fatalf("response should be ok: %s", rec.Body)
	}
}

func TestJournalRecordsEveryAcceptedOperation(t *testing.T) {
	h := newHarness(t)

	h.post(mustJSON(t, mustSign(t, h.key, "system.stats", map[string]any{})))

	entries := h.journalEntries()
	if len(entries) != 1 {
		t.Fatalf("want 1 journal entry, got %d", len(entries))
	}
	if entries[0].Op != "system.stats" || entries[0].Result != "ok" {
		t.Fatalf("unexpected entry: %+v", entries[0])
	}

	if _, err := journal.Verify(h.journal); err != nil {
		t.Fatalf("journal chain broken: %v", err)
	}
}

// ---- refusals ----------------------------------------------------------

func TestForgedSignatureIsRejected(t *testing.T) {
	h := newHarness(t)

	env, _ := envelope.Sign([]byte("ffffffffffffffffffffffffffffffff"), "account.create", validAccount(), "", time.Now())
	rec := h.post(mustJSON(t, env))

	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("a forged envelope must be 401, got %d", rec.Code)
	}
	if len(h.sys.created) != 0 {
		t.Fatal("the operation must not run for a forged request")
	}
}

func TestTamperedArgsAreRejected(t *testing.T) {
	h := newHarness(t)

	env := mustSign(t, h.key, "account.create", validAccount())
	// Swap the payload after signing: the classic bite.
	env.Request.Args = json.RawMessage(`{"username":"root","domain":"x.test","disk_mb":1,"inodes":1000,"php_version":"8.3"}`)

	if rec := h.post(mustJSON(t, env)); rec.Code != http.StatusUnauthorized {
		t.Fatalf("tampered args must be 401, got %d", rec.Code)
	}
	if len(h.sys.created) != 0 {
		t.Fatal("tampered request reached the system")
	}
}

func TestReplayIsRejected(t *testing.T) {
	h := newHarness(t)

	raw := mustJSON(t, mustSign(t, h.key, "system.stats", map[string]any{}))

	if rec := h.post(raw); rec.Code != http.StatusOK {
		t.Fatalf("first send should succeed, got %d", rec.Code)
	}
	if rec := h.post(raw); rec.Code != http.StatusConflict {
		t.Fatalf("replay should be 409, got %d: %s", rec.Code, rec.Body)
	}
	if h.sys.statsHit != 1 {
		t.Fatalf("the operation ran %d times, want 1", h.sys.statsHit)
	}
}

func TestExpiredEnvelopeIsRejected(t *testing.T) {
	h := newHarness(t)

	env := mustSign(t, h.key, "system.stats", map[string]any{})
	env.Request.Iat = time.Now().Add(-10 * time.Minute).Unix()
	env.Request.Exp = time.Now().Add(-9 * time.Minute).Unix()

	if rec := h.post(mustJSON(t, env)); rec.Code != http.StatusUnauthorized {
		t.Fatalf("expired envelope must be 401, got %d", rec.Code)
	}
}

func TestUnknownJSONFieldIsRejected(t *testing.T) {
	h := newHarness(t)

	env := mustSign(t, h.key, "system.stats", map[string]any{})
	raw := mustJSON(t, env)
	raw = append(raw[:len(raw)-1], []byte(`,"extra":"field"}`)...)

	if rec := h.post(raw); rec.Code != http.StatusBadRequest {
		t.Fatalf("unknown field must be 400, got %d", rec.Code)
	}
}

func TestMethodNotAllowed(t *testing.T) {
	h := newHarness(t)

	req := httptest.NewRequest(http.MethodGet, "/dispatch", nil)
	rec := httptest.NewRecorder()
	h.server.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusMethodNotAllowed {
		t.Fatalf("GET /dispatch must be 405, got %d", rec.Code)
	}
}

func TestOversizedBodyIsRejected(t *testing.T) {
	h := newHarness(t)

	huge := strings.Repeat("a", int(1<<20)+10)
	if rec := h.post([]byte(huge)); rec.Code != http.StatusBadRequest {
		t.Fatalf("oversized body must be 400, got %d", rec.Code)
	}
}

func TestUnknownOperationIsRejectedWithoutTouchingTheSystem(t *testing.T) {
	h := newHarness(t)

	rec := h.post(mustJSON(t, mustSign(t, h.key, "shell.exec", map[string]any{"cmd": "rm -rf /"})))

	if rec.Code != http.StatusUnprocessableEntity {
		t.Fatalf("unknown op must be 422, got %d: %s", rec.Code, rec.Body)
	}
	if h.sys.statsHit != 0 {
		t.Fatal("unknown operation reached the system")
	}
}

func TestDenialsAreAudited(t *testing.T) {
	h := newHarness(t)

	h.post(mustJSON(t, mustSign(t, []byte("ffffffffffffffffffffffffffffffff"), "system.stats", map[string]any{})))

	entries := h.journalEntries()
	if len(entries) == 0 {
		t.Fatal("a refused request must still be journaled")
	}
	found := false
	for _, e := range entries {
		if e.Result == "denied" {
			found = true
		}
	}
	if !found {
		t.Fatalf("no denial entry: %+v", entries)
	}
}

func TestHealthIsUnauthenticatedAndCheap(t *testing.T) {
	h := newHarness(t)

	req := httptest.NewRequest(http.MethodGet, "/health", nil)
	rec := httptest.NewRecorder()
	h.server.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusOK || !strings.Contains(rec.Body.String(), `"ok":true`) {
		t.Fatalf("health check broken: %d %s", rec.Code, rec.Body)
	}
}

// ---- peer credential checks -------------------------------------------

func TestUcredReportsTheConnectingProcess(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "peer.sock")

	ln, err := net.Listen("unix", path)
	if err != nil {
		t.Skipf("unix sockets unavailable here: %v", err)
	}
	defer ln.Close()

	type result struct {
		uid uint32
		err error
	}
	done := make(chan result, 1)

	go func() {
		conn, err := ln.Accept()
		if err != nil {
			done <- result{0, err}

			return
		}
		defer conn.Close()

		uc, ok := conn.(*net.UnixConn)
		if !ok {
			done <- result{0, io.ErrUnexpectedEOF}

			return
		}
		uid, err := UcredFromConn(uc)
		done <- result{uid, err}
	}()

	conn, err := net.Dial("unix", path)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()

	select {
	case res := <-done:
		if res.err != nil {
			t.Fatalf("ucred: %v", res.err)
		}
		if res.uid != uint32(os.Getuid()) {
			t.Fatalf("peer uid is %d, want %d", res.uid, os.Getuid())
		}
	case <-time.After(5 * time.Second):
		t.Fatal("timeout reading peer credentials")
	}
}

func TestPeerGuardRejectsUnexpectedUIDs(t *testing.T) {
	h := newHarness(t)

	// A guard configured for a different UID must refuse the test process.
	inner := h.server.Handler()
	guard := (&Server{
		cfg:     Config{AllowedUID: uint32(os.Getuid()) + 1},
		log:     slog.New(slog.NewTextHandler(io.Discard, nil)),
		journal: h.server.journal,
	}).PeerGuard(inner)

	// httptest requests carry no unix peer, so the guard must fail closed
	// rather than trusting an absent credential.
	req := httptest.NewRequest(http.MethodGet, "/health", nil)
	rec := httptest.NewRecorder()
	guard.ServeHTTP(rec, req)

	if rec.Code == http.StatusOK {
		t.Fatal("the guard must not pass a connection it cannot identify")
	}
}

// ---- helpers -----------------------------------------------------------

func mustSign(t *testing.T, key []byte, op string, args any) envelope.Envelope {
	t.Helper()

	env, err := envelope.Sign(key, op, args, "", time.Now())
	if err != nil {
		t.Fatal(err)
	}

	return env
}

func mustJSON(t *testing.T, v any) []byte {
	t.Helper()

	raw, err := json.Marshal(v)
	if err != nil {
		t.Fatal(err)
	}

	return raw
}

// hexNonce guards against a future change that stops randomising nonces.
func TestNoncesAreRandom(t *testing.T) {
	a, err := envelope.NewNonce()
	if err != nil {
		t.Fatal(err)
	}
	b, _ := envelope.NewNonce()

	if a == b {
		t.Fatal("two nonces collided")
	}
	if len(a) < 16 {
		t.Fatalf("nonce is too short: %d", len(a))
	}
	if _, err := hex.DecodeString(a); err != nil {
		t.Fatalf("nonce is not hex: %v", err)
	}
}

// ---- hosting feature set: no-ops ------------------------------------------
//
// The agent server test only exercises the envelope and auth path, so the new
// system methods are inert here. The ops package has its own tests for the
// argument validation.

func (s *testSystem) AttachAliases(context.Context, string, string, []string) error { return nil }
func (s *testSystem) WriteRedirect(context.Context, ops.RedirectSpec) error         { return nil }
func (s *testSystem) RemoveRedirect(context.Context, string, string, string) error  { return nil }
func (s *testSystem) CreateFTPAccount(context.Context, ops.FTPSpec) error           { return nil }
func (s *testSystem) DeleteFTPAccount(context.Context, string) error                { return nil }
func (s *testSystem) SetFTPPassword(context.Context, string, string) error          { return nil }
func (s *testSystem) WriteForwarder(context.Context, ops.ForwarderSpec) error       { return nil }
func (s *testSystem) RemoveForwarder(context.Context, string, string) error         { return nil }
func (s *testSystem) WriteAutoresponder(context.Context, ops.AutoresponderSpec) error {
	return nil
}
func (s *testSystem) RemoveAutoresponder(context.Context, string, string) error { return nil }
func (s *testSystem) WriteCatchall(context.Context, ops.CatchallSpec) error     { return nil }
func (s *testSystem) RemoveCatchall(context.Context, string) error              { return nil }
func (s *testSystem) WriteFilters(context.Context, ops.FilterSpec) error        { return nil }
func (s *testSystem) WriteSpamPolicy(context.Context, ops.SpamSpec) error       { return nil }
func (s *testSystem) EnsureDKIM(context.Context, string) (map[string]any, error) {
	return nil, nil
}
func (s *testSystem) WriteMailingList(context.Context, ops.MailingListSpec) error { return nil }
func (s *testSystem) GenerateCSR(context.Context, string, string) (ops.CSRResult, error) {
	return ops.CSRResult{}, nil
}
func (s *testSystem) InstallCertificate(context.Context, ops.InstallCertSpec) (ops.CertResult, error) {
	return ops.CertResult{}, nil
}
func (s *testSystem) CertificateInfo(context.Context, string) (ops.CertInfo, error) {
	return ops.CertInfo{}, nil
}
func (s *testSystem) WriteIPBlock(context.Context, ops.IPBlockSpec) error  { return nil }
func (s *testSystem) RemoveIPBlock(context.Context, ops.IPBlockSpec) error { return nil }
func (s *testSystem) WriteDirProtection(context.Context, ops.DirProtectSpec) error {
	return nil
}
func (s *testSystem) RemoveDirProtection(context.Context, string, string) error { return nil }
func (s *testSystem) WriteMime(context.Context, ops.MimeSpec) error             { return nil }
func (s *testSystem) RemoveMime(context.Context, string, string) error          { return nil }
func (s *testSystem) WriteErrorPage(context.Context, ops.ErrorPageSpec) error   { return nil }
func (s *testSystem) WritePHPSettings(context.Context, ops.PHPSettingsSpec) error {
	return nil
}
func (s *testSystem) ListPHPExtensions(context.Context, string) ([]ops.PHPExtension, error) {
	return nil, nil
}
func (s *testSystem) InstallApp(context.Context, ops.AppInstallSpec) (map[string]any, error) {
	return nil, nil
}
func (s *testSystem) RemoveApp(context.Context, string, string) error { return nil }
func (s *testSystem) AppInfo(context.Context, string, string) (ops.AppInfo, error) {
	return ops.AppInfo{}, nil
}
func (s *testSystem) CreateGitRepo(context.Context, ops.GitSpec) error { return nil }
func (s *testSystem) PullGitRepo(context.Context, string, string) (map[string]any, error) {
	return nil, nil
}
func (s *testSystem) CreateDatabaseUser(context.Context, ops.DBUserSpec) error { return nil }
func (s *testSystem) DropDatabaseUser(context.Context, string) error           { return nil }
func (s *testSystem) SetDatabaseUserPassword(context.Context, string, string) error {
	return nil
}
func (s *testSystem) SetRemoteMySQL(context.Context, ops.RemoteMySQLSpec) error { return nil }
func (s *testSystem) BandwidthReport(context.Context, string, int) (ops.BandwidthReport, error) {
	return ops.BandwidthReport{}, nil
}
func (s *testSystem) DiskUsage(context.Context, string, string) (ops.DiskUsage, error) {
	return ops.DiskUsage{}, nil
}
func (s *testSystem) AuthLog(context.Context, int) ([]ops.AuthLogEntry, error) { return nil, nil }

// ---- methods present on the widened System interface ---------------------

func (s *testSystem) CreateSubdomain(context.Context, ops.SubdomainSpec) error { return nil }

func (s *testSystem) RemoveDomain(context.Context, string, string) error { return nil }

func (s *testSystem) SetVhostPHP(context.Context, ops.VhostPhpSpec) error { return nil }

func (s *testSystem) RemoveMailingList(context.Context, string, string) error { return nil }

// ---- file manager and ssh ------------------------------------------------

func (s *testSystem) WriteUpload(_ context.Context, spec ops.FileUploadSpec, body []byte) (ops.FileUploadResult, error) {
	return ops.FileUploadResult{Name: spec.Path, Path: spec.Path, Bytes: len(body)}, nil
}

func (s *testSystem) ReadDownload(_ context.Context, spec ops.FileDownloadSpec) (ops.FileDownloadResult, error) {
	return ops.FileDownloadResult{Content: "", Bytes: 2, MimeType: "text/plain"}, nil
}

func (s *testSystem) CopyPath(_ context.Context, spec ops.FileCopySpec) (ops.FileCopyResult, error) {
	return ops.FileCopyResult{From: spec.From, To: spec.To, Copied: 1}, nil
}

func (s *testSystem) ChmodPath(_ context.Context, spec ops.FileChmodSpec) (ops.FileChmodResult, error) {
	return ops.FileChmodResult{Path: spec.Path, Mode: spec.Mode, Changed: 1}, nil
}

func (s *testSystem) CompressPaths(_ context.Context, spec ops.FileCompressSpec) (ops.FileCompressResult, error) {
	return ops.FileCompressResult{Archive: spec.Archive, Files: len(spec.Paths)}, nil
}

func (s *testSystem) ExtractArchive(_ context.Context, spec ops.FileExtractSpec) (ops.FileExtractResult, error) {
	return ops.FileExtractResult{Files: 1}, nil
}

func (s *testSystem) SearchFiles(_ context.Context, spec ops.FileSearchSpec) (ops.FileSearchResult, error) {
	return ops.FileSearchResult{Hits: []ops.FileHit{}}, nil
}

func (s *testSystem) StatPaths(_ context.Context, spec ops.FileStatSpec) (ops.FileStatResult, error) {
	return ops.FileStatResult{Sizes: []ops.FileSize{}}, nil
}

func (s *testSystem) TrashPath(_ context.Context, spec ops.FileTrashSpec) (ops.FileTrashResult, error) {
	return ops.FileTrashResult{Name: spec.Path, Path: spec.Path}, nil
}

func (s *testSystem) RestorePath(_ context.Context, spec ops.FileRestoreSpec) (ops.FileRestoreResult, error) {
	return ops.FileRestoreResult{Restored: spec.Path}, nil
}

func (s *testSystem) ListTrash(_ context.Context, username string) (ops.FileTrashListResult, error) {
	return ops.FileTrashListResult{Entries: []ops.TrashEntry{}}, nil
}

func (s *testSystem) GenerateSSHKey(_ context.Context, spec ops.SSHKeySpec) (ops.SSHKeyInfo, error) {
	return ops.SSHKeyInfo{Name: spec.Name, Type: spec.Type, HasPrivate: true}, nil
}

func (s *testSystem) ImportSSHKey(_ context.Context, spec ops.SSHKeySpec) (ops.SSHKeyInfo, error) {
	return ops.SSHKeyInfo{Name: spec.Name, Public: spec.Public}, nil
}

func (s *testSystem) ListSSHKeys(_ context.Context, username string) (ops.SSHKeyListResult, error) {
	return ops.SSHKeyListResult{Keys: []ops.SSHKeyInfo{}}, nil
}

func (s *testSystem) AuthorizeSSHKey(_ context.Context, username, name string) (any, error) {
	return map[string]any{"name": name, "authorized": true}, nil
}

func (s *testSystem) DeauthorizeSSHKey(_ context.Context, username, name string) (any, error) {
	return map[string]any{"name": name, "authorized": false}, nil
}

func (s *testSystem) DeleteSSHKey(_ context.Context, username, name string) (any, error) {
	return map[string]any{"name": name, "deleted": true}, nil
}

func (s *testSystem) SetSSHAccess(_ context.Context, spec ops.SSHAccessSpec) (ops.SSHAccessResult, error) {
	return ops.SSHAccessResult{Username: spec.Username, Enabled: spec.Enabled}, nil
}

func (s *testSystem) SSHInfo(_ context.Context) (ops.SSHInfo, error) {
	return ops.SSHInfo{Enabled: true, Port: 22}, nil
}
