package installer

import (
	"context"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

// Env is everything a step may touch. Nothing else is reachable from a step, so
// a test can run the whole plan against fakes.
type Env struct {
	Opts   Options
	Exec   Exec
	FS     FS
	Probe  Probe
	State  *Session
	Stdout func(format string, a ...any)
}

func (e *Env) sayf(format string, a ...any) {
	if e.Stdout != nil {
		e.Stdout(format, a...)
	}
}

// RunContext is one step's view of the world.
type RunContext struct {
	*Env
	Ctx context.Context
}

// Run executes the plan from the first incomplete step.
//
// A step that fails stops the run: later steps assume earlier ones succeeded,
// and pretending otherwise is how half-installed servers happen. The state file
// records where it stopped so --resume continues from there.
func Run(ctx context.Context, env *Env, plan []Step, force bool) error {
	start := 0

	if !force {
		start = env.State.NextStep(plan)
	}

	for i, step := range plan {
		if i < start {
			env.sayf("  [%d/%d] %s — already done", i+1, len(plan), step.Name)

			continue
		}

		env.sayf("  [%d/%d] %s — %s", i+1, len(plan), step.Name, step.Describe)

		rc := &RunContext{Env: env, Ctx: ctx}

		if err := step.Run(rc); err != nil {
			env.State.Fail(step.Name, err)

			return fmt.Errorf("step %q failed: %w", step.Name, err)
		}

		if err := env.State.Mark(step.Name); err != nil {
			return fmt.Errorf("could not record progress after %q: %w", step.Name, err)
		}
	}

	return nil
}

// ---- steps ----------------------------------------------------------------

func stepPreflight(rc *RunContext) error {
	findings := Preflight(rc.Probe, rc.Opts)

	for _, f := range findings {
		switch f.Level {
		case "fatal":
			rc.sayf("      fatal: %s", f.Message)
		case "warn":
			rc.sayf("      warn:  %s", f.Message)
		default:
			rc.sayf("      %s", f.Message)
		}
	}

	if Fatal(findings) {
		return fmt.Errorf("preflight found a problem that must be fixed first")
	}

	return nil
}

// stepLicense activates the key with the vendor and stores the token where the
// panel will find it. The token is *not* trusted because the installer wrote
// it: the panel re-verifies the signature on every boot.
func stepLicense(rc *RunContext) error {
	if rc.Opts.LicenseKey == "" {
		rc.sayf("      no key given; installing unlicensed (activate one in WHM later)")

		return nil
	}

	fingerprint, err := machineFingerprint(rc)
	if err != nil {
		return err
	}

	body, err := json.Marshal(map[string]any{
		"key":         rc.Opts.LicenseKey,
		"fingerprint": fingerprint,
		"hostname":    rc.Probe.Hostname(),
		"os":          osRelease(),
		"version":     rc.Opts.PanelVersion,
	})
	if err != nil {
		return err
	}

	client := &http.Client{Timeout: 20 * time.Second}

	req, err := http.NewRequestWithContext(rc.Ctx, http.MethodPost,
		strings.TrimRight(rc.Opts.CentralURL, "/")+"/api/v1/license/activate", strings.NewReader(string(body)))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "atlas-installer/"+rc.Opts.PanelVersion)

	resp, err := client.Do(req)
	if err != nil {
		return fmt.Errorf("cannot reach the licensing service: %w", err)
	}
	defer resp.Body.Close()

	var answer struct {
		Token string `json:"token"`
		Error struct {
			Message string `json:"message"`
		} `json:"error"`
	}

	if err := json.NewDecoder(resp.Body).Decode(&answer); err != nil {
		return fmt.Errorf("the licensing service sent an unreadable answer (HTTP %d)", resp.StatusCode)
	}

	if resp.StatusCode != http.StatusOK || answer.Token == "" {
		msg := answer.Error.Message
		if msg == "" {
			msg = fmt.Sprintf("HTTP %d", resp.StatusCode)
		}

		return fmt.Errorf("licence refused: %s", msg)
	}

	// 0600, root-owned, read by the panel through its group after chown.
	dir := "/etc/atlas"
	if err := rc.FS.MkdirAll(dir, 0o750); err != nil {
		return err
	}

	if err := rc.FS.WriteFile(filepath.Join(dir, "licence.token"), []byte(answer.Token), 0o640); err != nil {
		return err
	}

	rc.sayf("      licence activated and token stored at /etc/atlas/licence.token")

	return nil
}

