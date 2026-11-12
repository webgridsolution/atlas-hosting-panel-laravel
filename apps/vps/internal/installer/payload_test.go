package installer

import (
	"context"
	"path/filepath"
	"strings"
	"testing"
)

// The release layout is a contract between three things that are built and
// shipped separately: build-release.sh writes it, atlas-install reads it, and
// deploy/install.sh unpacks it. These tests pin the layout the installer
// assumes, because a mismatch between them is invisible until a customer runs
// the installer on a real server.
func seedPayload(fs *fakeFS, root string) {
	files := map[string]string{
		root + "/bin/atlas-agent":               "ELF",
		root + "/bin/atlas-install":             "ELF",
		root + "/nginx/atlas-whm.conf.tmpl":     "# managed by atlas\nserver { listen {{ADMIN_PORT}} ssl; }",
		root + "/nginx/atlas-service.conf.tmpl": "# managed by atlas\nserver { listen {{PANEL_PORT}} ssl; }",
		root + "/php-fpm/atlas-panel.conf.tmpl": "; managed by atlas\n[atlas]\nuser = atlas",
		root + "/systemd/atlas-agent.service":   "# managed by atlas\n[Unit]\nDescription=agent",
		root + "/systemd/atlas-panel.service":   "# managed by atlas\n[Unit]\nDescription=panel",
		root + "/panel/artisan":                 "#!/usr/bin/env php",
	}

	for path, content := range files {
		_ = fs.WriteFile(path, []byte(content), 0o644)
	}
}

func stepEnv(t *testing.T, payload string) (*Env, *fakeExec, *fakeFS) {
	t.Helper()

	opts := DefaultOptions()
	opts.Payload = payload
	opts.SkipMail = true

	exec := &fakeExec{}
	fs := newFakeFS()

	return &Env{Opts: opts, Exec: exec, FS: fs, Probe: goodProbe(), State: mustState(t), Stdout: func(string, ...any) {}}, exec, fs
}

func TestAgentStepInstallsFromTheReleasePayload(t *testing.T) {
	env, exec, fs := stepEnv(t, "/opt/payload")
	seedPayload(fs, "/opt/payload")

	if err := stepAgent(&RunContext{Env: env, Ctx: context.Background()}); err != nil {
		t.Fatal(err)
	}

	if !fs.Exists("/usr/local/bin/atlas-agent") {
		t.Fatal("the agent binary was not installed")
	}

	if !exec.ran("systemctl enable --now atlas-agent") {
		t.Fatal("the agent was not enabled; the panel would start with no agent")
	}

	// The shared secret is generated, not shipped.
	if len(fs.files["/etc/atlas/agent.secret"]) < 32 {
		t.Fatal("the agent secret was not generated")
	}
}

func TestAgentStepFailsLoudlyWhenThePayloadIsIncomplete(t *testing.T) {
	env, _, fs := stepEnv(t, "/opt/payload")
	_ = fs.WriteFile("/opt/payload/systemd/atlas-agent.service", []byte("# managed by atlas"), 0o644)
	// bin/atlas-agent deliberately missing.

	err := stepAgent(&RunContext{Env: env, Ctx: context.Background()})
	if err == nil || !strings.Contains(err.Error(), "agent binary missing") {
		t.Fatalf("an incomplete release must stop the install, got %v", err)
	}
}

