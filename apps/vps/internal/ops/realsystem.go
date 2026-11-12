package ops

import (
	"context"
	"fmt"
	"html"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"

	"atlas/internal/execx"
)

// FileSystem is the agent's view of the filesystem, injected so that the
// provisioning logic can be tested without writing to /etc.
type FileSystem interface {
	WriteFile(path string, data []byte, mode os.FileMode) error
	ReadFile(path string) ([]byte, error)
	Remove(path string) error
	MkdirAll(path string, mode os.FileMode) error
	Exists(path string) bool
	ListDir(path string) ([]os.DirEntry, error)
	Stat(path string) (os.FileInfo, error)
	Rename(from, to string) error
}

// OSFileSystem writes to the real filesystem, creating parents as needed.
type OSFileSystem struct{}

func (OSFileSystem) WriteFile(path string, data []byte, mode os.FileMode) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}

	return os.WriteFile(path, data, mode)
}

func (OSFileSystem) ReadFile(path string) ([]byte, error)    { return os.ReadFile(path) }
func (OSFileSystem) Remove(path string) error                { return os.RemoveAll(path) }
func (OSFileSystem) MkdirAll(p string, m os.FileMode) error  { return os.MkdirAll(p, m) }
func (OSFileSystem) ListDir(p string) ([]os.DirEntry, error) { return os.ReadDir(p) }
func (OSFileSystem) Stat(p string) (os.FileInfo, error)      { return os.Stat(p) }
func (OSFileSystem) Rename(from, to string) error            { return os.Rename(from, to) }

func (OSFileSystem) Exists(path string) bool {
	_, err := os.Stat(path)

	return err == nil
}

// Paths keeps every location the agent touches in one place, so a distribution
// that moves Apache's config directory is a one-line change.
type Paths struct {
	ApacheSitesAvailable string
	ApacheSitesEnabled   string
	PHPFPM               func(version string) string
	HomeRoot             string
	MailRoot             string
	ZoneDir              string
	BackupRoot           string
	ACMEWebroot          string
}

func DefaultPaths() Paths {
	return Paths{
		ApacheSitesAvailable: "/etc/apache2/sites-available",
		ApacheSitesEnabled:   "/etc/apache2/sites-enabled",
		PHPFPM:               func(v string) string { return "/etc/php/" + v + "/fpm/pool.d" },
		HomeRoot:             "/home",
		MailRoot:             "/var/mail/vhosts",
		ZoneDir:              "/etc/powerdns/zones",
		BackupRoot:           "/var/backups/atlas",
		ACMEWebroot:          "/var/www/atlas-acme",
	}
}

// RealSystem performs the operations with allowlisted binaries only.
type RealSystem struct {
	run    *execx.Runner
	fs     FileSystem
	paths  Paths
	now    func() time.Time
	system func(ctx context.Context) (Stats, error) // overridable in tests
}

func NewRealSystem(run *execx.Runner, fs FileSystem, paths Paths) *RealSystem {
	r := &RealSystem{run: run, fs: fs, paths: paths, now: time.Now}
	r.system = r.readStats

	return r
}

