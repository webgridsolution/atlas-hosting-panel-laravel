// Package installer performs the one-command installation of the Atlas panel.
//
// Design rules, inherited from docs/06 and enforced here:
//   - every step is idempotent and records its completion in a state file, so
//     an interrupted install resumes instead of starting over;
//   - nothing the installer did not create is overwritten: an existing Apache
//     or nginx configuration stops the run and asks for --adopt-webserver;
//   - every privileged command goes through Exec, which never uses a shell.
package installer

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"
)

// StatePath records which steps completed.
const StatePath = "/var/lib/atlas/install-state.json"

// ReleaseRoot holds one directory per installed release; CurrentLink points at
// the active one. Upgrades add a directory, rollback flips the symlink.
const (
	ReleaseRoot = "/opt/atlas/releases"
	CurrentLink = "/opt/atlas/current"
)

// Exec runs a privileged command. There is no shell and no string splitting:
// arguments are passed as a slice, which is the only way to keep a filename
// with a space from becoming two arguments.
type Exec interface {
	Run(ctx context.Context, name string, args ...string) (Result, error)
}

// Result is the outcome of one command.
type Result struct {
	Stdout string
	Stderr string
	Code   int
}

// RealExec runs commands on the machine and logs each one.
type RealExec struct {
	Logger func(string)
}

func (e RealExec) Run(ctx context.Context, name string, args ...string) (Result, error) {
	if e.Logger != nil {
		e.Logger("  $ " + strings.Join(append([]string{name}, args...), " "))
	}

	runCtx, cancel := context.WithTimeout(ctx, 30*time.Minute)
	defer cancel()

	cmd := exec.CommandContext(runCtx, name, args...)
	cmd.Env = []string{
		"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
		"DEBIAN_FRONTEND=noninteractive",
		"LC_ALL=C",
	}

	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	err := cmd.Run()

	res := Result{Stdout: strings.TrimSpace(stdout.String()), Stderr: strings.TrimSpace(stderr.String())}
	if cmd.ProcessState != nil {
		res.Code = cmd.ProcessState.ExitCode()
	}

	if err != nil {
		return res, fmt.Errorf("%s %s: %w: %s", name, strings.Join(args, " "), err, firstLine(res.Stderr))
	}

	return res, nil
}

func firstLine(s string) string {
	if i := strings.IndexByte(s, '\n'); i >= 0 {
		s = s[:i]
	}
	if s == "" {
		return "(no output)"
	}

	return s
}

// FS is the installer's view of the filesystem, injectable for tests.
type FS interface {
	ReadFile(path string) ([]byte, error)
	WriteFile(path string, data []byte, mode os.FileMode) error
	Exists(path string) bool
	MkdirAll(path string, mode os.FileMode) error
	Remove(path string) error
	Symlink(target, link string) error
	Readlink(link string) (string, error)
	ReadDir(path string) ([]os.DirEntry, error)
	CopyFile(src, dst string, mode os.FileMode) error
}

// RealFS is the real filesystem.
type RealFS struct{}

func (RealFS) ReadFile(p string) ([]byte, error) { return os.ReadFile(p) }
func (RealFS) WriteFile(p string, data []byte, mode os.FileMode) error {
	return os.WriteFile(p, data, mode)
}
func (RealFS) Exists(p string) bool {
	_, err := os.Stat(p)

	return err == nil
}
func (RealFS) MkdirAll(p string, mode os.FileMode) error { return os.MkdirAll(p, mode) }
func (RealFS) Remove(p string) error                     { return os.RemoveAll(p) }
func (RealFS) ReadDir(p string) ([]os.DirEntry, error)   { return os.ReadDir(p) }
func (RealFS) Readlink(p string) (string, error)         { return os.Readlink(p) }

func (RealFS) Symlink(target, link string) error {
	// Replace atomically: a dangling symlink must not block an upgrade.
	_ = os.Remove(link)

	return os.Symlink(target, link)
}

func (RealFS) CopyFile(src, dst string, mode os.FileMode) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()

	if err := os.MkdirAll(filepath.Dir(dst), 0o755); err != nil {
		return err
	}

	out, err := os.OpenFile(dst, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, mode)
	if err != nil {
		return err
	}
	defer out.Close()

	_, err = io.Copy(out, in)

	return err
}

// OSInfo is what the installer learned about the machine.
type OSInfo struct {
	ID         string // ubuntu
	VersionID  string // 24.04
	PrettyName string // Ubuntu 24.04.3 LTS
	Codename   string // noble
}

// Probe reads facts about the machine.
//
// It includes the two checks a test cannot fake from the outside — effective
// UID and whether a port is bindable — so the preflight logic itself is
// testable and the plan runs the same code the dry run does.
type Probe interface {
	OS() (OSInfo, error)
	Arch() string
	Hostname() string
	TotalRAMMB() (int, error)
	CPUs() int
	IsRoot() bool
	PortFree(port int) bool
}

// IsRoot reports an effective UID of 0.
func (LinuxProbe) IsRoot() bool { return os.Geteuid() == 0 }

// PortFree tries to bind the port and immediately releases it.
func (LinuxProbe) PortFree(port int) bool {
	l, err := net.Listen("tcp", fmt.Sprintf(":%d", port))
	if err != nil {
		return false
	}

	_ = l.Close()

	return true
}

// LinuxProbe reads /etc/os-release and /proc.
type LinuxProbe struct{}

func (LinuxProbe) OS() (OSInfo, error) {
	data, err := os.ReadFile("/etc/os-release")
	if err != nil {
		return OSInfo{}, fmt.Errorf("cannot read /etc/os-release: %w", err)
	}

	info := OSInfo{}

	for _, line := range strings.Split(string(data), "\n") {
		key, value, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}

		value = strings.Trim(value, `"'`)

		switch key {
		case "ID":
			info.ID = value
		case "VERSION_ID":
			info.VersionID = value
		case "PRETTY_NAME":
			info.PrettyName = value
		case "VERSION_CODENAME":
			info.Codename = value
		}
	}

	if info.PrettyName == "" {
		info.PrettyName = info.ID + " " + info.VersionID
	}

	return info, nil
}

func (LinuxProbe) Arch() string {
	switch runtimeArch() {
	case "amd64":
		return "amd64"
	case "arm64":
		return "arm64"
	default:
		return runtimeArch()
	}
}

func (LinuxProbe) Hostname() string {
	h, err := os.Hostname()
	if err != nil {
		return ""
	}

	return h
}

func (LinuxProbe) TotalRAMMB() (int, error) {
	data, err := os.ReadFile("/proc/meminfo")
	if err != nil {
		return 0, err
	}

	for _, line := range strings.Split(string(data), "\n") {
		if !strings.HasPrefix(line, "MemTotal:") {
			continue
		}

		fields := strings.Fields(line)
		if len(fields) < 2 {
			break
		}

		var kb int
		if _, err := fmt.Sscanf(fields[1], "%d", &kb); err != nil {
			return 0, err
		}

		return kb / 1024, nil
	}

	return 0, fmt.Errorf("MemTotal not found in /proc/meminfo")
}

func (LinuxProbe) CPUs() int { return runtimeNumCPU() }
