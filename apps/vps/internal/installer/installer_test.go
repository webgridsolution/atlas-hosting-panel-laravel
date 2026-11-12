package installer

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// ---- fakes ---------------------------------------------------------------

type fakeExec struct {
	commands []string
	failOn   string
	outputs  map[string]string
}

func (f *fakeExec) Run(_ context.Context, name string, args ...string) (Result, error) {
	line := strings.Join(append([]string{name}, args...), " ")
	f.commands = append(f.commands, line)

	if f.failOn != "" && strings.HasPrefix(line, f.failOn) {
		return Result{Code: 1, Stderr: "boom"}, errors.New("command failed")
	}

	return Result{Stdout: f.outputs[line]}, nil
}

func (f *fakeExec) ran(substr string) bool {
	for _, c := range f.commands {
		if strings.Contains(c, substr) {
			return true
		}
	}

	return false
}

type fakeFS struct {
	files map[string]string
	links map[string]string
	dirs  map[string][]os.DirEntry
}

func newFakeFS() *fakeFS {
	return &fakeFS{files: map[string]string{}, links: map[string]string{}, dirs: map[string][]os.DirEntry{}}
}

func (f *fakeFS) ReadFile(p string) ([]byte, error) {
	if s, ok := f.files[p]; ok {
		return []byte(s), nil
	}

	return nil, os.ErrNotExist
}
func (f *fakeFS) WriteFile(p string, data []byte, _ os.FileMode) error {
	f.files[p] = string(data)

	return nil
}
func (f *fakeFS) Exists(p string) bool {
	if _, ok := f.files[p]; ok {
		return true
	}
	if _, ok := f.links[p]; ok {
		return true
	}

	// A directory exists if anything inside it does. The real filesystem behaves
	// the same way for the checks the installer performs.
	for path := range f.files {
		if strings.HasPrefix(path, p+"/") {
			return true
		}
	}

	return false
}

// Compile-time guard: the fakes must satisfy the same interfaces as the real
// implementations, or the tests would pass while the installer fails.
var (
	_ FS    = (*fakeFS)(nil)
	_ FS    = RealFS{}
	_ Exec  = (*fakeExec)(nil)
	_ Exec  = RealExec{}
	_ Probe = fakeProbe{}
	_ Probe = LinuxProbe{}
)

func (f *fakeFS) MkdirAll(p string, _ os.FileMode) error {
	f.dirs[p] = f.dirs[p]

	return nil
}
func (f *fakeFS) Remove(p string) error                   { delete(f.files, p); return nil }
func (f *fakeFS) Symlink(target, link string) error       { f.links[link] = target; return nil }
func (f *fakeFS) Readlink(link string) (string, error)    { return f.links[link], nil }
func (f *fakeFS) ReadDir(p string) ([]os.DirEntry, error) { return f.dirs[p], nil }
func (f *fakeFS) CopyFile(src, dst string, _ os.FileMode) error {
	if s, ok := f.files[src]; ok {
		f.files[dst] = s

		return nil
	}

	return os.ErrNotExist
}

type fakeProbe struct {
	osInfo OSInfo
	ram    int
	host   string
	busy   map[int]bool
}

func (p fakeProbe) OS() (OSInfo, error) { return p.osInfo, nil }
func (p fakeProbe) Arch() string        { return "amd64" }
func (p fakeProbe) Hostname() string    { return p.host }
func (p fakeProbe) TotalRAMMB() (int, error) {
	return p.ram, nil
}
func (p fakeProbe) CPUs() int    { return 4 }
func (p fakeProbe) IsRoot() bool { return true }
func (p fakeProbe) PortFree(port int) bool {
	if p.busy != nil {
		return !p.busy[port]
	}

	return true
}

func goodProbe() fakeProbe {
	return fakeProbe{
		osInfo: OSInfo{ID: "ubuntu", VersionID: "24.04", PrettyName: "Ubuntu 24.04.3 LTS", Codename: "noble"},
		ram:    4096,
		host:   "panel.example.com",
	}
}

