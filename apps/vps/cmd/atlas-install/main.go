// Command atlas-install is the one-command installer for the Atlas hosting
// panel. It is safe to re-run: every step is recorded in a state file and a
// resumed run continues from the first step that did not finish.
//
//	atlas-install --license ATLS-XXXX-XXXX-XXXX-XXXX
//	atlas-install --dry-run --license ...        # print the plan, change nothing
//	atlas-install --resume                        # continue after a failure
//	atlas-install --repair                        # re-run from the failed step
//	atlas-install --upgrade --package atlas-0.9.1.tar.gz
//	atlas-install --rollback
//	atlas-install --uninstall [--delete-data --yes-really]
package main

import (
	"context"
	"flag"
	"fmt"
	"log"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"syscall"
	"time"

	"atlas/internal/installer"
)

const version = "0.9.0"

func main() {
	var (
		license    = flag.String("license", "", "licence key from the Atlas customer portal")
		adminPort  = flag.Int("admin-port", 2087, "WHM console port")
		panelPort  = flag.Int("panel-port", 2083, "customer panel port")
		mailPort   = flag.Int("webmail-port", 2096, "webmail port")
		skipMail   = flag.Bool("skip-mail", false, "do not install the mail stack")
		adopt      = flag.Bool("adopt-webserver", false, "install alongside an existing web server")
		centralURL = flag.String("central-url", installer.DefaultOptions().CentralURL, "licensing service base URL")
		dryRun     = flag.Bool("dry-run", false, "print the plan without changing anything")
		resume     = flag.Bool("resume", false, "continue an interrupted install")
		repair     = flag.Bool("repair", false, "retry from the failed step")
		force      = flag.Bool("force", false, "re-run every step, including completed ones")
		upgrade    = flag.Bool("upgrade", false, "install a newer release beside the current one")
		pkg        = flag.String("package", "", "release tarball for --upgrade")
		rollback   = flag.Bool("rollback", false, "switch back to the previous release")
		uninstall  = flag.Bool("uninstall", false, "remove the panel (keeps customer data)")
		deleteData = flag.Bool("delete-data", false, "with --uninstall: also delete Atlas state and backups")
		yesReally  = flag.Bool("yes-really", false, "confirm data deletion")
		logPath    = flag.String("log", "/var/log/atlas/install.log", "installer log file")
		statePath  = flag.String("state", installer.StatePath, "install state file")
		payload    = flag.String("payload", "", "unpacked release directory (default: next to this binary)")
		showVer    = flag.Bool("version", false, "print version and exit")
	)

	flag.Usage = usage

	if err := flag.CommandLine.Parse(os.Args[1:]); err != nil {
		os.Exit(2)
	}

	if *showVer {
		fmt.Println("atlas-install " + version)
		os.Exit(0)
	}

	logger, closeLog, err := openLog(*logPath)
	if err != nil {
		log.Fatalf("cannot open log: %v", err)
	}
	defer closeLog()

	opts := installer.DefaultOptions()
	opts.LicenseKey = *license
	opts.AdminPort = *adminPort
	opts.PanelPort = *panelPort
	opts.WebmailPort = *mailPort
	opts.SkipMail = *skipMail
	opts.AdoptWebserver = *adopt
	opts.CentralURL = *centralURL
	opts.PanelVersion = version
	opts.Payload = *payload

	if opts.Payload == "" {
		if exe, err := os.Executable(); err == nil {
			// The release layout is <release>/bin/atlas-install, so the payload
			// root is the parent of bin/, not the directory holding the binary.
			dir := filepath.Dir(exe)
			if filepath.Base(dir) == "bin" {
				dir = filepath.Dir(dir)
			}
			opts.Payload = dir
		}
	}

	probe := installer.LinuxProbe{}
	exec := installer.RealExec{Logger: logger}
	fs := installer.RealFS{}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	// One line at the top of every run so the log is self-explanatory.
	osInfo, _ := probe.OS()
	logger(fmt.Sprintf("atlas-install %s on %s (%s)", version, osInfo.PrettyName, probe.Arch()))

	// --uninstall and --rollback are single actions, not plan runs.
	if *uninstall {
		if *deleteData && !*yesReally {
			fmt.Fprintln(os.Stderr, "refusing: --delete-data requires --yes-really")
			os.Exit(2)
		}

		if err := installer.Uninstall(ctx, exec, fs, *deleteData); err != nil {
			logger("uninstall failed: " + err.Error())
			os.Exit(1)
		}

		logger("panel removed; customer sites, databases, mail and DNS zones were left untouched")
		fmt.Println("Atlas panel removed. Customer hosting data was left in place.")

		return
	}

	if *rollback {
		name, err := installer.Rollback(fs, installer.ReleaseRoot, installer.CurrentLink)
		if err != nil {
			logger("rollback failed: " + err.Error())
			os.Exit(1)
		}

		_, _ = exec.Run(ctx, "systemctl", "restart", "atlas-panel")
		fmt.Printf("rolled back to release %s\n", name)
		logger("rolled back to " + name)

		return
	}

	if *upgrade {
		if *pkg == "" {
			fmt.Fprintln(os.Stderr, "--upgrade needs --package <tarball>")
			os.Exit(2)
		}

		if err := upgradeRelease(ctx, exec, fs, *pkg, logger); err != nil {
			logger("upgrade failed: " + err.Error())
			os.Exit(1)
		}

		fmt.Println("upgrade installed; roll back with --rollback if anything looks wrong")

		return
	}

	plan := installer.Plan()

	if *dryRun {
		fmt.Print(installer.DryRun(plan, opts))

		// Still run preflight: a dry run that hides a fatal problem is useless.
		findings := installer.Preflight(probe, opts)
		if len(findings) > 0 {
			fmt.Println("\nPreflight:")
			for _, f := range findings {
				fmt.Printf("  [%s] %s\n", f.Level, f.Message)
			}
		}

		return
	}

	session, err := installer.LoadState(*statePath, version)
	if err != nil {
		log.Fatalf("cannot load install state: %v", err)
	}

	if !*resume && !*repair && !*force && session.NextStep(plan) == len(plan) {
		fmt.Println("Atlas is already installed on this server.")
		fmt.Println("Use --repair to re-check, --upgrade to install a new release, or --rollback.")
		os.Exit(0)
	}

	if *repair {
		session.ResetFailed(plan)
	}

	// Print what is about to happen before doing it.
	fmt.Printf("Atlas %s installer\n\n", version)
	fmt.Printf("  This will install a web server, PHP-FPM, MariaDB, a firewall and the\n")
	fmt.Printf("  Atlas panel on this machine (%s).\n\n", osInfo.PrettyName)
	fmt.Printf("  Existing websites are not touched. Existing web server configs are\n")
	fmt.Printf("  refused unless --adopt-webserver was passed.\n\n")

	env := &installer.Env{
		Opts:   opts,
		Exec:   exec,
		FS:     fs,
		Probe:  probe,
		State:  session,
		Stdout: func(format string, a ...any) { logger(fmt.Sprintf(format, a...)) },
	}

	start := time.Now()
	if err := installer.Run(ctx, env, plan, *force); err != nil {
		fmt.Fprintf(os.Stderr, "\nInstallation stopped: %v\n", err)
		fmt.Fprintf(os.Stderr, "Fix the problem, then run: atlas-install --resume\n")
		logger("FAILED: " + err.Error())
		os.Exit(1)
	}

	printSummary(opts, hostnameOf(probe))
	logger(fmt.Sprintf("install complete in %s", time.Since(start).Round(time.Second)))
}

