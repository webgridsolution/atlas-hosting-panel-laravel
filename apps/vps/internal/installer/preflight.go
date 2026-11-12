package installer

import (
	"fmt"
	"net"
	"os"
	"os/exec"
	"strings"
)

// Finding is one preflight result. Level "fatal" stops the install; "warn"
// prints and continues; "info" is context the operator probably wants.
type Finding struct {
	Level   string
	Message string
}

// Preflight checks the machine before the plan runs. It is deliberately
// read-only: a dry run calls it, so it must not create users or write configs.
func Preflight(p Probe, opts Options) []Finding {
	var out []Finding

	warn := func(format string, a ...any) { out = append(out, Finding{"warn", fmt.Sprintf(format, a...)}) }
	fatal := func(format string, a ...any) { out = append(out, Finding{"fatal", fmt.Sprintf(format, a...)}) }
	info := func(format string, a ...any) { out = append(out, Finding{"info", fmt.Sprintf(format, a...)}) }

	// --- operating system ---------------------------------------------------
	osInfo, err := p.OS()
	switch {
	case err != nil:
		fatal("cannot determine the operating system: %v", err)
	case osInfo.ID != "ubuntu":
		fatal("%s is not supported. Atlas currently installs on Ubuntu 22.04 or 24.04 only.", osInfo.PrettyName)
	case osInfo.VersionID != "22.04" && osInfo.VersionID != "24.04":
		fatal("Ubuntu %s is not supported yet. Supported: 22.04 LTS and 24.04 LTS.", osInfo.VersionID)
	default:
		info("%s (%s) — supported", osInfo.PrettyName, osInfo.Codename)
	}

	// --- architecture -------------------------------------------------------
	switch p.Arch() {
	case "amd64", "arm64":
		info("architecture: %s", p.Arch())
	default:
		fatal("architecture %s is not supported (amd64 or arm64)", p.Arch())
	}

	// --- privileges ---------------------------------------------------------
	if !p.IsRoot() {
		fatal("this must run as root (sudo atlas-install ...)")
	}

	// --- memory and CPU -----------------------------------------------------
	if ram, err := p.TotalRAMMB(); err != nil {
		warn("cannot read memory size: %v", err)
	} else if ram < 1800 {
		fatal("only %d MB RAM. A panel with a database and PHP-FPM needs at least 2 GB.", ram)
	} else {
		info("%d MB RAM, %d CPU(s)", ram, p.CPUs())
	}

	if p.CPUs() < 1 {
		fatal("no CPUs detected")
	}

	// --- hostname -----------------------------------------------------------
	if h := p.Hostname(); h == "" {
		warn("the hostname is empty; set one with hostnamectl so certificates and logs are readable")
	} else {
		info("hostname: %s", h)
	}

	// --- ports --------------------------------------------------------------
	for name, port := range map[string]int{
		"WHM": opts.AdminPort, "panel": opts.PanelPort, "webmail": opts.WebmailPort,
	} {
		if port < 1 || port > 65535 {
			fatal("%s port %d is not a valid port", name, port)
			continue
		}

		if !p.PortFree(port) {
			fatal("%s port %d is already in use — choose another with --%s-port", name, port, strings.ToLower(name))
		}
	}

	for _, port := range []int{80, 443} {
		if !p.PortFree(port) {
			if opts.AdoptWebserver {
				warn("port %d is in use; assuming an existing web server (--adopt-webserver)", port)
			} else {
				fatal("port %d is in use by another service. Stop it, or pass --adopt-webserver to install alongside.", port)
			}

			continue
		}
	}

	// --- existing software --------------------------------------------------
	if hasBinary("apache2ctl") && !opts.AdoptWebserver && managedApachePresent() {
		warn("an Apache installation with its own sites exists. Atlas will not modify or disable existing vhosts; " +
			"pass --adopt-webserver to install beside them.")
	}

	if hasBinary("atlas-panel") || fileExists(CurrentLink) {
		warn("an Atlas panel is already installed at %s. Use --upgrade, --repair or --rollback instead of a fresh install.", CurrentLink)
	}

	// --- disk ---------------------------------------------------------------
	if free, err := freeDiskMB("/"); err == nil {
		if free < 5000 {
			fatal("only %d MB free on /. The panel, PHP and the database need at least 5 GB.", free)
		} else if free < 10000 {
			warn("%d MB free on /; comfortable installs have 10 GB or more", free)
		} else {
			info("%d MB free on /", free)
		}
	}

	// --- DNS ----------------------------------------------------------------
	if h := p.Hostname(); h != "" {
		if ips, err := net.LookupHost(h); err == nil && len(ips) > 0 {
			info("%s resolves to %s", h, strings.Join(ips, ", "))
		} else {
			warn("%s does not resolve yet. The panel will use a self-signed certificate until DNS points here.", h)
		}
	}

	// --- licence ------------------------------------------------------------
	if opts.LicenseKey == "" {
		warn("no --license key given. The panel will install in unlicensed mode: existing sites would keep " +
			"serving, but nothing can be provisioned until a key is activated in WHM.")
	}

	return out
}

// Fatal reports whether any finding stops the install.
func Fatal(findings []Finding) bool {
	for _, f := range findings {
		if f.Level == "fatal" {
			return true
		}
	}

	return false
}

func hasBinary(name string) bool {
	_, err := exec.LookPath(name)

	return err == nil
}

func fileExists(path string) bool {
	_, err := os.Stat(path)

	return err == nil
}

// managedApachePresent reports whether Apache has site configs that are not
// ours. Those are the ones we must never touch.
func managedApachePresent() bool {
	entries, err := os.ReadDir("/etc/apache2/sites-enabled")
	if err != nil {
		return false
	}

	for _, e := range entries {
		if strings.HasPrefix(e.Name(), "atlas-") {
			continue
		}

		data, err := os.ReadFile("/etc/apache2/sites-enabled/" + e.Name())
		if err == nil && !strings.Contains(string(data), "# managed by atlas") {
			return true
		}
	}

	return false
}
