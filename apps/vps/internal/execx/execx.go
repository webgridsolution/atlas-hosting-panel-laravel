// Package execx runs privileged helpers without a shell.
//
// Rules enforced here, so no caller can forget them:
//   - the binary must be on an explicit allowlist;
//   - arguments are passed as a slice (never a string that gets split);
//   - NUL, newlines and shell metacharacters are refused outright;
//   - every command has a timeout and a bounded output buffer;
//   - the environment is fixed, not inherited.
package execx

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"os/exec"
	"regexp"
	"strings"
	"time"
)

var (
	ErrNotAllowed  = errors.New("execx: binary is not on the allowlist")
	ErrBadArgument = errors.New("execx: argument contains forbidden characters")
)

// metacharacters are refused even though we never use a shell. Defence in
// depth: a future refactor that introduces one must not become an injection.
var metacharacters = regexp.MustCompile("[;&|`$<>\\n\\r\\x00]")

// Result is the outcome of one command.
type Result struct {
	Command  []string
	Stdout   string
	Stderr   string
	ExitCode int
}

// Runner executes allowlisted binaries.
type Runner struct {
	allowlist map[string]bool
	timeout   time.Duration
	maxOutput int
	env       []string
}

// New returns a runner limited to the given binaries.
func New(timeout time.Duration, allow ...string) *Runner {
	m := make(map[string]bool, len(allow))
	for _, b := range allow {
		m[b] = true
	}

	return &Runner{
		allowlist: m,
		timeout:   timeout,
		maxOutput: 256 * 1024,
		env:       []string{"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin", "LANG=C", "LC_ALL=C"},
	}
}

// RunInput executes name with args, feeding stdin from a string. Used for SQL
// and crontab payloads so secrets and content never appear in argv (and
// therefore never in `ps`).
func (r *Runner) RunInput(ctx context.Context, stdin string, name string, args ...string) (Result, error) {
	if !r.allowlist[name] {
		return Result{}, fmt.Errorf("%w: %s", ErrNotAllowed, name)
	}

	for _, a := range args {
		if metacharacters.MatchString(a) {
			return Result{}, fmt.Errorf("%w: %q", ErrBadArgument, a)
		}
	}

	runCtx, cancel := context.WithTimeout(ctx, r.timeout)
	defer cancel()

	cmd := exec.CommandContext(runCtx, name, args...)
	cmd.Env = r.env
	cmd.Stdin = strings.NewReader(stdin)

	var stdout, stderr bytes.Buffer
	cmd.Stdout = &limitedWriter{w: &stdout, max: r.maxOutput}
	cmd.Stderr = &limitedWriter{w: &stderr, max: r.maxOutput}

	err := cmd.Run()

	res := Result{
		Command: append([]string{name}, args...),
		Stdout:  strings.TrimSpace(stdout.String()),
		Stderr:  strings.TrimSpace(stderr.String()),
	}
	if cmd.ProcessState != nil {
		res.ExitCode = cmd.ProcessState.ExitCode()
	}

	if runCtx.Err() == context.DeadlineExceeded {
		return res, fmt.Errorf("execx: %s timed out after %s", name, r.timeout)
	}
	if err != nil {
		return res, fmt.Errorf("execx: %s failed (exit %d): %s", name, res.ExitCode, firstNonEmpty(res.Stderr, res.Stdout))
	}

	return res, nil
}

// Run executes name with args. An empty name or a disallowed binary is refused
// before anything is spawned.
func (r *Runner) Run(ctx context.Context, name string, args ...string) (Result, error) {
	if !r.allowlist[name] {
		return Result{}, fmt.Errorf("%w: %s", ErrNotAllowed, name)
	}

	for _, a := range args {
		if metacharacters.MatchString(a) {
			return Result{}, fmt.Errorf("%w: %q", ErrBadArgument, a)
		}
		if len(a) > 512 {
			return Result{}, fmt.Errorf("%w: argument too long", ErrBadArgument)
		}
	}

	runCtx, cancel := context.WithTimeout(ctx, r.timeout)
	defer cancel()

	cmd := exec.CommandContext(runCtx, name, args...)
	cmd.Env = r.env

	var stdout, stderr bytes.Buffer
	cmd.Stdout = &limitedWriter{w: &stdout, max: r.maxOutput}
	cmd.Stderr = &limitedWriter{w: &stderr, max: r.maxOutput}

	err := cmd.Run()

	res := Result{
		Command: append([]string{name}, args...),
		Stdout:  strings.TrimSpace(stdout.String()),
		Stderr:  strings.TrimSpace(stderr.String()),
	}

	if cmd.ProcessState != nil {
		res.ExitCode = cmd.ProcessState.ExitCode()
	}

	if runCtx.Err() == context.DeadlineExceeded {
		return res, fmt.Errorf("execx: %s timed out after %s", name, r.timeout)
	}

	if err != nil {
		return res, fmt.Errorf("execx: %s failed (exit %d): %s", name, res.ExitCode, firstNonEmpty(res.Stderr, res.Stdout))
	}

	return res, nil
}

func firstNonEmpty(values ...string) string {
	for _, v := range values {
		if v != "" {
			return v
		}
	}

	return "(no output)"
}

// limitedWriter truncates rather than growing without bound.
type limitedWriter struct {
	w       *bytes.Buffer
	max     int
	written int
}

func (l *limitedWriter) Write(p []byte) (int, error) {
	// Report the full length even when truncating: io.Copy treats a short write
	// as an error ("short write") and aborts the whole command.
	n := len(p)

	remaining := l.max - l.written
	if remaining <= 0 {
		return n, nil
	}
	if n > remaining {
		p = p[:remaining]
	}
	l.written += len(p)
	_, _ = l.w.Write(p)

	return n, nil
}