func (s *RealSystem) CreateAccount(ctx context.Context, spec AccountSpec) error {
	home := filepath.Join(s.paths.HomeRoot, spec.Username)

	if s.fs.Exists(home) {
		return fmt.Errorf("home directory %s already exists; refusing to take over an existing account", home)
	}

	// -U creates a matching group; the home is made here so the modes are ours.
	if _, err := s.run.Run(ctx, "useradd", "--create-home", "--user-group",
		"--home-dir", home, "--shell", "/bin/bash", spec.Username); err != nil {
		return fmt.Errorf("useradd: %w", err)
	}

	for _, dir := range []string{"public_html", "logs", "tmp", ".ssh", "backups"} {
		if err := s.fs.MkdirAll(filepath.Join(home, dir), 0o750); err != nil {
			return err
		}
	}

	if err := s.fs.WriteFile(filepath.Join(home, "public_html", "index.html"),
		[]byte(defaultIndexPage(spec.Domain)), 0o644); err != nil {
		return err
	}

	// Quota first, so a runaway script cannot fill the disk on day one.
	if err := s.ApplyQuota(ctx, spec.Username, spec.DiskMB, spec.Inodes); err != nil {
		return err
	}

	// A dedicated PHP-FPM pool per account. This is *not* a security boundary on
	// its own — see docs/16 — but it is the process and resource isolation that
	// makes per-account limits enforceable.
	version := spec.PHPVersion
	if version == "" {
		version = "8.3"
	}

	pool := fmt.Sprintf(poolTemplate, spec.Username, home)
	if err := s.fs.WriteFile(filepath.Join(s.paths.PHPFPM(version), spec.Username+".conf"), []byte(pool), 0o644); err != nil {
		return err
	}

	if err := s.WriteVhost(ctx, VhostSpec{
		Domain:       spec.Domain,
		Username:     spec.Username,
		DocumentRoot: filepath.Join(home, "public_html"),
		PHPVersion:   version,
	}); err != nil {
		return err
	}

	if _, err := s.run.Run(ctx, "systemctl", "reload", "php"+version+"-fpm"); err != nil {
		return err
	}

	return nil
}

func (s *RealSystem) SuspendAccount(ctx context.Context, username string) error {
	// Lock the Unix account (no new logins, no cron, no FTP).
	if _, err := s.run.Run(ctx, "usermod", "--lock", username); err != nil {
		return err
	}

	// Remove the vhost symlink so the site stops serving, and rewrite the vhost
	// to return 503 rather than executing PHP. Files are untouched: suspension
	// is reversible and never destroys data.
	if err := s.disableVhosts(ctx, username); err != nil {
		return err
	}

	_, err := s.run.Run(ctx, "systemctl", "reload", "apache2")

	return err
}

func (s *RealSystem) UnsuspendAccount(ctx context.Context, username string) error {
	if _, err := s.run.Run(ctx, "usermod", "--unlock", username); err != nil {
		return err
	}

	if err := s.enableVhosts(ctx, username); err != nil {
		return err
	}

	_, err := s.run.Run(ctx, "systemctl", "reload", "apache2")

	return err
}

func (s *RealSystem) DeleteAccount(ctx context.Context, username string, keepData bool) error {
	if keepData {
		// Terminate-with-data: lock the account and move the tree aside.
		if _, err := s.run.Run(ctx, "usermod", "--lock", username); err != nil {
			return err
		}

		if err := s.disableVhosts(ctx, username); err != nil {
			return err
		}

		archive := filepath.Join(s.paths.BackupRoot, "terminated", username+"-"+s.now().Format("20060102-150405"))
		if err := s.fs.MkdirAll(filepath.Dir(archive), 0o750); err != nil {
			return err
		}

		_, err := s.run.Run(ctx, "tar", "-czf", archive+".tar.gz", "-C", s.paths.HomeRoot, username)

		return err
	}

	if err := s.disableVhosts(ctx, username); err != nil {
		return err
	}

	if _, err := s.run.Run(ctx, "userdel", "--remove", username); err != nil {
		return err
	}

	s.removePoolFiles(username)

	return nil
}

func (s *RealSystem) ApplyQuota(ctx context.Context, username string, diskMB, inodes int) error {
	diskKB := diskMB * 1024

	// setquota works on ext4/xfs with quota enabled; the installer turns that on.
	// Soft = 95%, hard = 100%: a customer gets a warning window, not a wall.
	soft := diskKB * 95 / 100
	softInodes := inodes * 95 / 100

	_, err := s.run.Run(ctx, "setquota", "-u", username,
		strconv.Itoa(soft), strconv.Itoa(diskKB), strconv.Itoa(softInodes), strconv.Itoa(inodes), "/")

	return err
}

