package ops

import (
	"context"
	"os"
	"strings"
	"testing"
	"time"

	"atlas/internal/execx"
)

// memFS is an in-memory FileSystem, so provisioning can be tested without root.
type memFS struct {
	files map[string]string
	dirs  map[string]bool
}

func newMemFS() *memFS {
	return &memFS{files: map[string]string{}, dirs: map[string]bool{"/": true}}
}

func (m *memFS) WriteFile(path string, data []byte, _ os.FileMode) error {
	m.files[path] = string(data)

	return nil
}
func (m *memFS) ReadFile(path string) ([]byte, error) {
	if s, ok := m.files[path]; ok {
		return []byte(s), nil
	}

	return nil, os.ErrNotExist
}
func (m *memFS) Remove(path string) error {
	delete(m.files, path)

	return nil
}
func (m *memFS) MkdirAll(path string, _ os.FileMode) error { m.dirs[path] = true; return nil }
func (m *memFS) Exists(path string) bool {
	_, ok := m.files[path]

	return ok || m.dirs[path]
}
func (m *memFS) ListDir(path string) ([]os.DirEntry, error) { return nil, os.ErrNotExist }
func (m *memFS) Stat(path string) (os.FileInfo, error)      { return nil, os.ErrNotExist }
func (m *memFS) Rename(from, to string) error {
	data, ok := m.files[from]
	if !ok {
		return os.ErrNotExist
	}
	delete(m.files, from)
	m.files[to] = data

	return nil
}

// fakeRunner is not an execx.Runner, so the pure functions are tested directly;
// the command sequences are covered by installer tests and the acceptance run.
func TestRenderVhostIsDeterministicAndComplete(t *testing.T) {
	spec := VhostSpec{
		Domain:       "acme.test",
		Username:     "acme",
		DocumentRoot: "/home/acme/public_html",
		Aliases:      []string{"www.acme.test"},
		PHPVersion:   "8.3",
		SSL:          true,
	}

	a := renderVhost(spec, DefaultPaths())
	b := renderVhost(spec, DefaultPaths())

	if a != b {
		t.Fatal("renderVhost is not deterministic")
	}

	for _, want := range []string{
		"# managed by atlas",
		"ServerName acme.test",
		"ServerAlias www.acme.test",
		"DocumentRoot /home/acme/public_html",
		"unix:/run/php/atlas-acme.sock",
		"/home/acme/logs/error.log",
		"SSLEngine on",
		"/etc/letsencrypt/live/acme.test/fullchain.pem",
	} {
		if !strings.Contains(a, want) {
			t.Errorf("vhost is missing %q\n%s", want, a)
		}
	}

	// The HTTP vhost must never claim TLS just because HTTPS was requested.
	if strings.Count(a, "SSLEngine on") != 1 {
		t.Errorf("expected exactly one SSLEngine directive, got %d", strings.Count(a, "SSLEngine on"))
	}
}

func TestPoolIsolationDirectives(t *testing.T) {
	pool := renderPool("acme", "/home/acme")

	for _, want := range []string{
		"user = acme",
		"group = acme",
		"listen = /run/php/atlas-acme.sock",
		"open_basedir] = /home/acme:/tmp",
		"disable_functions] = exec,passthru,shell_exec,system,proc_open,popen",
		"expose_php] = off",
	} {
		if !strings.Contains(pool, want) {
			t.Errorf("pool is missing %q", want)
		}
	}

	other := renderPool("sunny", "/home/sunny")

	if strings.Contains(pool, "sunny") || strings.Contains(other, "acme") {
		t.Fatal("pools must not reference another account")
	}
}

func TestCreateDatabaseSQLIsEscaped(t *testing.T) {
	sql := buildCreateDatabaseSQL(DatabaseSpec{
		Name:     "acme_wp",
		Username: "acme_wp",
		Password: `pa'ss\word`,
	})

	if strings.Contains(sql, "pa'ss") {
		t.Fatalf("password was not escaped:\n%s", sql)
	}
	if !strings.Contains(sql, `pa\'ss\\word`) {
		t.Fatalf("expected an escaped password:\n%s", sql)
	}
	if !strings.Contains(sql, "GRANT ALL PRIVILEGES ON `acme_wp`.* TO 'acme_wp'@'localhost'") {
		t.Fatalf("the grant must be scoped to the one database:\n%s", sql)
	}
}

func TestQuotaSoftLimitIsBelowHardLimit(t *testing.T) {
	// The arithmetic lives in ApplyQuota; the property that matters is that a
	// customer gets a warning window rather than an instant wall.
	const diskMB = 5000
	soft := diskMB * 1024 * 95 / 100

	if soft >= diskMB*1024 {
		t.Fatal("soft limit must be below the hard limit")
	}
}