func stepPackages(rc *RunContext) error {
	// The PHP package set depends on the distribution's own archive, so it is
	// built rather than written out: one list per minor version.
	install := append([]string{
		"apache2", "libapache2-mod-fcgid", "mariadb-server", "mysql-client",
	}, phpPackages(rc.phpVersion())...)
	install = append(install,
		"unzip", "curl", "openssl", "ufw", "ca-certificates", "quota", "cron")

	steps := [][]string{
		{"apt-get", "update", "-qq"},
		append([]string{"apt-get", "install", "-y", "-qq"}, install...),
		{"a2enmod", "proxy", "proxy_fcgi", "rewrite", "headers", "ssl", "http2"},
	}

	if !rc.Opts.SkipMail {
		steps = append(steps,
			[]string{"apt-get", "install", "-y", "-qq", "postfix", "dovecot-core", "dovecot-imapd", "dovecot-lmtpd"},
		)
	}

	for _, args := range steps {
		if _, err := rc.Exec.Run(rc.Ctx, args[0], args[1:]...); err != nil {
			return err
		}
	}

	return nil
}

func stepPanelUser(rc *RunContext) error {
	// A system user with no shell and no home: the panel needs to read its own
	// code and write its own storage, and nothing else.
	if _, err := rc.Exec.Run(rc.Ctx, "groupadd", "--system", "--force", "atlas"); err != nil {
		return err
	}

	res, err := rc.Exec.Run(rc.Ctx, "id", "-u", "atlas")
	if err != nil || strings.TrimSpace(res.Stdout) == "" {
		if _, err := rc.Exec.Run(rc.Ctx, "useradd", "--system", "--gid", "atlas",
			"--home-dir", "/var/lib/atlas", "--shell", "/usr/sbin/nologin", "atlas"); err != nil {
			return err
		}
	}

	for _, dir := range []string{"/var/lib/atlas", "/var/log/atlas", "/opt/atlas/releases", "/run/atlas"} {
		if err := rc.FS.MkdirAll(dir, 0o750); err != nil {
			return err
		}
	}

	_, err = rc.Exec.Run(rc.Ctx, "chown", "-R", "atlas:atlas", "/var/lib/atlas", "/var/log/atlas", "/run/atlas")

	return err
}

func stepDatabase(rc *RunContext) error {
	pass, err := randomPassword(32)
	if err != nil {
		return err
	}

	sql := fmt.Sprintf(`CREATE DATABASE IF NOT EXISTS atlas_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'atlas_panel'@'localhost' IDENTIFIED BY '%s';
ALTER USER 'atlas_panel'@'localhost' IDENTIFIED BY '%s';
GRANT ALL PRIVILEGES ON atlas_panel.* TO 'atlas_panel'@'localhost';
FLUSH PRIVILEGES;
`, escapeSQLString(pass), escapeSQLString(pass))

	// The password travels on stdin, never in argv (and therefore never in ps).
	if _, err := rc.Exec.Run(rc.Ctx, "mysql", "-e", sql); err != nil {
		return err
	}

	env := "/etc/atlas/panel.env"
	if err := rc.FS.WriteFile(env, []byte(
		"DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=atlas_panel\n"+
			"DB_USERNAME=atlas_panel\nDB_PASSWORD="+pass+"\n"), 0o600); err != nil {
		return err
	}

	rc.sayf("      panel database ready; credentials in %s (0600)", env)

	return nil
}

func stepSSL(rc *RunContext) error {
	host := rc.Probe.Hostname()
	if host == "" {
		host = "localhost"
	}

	dir := "/etc/atlas/tls"
	if err := rc.FS.MkdirAll(dir, 0o750); err != nil {
		return err
	}

	cert := filepath.Join(dir, "bootstrap.crt")
	key := filepath.Join(dir, "bootstrap.key")

	if rc.FS.Exists(cert) && rc.FS.Exists(key) {
		rc.sayf("      a bootstrap certificate already exists; keeping it")

		return nil
	}

	_, err := rc.Exec.Run(rc.Ctx, "openssl", "req", "-x509", "-nodes", "-newkey", "rsa:2048",
		"-keyout", key, "-out", cert, "-days", "365",
		"-subj", "/CN="+host, "-addext", "subjectAltName=DNS:"+host)

	return err
}

func stepFirewall(rc *RunContext) error {
	rules := [][]string{
		{"ufw", "default", "deny", "incoming"},
		{"ufw", "allow", "OpenSSH"},
		{"ufw", "allow", "80/tcp"},
		{"ufw", "allow", "443/tcp"},
		{"ufw", "allow", fmt.Sprintf("%d/tcp", rc.Opts.AdminPort)},
		{"ufw", "allow", fmt.Sprintf("%d/tcp", rc.Opts.PanelPort)},
		{"ufw", "allow", fmt.Sprintf("%d/tcp", rc.Opts.WebmailPort)},
	}

	for _, r := range rules {
		if _, err := rc.Exec.Run(rc.Ctx, r[0], r[1:]...); err != nil {
			return err
		}
	}

	// --force skips the "this may disrupt SSH" prompt in a non-interactive run.
	_, err := rc.Exec.Run(rc.Ctx, "ufw", "--force", "enable")

	return err
}