// ---- tests ---------------------------------------------------------------

func TestPlanCoversTheDocumentedStepsInOrder(t *testing.T) {
	plan := Plan()

	want := []string{"preflight", "license", "packages", "panel_user", "database", "ssl", "firewall", "agent", "panel", "healthcheck"}

	if len(plan) != len(want) {
		t.Fatalf("plan has %d steps, want %d", len(plan), len(want))
	}

	for i, name := range want {
		if plan[i].Name != name {
			t.Fatalf("step %d is %q, want %q", i, plan[i].Name, name)
		}
		if plan[i].Describe == "" {
			t.Errorf("step %q has no description", name)
		}
	}
}

func TestPreflightAcceptsUbuntu2404(t *testing.T) {
	findings := Preflight(goodProbe(), DefaultOptions())

	if Fatal(findings) {
		t.Fatalf("unexpected fatal finding: %+v", findings)
	}
}

func TestPreflightRefusesAnUnsupportedOS(t *testing.T) {
	for _, os := range []OSInfo{
		{ID: "debian", VersionID: "12", PrettyName: "Debian 12"},
		{ID: "ubuntu", VersionID: "20.04", PrettyName: "Ubuntu 20.04"},
		{ID: "ubuntu", VersionID: "25.10", PrettyName: "Ubuntu 25.10"},
		{ID: "centos", VersionID: "9", PrettyName: "CentOS Stream 9"},
	} {
		p := goodProbe()
		p.osInfo = os

		findings := Preflight(p, DefaultOptions())
		if !Fatal(findings) {
			t.Errorf("%s should be refused, findings: %+v", os.PrettyName, findings)
		}
	}
}

func TestPreflightAcceptsUbuntu2204(t *testing.T) {
	p := goodProbe()
	p.osInfo = OSInfo{ID: "ubuntu", VersionID: "22.04", PrettyName: "Ubuntu 22.04.5 LTS"}

	if Fatal(Preflight(p, DefaultOptions())) {
		t.Fatal("22.04 is a supported target")
	}
}

func TestPreflightRefusesLowMemory(t *testing.T) {
	p := goodProbe()
	p.ram = 1024

	if !Fatal(Preflight(p, DefaultOptions())) {
		t.Fatal("1 GB RAM must be refused for a panel with MySQL and PHP-FPM")
	}
}

func TestPreflightWarnsWhenUnlicensedButDoesNotStop(t *testing.T) {
	findings := Preflight(goodProbe(), DefaultOptions())

	var warned bool

	for _, f := range findings {
		if f.Level == "warn" && strings.Contains(f.Message, "unlicensed") {
			warned = true
		}
	}

	if !warned {
		t.Fatal("a missing licence should warn: the panel would install but be unable to provision")
	}
	if Fatal(findings) {
		t.Fatal("a missing licence must not stop the install")
	}
}

func TestDryRunMentionsPortsAndNothingIsExecuted(t *testing.T) {
	opts := DefaultOptions()
	opts.Payload = "/tmp/release"
	opts.LicenseKey = "ATLS-AAAA-BBBB-CCCC"

	out := DryRun(Plan(), opts)

	for _, want := range []string{"2087", "2083", "2096", "/tmp/release", "ATLS-****-CCCC", "dry run"} {
		if !strings.Contains(out, want) {
			t.Errorf("dry run output is missing %q", want)
		}
	}

	if strings.Contains(out, "BBBB") {
		t.Error("the dry run must not print the middle of a licence key")
	}
}

