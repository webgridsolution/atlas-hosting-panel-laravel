package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"sort"
	"strings"
)

// Handler receives already-authenticated arguments.
type Handler func(ctx context.Context, raw json.RawMessage) (any, error)

// Registry maps operation names to handlers.
type Registry struct {
	system   System
	handlers map[string]Handler
}

// NewRegistry wires the allowlist. Adding an operation means adding a line here
// and a method on System — there is no dynamic dispatch anywhere.
func NewRegistry(s System) *Registry {
	r := &Registry{system: s, handlers: map[string]Handler{}}

	r.register("account.create", r.createAccount)
	r.register("account.suspend", r.suspendAccount)
	r.register("account.unsuspend", r.unsuspendAccount)
	r.register("account.delete", r.deleteAccount)
	r.register("account.quota.set", r.setQuota)
	r.register("vhost.write", r.writeVhost)
	r.register("vhost.remove", r.removeVhost)
	r.register("db.create", r.createDatabase)
	r.register("db.drop", r.dropDatabase)
	r.register("mail.mailbox.create", r.createMailbox)
	r.register("mail.mailbox.delete", r.deleteMailbox)
	r.register("dns.zone.write", r.writeZone)
	r.register("ssl.issue", r.issueCert)
	r.register("cron.set", r.setCron)
	r.register("backup.run", r.runBackup)
	r.register("backup.restore", r.restoreBackup)
	r.register("service.restart", r.restartService)
	r.register("system.stats", r.stats)
	r.register("file.list", r.listFiles)
	r.register("file.read", r.readFile)
	r.register("file.write", r.writeFile)
	r.register("file.mkdir", r.makeDir)
	r.register("file.delete", r.deletePath)
	r.register("file.rename", r.movePath)

	// The hosting set lives in hosting_registry.go so this list stays short
	// enough to read in one screen.
	r.registerHosting()

	// The cPanel-class file manager and SSH access set.
	r.registerFileAndSSH()

	return r
}

func (r *Registry) register(name string, h Handler) { r.handlers[name] = h }

// Ops lists the allowlist, sorted — used by `atlas-agent --list-ops` and by the
// panel's capability cross-check at boot.
func (r *Registry) Ops() []string {
	names := make([]string, 0, len(r.handlers))
	for name := range r.handlers {
		names = append(names, name)
	}
	sort.Strings(names)

	return names
}

// Dispatch runs one operation. Unknown names are refused without touching the
// system, so a typo or a probe never has side effects.
func (r *Registry) Dispatch(ctx context.Context, op string, args json.RawMessage) (any, error) {
	h, ok := r.handlers[op]
	if !ok {
		return nil, fmt.Errorf("unknown operation %q", op)
	}

	if r.system == nil {
		return nil, fmt.Errorf("agent has no system bound")
	}

	return h(ctx, args)
}

// ---- handlers ----