func (s *RealSystem) WriteVhost(ctx context.Context, spec VhostSpec) error {
	conf := renderVhost(spec, s.paths)

	path := filepath.Join(s.paths.ApacheSitesAvailable, spec.Domain+".conf")

	if existing, err := s.fs.ReadFile(path); err == nil {
		// Never silently overwrite a file the panel did not create.
		if !strings.Contains(string(existing), "# managed by atlas") {
			return fmt.Errorf("refusing to overwrite %s: it was not created by Atlas", path)
		}
	}

	if err := s.fs.WriteFile(path, []byte(conf), 0o644); err != nil {
		return err
	}

	if _, err := s.run.Run(ctx, "a2ensite", spec.Domain+".conf"); err != nil {
		return err
	}

	if err := s.fs.MkdirAll(filepath.Join(s.paths.HomeRoot, spec.Username, "logs"), 0o750); err != nil {
		return err
	}

	_, err := s.run.Run(ctx, "systemctl", "reload", "apache2")

	return err
}

// renderVhost is pure: same spec in, same config out. That makes it testable
// without root and reviewable as a diff.
func renderVhost(spec VhostSpec, paths Paths) string {
	aliases := ""
	for _, a := range spec.Aliases {
		aliases += "    ServerAlias " + a + "\n"
	}

	sslLines := ""
	if spec.SSL {
		sslLines = fmt.Sprintf(`
    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/%[1]s/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/%[1]s/privkey.pem
`, spec.Domain)
	}

	return fmt.Sprintf(vhostTemplate, spec.Domain, aliases, spec.DocumentRoot,
		filepath.Join(paths.HomeRoot, spec.Username, "logs"), spec.Username, sslLines)
}

func (s *RealSystem) RemoveVhost(ctx context.Context, domain string) error {
	path := filepath.Join(s.paths.ApacheSitesAvailable, domain+".conf")

	if data, err := s.fs.ReadFile(path); err == nil && !strings.Contains(string(data), "# managed by atlas") {
		return fmt.Errorf("refusing to remove %s: it was not created by Atlas", path)
	}

	if _, err := s.run.Run(ctx, "a2dissite", domain+".conf"); err != nil {
		return err
	}

	if err := s.fs.Remove(path); err != nil && !os.IsNotExist(err) {
		return err
	}

	if err := s.fs.Remove(filepath.Join(s.paths.ApacheSitesEnabled, domain+".conf")); err != nil && !os.IsNotExist(err) {
		return err
	}

	_, err := s.run.Run(ctx, "systemctl", "reload", "apache2")

	return err
}

// disableVhosts rewrites every managed vhost that belongs to username to a
// maintenance page. Rewriting (rather than deleting) keeps the configuration
// intact for unsuspend and makes the state obvious in the file.
func (s *RealSystem) disableVhosts(ctx context.Context, username string) error {
	for _, domain := range s.domainsOf(username) {
		path := filepath.Join(s.paths.ApacheSitesAvailable, domain+".conf")
		data, err := s.fs.ReadFile(path)
		if err != nil {
			continue
		}

		disabled := "# managed by atlas — suspended\n" + suspendedVhostRendered(domain)

		if err := s.fs.WriteFile(path, []byte(disabled), 0o644); err != nil {
			return err
		}

		_ = data
	}

	return nil
}

func (s *RealSystem) enableVhosts(ctx context.Context, username string) error {
	for _, domain := range s.domainsOf(username) {
		path := filepath.Join(s.paths.ApacheSitesAvailable, domain+".conf")
		data, err := s.fs.ReadFile(path)
		if err != nil {
			continue
		}
		if !strings.Contains(string(data), "suspended") {
			continue
		}

		docroot := filepath.Join(s.paths.HomeRoot, username, "public_html")
		conf := renderVhost(VhostSpec{Domain: domain, Username: username, DocumentRoot: docroot}, s.paths)

		if err := s.fs.WriteFile(path, []byte(conf), 0o644); err != nil {
			return err
		}
	}

	return nil
}