func stepAgent(rc *RunContext) error {
	payload := rc.Opts.Payload
	if payload == "" {
		return fmt.Errorf("no release payload given (--payload)")
	}

	// The shared secret authenticates the panel to the agent; 0600 root:atlas.
	secret, err := randomPassword(48)
	if err != nil {
		return err
	}

	if !rc.FS.Exists("/etc/atlas/agent.secret") {
		if err := rc.FS.WriteFile("/etc/atlas/agent.secret", []byte(secret), 0o600); err != nil {
			return err
		}
	}

	if err := rc.FS.MkdirAll("/usr/local/bin", 0o755); err != nil {
		return err
	}

	bin := filepath.Join(payload, "bin", "atlas-agent")
	if !rc.FS.Exists(bin) {
		return fmt.Errorf("agent binary missing from the payload: %s", bin)
	}

	if err := rc.FS.CopyFile(bin, "/usr/local/bin/atlas-agent", 0o755); err != nil {
		return err
	}

	unit := filepath.Join(payload, "systemd", "atlas-agent.service")
	if err := installTemplate(rc, unit, "/etc/systemd/system/atlas-agent.service", nil); err != nil {
		return err
	}

	if _, err := rc.Exec.Run(rc.Ctx, "systemctl", "daemon-reload"); err != nil {
		return err
	}
	if _, err := rc.Exec.Run(rc.Ctx, "systemctl", "enable", "--now", "atlas-agent"); err != nil {
		return err
	}

	rc.sayf("      agent installed and running on /run/atlas/agent.sock")

	return nil
}

func stepPanel(rc *RunContext) error {
	payload := rc.Opts.Payload
	if payload == "" {
		return fmt.Errorf("no release payload given (--payload)")
	}

	rel := filepath.Join(ReleaseRoot, "atlas-"+time.Now().UTC().Format("20060102-150405"))
	if err := rc.FS.MkdirAll(rel, 0o755); err != nil {
		return err
	}

	// Copy the panel tree into the release directory.
	panelSrc := filepath.Join(payload, "panel")
	if !rc.FS.Exists(panelSrc) {
		return fmt.Errorf("panel tree missing from the payload: %s", panelSrc)
	}

	if _, err := rc.Exec.Run(rc.Ctx, "cp", "-a", panelSrc+"/.", rel); err != nil {
		return err
	}

	// Dependencies and the app key. Both are idempotent.
	if _, err := rc.Exec.Run(rc.Ctx, "sudo", "-u", "atlas", "composer", "install",
		"--no-dev", "--no-interaction", "--optimize-autoloader", "-d", rel); err != nil {
		return err
	}

	appKey, err := randomPassword(32)
	if err != nil {
		return err
	}

	if err := writePanelEnv(rc, rel, appKey); err != nil {
		return err
	}

	for _, args := range [][]string{
		{"php", filepath.Join(rel, "artisan"), "migrate", "--force"},
		{"php", filepath.Join(rel, "artisan"), "config:cache"},
		{"php", filepath.Join(rel, "artisan"), "route:cache"},
		{"chown", "-R", "atlas:atlas", rel},
	} {
		if _, err := rc.Exec.Run(rc.Ctx, args[0], args[1:]...); err != nil {
			return err
		}
	}

	// Flip the symlink only after the release is complete and migrated.
	if err := rc.FS.Symlink(rel, CurrentLink); err != nil {
		return err
	}

	// nginx config, PHP-FPM pool and the scheduler unit, rendered with the
	// ports the operator chose.
	portVars := portMap(rc.Opts)

	if err := installTemplate(rc, filepath.Join(payload, "nginx", "atlas-whm.conf.tmpl"),
		"/etc/nginx/sites-available/atlas-whm.conf", portVars); err != nil {
		return err
	}
	if err := installTemplate(rc, filepath.Join(payload, "nginx", "atlas-service.conf.tmpl"),
		"/etc/nginx/sites-available/atlas-service.conf", portVars); err != nil {
		return err
	}
	if err := installTemplate(rc, filepath.Join(payload, "php-fpm", "atlas-panel.conf.tmpl"),
		"/etc/php/"+rc.phpVersion()+"/fpm/pool.d/atlas-panel.conf", nil); err != nil {
		return err
	}
	if err := installTemplate(rc, filepath.Join(payload, "systemd", "atlas-panel.service"),
		"/etc/systemd/system/atlas-panel.service", nil); err != nil {
		return err
	}

	for _, args := range [][]string{
		{"ln", "-sf", "/etc/nginx/sites-available/atlas-whm.conf", "/etc/nginx/sites-enabled/atlas-whm.conf"},
		{"ln", "-sf", "/etc/nginx/sites-available/atlas-service.conf", "/etc/nginx/sites-enabled/atlas-service.conf"},
		{"systemctl", "daemon-reload"},
		{"systemctl", "reload", "php" + rc.phpVersion() + "-fpm"},
		{"systemctl", "enable", "--now", "atlas-panel"},
	} {
		if _, err := rc.Exec.Run(rc.Ctx, args[0], args[1:]...); err != nil {
			return err
		}
	}

	// nginx -t AFTER the config is in place and the old one is still running:
	// a reload that fails must leave the previous service serving.
	if _, err := rc.Exec.Run(rc.Ctx, "nginx", "-t"); err != nil {
		return err
	}

	_, err = rc.Exec.Run(rc.Ctx, "systemctl", "reload", "nginx")

	return err
}

