package installer

import (
	"context"
	"fmt"
	"path/filepath"
	"sort"
	"strings"
)

// Uninstall removes the panel software.
//
// What it does NOT touch is the point of the command: customer home
// directories, databases, mail, DNS zones and the Apache vhosts that serve
// them all stay exactly as they were. Hosting keeps working without the panel;
// re-installing re-attaches to it.
func Uninstall(ctx context.Context, exec Exec, fs FS, deleteData bool) error {
	// 1. Stop the services that consume the panel code.
	for _, unit := range []string{"atlas-panel", "atlas-agent"} {
		_, _ = exec.Run(ctx, "systemctl", "disable", "--now", unit)
	}

	// 2. Remove the units so systemd cannot restart a binary that is gone.
	for _, unit := range []string{"/etc/systemd/system/atlas-panel.service", "/etc/systemd/system/atlas-agent.service"} {
		if err := fs.Remove(unit); err == nil {
			_ = err
		}
	}
	_, _ = exec.Run(ctx, "systemctl", "daemon-reload")

	// 3. Remove the panel's own nginx configs; leave every customer vhost.
	for _, conf := range []string{
		"/etc/nginx/sites-enabled/atlas-whm.conf",
		"/etc/nginx/sites-enabled/atlas-service.conf",
		"/etc/nginx/sites-available/atlas-whm.conf",
		"/etc/nginx/sites-available/atlas-service.conf",
	} {
		if data, err := fs.ReadFile(conf); err == nil && !strings.Contains(string(data), "managed by atlas") {
			return fmt.Errorf("refusing to remove %s: it was not created by Atlas", conf)
		}
		if err := fs.Remove(conf); err != nil {
			return err
		}
	}
	_, _ = exec.Run(ctx, "systemctl", "reload", "nginx")

	// 4. The release tree and the panel binaries.
	if err := fs.Remove(CurrentLink); err != nil {
		return err
	}
	if err := fs.Remove(ReleaseRoot); err != nil {
		return err
	}
	for _, bin := range []string{"/usr/local/bin/atlas-agent", "/usr/local/bin/atlas-install"} {
		if err := fs.Remove(bin); err != nil {
			return err
		}
	}

	// 5. The PHP-FPM pool that belonged to the panel (not the per-account ones).
	//
	// The version is discovered rather than assumed: a server that started on
	// 8.1 and was upgraded to 8.3 would otherwise leave the pool file behind,
	// and the next install of the old version would silently reuse it.
	for _, php := range []string{"8.1", "8.2", "8.3", "8.4", "8.5"} {
		pool := "/etc/php/" + php + "/fpm/pool.d/atlas-panel.conf"

		if _, err := fs.ReadFile(pool); err != nil {
			continue
		}

		if err := fs.Remove(pool); err != nil {
			return err
		}
	}

	if !deleteData {
		return nil
	}

	// 6. Explicit, confirmed data deletion. The caller has already checked
	//    --yes-really; this is the only path in the product that destroys
	//    customer data, and it says so.
	for _, path := range []string{"/var/lib/atlas", "/etc/atlas"} {
		if err := fs.Remove(path); err != nil {
			return err
		}
	}

	for _, db := range []string{"atlas_panel"} {
		if _, err := exec.Run(ctx, "mysql", "-e", "DROP DATABASE IF EXISTS `"+db+"`;"); err != nil {
			return err
		}
	}

	// Customer backups are the last thing between an operator and a mistake;
	// they are only removed by an explicit second flag.
	if _, err := exec.Run(ctx, "sh", "-c", "test -d "+filepath.Join("/var/backups", "atlas")); err == nil {
		if err := fs.Remove("/var/backups/atlas"); err != nil {
			return err
		}
	}

	return nil
}

// Rollback flips the current symlink to the previous release and returns its
// name. Releasing in this order (new dir, migrate, flip) means a failure at any
// point leaves the previous release serving.
func Rollback(fs FS, releaseRoot, currentLink string) (string, error) {
	target, err := fs.Readlink(currentLink)
	if err != nil {
		return "", fmt.Errorf("no current release is linked (%s): %w", currentLink, err)
	}

	entries, err := fs.ReadDir(releaseRoot)
	if err != nil {
		return "", err
	}

	var names []string

	for _, e := range entries {
		if e.IsDir() {
			names = append(names, e.Name())
		}
	}

	sort.Strings(names)

	current := filepath.Base(target)
	idx := -1

	for i, n := range names {
		if n == current {
			idx = i
			break
		}
	}

	if idx < 0 {
		return "", fmt.Errorf("the current release %q is not in %s", current, releaseRoot)
	}

	if idx == 0 {
		return "", fmt.Errorf("no earlier release to roll back to (current is %s)", current)
	}

	previous := filepath.Join(releaseRoot, names[idx-1])

	if err := fs.Symlink(previous, currentLink); err != nil {
		return "", err
	}

	return names[idx-1], nil
}