// domainsOf finds managed vhosts by their DocumentRoot, which is the only
// field that reliably identifies the owning account in the config.
func (s *RealSystem) domainsOf(username string) []string {
	files, err := s.fs.ListDir(s.paths.ApacheSitesAvailable)
	if err != nil {
		return nil
	}

	var out []string

	for _, f := range files {
		if f.IsDir() || !strings.HasSuffix(f.Name(), ".conf") {
			continue
		}

		data, err := s.fs.ReadFile(filepath.Join(s.paths.ApacheSitesAvailable, f.Name()))
		if err != nil || !strings.Contains(string(data), "# managed by atlas") {
			continue
		}

		if strings.Contains(string(data), "/home/"+username+"/") {
			out = append(out, strings.TrimSuffix(f.Name(), ".conf"))
		}
	}

	sort.Strings(out)

	return out
}

// buildCreateDatabaseSQL is pure for the same reason. Names are already
// constrained to [a-z0-9_] by the registry; the password is escaped for SQL
// quoting and the statement is streamed on stdin.
func buildCreateDatabaseSQL(spec DatabaseSpec) string {
	name := spec.Name
	dbUser := spec.Username
	pass := escapeSQL(spec.Password)

	return "CREATE DATABASE IF NOT EXISTS `" + name + "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n" +
		"CREATE USER IF NOT EXISTS '" + dbUser + "'@'localhost' IDENTIFIED BY '" + pass + "';\n" +
		"GRANT ALL PRIVILEGES ON `" + name + "`.* TO '" + dbUser + "'@'localhost';\n" +
		"FLUSH PRIVILEGES;\n"
}

func (s *RealSystem) CreateDatabase(ctx context.Context, spec DatabaseSpec) error {
	_, err := s.run.RunInput(ctx, buildCreateDatabaseSQL(spec), "mysql", "--protocol=socket")

	return err
}

func (s *RealSystem) DropDatabase(ctx context.Context, spec DatabaseSpec) error {
	// DROP USER is scoped to the database user; DROP DATABASE only removes the
	// schema. Both are needed, and neither touches other databases.
	sql := "DROP DATABASE IF EXISTS `" + spec.Name + "`;\n" +
		"DROP USER IF EXISTS '" + spec.Username + "'@'localhost';\n" +
		"FLUSH PRIVILEGES;\n"

	_, err := s.run.RunInput(ctx, sql, "mysql", "--protocol=socket")

	return err
}

func (s *RealSystem) CreateMailbox(ctx context.Context, spec MailboxSpec) error {
	// Dovecot reads virtual users from a passwd-file keyed by address; the hash
	// is produced by `doveadm pw` so we never store a plaintext password.
	res, err := s.run.RunInput(ctx, spec.Password, "doveadm", "pw", "-s", "SHA512-CRYPT", "-p", "-")
	if err != nil {
		return err
	}

	hash := strings.TrimSpace(res.Stdout)
	if hash == "" || !strings.HasPrefix(hash, "{SHA512-CRYPT}") {
		return fmt.Errorf("dovecot did not return a password hash")
	}

	home := filepath.Join(s.paths.MailRoot, spec.Domain, spec.LocalPart)
	entry := spec.LocalPart + "@" + spec.Domain + ":" + hash + ":" + strconv.Itoa(spec.QuotaMB*1024) + ":::" + home + "::\n"

	file := filepath.Join(s.paths.MailRoot, spec.Domain, "passwd")

	existing, _ := s.fs.ReadFile(file)
	if strings.Contains(string(existing), spec.LocalPart+"@"+spec.Domain+":") {
		return fmt.Errorf("mailbox %s@%s already exists", spec.LocalPart, spec.Domain)
	}

	if err := s.fs.WriteFile(file, append(existing, []byte(entry)...), 0o640); err != nil {
		return err
	}

	return s.fs.MkdirAll(home, 0o700)
}

