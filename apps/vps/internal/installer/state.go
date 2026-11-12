package installer

import (
	"encoding/json"
	"fmt"
	"os"
	"time"
)

// Session is the persisted progress of an install. It is written after every
// step, so killing the process at any point leaves a resumable state.
type Session struct {
	Version     string            `json:"version"`
	StartedAt   string            `json:"started_at"`
	UpdatedAt   string            `json:"updated_at"`
	Completed   map[string]string `json:"completed"` // step -> RFC3339 time
	Failed      string            `json:"failed"`    // last step that failed
	FailedError string            `json:"failed_error"`
	path        string
}

// LoadState reads the state file, or returns a fresh session when there is
// none. A corrupt file is an error, not an excuse to start over silently: that
// would re-run steps against a half-installed server.
func LoadState(path, version string) (*Session, error) {
	s := &Session{
		Version:   version,
		StartedAt: time.Now().UTC().Format(time.RFC3339),
		Completed: map[string]string{},
		path:      path,
	}

	data, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			return s, nil
		}

		return nil, err
	}

	if err := json.Unmarshal(data, s); err != nil {
		return nil, fmt.Errorf("install state at %s is corrupt: %w", path, err)
	}

	if s.Completed == nil {
		s.Completed = map[string]string{}
	}

	s.path = path

	return s, nil
}

// NextStep returns the index of the first step that has not completed.
func (s *Session) NextStep(plan []Step) int {
	for i, step := range plan {
		if _, done := s.Completed[step.Name]; !done {
			return i
		}
	}

	return len(plan)
}

// Mark records a successful step and flushes to disk.
func (s *Session) Mark(name string) error {
	s.Completed[name] = time.Now().UTC().Format(time.RFC3339)
	s.Failed = ""
	s.FailedError = ""

	return s.save()
}

// Fail records the step that failed and why.
func (s *Session) Fail(name string, err error) {
	s.Failed = name
	s.FailedError = err.Error()
	_ = s.save()
}

// ResetFailed clears the failure marker (and, unless the step actually
// completed, its completion entry) so --repair retries the right step.
func (s *Session) ResetFailed(plan []Step) {
	s.Failed = ""
	s.FailedError = ""
	_ = s.save()
}

func (s *Session) save() error {
	if s.path == "" {
		return nil
	}

	s.UpdatedAt = time.Now().UTC().Format(time.RFC3339)

	if err := os.MkdirAll(dirOf(s.path), 0o750); err != nil {
		return err
	}

	data, err := json.MarshalIndent(s, "", "  ")
	if err != nil {
		return err
	}

	// Write-then-rename: a power cut mid-write must not leave a truncated file
	// that makes the next run think nothing was done.
	tmp := s.path + ".tmp"

	if err := os.WriteFile(tmp, data, 0o640); err != nil {
		return err
	}

	return os.Rename(tmp, s.path)
}

func dirOf(path string) string {
	for i := len(path) - 1; i >= 0; i-- {
		if path[i] == '/' {
			return path[:i]
		}
	}

	return "."
}
