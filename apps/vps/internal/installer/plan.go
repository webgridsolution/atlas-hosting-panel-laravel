package installer

import (
	"fmt"
	"strings"
)

// Options are the choices an operator made on the command line.
type Options struct {
	LicenseKey     string
	AdminPort      int
	PanelPort      int
	WebmailPort    int
	SkipMail       bool
	AdoptWebserver bool
	CentralURL     string
	PanelVersion   string
	// Payload is the unpacked release directory (bin/, panel/, nginx/,
	// php-fpm/, systemd/). Defaults to the directory holding the binary.
	Payload string
}

func DefaultOptions() Options {
	return Options{
		AdminPort:    2087,
		PanelPort:    2083,
		WebmailPort:  2096,
		CentralURL:   "https://api.atlas.example",
		PanelVersion: "0.9.0",
	}
}

// Step is one unit of work. Name is stable (it is written to the state file),
// Describe is shown to the operator and must stay honest about what happens.
type Step struct {
	Name     string
	Describe string
	Run      func(ctx *RunContext) error
}

// Plan is the ordered install. The order is not cosmetic: the database must
// exist before migrations, the agent before the panel can health-check it.
func Plan() []Step {
	return []Step{
		{"preflight", "Check OS, architecture, memory, ports and existing services", stepPreflight},
		{"license", "Verify the licence with the vendor and store the signed token", stepLicense},
		{"packages", "Install the web server, PHP, database and firewall packages", stepPackages},
		{"panel_user", "Create the unprivileged atlas service user", stepPanelUser},
		{"database", "Create the panel database and a dedicated MySQL user", stepDatabase},
		{"ssl", "Create the self-signed bootstrap certificate", stepSSL},
		{"firewall", "Configure ufw for 80/443 and the panel ports", stepFirewall},
		{"agent", "Install and start the privileged agent", stepAgent},
		{"panel", "Deploy the panel release, migrate and enable the services", stepPanel},
		{"healthcheck", "Confirm the agent, the panel and the licence are healthy", stepHealthcheck},
	}
}

// DryRun prints what would happen, including the exact ports and paths, so an
// operator can review before anything changes.
func DryRun(plan []Step, opts Options) string {
	var b strings.Builder

	fmt.Fprintf(&b, "Atlas installer — dry run (nothing will be changed)\n\n")
	fmt.Fprintf(&b, "Release payload : %s\n", orDefault(opts.Payload, "(next to the binary)"))
	fmt.Fprintf(&b, "Licence key     : %s\n", maskKey(opts.LicenseKey))
	fmt.Fprintf(&b, "Central service : %s\n", opts.CentralURL)
	fmt.Fprintf(&b, "Web server      : %s\n\n", adoptNote(opts))

	for i, step := range plan {
		fmt.Fprintf(&b, "%2d. %-12s %s\n", i+1, step.Name, step.Describe)
	}

	fmt.Fprintf(&b, "\nPorts: WHM %d, panel %d, webmail %d\n", opts.AdminPort, opts.PanelPort, opts.WebmailPort)
	fmt.Fprintf(&b, "State: %s\n", StatePath)
	fmt.Fprintf(&b, "Releases: %s (current -> %s)\n", ReleaseRoot, CurrentLink)

	if opts.SkipMail {
		fmt.Fprintf(&b, "\nMail: skipped (--skip-mail). The mail module will be hidden in the panel.\n")
	} else {
		fmt.Fprintf(&b, "\nMail: Postfix + Dovecot will be installed and the mail module enabled.\n")
	}

	return b.String()
}

func adoptNote(opts Options) string {
	if opts.AdoptWebserver {
		return "install alongside an existing web server (--adopt-webserver)"
	}

	return "install our own Apache; an existing unmanaged Apache config stops the run"
}

func orDefault(v, def string) string {
	if v == "" {
		return def
	}

	return v
}

// maskKey keeps the first and last block so an operator can confirm they pasted
// the right key without the log becoming a credential store.
func maskKey(k string) string {
	if k == "" {
		return "(none — the panel will start unlicensed)"
	}

	parts := strings.Split(k, "-")
	if len(parts) < 3 {
		return "****"
	}

	return parts[0] + "-****-" + parts[len(parts)-1]
}