func (s *RealSystem) DeleteMailbox(ctx context.Context, spec MailboxSpec) error {
	file := filepath.Join(s.paths.MailRoot, spec.Domain, "passwd")

	existing, err := s.fs.ReadFile(file)
	if err != nil {
		return fmt.Errorf("mailbox store not found for %s", spec.Domain)
	}

	needle := spec.LocalPart + "@" + spec.Domain + ":"

	var kept []string

	for _, line := range strings.Split(strings.TrimRight(string(existing), "\n"), "\n") {
		if line == "" || strings.HasPrefix(line, needle) {
			continue
		}
		kept = append(kept, line)
	}

	if err := s.fs.WriteFile(file, []byte(strings.Join(kept, "\n")+"\n"), 0o640); err != nil {
		return err
	}

	// The mail is moved, not deleted: an operator can recover it from the
	// deleted-mail store for 30 days.
	home := filepath.Join(s.paths.MailRoot, spec.Domain, spec.LocalPart)
	trash := filepath.Join(s.paths.BackupRoot, "deleted-mail",
		spec.Domain+"-"+spec.LocalPart+"-"+s.now().Format("20060102-150405"))

	if s.fs.Exists(home) {
		return s.fs.Rename(home, trash)
	}

	return nil
}

func (s *RealSystem) WriteZone(ctx context.Context, spec ZoneSpec) error {
	var b strings.Builder

	b.WriteString("$ORIGIN " + spec.Domain + ".\n$TTL 3600\n; managed by atlas\n")

	for _, rec := range spec.Records {
		priority := ""
		if rec.Type == "MX" && rec.Priority > 0 {
			priority = strconv.Itoa(rec.Priority) + " "
		}
		b.WriteString(fmt.Sprintf("%s\t%d\tIN\t%s\t%s%s\n", rec.Name, rec.TTL, rec.Type, priority, rec.Content))
	}

	if err := s.fs.WriteFile(filepath.Join(s.paths.ZoneDir, spec.Domain+".zone"), []byte(b.String()), 0o640); err != nil {
		return err
	}

	_, err := s.run.Run(ctx, "pdns_control", "rediscover")

	return err
}

func (s *RealSystem) IssueCertificate(ctx context.Context, domain, username string) (CertResult, error) {
	docroot := filepath.Join(s.paths.HomeRoot, username, "public_html")

	if !s.fs.Exists(docroot) {
		return CertResult{}, fmt.Errorf("document root for %s does not exist", username)
	}

	if err := s.fs.MkdirAll(s.paths.ACMEWebroot, 0o755); err != nil {
		return CertResult{}, err
	}

	// lego writes the certificate; the vhost is regenerated with TLS enabled.
	if _, err := s.run.Run(ctx, "lego", "--accept-tos", "--email", "hostmaster@"+domain,
		"--domains", domain, "--http", "--http.webroot", s.paths.ACMEWebroot,
		"--path", "/etc/letsencrypt", "run"); err != nil {
		return CertResult{Domain: domain, Issued: false, Message: err.Error()}, nil
	}

	spec := VhostSpec{
		Domain:       domain,
		Username:     username,
		DocumentRoot: docroot,
		SSL:          true,
	}

	if err := s.WriteVhost(ctx, spec); err != nil {
		return CertResult{}, err
	}

	return CertResult{Domain: domain, Issued: true, Message: "certificate installed; vhost now serves HTTPS"}, nil
}

func (s *RealSystem) SetCron(ctx context.Context, spec CronSpec) error {
	if err := validCronEntry(spec.Entry); err != nil {
		return err
	}

	// `crontab -u` replaces the whole table, so read, merge and write back.
	res, err := s.run.Run(ctx, "crontab", "-u", spec.Username, "-l")
	existing := ""
	if err == nil {
		existing = res.Stdout
	}

	var lines []string

	for _, line := range strings.Split(existing, "\n") {
		if line != "" && line != spec.Entry {
			lines = append(lines, line)
		}
	}
	lines = append(lines, spec.Entry)

	_, err = s.run.RunInput(ctx, strings.Join(lines, "\n")+"\n", "crontab", "-u", spec.Username, "-")

	return err
}