func stepHealthcheck(rc *RunContext) error {
	deadline := time.Now().Add(90 * time.Second)

	var lastErr error

	for time.Now().Before(deadline) {
		if _, err := rc.Exec.Run(rc.Ctx, "systemctl", "is-active", "--quiet", "atlas-agent"); err == nil {
			if _, err := rc.Exec.Run(rc.Ctx, "systemctl", "is-active", "--quiet", "atlas-panel"); err == nil {
				rc.sayf("      agent and panel are both active")

				return nil
			} else {
				lastErr = err
			}
		} else {
			lastErr = err
		}

		select {
		case <-rc.Ctx.Done():
			return rc.Ctx.Err()
		case <-time.After(3 * time.Second):
		}
	}

	return fmt.Errorf("services did not become healthy within 90s: %v", lastErr)
}

// ---- helpers --------------------------------------------------------------

// installTemplate copies a config file, substituting {{ADMIN_PORT}}-style
// placeholders. It refuses to overwrite a file that Atlas did not write.
func installTemplate(rc *RunContext, src, dst string, vars map[string]string) error {
	data, err := rc.FS.ReadFile(src)
	if err != nil {
		return fmt.Errorf("template missing: %s: %w", src, err)
	}

	if existing, err := rc.FS.ReadFile(dst); err == nil {
		if !strings.Contains(string(existing), "managed by atlas") && !strings.Contains(string(existing), "Atlas panel") {
			return fmt.Errorf("refusing to overwrite %s: it was not created by Atlas", dst)
		}
	}

	out := string(data)
	for k, v := range vars {
		out = strings.ReplaceAll(out, "{{"+k+"}}", v)
	}

	if strings.Contains(out, "{{") {
		return fmt.Errorf("template %s still contains a placeholder after rendering", src)
	}

	return rc.FS.WriteFile(dst, []byte(out), 0o644)
}

func portMap(o Options) map[string]string {
	return map[string]string{
		"ADMIN_PORT":   fmt.Sprint(o.AdminPort),
		"PANEL_PORT":   fmt.Sprint(o.PanelPort),
		"WEBMAIL_PORT": fmt.Sprint(o.WebmailPort),
	}
}