func (r *Registry) createAccount(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[AccountSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validDomain(spec.Domain); err != nil {
		return nil, err
	}
	if err := validQuota(spec.DiskMB, spec.Inodes); err != nil {
		return nil, err
	}
	if err := validPHP(spec.PHPVersion); err != nil {
		return nil, err
	}

	if err := r.system.CreateAccount(ctx, spec); err != nil {
		return nil, err
	}

	return map[string]any{
		"username": spec.Username,
		"home":     "/home/" + spec.Username,
		"docroot":  "/home/" + spec.Username + "/public_html",
	}, nil
}

func (r *Registry) suspendAccount(ctx context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Username string `json:"username"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(in.Username); err != nil {
		return nil, err
	}

	if err := r.system.SuspendAccount(ctx, in.Username); err != nil {
		return nil, err
	}

	return map[string]any{"username": in.Username, "status": "suspended"}, nil
}

func (r *Registry) unsuspendAccount(ctx context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Username string `json:"username"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(in.Username); err != nil {
		return nil, err
	}

	if err := r.system.UnsuspendAccount(ctx, in.Username); err != nil {
		return nil, err
	}

	return map[string]any{"username": in.Username, "status": "active"}, nil
}

func (r *Registry) deleteAccount(ctx context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Username string `json:"username"`
		KeepData bool   `json:"keep_data"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(in.Username); err != nil {
		return nil, err
	}

	if err := r.system.DeleteAccount(ctx, in.Username, in.KeepData); err != nil {
		return nil, err
	}

	return map[string]any{"username": in.Username, "data_kept": in.KeepData}, nil
}

func (r *Registry) setQuota(ctx context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Username string `json:"username"`
		DiskMB   int    `json:"disk_mb"`
		Inodes   int    `json:"inodes"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(in.Username); err != nil {
		return nil, err
	}
	if err := validQuota(in.DiskMB, in.Inodes); err != nil {
		return nil, err
	}

	if err := r.system.ApplyQuota(ctx, in.Username, in.DiskMB, in.Inodes); err != nil {
		return nil, err
	}

	return map[string]any{"username": in.Username, "disk_mb": in.DiskMB, "inodes": in.Inodes}, nil
}

func (r *Registry) writeVhost(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[VhostSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validDomain(spec.Domain); err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validPHP(spec.PHPVersion); err != nil {
		return nil, err
	}
	if err := documentRootMustStayInsideHome(spec.DocumentRoot, spec.Username); err != nil {
		return nil, err
	}
	for _, a := range spec.Aliases {
		if err := validDomain(a); err != nil {
			return nil, fmt.Errorf("alias: %w", err)
		}
	}

	if err := r.system.WriteVhost(ctx, spec); err != nil {
		return nil, err
	}

	return map[string]any{"domain": spec.Domain, "ssl": spec.SSL}, nil
}

func (r *Registry) removeVhost(ctx context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Domain string `json:"domain"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validDomain(in.Domain); err != nil {
		return nil, err
	}

	if err := r.system.RemoveVhost(ctx, in.Domain); err != nil {
		return nil, err
	}

	return map[string]any{"domain": in.Domain, "removed": true}, nil
}

func (r *Registry) createDatabase(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[DatabaseSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validDatabase(spec.Name, spec.Username); err != nil {
		return nil, err
	}
	if len(spec.Password) < 12 || len(spec.Password) > 128 {
		return nil, fmt.Errorf("database password must be 12–128 characters")
	}

	if err := r.system.CreateDatabase(ctx, spec); err != nil {
		return nil, err
	}

	return map[string]any{"database": spec.Name, "user": spec.Username}, nil
}

func (r *Registry) dropDatabase(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[DatabaseSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validDatabase(spec.Name, spec.Username); err != nil {
		return nil, err
	}

	if err := r.system.DropDatabase(ctx, spec); err != nil {
		return nil, err
	}

	return map[string]any{"database": spec.Name, "dropped": true}, nil
}

func (r *Registry) createMailbox(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[MailboxSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validDomain(spec.Domain); err != nil {
		return nil, err
	}
	if err := validMailboxLocalPart(spec.LocalPart); err != nil {
		return nil, err
	}
	if spec.QuotaMB < 0 || spec.QuotaMB > 102400 {
		return nil, fmt.Errorf("mailbox quota must be 0–102400 MB")
	}
	if len(spec.Password) < 12 || len(spec.Password) > 128 {
		return nil, fmt.Errorf("mailbox password must be 12–128 characters")
	}

	if err := r.system.CreateMailbox(ctx, spec); err != nil {
		return nil, err
	}

	return map[string]any{"address": spec.LocalPart + "@" + spec.Domain, "quota_mb": spec.QuotaMB}, nil
}

func (r *Registry) deleteMailbox(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[MailboxSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validDomain(spec.Domain); err != nil {
		return nil, err
	}
	if err := validMailboxLocalPart(spec.LocalPart); err != nil {
		return nil, err
	}

	if err := r.system.DeleteMailbox(ctx, spec); err != nil {
		return nil, err
	}

	return map[string]any{"address": spec.LocalPart + "@" + spec.Domain, "deleted": true}, nil
}

func (r *Registry) writeZone(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[ZoneSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validDomain(spec.Domain); err != nil {
		return nil, err
	}
	if len(spec.Records) == 0 || len(spec.Records) > 500 {
		return nil, fmt.Errorf("a zone needs 1–500 records")
	}
	for _, rec := range spec.Records {
		if err := validRecord(rec); err != nil {
			return nil, err
		}
	}

	if err := r.system.WriteZone(ctx, spec); err != nil {
		return nil, err
	}

	return map[string]any{"domain": spec.Domain, "records": len(spec.Records)}, nil
}

func (r *Registry) issueCert(ctx context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Domain   string `json:"domain"`
		Username string `json:"username"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validDomain(in.Domain); err != nil {
		return nil, err
	}
	if err := validUsername(in.Username); err != nil {
		return nil, err
	}

	return r.system.IssueCertificate(ctx, in.Domain, in.Username)
}

func (r *Registry) setCron(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[CronSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validCronEntry(spec.Entry); err != nil {
		return nil, err
	}

	if err := r.system.SetCron(ctx, spec); err != nil {
		return nil, err
	}

	return map[string]any{"username": spec.Username, "entry": spec.Entry}, nil
}

func (r *Registry) runBackup(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[BackupSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if spec.Destination != "" && !strings.HasPrefix(spec.Destination, "/var/backups/") {
		return nil, fmt.Errorf("backups must be written under /var/backups")
	}

	return r.system.RunBackup(ctx, spec)
}

func (r *Registry) restoreBackup(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[RestoreSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if !strings.HasPrefix(spec.Archive, "/var/backups/") || strings.Contains(spec.Archive, "..") {
		return nil, fmt.Errorf("restore archive must live under /var/backups")
	}

	if err := r.system.RestoreBackup(ctx, spec); err != nil {
		return nil, err
	}

	return map[string]any{"username": spec.Username, "restored": spec.Archive}, nil
}

func (r *Registry) restartService(ctx context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Unit string `json:"unit"`
	}](raw)
	if err != nil {
		return nil, err
	}

	// The unit name is matched against a fixed set: `service.restart` must not
	// be able to stop anything that is not part of the hosting stack.
	switch in.Unit {
	case "apache2", "nginx", "php8.1-fpm", "php8.2-fpm", "php8.3-fpm", "php8.4-fpm", "php8.5-fpm",
		"mariadb", "mysql", "postfix", "dovecot", "pdns", "powerdns", "atlas-panel":
	default:
		return nil, fmt.Errorf("service %q is not restartable through the panel", in.Unit)
	}

	if err := r.system.RestartService(ctx, in.Unit); err != nil {
		return nil, err
	}

	return map[string]any{"unit": in.Unit, "restarted": true}, nil
}

func (r *Registry) stats(ctx context.Context, _ json.RawMessage) (any, error) {
	return r.system.Stats(ctx)
}

func (r *Registry) listFiles(ctx context.Context, raw json.RawMessage) (any, error) {
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

	return r.system.ListFiles(ctx, in.Username, in.Path)
}

func (r *Registry) readFile(ctx context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Username string `json:"username"`
		Path     string `json:"path"`
		MaxBytes int    `json:"max_bytes"`
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

	const hardCap = 1 << 20 // 1 MiB: the editor is for code, not for dumps
	if in.MaxBytes <= 0 || in.MaxBytes > hardCap {
		in.MaxBytes = hardCap
	}

	return r.system.ReadFile(ctx, in.Username, in.Path, in.MaxBytes)
}

func (r *Registry) writeFile(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[FileWriteSpec](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validHomePath(spec.Username, spec.Path); err != nil {
		return nil, err
	}

	const maxWrite = 8 << 20 // 8 MiB
	if len(spec.Content) > maxWrite {
		return nil, fmt.Errorf("file content exceeds the 8 MB write limit")
	}

	if err := r.system.WriteFile(ctx, spec); err != nil {
		return nil, err
	}

	return map[string]any{"path": spec.Path, "bytes": len(spec.Content)}, nil
}

func (r *Registry) makeDir(ctx context.Context, raw json.RawMessage) (any, error) {
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

	if err := r.system.MakeDir(ctx, in.Username, in.Path); err != nil {
		return nil, err
	}

	return map[string]any{"path": in.Path, "created": true}, nil
}

func (r *Registry) deletePath(ctx context.Context, raw json.RawMessage) (any, error) {
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

	// Deleting the home directory itself is never a file-manager action; it is
	// account termination, which has its own operation and its own policy.
	if in.Path == "/home/"+in.Username {
		return nil, fmt.Errorf("refusing to delete the account home directory")
	}

	if err := r.system.DeletePath(ctx, in.Username, in.Path); err != nil {
		return nil, err
	}

	return map[string]any{"path": in.Path, "deleted": true}, nil
}

func (r *Registry) movePath(ctx context.Context, raw json.RawMessage) (any, error) {
	in, err := decodeArgs[struct {
		Username string `json:"username"`
		From     string `json:"from"`
		To       string `json:"to"`
	}](raw)
	if err != nil {
		return nil, err
	}
	if err := validUsername(in.Username); err != nil {
		return nil, err
	}
	if err := validHomePath(in.Username, in.From); err != nil {
		return nil, err
	}
	if err := validHomePath(in.Username, in.To); err != nil {
		return nil, err
	}
	if in.From == in.To {
		return nil, fmt.Errorf("source and destination are the same path")
	}

	if err := r.system.MovePath(ctx, in.Username, in.From, in.To); err != nil {
		return nil, err
	}

	return map[string]any{"from": in.From, "to": in.To}, nil
}