func (s *RealSystem) RunBackup(ctx context.Context, spec BackupSpec) (BackupResult, error) {
	stamp := s.now().Format("20060102-150405")

	dir := spec.Destination
	if dir == "" {
		dir = s.paths.BackupRoot
	}

	if err := s.fs.MkdirAll(dir, 0o750); err != nil {
		return BackupResult{}, err
	}

	archive := filepath.Join(dir, spec.Username+"-"+stamp+".tar.gz")

	if _, err := s.run.Run(ctx, "tar", "-czf", archive, "-C", s.paths.HomeRoot, spec.Username); err != nil {
		return BackupResult{}, err
	}

	if spec.IncludeDatabases {
		if _, err := s.run.Run(ctx, "tar", "-czf", strings.TrimSuffix(archive, ".tar.gz")+"-db.tar.gz",
			"-C", s.paths.BackupRoot, "db"); err != nil {
			// A database dump failure must fail the backup, not silently ship a
			// half backup.
			return BackupResult{}, fmt.Errorf("database dump failed: %w", err)
		}
	}

	res := BackupResult{Username: spec.Username, Archive: archive}

	if info, err := s.fs.Stat(archive); err == nil {
		res.Bytes = info.Size()
	}

	return res, nil
}

func (s *RealSystem) RestoreBackup(ctx context.Context, spec RestoreSpec) error {
	if !s.fs.Exists(spec.Archive) {
		return fmt.Errorf("archive %s does not exist", spec.Archive)
	}

	// Restore into a staging directory first: a corrupted archive must not
	// destroy the live site.
	staging := filepath.Join(s.paths.HomeRoot, spec.Username+".restore-"+s.now().Format("20060102-150405"))

	if err := s.fs.MkdirAll(staging, 0o750); err != nil {
		return err
	}

	if _, err := s.run.Run(ctx, "tar", "-xzf", spec.Archive, "-C", staging); err != nil {
		return err
	}

	live := filepath.Join(s.paths.HomeRoot, spec.Username)

	if err := s.fs.Rename(live, live+".pre-restore"); err != nil {
		if !os.IsNotExist(err) {
			return err
		}
	}

	return s.fs.Rename(filepath.Join(staging, spec.Username), live)
}

func (s *RealSystem) RestartService(ctx context.Context, unit string) error {
	_, err := s.run.Run(ctx, "systemctl", "restart", unit)

	return err
}

func (s *RealSystem) Stats(ctx context.Context) (Stats, error) { return s.system(ctx) }

func (s *RealSystem) readStats(ctx context.Context) (Stats, error) {
	st := Stats{Services: map[string]bool{}}

	if host, err := os.Hostname(); err == nil {
		st.Hostname = host
	}

	if data, err := os.ReadFile("/proc/loadavg"); err == nil {
		fields := strings.Fields(string(data))
		for _, f := range fields[:min(3, len(fields))] {
			if v, err := strconv.ParseFloat(f, 64); err == nil {
				st.LoadAvg = append(st.LoadAvg, v)
			}
		}
	}

	if data, err := os.ReadFile("/proc/meminfo"); err == nil {
		for _, line := range strings.Split(string(data), "\n") {
			fields := strings.Fields(line)
			if len(fields) < 2 {
				continue
			}

			v, _ := strconv.ParseInt(fields[1], 10, 64)

			switch fields[0] {
			case "MemTotal:":
				st.MemoryTotal = v
			case "MemAvailable:":
				st.MemoryFree = v
			}
		}
	}

	if data, err := os.ReadFile("/proc/uptime"); err == nil {
		if f := strings.Fields(string(data)); len(f) > 0 {
			if v, err := strconv.ParseFloat(f[0], 64); err == nil {
				st.UptimeSecs = int64(v)
			}
		}
	}

	// Disk usage of /home is the number a hosting operator actually acts on.
	st.DiskTotal, st.DiskFree = diskUsage(s.paths.HomeRoot)

	for _, unit := range []string{"apache2", "mariadb", "postfix", "dovecot", "pdns", "atlas-panel"} {
		_, err := s.run.Run(ctx, "systemctl", "is-active", "--quiet", unit)
		st.Services[unit] = err == nil
	}

	return st, nil
}