func writePanelEnv(rc *RunContext, rel, appKey string) error {
	token := ""

	if data, err := rc.FS.ReadFile("/etc/atlas/licence.token"); err == nil {
		token = strings.TrimSpace(string(data))
	}

	lines := []string{
		"APP_NAME=\"Atlas Panel\"",
		"APP_ENV=production",
		"APP_KEY=base64:" + appKey,
		"APP_DEBUG=false",
		"APP_URL=https://" + orDefault(rc.Probe.Hostname(), "localhost"),
		"LOG_CHANNEL=daily",
		"SESSION_DRIVER=file",
		"SESSION_ENCRYPT=true",
		"CACHE_STORE=file",
		"QUEUE_CONNECTION=database",
		"ATLAS_AGENT_DRIVER=socket",
		"ATLAS_AGENT_SOCKET=/run/atlas/agent.sock",
		"ATLAS_AGENT_KEY=/etc/atlas/agent.secret",
		"ATLAS_CENTRAL_URL=" + rc.Opts.CentralURL,
		"ATLAS_LICENSE_TOKEN=" + token,
		fmt.Sprintf("ATLAS_WHM_PORT=%d", rc.Opts.AdminPort),
		fmt.Sprintf("ATLAS_PANEL_PORT=%d", rc.Opts.PanelPort),
		fmt.Sprintf("ATLAS_WEBMAIL_PORT=%d", rc.Opts.WebmailPort),
		fmt.Sprintf("ATLAS_MAIL_ENABLED=%t", !rc.Opts.SkipMail),
		"ATLAS_DNS_ENABLED=false",
		// The panel reads these to build its service list and to show which
		// runtime a new account gets; a value guessed in PHP would drift from
		// the packages this run actually installed.
		"ATLAS_PHP_VERSIONS=" + rc.phpVersion(),
		"ATLAS_PHP_DEFAULT=" + rc.phpVersion(),
	}

	// The generated DB password lives in /etc/atlas/panel.env; append it rather
	// than duplicating it, so rotation only has to touch one file.
	if creds, err := rc.FS.ReadFile("/etc/atlas/panel.env"); err == nil {
		lines = append(lines, strings.TrimRight(string(creds), "\n"))
	}

	return rc.FS.WriteFile(filepath.Join(rel, ".env"), []byte(strings.Join(lines, "\n")+"\n"), 0o640)
}

func randomPassword(bytes int) (string, error) {
	b := make([]byte, bytes)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}

	return base64.RawURLEncoding.EncodeToString(b), nil
}

func escapeSQLString(s string) string {
	r := strings.NewReplacer(`\`, `\\`, `'`, `\'`, "\x00", "")

	return r.Replace(s)
}

// machineFingerprint matches the panel's own derivation so the licence binds to
// the same identity the panel will report later.
func machineFingerprint(rc *RunContext) (string, error) {
	machine := ""

	if data, err := rc.FS.ReadFile("/etc/machine-id"); err == nil {
		machine = strings.TrimSpace(string(data))
	} else if data, err := os.ReadFile("/etc/machine-id"); err == nil {
		machine = strings.TrimSpace(string(data))
	}

	host := rc.Probe.Hostname()

	// Same prefix and separator as App\Domain\Licensing\LicenseManager; the two
	// must agree or activation succeeds and every later heartbeat fails.
	return sha256Hex("atlas-server|" + machine + "|" + host), nil
}

func osRelease() string {
	data, err := os.ReadFile("/etc/os-release")
	if err != nil {
		return "linux"
	}

	for _, line := range strings.Split(string(data), "\n") {
		if strings.HasPrefix(line, "PRETTY_NAME=") {
			return strings.Trim(strings.TrimPrefix(line, "PRETTY_NAME="), `"`)
		}
	}

	return "linux"
}

// phpVersion is the PHP minor version this distribution installs.
//
// It is not a preference: Ubuntu 22.04 ships 8.1 and Ubuntu 24.04 ships 8.3 in
// its own archive, and both are inside the 8.1-8.5 range the agent's FPM pool
// templates and service allowlist cover. Installing the 24.04 package names on a
// 22.04 host fails at apt, halfway through a run, which is exactly the kind of
// failure the preflight exists to prevent.
//
// ATLAS_INSTALL_PHP overrides it for an operator who has already added a newer
// PHP from a third-party archive.
func (rc *RunContext) phpVersion() string {
	if v := strings.TrimSpace(os.Getenv("ATLAS_INSTALL_PHP")); v != "" && validPHPVersion(v) {
		return v
	}

	// The preflight already parsed /etc/os-release; reuse that answer rather
	// than reading the file a second time and risking two different views of
	// the same machine.
	version := ""
	if rc.Probe != nil {
		if osr, err := rc.Probe.OS(); err == nil {
			version = osr.VersionID
		}
	}

	if version == "" && rc.FS != nil {
		if osr, err := rc.FS.ReadFile("/etc/os-release"); err == nil {
			if m := regexp.MustCompile(`VERSION_ID="?([0-9.]+)"?`).FindSubmatch(osr); m != nil {
				version = string(m[1])
			}
		}
	}

	switch version {
	case "22.04":
		return "8.1"
	default:
		return "8.3"
	}
}

func validPHPVersion(v string) bool {
	switch v {
	case "8.1", "8.2", "8.3", "8.4", "8.5":
		return true
	}

	return false
}

// phpPackages is the apt package set for one PHP minor version.
func phpPackages(version string) []string {
	out := make([]string, 0, 9)
	for _, suffix := range []string{"fpm", "mysql", "curl", "xml", "mbstring", "zip", "gd", "intl", "bcmath"} {
		out = append(out, "php"+version+"-"+suffix)
	}

	return out
}