func printSummary(o installer.Options, host string) {
	fmt.Printf(`
Atlas is installed.

  WHM console      https://%[1]s:%[2]d
  Customer panel   https://%[1]s:%[3]d
  Webmail          https://%[1]s:%[4]d

The certificate is self-signed until you point a hostname at this server and
press "Issue certificate" in the WHM console — your browser will warn once.

Next steps:
  1. Open the WHM console and complete the setup wizard.
  2. Create the first admin account (there is no default password).
  3. Add a hosting account and publish a site.

Installer log: /var/log/atlas/install.log
Report problems with: atlas-agent -version and the log above.
`, host, o.AdminPort, o.PanelPort, o.WebmailPort)
}

func hostnameOf(p installer.Probe) string {
	h := p.Hostname()
	if h == "" {
		return "<server-ip>"
	}

	// A bare container id is not useful to a human; prefer the IP placeholder.
	if strings.Count(h, "-") > 4 {
		return "<server-ip>"
	}

	return h
}

func upgradeRelease(ctx context.Context, exec installer.Exec, fs installer.FS, tarball string, logger func(string)) error {
	if _, err := os.Stat(tarball); err != nil {
		return fmt.Errorf("release tarball not found: %w", err)
	}

	// Checksums travel beside the tarball, published by the same build job.
	sumFile := tarball + ".sha256"
	if _, err := os.Stat(sumFile); err != nil {
		return fmt.Errorf("missing checksum file %s — refusing to install an unverified release", sumFile)
	}

	if _, err := exec.Run(ctx, "sha256sum", "-c", sumFile); err != nil {
		return fmt.Errorf("release checksum mismatch: %w", err)
	}

	target := filepath.Join(installer.ReleaseRoot, fmt.Sprintf("atlas-%d", time.Now().Unix()))
	if err := fs.MkdirAll(target, 0o755); err != nil {
		return err
	}

	if _, err := exec.Run(ctx, "tar", "-xzf", tarball, "-C", target, "--strip-components=1"); err != nil {
		return err
	}

	// Migrations run against the new code but before the symlink flips, so a
	// failure leaves the previous release serving traffic.
	if _, err := exec.Run(ctx, "php", filepath.Join(target, "artisan"), "migrate", "--force"); err != nil {
		logger("migration failed; the current release is untouched")

		return err
	}

	if err := fs.Symlink(target, installer.CurrentLink); err != nil {
		return err
	}

	_, err := exec.Run(ctx, "systemctl", "restart", "atlas-panel")

	return err
}

func openLog(path string) (func(string), func(), error) {
	// A dry run is often done as an unprivileged user to preview the plan, so a
	// log we cannot write is not fatal: fall back to the terminal and say so.
	if err := os.MkdirAll(filepath.Dir(path), 0o750); err != nil {
		return consoleLogger(path), func() {}, nil
	}

	f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o640)
	if err != nil {
		return consoleLogger(path), func() {}, nil
	}

	logger := func(line string) {
		stamped := time.Now().Format("2006-01-02 15:04:05 ") + line
		fmt.Println(line)
		_, _ = f.WriteString(stamped + "\n")
	}

	return logger, func() { _ = f.Close() }, nil
}

func consoleLogger(path string) func(string) {
	return func(line string) {
		fmt.Println(line)
		fmt.Fprintf(os.Stderr, "(log file %s is not writable; output is on the terminal only)\n", path)
	}
}

func usage() {
	fmt.Fprintf(os.Stderr, `Atlas installer %s

  atlas-install --license KEY [options]     install
  atlas-install --dry-run --license KEY     show the plan only
  atlas-install --resume                    continue an interrupted install
  atlas-install --repair                    retry from the failed step
  atlas-install --upgrade --package t.tgz   install a newer release
  atlas-install --rollback                  return to the previous release
  atlas-install --uninstall                 remove the panel, keep data

Options:
`, version)
	flag.PrintDefaults()
}
