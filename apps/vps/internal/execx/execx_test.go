package execx

import (
	"context"
	"errors"
	"strings"
	"testing"
	"time"
)

func TestRejectsBinariesOutsideTheAllowlist(t *testing.T) {
	r := New(2*time.Second, "true")

	if _, err := r.Run(context.Background(), "sh", "-c", "id"); !errors.Is(err, ErrNotAllowed) {
		t.Fatalf("want ErrNotAllowed for sh, got %v", err)
	}
}

func TestRejectsShellMetacharacters(t *testing.T) {
	r := New(2*time.Second, "echo")

	for _, arg := range []string{"a; rm -rf /", "$(id)", "`id`", "a\nb", "a|b", "a&&b"} {
		if _, err := r.Run(context.Background(), "echo", arg); !errors.Is(err, ErrBadArgument) {
			t.Fatalf("arg %q must be refused, got %v", arg, err)
		}
	}
}

func TestRunsAnAllowlistedBinary(t *testing.T) {
	r := New(5*time.Second, "echo")

	res, err := r.Run(context.Background(), "echo", "hello")
	if err != nil {
		t.Fatal(err)
	}
	if res.Stdout != "hello" {
		t.Fatalf("want hello, got %q", res.Stdout)
	}
	if res.ExitCode != 0 {
		t.Fatalf("want exit 0, got %d", res.ExitCode)
	}
}

func TestNonZeroExitReturnsAnError(t *testing.T) {
	r := New(5*time.Second, "false")

	res, err := r.Run(context.Background(), "false")
	if err == nil {
		t.Fatal("false must return an error")
	}
	if res.ExitCode == 0 {
		t.Fatal("exit code must be recorded")
	}
}

func TestTimeoutIsEnforced(t *testing.T) {
	r := New(200*time.Millisecond, "sleep")

	start := time.Now()
	_, err := r.Run(context.Background(), "sleep", "5")
	if err == nil || !strings.Contains(err.Error(), "timed out") {
		t.Fatalf("want a timeout error, got %v", err)
	}
	if elapsed := time.Since(start); elapsed > 3*time.Second {
		t.Fatalf("timeout took %s — the process was not killed", elapsed)
	}
}

func TestOutputIsBounded(t *testing.T) {
	r := New(10*time.Second, "printf")
	r.maxOutput = 16

	res, err := r.Run(context.Background(), "printf", "%s", strings.Repeat("x", 200))
	if err != nil {
		t.Fatal(err)
	}
	if len(res.Stdout) > 16 {
		t.Fatalf("output must be truncated to 16 bytes, got %d", len(res.Stdout))
	}
}