func TestStateResumesFromTheFirstIncompleteStep(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "state.json")

	s, err := LoadState(path, "0.9.0")
	if err != nil {
		t.Fatal(err)
	}

	plan := Plan()

	if got := s.NextStep(plan); got != 0 {
		t.Fatalf("a fresh session should start at 0, got %d", got)
	}

	for _, name := range []string{"preflight", "license"} {
		if err := s.Mark(name); err != nil {
			t.Fatal(err)
		}
	}

	// A second process must see the same progress.
	again, err := LoadState(path, "0.9.0")
	if err != nil {
		t.Fatal(err)
	}

	if got := again.NextStep(plan); got != 2 {
		t.Fatalf("resume should start at 2, got %d", got)
	}
}

func TestCorruptStateIsAnErrorNotAFreshStart(t *testing.T) {
	path := filepath.Join(t.TempDir(), "state.json")

	if err := os.WriteFile(path, []byte("{not json"), 0o600); err != nil {
		t.Fatal(err)
	}

	if _, err := LoadState(path, "0.9.0"); err == nil {
		t.Fatal("a corrupt state file must be reported, not silently ignored")
	}
}

func TestRunStopsAtTheFailingStepAndRecordsIt(t *testing.T) {
	state, err := LoadState(filepath.Join(t.TempDir(), "state.json"), "0.9.0")
	if err != nil {
		t.Fatal(err)
	}

	exec := &fakeExec{}
	env := &Env{
		Opts:   DefaultOptions(),
		Exec:   exec,
		FS:     newFakeFS(),
		Probe:  goodProbe(),
		State:  state,
		Stdout: func(string, ...any) {},
	}

	plan := []Step{
		{Name: "one", Describe: "first", Run: func(*RunContext) error { return nil }},
		{Name: "two", Describe: "second", Run: func(*RunContext) error { return errors.New("nope") }},
		{Name: "three", Describe: "third", Run: func(*RunContext) error { return nil }},
	}

	if err := Run(context.Background(), env, plan, false); err == nil {
		t.Fatal("a failing step must fail the run")
	}

	if state.Failed != "two" {
		t.Fatalf("the failed step was not recorded: %q", state.Failed)
	}

	if _, done := state.Completed["three"]; done {
		t.Fatal("later steps must not run after a failure")
	}
}

func TestRunSkipsCompletedStepsUnlessForced(t *testing.T) {
	state, _ := LoadState(filepath.Join(t.TempDir(), "state.json"), "0.9.0")
	_ = state.Mark("one")

	var ran []string

	plan := []Step{
		{Name: "one", Describe: "first", Run: func(*RunContext) error { ran = append(ran, "one"); return nil }},
		{Name: "two", Describe: "second", Run: func(*RunContext) error { ran = append(ran, "two"); return nil }},
	}

	env := &Env{Opts: DefaultOptions(), Exec: &fakeExec{}, FS: newFakeFS(), Probe: goodProbe(), State: state, Stdout: func(string, ...any) {}}

	if err := Run(context.Background(), env, plan, false); err != nil {
		t.Fatal(err)
	}

	if strings.Join(ran, ",") != "two" {
		t.Fatalf("resume should only run the incomplete step, ran: %v", ran)
	}

	ran = nil

	if err := Run(context.Background(), env, plan, true); err != nil {
		t.Fatal(err)
	}

	if strings.Join(ran, ",") != "one,two" {
		t.Fatalf("--force should re-run everything, ran: %v", ran)
	}
}