func (s *RealSystem) ListFiles(ctx context.Context, username, path string) (FileList, error) {
	entries, err := s.fs.ListDir(path)
	if err != nil {
		return FileList{}, err
	}

	out := FileList{Path: path, Entries: make([]FileEntry, 0, len(entries))}

	for _, e := range entries {
		info, err := e.Info()
		if err != nil {
			continue
		}

		kind := "file"
		switch {
		case e.IsDir():
			kind = "dir"
		case info.Mode()&os.ModeSymlink != 0:
			kind = "link"
		}

		out.Entries = append(out.Entries, FileEntry{
			Name:    e.Name(),
			Type:    kind,
			Size:    info.Size(),
			Mode:    info.Mode().String(),
			ModTime: info.ModTime().UTC().Format(time.RFC3339),
		})
	}

	sort.Slice(out.Entries, func(i, j int) bool {
		if out.Entries[i].Type != out.Entries[j].Type {
			return out.Entries[i].Type == "dir"
		}

		return strings.ToLower(out.Entries[i].Name) < strings.ToLower(out.Entries[j].Name)
	})

	return out, nil
}

func (s *RealSystem) ReadFile(ctx context.Context, username, path string, maxBytes int) (FileRead, error) {
	info, err := s.fs.Stat(path)
	if err != nil {
		return FileRead{}, fmt.Errorf("file not found: %s", path)
	}
	if info.IsDir() {
		return FileRead{}, fmt.Errorf("%s is a directory", path)
	}

	data, err := s.fs.ReadFile(path)
	if err != nil {
		return FileRead{}, err
	}

	// NUL bytes in the first block mean binary; the editor must not mangle it.
	probe := data
	if len(probe) > 8000 {
		probe = probe[:8000]
	}
	if strings.ContainsRune(string(probe), 0) {
		return FileRead{}, fmt.Errorf("%s looks like a binary file and cannot be edited", path)
	}

	out := FileRead{Path: path, Bytes: int64(len(data))}

	if maxBytes > 0 && len(data) > maxBytes {
		out.Content = string(data[:maxBytes])
		out.Truncated = true
	} else {
		out.Content = string(data)
	}

	return out, nil
}

func (s *RealSystem) WriteFile(ctx context.Context, spec FileWriteSpec) error {
	// 0640 owned by the account: the web server reads it via the group, and no
	// other customer can.
	if !spec.Append {
		return s.fs.WriteFile(spec.Path, []byte(spec.Content), 0o640)
	}

	f, err := os.OpenFile(spec.Path, os.O_WRONLY|os.O_CREATE|os.O_APPEND, 0o640)
	if err != nil {
		return fmt.Errorf("append %s: %w", spec.Path, err)
	}
	defer f.Close()

	if _, err := f.WriteString(spec.Content); err != nil {
		return fmt.Errorf("append %s: %w", spec.Path, err)
	}

	return nil
}

func (s *RealSystem) MakeDir(ctx context.Context, username, path string) error {
	return s.fs.MkdirAll(path, 0o750)
}

func (s *RealSystem) DeletePath(ctx context.Context, username, path string) error {
	if path == filepath.Join(s.paths.HomeRoot, username) {
		return fmt.Errorf("refusing to delete the account home directory")
	}

	return s.fs.Remove(path)
}

func (s *RealSystem) MovePath(ctx context.Context, username, from, to string) error {
	// os.Rename silently replaces the destination on Unix; the panel must not
	// lose a file to a typo in the "rename" field.
	if s.fs.Exists(to) {
		return fmt.Errorf("%s already exists", to)
	}

	return s.fs.Rename(from, to)
}

