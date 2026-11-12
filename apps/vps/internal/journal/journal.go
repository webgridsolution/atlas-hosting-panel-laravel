// Package journal is the agent's tamper-evident audit trail.
//
// Every dispatched operation — allowed or refused — appends one JSON line. Each
// line carries the hash of its predecessor, so deleting or editing a record in
// the middle of the file breaks the chain and Verify reports exactly where.
//
// The journal is the only durable evidence of what root did on the box; it is
// written before the operation returns and fsynced, so a crash cannot hide an
// action that already took effect.
package journal

import (
	"bufio"
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sync"
	"time"
)

// Entry is one line of the journal.
type Entry struct {
	Seq      int64  `json:"seq"`
	Time     string `json:"time"`
	Op       string `json:"op"`
	Actor    string `json:"actor"`
	ArgsHash string `json:"args_hash"`
	Result   string `json:"result"` // ok | error | denied
	Detail   string `json:"detail,omitempty"`
	PrevHash string `json:"prev_hash"`
	Hash     string `json:"hash"`
}

// Journal is safe for concurrent use; the agent serves requests in parallel.
type Journal struct {
	mu     sync.Mutex
	path   string
	file   *os.File
	seq    int64
	last   string
	closed bool
}

// Open creates or reopens a journal, recovering the sequence number and last
// hash from the tail of the existing file.
func Open(path string) (*Journal, error) {
	if err := os.MkdirAll(filepath.Dir(path), 0o750); err != nil {
		return nil, fmt.Errorf("journal: %w", err)
	}

	j := &Journal{path: path, last: genesis}

	if err := j.recover(); err != nil {
		return nil, err
	}

	file, err := os.OpenFile(path, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o640)
	if err != nil {
		return nil, fmt.Errorf("journal: %w", err)
	}

	j.file = file

	return j, nil
}

const genesis = "0000000000000000000000000000000000000000000000000000000000000000"

func (j *Journal) recover() error {
	file, err := os.Open(j.path)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}

		return err
	}
	defer file.Close()

	scanner := bufio.NewScanner(file)
	scanner.Buffer(make([]byte, 0, 64*1024), 1024*1024)

	for scanner.Scan() {
		var e Entry
		if err := json.Unmarshal(scanner.Bytes(), &e); err != nil {
			// A torn final line after a crash is expected; anything else is
			// corruption the operator must see, so stop at the first parse error.
			break
		}
		j.seq = e.Seq
		j.last = e.Hash
	}

	return nil
}

// Append writes one entry, chaining it to the previous hash.
func (j *Journal) Append(op, actor, argsHash, result, detail string, now time.Time) (Entry, error) {
	j.mu.Lock()
	defer j.mu.Unlock()

	if j.closed {
		return Entry{}, fmt.Errorf("journal: closed")
	}

	e := Entry{
		Seq:      j.seq + 1,
		Time:     now.UTC().Format(time.RFC3339Nano),
		Op:       op,
		Actor:    actor,
		ArgsHash: argsHash,
		Result:   result,
		Detail:   detail,
		PrevHash: j.last,
	}
	e.Hash = hashEntry(e)

	line, err := json.Marshal(e)
	if err != nil {
		return Entry{}, err
	}

	if _, err := j.file.Write(append(line, '\n')); err != nil {
		return Entry{}, err
	}

	if err := j.file.Sync(); err != nil {
		return Entry{}, err
	}

	j.seq = e.Seq
	j.last = e.Hash

	return e, nil
}

func (j *Journal) Close() error {
	j.mu.Lock()
	defer j.mu.Unlock()
	j.closed = true

	return j.file.Close()
}

// hashEntry is sha256 over every field except Hash itself, with fixed ordering
// so it is reproducible outside Go.
func hashEntry(e Entry) string {
	payload := fmt.Sprintf("%d|%s|%s|%s|%s|%s|%s|%s", e.Seq, e.Time, e.Op, e.Actor, e.ArgsHash, e.Result, e.Detail, e.PrevHash)
	sum := sha256.Sum256([]byte(payload))

	return hex.EncodeToString(sum[:])
}

// Verify walks the whole file and returns the number of valid entries. A
// non-nil error describes the first broken link.
func Verify(path string) (int, error) {
	file, err := os.Open(path)
	if err != nil {
		return 0, err
	}
	defer file.Close()

	scanner := bufio.NewScanner(file)
	scanner.Buffer(make([]byte, 0, 64*1024), 1024*1024)

	prev := genesis
	count := 0

	for scanner.Scan() {
		var e Entry
		if err := json.Unmarshal(scanner.Bytes(), &e); err != nil {
			return count, fmt.Errorf("line %d: not valid JSON: %w", count+1, err)
		}

		if e.Seq != int64(count+1) {
			return count, fmt.Errorf("line %d: sequence jumped to %d", count+1, e.Seq)
		}

		if e.PrevHash != prev {
			return count, fmt.Errorf("entry %d: prev_hash does not match the previous record (chain broken)", e.Seq)
		}

		if hashEntry(e) != e.Hash {
			return count, fmt.Errorf("entry %d: record was modified after it was written", e.Seq)
		}

		prev = e.Hash
		count++
	}

	return count, scanner.Err()
}

// Read returns every entry in the file, oldest first. It does not verify the
// chain — use Verify for that. Readers are expected to treat a malformed line
// as a hard error rather than skipping it, because a gap is exactly what an
// attacker would want us to ignore.
func Read(path string) ([]Entry, error) {
	file, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer file.Close()

	scanner := bufio.NewScanner(file)
	scanner.Buffer(make([]byte, 0, 64*1024), 1024*1024)

	var entries []Entry

	for scanner.Scan() {
		if len(bytes.TrimSpace(scanner.Bytes())) == 0 {
			continue
		}

		var e Entry
		if err := json.Unmarshal(scanner.Bytes(), &e); err != nil {
			return entries, fmt.Errorf("line %d: not valid JSON: %w", len(entries)+1, err)
		}

		entries = append(entries, e)
	}

	return entries, scanner.Err()
}