func TestLicenseStepStoresTheTokenTheVendorSigned(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/license/activate" {
			t.Errorf("installer called the wrong endpoint: %s", r.URL.Path)
		}

		var body map[string]any
		_ = json.NewDecoder(r.Body).Decode(&body)

		for _, key := range []string{"key", "fingerprint", "hostname", "os", "version"} {
			if _, ok := body[key]; !ok {
				t.Errorf("activation payload is missing %q", key)
			}
		}

		_ = json.NewEncoder(w).Encode(map[string]string{"token": "atlas-lic-v1.payload.sig"})
	}))
	defer srv.Close()

	opts := DefaultOptions()
	opts.LicenseKey = "ATLS-AAAA-BBBB-CCCC"
	opts.CentralURL = srv.URL
	opts.Payload = "/payload"

	fs := newFakeFS()
	// The fingerprint is read from the machine-id file the panel also uses.
	_ = fs.WriteFile("/etc/machine-id", []byte("0123456789abcdef"), 0o644)

	env := &Env{Opts: opts, Exec: &fakeExec{}, FS: fs, Probe: goodProbe(), State: mustState(t), Stdout: func(string, ...any) {}}

	if err := stepLicense(&RunContext{Env: env, Ctx: context.Background()}); err != nil {
		t.Fatal(err)
	}

	if fs.files["/etc/atlas/licence.token"] != "atlas-lic-v1.payload.sig" {
		t.Fatalf("token not stored, files: %v", fs.files)
	}
}

func TestLicenseStepReportsARefusalAndStoresNothing(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusConflict)
		_ = json.NewEncoder(w).Encode(map[string]any{
			"error": map[string]string{"message": "This key is already active on another server."},
		})
	}))
	defer srv.Close()

	opts := DefaultOptions()
	opts.LicenseKey = "ATLS-AAAA-BBBB-CCCC"
	opts.CentralURL = srv.URL

	fs := newFakeFS()
	_ = fs.WriteFile("/etc/machine-id", []byte("0123456789abcdef"), 0o644)

	env := &Env{Opts: opts, Exec: &fakeExec{}, FS: fs, Probe: goodProbe(), State: mustState(t), Stdout: func(string, ...any) {}}

	err := stepLicense(&RunContext{Env: env, Ctx: context.Background()})
	if err == nil || !strings.Contains(err.Error(), "another server") {
		t.Fatalf("the vendor's refusal must reach the operator, got %v", err)
	}

	if _, stored := fs.files["/etc/atlas/licence.token"]; stored {
		t.Fatal("a refused key must not leave a token behind")
	}
}

func TestLicenseStepWithoutAKeyIsANoOp(t *testing.T) {
	fs := newFakeFS()
	env := &Env{Opts: DefaultOptions(), Exec: &fakeExec{}, FS: fs, Probe: goodProbe(), State: mustState(t), Stdout: func(string, ...any) {}}

	if err := stepLicense(&RunContext{Env: env, Ctx: context.Background()}); err != nil {
		t.Fatal(err)
	}

	if len(fs.files) != 0 {
		t.Fatalf("nothing should be written without a key: %v", fs.files)
	}
}

func TestTemplateRefusesToOverwriteAnUnmanagedFile(t *testing.T) {
	fs := newFakeFS()
	_ = fs.WriteFile("/payload/nginx/x.conf.tmpl", []byte("# managed by atlas\nlisten {{ADMIN_PORT}};"), 0o644)
	_ = fs.WriteFile("/etc/nginx/sites-available/x.conf", []byte("# someone else's site"), 0o644)

	env := &Env{Opts: DefaultOptions(), Exec: &fakeExec{}, FS: fs, Probe: goodProbe(), State: mustState(t), Stdout: func(string, ...any) {}}

	err := installTemplate(&RunContext{Env: env, Ctx: context.Background()},
		"/payload/nginx/x.conf.tmpl", "/etc/nginx/sites-available/x.conf", portMap(DefaultOptions()))
	if err == nil {
		t.Fatal("an unmanaged config must never be overwritten")
	}

	if fs.files["/etc/nginx/sites-available/x.conf"] != "# someone else's site" {
		t.Fatal("the existing file was modified")
	}
}

