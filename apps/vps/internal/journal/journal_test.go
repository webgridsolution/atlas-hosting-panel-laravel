package journal

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func newJournal(t *testing.T) (*Journal, string) {
	t.Helper()
	path := filepath.Join(t.TempDir(), "journal.jsonl")
	j, err := Open(path)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = j.Close() })

	return j, path
}

func TestAppendAndVerify(t *testing.T) {
	j, path := newJournal(t)

	for i := 0; i < 3; i++ {
		if _, err := j.Append("account.create", "panel", "abc", "ok", "alice", time.Now()); err != nil {
			t.Fatal(err)
		}
	}

	n, err := Verify(path)
	if err != nil {
		t.Fatalf("verify: %v", err)
	}
	if n != 3 {
		t.Fatalf("want 3 entries, got %d", n)
	}
}

func TestReopenContinuesTheChain(t *testing.T) {
	j, path := newJournal(t)
	if _, err := j.Append("a", "panel", "h", "ok", "", time.Now()); err != nil {
		t.Fatal(err)
	}
	_ = j.Close()

	j2, err := Open(path)
	if err != nil {
		t.Fatal(err)
	}
	defer j2.Close()

	e, err := j2.Append("b", "panel", "h", "ok", "", time.Now())
	if err != nil {
		t.Fatal(err)
	}
	if e.Seq != 2 {
		t.Fatalf("want seq 2 after reopen, got %d", e.Seq)
	}

	if n, err := Verify(path); err != nil || n != 2 {
		t.Fatalf("verify after reopen: n=%d err=%v", n, err)
	}
}

func TestEditedRecordIsDetected(t *testing.T) {
	j, path := newJournal(t)
	for i := 0; i < 3; i++ {
		_, _ = j.Append("account.create", "panel", "h", "ok", "alice", time.Now())
	}
	_ = j.Close()

	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}

	// Change the actor on the second line — the sort of edit an attacker with
	// file access would make to hide their action.
	lines := strings.Split(strings.TrimSpace(string(raw)), "\n")
	lines[1] = strings.Replace(lines[1], `"actor":"panel"`, `"actor":"root"`, 1)
	if err := os.WriteFile(path, []byte(strings.Join(lines, "\n")+"\n"), 0o640); err != nil {
		t.Fatal(err)
	}

	if _, err := Verify(path); err == nil || !strings.Contains(err.Error(), "modified") {
		t.Fatalf("want a modification error, got %v", err)
	}
}

func TestDeletedRecordIsDetected(t *testing.T) {
	j, path := newJournal(t)
	for i := 0; i < 4; i++ {
		_, _ = j.Append("op", "panel", "h", "ok", "", time.Now())
	}
	_ = j.Close()

	raw, _ := os.ReadFile(path)
	lines := strings.Split(strings.TrimSpace(string(raw)), "\n")
	// Remove the second entry entirely.
	lines = append(lines[:1], lines[2:]...)
	_ = os.WriteFile(path, []byte(strings.Join(lines, "\n")+"\n"), 0o640)

	if _, err := Verify(path); err == nil {
		t.Fatal("deleting a record must break verification")
	}
}

func TestConcurrentAppendsAreSequential(t *testing.T) {
	j, path := newJournal(t)

	done := make(chan struct{})
	for i := 0; i < 50; i++ {
		go func() {
			defer func() { done <- struct{}{} }()
			_, _ = j.Append("op", "panel", "h", "ok", "", time.Now())
		}()
	}
	for i := 0; i < 50; i++ {
		<-done
	}

	n, err := Verify(path)
	if err != nil || n != 50 {
		t.Fatalf("n=%d err=%v", n, err)
	}
}