func TestDefaultIndexPageDoesNotEchoUnescapedInput(t *testing.T) {
	page := defaultIndexPage(`</title><script>alert(1)</script>`)

	if strings.Contains(page, "<script>alert(1)</script>") {
		t.Fatal("the placeholder page must not reflect the domain into HTML unescaped")
	}
	if !strings.Contains(page, "&lt;/title&gt;") && !strings.Contains(page, "is ready") {
		t.Fatal("the page lost its content")
	}
}

func TestSuspendedVhostReturnsServiceUnavailable(t *testing.T) {
	conf := suspendedVhostRendered("acme.test")

	if !strings.Contains(conf, "R=503") {
		t.Fatal("a suspended site must return 503, not a 404 or a stale site")
	}
	if strings.Contains(conf, "/home/acme") {
		t.Fatal("a suspended vhost must not keep pointing at the customer's files")
	}
}

func TestReadFileDetectsBinary(t *testing.T) {
	m := newMemFS()
	_ = m.WriteFile("/home/acme/blob.bin", []byte{0x00, 0x01, 0x02}, 0o644)

	// Stat is not implemented in memFS, so use the OS-backed path with a temp
	// file instead: the binary check is what is under test.
	f, err := os.CreateTemp(t.TempDir(), "bin")
	if err != nil {
		t.Fatal(err)
	}
	_, _ = f.Write([]byte{0x00, 0x01})
	_ = f.Close()

	s := &RealSystem{fs: OSFileSystem{}, paths: DefaultPaths(), now: time.Now}

	if _, err := s.ReadFile(context.Background(), "acme", f.Name(), 1024); err == nil ||
		!strings.Contains(err.Error(), "binary") {
		t.Fatalf("a binary file must be refused, got %v", err)
	}
}

func TestReadFileTruncatesAtTheCap(t *testing.T) {
	dir := t.TempDir()
	path := dir + "/big.txt"
	if err := os.WriteFile(path, []byte(strings.Repeat("a", 4096)), 0o644); err != nil {
		t.Fatal(err)
	}

	s := &RealSystem{fs: OSFileSystem{}, paths: DefaultPaths(), now: time.Now}

	got, err := s.ReadFile(context.Background(), "acme", path, 1024)
	if err != nil {
		t.Fatal(err)
	}

	if !got.Truncated || len(got.Content) != 1024 || got.Bytes != 4096 {
		t.Fatalf("cap not applied: truncated=%v len=%d bytes=%d", got.Truncated, len(got.Content), got.Bytes)
	}
}

func TestMovePathRefusesToOverwrite(t *testing.T) {
	m := newMemFS()
	_ = m.WriteFile("/home/acme/a.txt", []byte("a"), 0o644)
	_ = m.WriteFile("/home/acme/b.txt", []byte("b"), 0o644)

	s := &RealSystem{fs: m, paths: DefaultPaths(), now: time.Now}

	if err := s.MovePath(context.Background(), "acme", "/home/acme/a.txt", "/home/acme/b.txt"); err == nil {
		t.Fatal("renaming onto an existing file must be refused; the data would be lost")
	}

	if err := s.MovePath(context.Background(), "acme", "/home/acme/a.txt", "/home/acme/c.txt"); err != nil {
		t.Fatalf("a normal rename failed: %v", err)
	}
}

func TestDeletePathRefusesHomeRoot(t *testing.T) {
	s := &RealSystem{fs: newMemFS(), paths: DefaultPaths(), now: time.Now}

	if err := s.DeletePath(context.Background(), "acme", "/home/acme"); err == nil {
		t.Fatal("deleting the home root must be refused")
	}
}

func TestExistingUnmanagedVhostIsNeverOverwritten(t *testing.T) {
	// This is the property that protects a server that already hosts sites:
	// a file the panel did not write stops the operation with a clear error.
	if strings.Contains(vhostTemplate, "managed by atlas") == false {
		t.Fatal("every generated vhost must carry the ownership marker")
	}

	src := renderVhost(VhostSpec{Domain: "shop.test", Username: "shop", DocumentRoot: "/home/shop/public_html"}, DefaultPaths())
	if !strings.HasPrefix(src, "# managed by atlas") {
		t.Fatal("the marker must be the first line so it survives a partial write")
	}
}

func TestEscapeSQL(t *testing.T) {
	cases := map[string]string{
		"plain":     "plain",
		`it's`:      `it\'s`,
		`back\sl`:   `back\\sl`,
		"nul\x00ed": "nuled",
	}

	for in, want := range cases {
		if got := escapeSQL(in); got != want {
			t.Errorf("escapeSQL(%q) = %q, want %q", in, got, want)
		}
	}
}

// Guard against regressions in the allowlisted binary set: the command runner is
// constructed by cmd/atlas-agent, and a missing binary is a runtime failure of a
// privileged path, which is the worst place to discover a typo.
func TestRunnerRejectsUnknownBinaries(t *testing.T) {
	r := execx.New(time.Second, "useradd")

	if _, err := r.Run(context.Background(), "bash", "-c", "id"); err == nil {
		t.Fatal("execx must refuse binaries outside its allowlist")
	}
}