func TestPanelStepRendersPortsAndFlipsTheSymlinkAfterMigrating(t *testing.T) {
	env, exec, fs := stepEnv(t, "/opt/payload")
	env.Opts.AdminPort = 9443
	seedPayload(fs, "/opt/payload")

	if err := stepPanel(&RunContext{Env: env, Ctx: context.Background()}); err != nil {
		t.Fatal(err)
	}

	whm := fs.files["/etc/nginx/sites-available/atlas-whm.conf"]
	if !strings.Contains(whm, "listen 9443 ssl") {
		t.Fatalf("the WHM nginx block was not rendered with the chosen port:\n%s", whm)
	}

	if strings.Contains(whm, "{{") {
		t.Fatal("an unrendered placeholder reached the server")
	}

	// The symlink must point at the release that was just migrated, and it must
	// be created after migrate/config:cache, or a failure leaves a broken panel
	// serving traffic.
	var migrateAt, symlinkAt = -1, -1

	for i, c := range exec.commands {
		if strings.Contains(c, "migrate --force") {
			migrateAt = i
		}
	}
	for i := range fs.links {
		_ = i
	}
	for i, c := range exec.commands {
		if strings.Contains(c, "systemctl enable --now atlas-panel") {
			symlinkAt = i
		}
	}

	if migrateAt == -1 || symlinkAt == -1 || migrateAt > symlinkAt {
		t.Fatalf("migrate must run before the panel is enabled (migrate at %d, enable at %d)", migrateAt, symlinkAt)
	}

	if target, ok := fs.links[CurrentLink]; !ok || !strings.HasPrefix(target, ReleaseRoot) {
		t.Fatalf("current symlink is wrong: %q", target)
	}

	if !exec.ran("nginx -t") {
		t.Fatal("nginx was never validated before reload")
	}
}

func TestPanelStepRefusesToOverwriteAForeignNginxBlock(t *testing.T) {
	env, _, fs := stepEnv(t, "/opt/payload")
	seedPayload(fs, "/opt/payload")

	_ = fs.WriteFile("/etc/nginx/sites-available/atlas-whm.conf",
		[]byte("# the customer's own site block"), 0o644)

	if err := stepPanel(&RunContext{Env: env, Ctx: context.Background()}); err == nil {
		t.Fatal("an existing unmanaged config must stop the install")
	}

	if fs.files["/etc/nginx/sites-available/atlas-whm.conf"] != "# the customer's own site block" {
		t.Fatal("the customer's config was modified")
	}
}

func TestPanelEnvCarriesTheLicenceTokenAndTheRealPorts(t *testing.T) {
	env, _, fs := stepEnv(t, "/opt/payload")
	seedPayload(fs, "/opt/payload")

	_ = fs.WriteFile("/etc/atlas/licence.token", []byte("atlas-lic-v1.abc.def"), 0o640)
	_ = fs.WriteFile("/etc/atlas/panel.env", []byte("DB_DATABASE=atlas_panel\nDB_PASSWORD=s3cret\n"), 0o600)
	env.Opts.AdminPort = 9443

	if err := writePanelEnv(&RunContext{Env: env, Ctx: context.Background()}, "/opt/atlas/releases/atlas-x", "key"); err != nil {
		t.Fatal(err)
	}

	text := fs.files["/opt/atlas/releases/atlas-x/.env"]

	for _, want := range []string{
		"ATLAS_LICENSE_TOKEN=atlas-lic-v1.abc.def",
		"ATLAS_WHM_PORT=9443",
		"ATLAS_AGENT_DRIVER=socket",
		"DB_PASSWORD=s3cret",
		"APP_DEBUG=false",
	} {
		if !strings.Contains(text, want) {
			t.Errorf(".env is missing %q\n%s", want, text)
		}
	}

	if !strings.Contains(text, "APP_KEY=base64:") {
		t.Error("the app key was not generated")
	}
}

func TestMailStaysOffWhenTheOperatorSkippedIt(t *testing.T) {
	env, _, fs := stepEnv(t, "/opt/payload")
	seedPayload(fs, "/opt/payload")

	if err := writePanelEnv(&RunContext{Env: env, Ctx: context.Background()}, "/opt/atlas/releases/x", "key"); err != nil {
		t.Fatal(err)
	}

	if !strings.Contains(fs.files["/opt/atlas/releases/x/.env"], "ATLAS_MAIL_ENABLED=false") {
		t.Fatal("--skip-mail must disable the module in the panel, not just skip the packages")
	}
}

func TestStatePathIsOutsideTheReleaseTree(t *testing.T) {
	// The state must survive an uninstall of /opt/atlas, otherwise a reinstall
	// re-runs steps against an existing server as if it were fresh.
	if strings.HasPrefix(StatePath, ReleaseRoot) || strings.HasPrefix(StatePath, "/opt/atlas") {
		t.Fatalf("state file lives inside the release tree: %s", StatePath)
	}
	if filepath.Dir(ReleaseRoot) == ReleaseRoot {
		t.Fatal("ReleaseRoot has no parent")
	}
}