func TestTemplateRendersPortsAndRefusesLeftovers(t *testing.T) {
	fs := newFakeFS()
	_ = fs.WriteFile("/p/a.tmpl", []byte("# managed by atlas\nlisten {{ADMIN_PORT}} ssl;"), 0o644)

	opts := DefaultOptions()
	opts.AdminPort = 9443

	env := &Env{Opts: opts, Exec: &fakeExec{}, FS: fs, Probe: goodProbe(), State: mustState(t), Stdout: func(string, ...any) {}}

	if err := installTemplate(&RunContext{Env: env, Ctx: context.Background()}, "/p/a.tmpl", "/etc/a.conf", portMap(opts)); err != nil {
		t.Fatal(err)
	}

	if !strings.Contains(fs.files["/etc/a.conf"], "listen 9443") {
		t.Fatalf("port was not rendered: %s", fs.files["/etc/a.conf"])
	}

	_ = fs.WriteFile("/p/b.tmpl", []byte("# managed by atlas\n{{UNKNOWN}}"), 0o644)

	if err := installTemplate(&RunContext{Env: env, Ctx: context.Background()}, "/p/b.tmpl", "/etc/b.conf", portMap(opts)); err == nil {
		t.Fatal("an unrendered placeholder must stop the install")
	}
}

func TestRollbackMovesToThePreviousRelease(t *testing.T) {
	fs := newFakeFS()
	fs.dirs[ReleaseRoot] = []os.DirEntry{
		fakeDir{"atlas-20260101-000000"},
		fakeDir{"atlas-20260202-000000"},
		fakeDir{"atlas-20260303-000000"},
	}
	fs.links[CurrentLink] = filepath.Join(ReleaseRoot, "atlas-20260303-000000")

	name, err := Rollback(fs, ReleaseRoot, CurrentLink)
	if err != nil {
		t.Fatal(err)
	}

	if name != "atlas-20260202-000000" {
		t.Fatalf("rolled back to %q, want the previous release", name)
	}

	if fs.links[CurrentLink] != filepath.Join(ReleaseRoot, name) {
		t.Fatal("the current symlink was not moved")
	}
}

func TestRollbackRefusesWhenThereIsNothingToGoBackTo(t *testing.T) {
	fs := newFakeFS()
	fs.dirs[ReleaseRoot] = []os.DirEntry{fakeDir{"atlas-20260101-000000"}}
	fs.links[CurrentLink] = filepath.Join(ReleaseRoot, "atlas-20260101-000000")

	if _, err := Rollback(fs, ReleaseRoot, CurrentLink); err == nil {
		t.Fatal("rolling back the only release must fail with a clear message")
	}
}

func TestUninstallKeepsCustomerDataUnlessAsked(t *testing.T) {
	fs := newFakeFS()
	_ = fs.WriteFile("/etc/nginx/sites-available/atlas-whm.conf", []byte("# managed by atlas"), 0o644)

	exec := &fakeExec{}

	if err := Uninstall(context.Background(), exec, fs, false); err != nil {
		t.Fatal(err)
	}

	if exec.ran("DROP DATABASE") {
		t.Fatal("uninstall must not drop databases without --delete-data")
	}

	if fs.Exists("/var/backups/atlas") {
		t.Fatal("customer backups must survive an uninstall")
	}

	// With data deletion, the panel database goes but customer files do not.
	if err := Uninstall(context.Background(), exec, fs, true); err != nil {
		t.Fatal(err)
	}

	if !exec.ran("DROP DATABASE IF EXISTS `atlas_panel`") {
		t.Fatal("--delete-data should drop the panel database")
	}

	if exec.ran("/home/") {
		t.Fatal("uninstall must never touch customer home directories")
	}
}

// ---- helpers -------------------------------------------------------------

func mustState(t *testing.T) *Session {
	t.Helper()

	s, err := LoadState(filepath.Join(t.TempDir(), "state.json"), "0.9.0")
	if err != nil {
		t.Fatal(err)
	}

	return s
}

type fakeDir struct{ name string }

func (d fakeDir) Name() string               { return d.name }
func (d fakeDir) IsDir() bool                { return true }
func (d fakeDir) Type() os.FileMode          { return os.ModeDir }
func (d fakeDir) Info() (os.FileInfo, error) { return nil, nil }