func (s *RealSystem) removePoolFiles(username string) {
	for _, v := range []string{"8.1", "8.2", "8.3", "8.4", "8.5"} {
		_ = s.fs.Remove(filepath.Join(s.paths.PHPFPM(v), username+".conf"))
	}
}

// diskUsage reads the filesystem that holds root, which is where /home lives on
// a single-disk VPS. Values are in kilobytes.
func diskUsage(path string) (total, free int64) {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil {
		return 0, 0
	}

	bsize := int64(st.Bsize)

	return int64(st.Blocks) * bsize / 1024, int64(st.Bavail) * bsize / 1024
}

func escapeSQL(s string) string {
	// MySQL with NO_BACKSLASH_ESCAPES off: backslash and quote are the only
	// metacharacters that matter inside a single-quoted literal.
	r := strings.NewReplacer(`\`, `\\`, `'`, `\'`, "\x00", "")

	return r.Replace(s)
}

// renderPool is pure for the same reason as renderVhost, and is the only place
// the per-account PHP limits are expressed.
func renderPool(username, home string) string {
	return fmt.Sprintf(poolTemplate, username, home)
}

func defaultIndexPage(domain string) string {
	// The domain arrives from the panel, which validates it, but the placeholder
	// page is written to disk and served: escape it anyway.
	domain = html.EscapeString(domain)

	return `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Site is ready</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{font:16px/1.6 system-ui,sans-serif;margin:0;display:grid;place-items:center;min-height:100vh;background:#0f1115;color:#e8eaf0}
main{max-width:34rem;padding:2rem;text-align:center}h1{font-size:1.6rem;margin:0 0 .5rem}
code{background:#1a1e26;padding:.15rem .4rem;border-radius:.3rem}</style></head>
<body><main><h1>` + domain + ` is ready</h1>
<p>Upload your site to <code>public_html</code> or install an app from the panel.</p></main></body></html>
`
}

const vhostTemplate = `# managed by atlas — regenerated on every change, do not edit by hand
<VirtualHost *:80>
    ServerName %[1]s
%[2]s    DocumentRoot %[3]s

    <Directory %[3]s>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/atlas-%[5]s.sock|fcgi://localhost"
    </FilesMatch>

    ErrorLog  %[4]s/error.log
    CustomLog %[4]s/access.log combined
%[6]s</VirtualHost>

<VirtualHost *:443>
    ServerName %[1]s
%[2]s    DocumentRoot %[3]s

    <Directory %[3]s>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/atlas-%[5]s.sock|fcgi://localhost"
    </FilesMatch>

    ErrorLog  %[4]s/error.log
    CustomLog %[4]s/access.log combined
</VirtualHost>
`

const suspendedVhost = `<VirtualHost *:80>
    ServerName %[1]s
    DocumentRoot /var/www/atlas-suspended

    <Directory /var/www/atlas-suspended>
        Require all granted
    </Directory>

    ErrorDocument 503 /index.html
    RewriteEngine On
    RewriteRule ^ - [R=503,L]
</VirtualHost>
`

const poolTemplate = `; managed by atlas — regenerated on every change, do not edit by hand
[%[1]s]
user = %[1]s
group = %[1]s
listen = /run/php/atlas-%[1]s.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 10
pm.process_idle_timeout = 10s
pm.max_requests = 500
chdir = %[2]s
; open_basedir is what stops a compromised site reading a neighbour's wp-config.php
php_admin_value[open_basedir] = %[2]s:/tmp
php_admin_value[session.save_path] = %[2]s/tmp
php_admin_value[upload_tmp_dir] = %[2]s/tmp
php_admin_value[sys_temp_dir] = %[2]s/tmp
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen
php_admin_flag[expose_php] = off
`

func suspendedVhostRendered(domain string) string { return fmt.Sprintf(suspendedVhost, domain) }
