// Package envelope implements the signed request envelope used between the
// panel (unprivileged) and the agent (root).
//
// Design constraints, in order of importance:
//
//  1. The agent must never execute arbitrary commands. The envelope authenticates
//     a *named operation* plus a typed argument document, not a command line.
//  2. A captured request must be useless when replayed: requests carry a
//     one-shot nonce and a 30 second validity window.
//  3. Verification must be constant-time and must happen before any JSON
//     payload is interpreted by an operation handler.
package envelope

import (
	"bytes"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"sync"
	"time"
)

// MaxClockSkew tolerates small drift between the panel host and the agent host.
// On a single machine it only needs to absorb NTP jitter.
const MaxClockSkew = 5 * time.Second

// DefaultTTL is deliberately short: the panel mints a request immediately before
// sending it, so there is no reason to accept stale envelopes.
const DefaultTTL = 30 * time.Second

var (
	ErrBadSignature = errors.New("envelope: bad signature")
	ErrExpired      = errors.New("envelope: request expired or not yet valid")
	ErrReplay       = errors.New("envelope: nonce already used")
	ErrMissingNonce = errors.New("envelope: nonce required")
)

// Request is the signed body. Field order is fixed and the JSON encoder sorts
// map keys, so json.Marshal is deterministic for a given Request value — which
// is what makes client and agent signatures agree without a canonicalisation
// library.
type Request struct {
	Op    string          `json:"op"`
	Args  json.RawMessage `json:"args,omitempty"`
	Nonce string          `json:"nonce"`
	Iat   int64           `json:"iat"` // unix seconds
	Exp   int64           `json:"exp"` // unix seconds
}

// Envelope travels over the socket.
type Envelope struct {
	Request Request `json:"request"`
	Sig     string  `json:"sig"` // hex HMAC-SHA256 over Canonical(request)
}

// Canonical returns the exact bytes that are signed.
//
// The signed material deliberately avoids embedding a re-encoded JSON document:
// the panel is written in PHP and the agent in Go, and the two have subtly
// different JSON encoders (HTML escaping, unicode escaping). Signing
// "op, nonce, window, sha256(args as sent)" means both sides hash the same bytes
// and neither has to reproduce the other's encoder.
func Canonical(r Request) ([]byte, error) {
	args := r.Args
	if len(args) == 0 {
		args = json.RawMessage("{}")
	}

	var compact bytes.Buffer
	if err := json.Compact(&compact, args); err != nil {
		return nil, fmt.Errorf("envelope: args are not valid JSON: %w", err)
	}

	sum := sha256.Sum256(compact.Bytes())

	return []byte(fmt.Sprintf("atlas-envelope-v1\n%s\n%s\n%d\n%d\n%x",
		r.Op, r.Nonce, r.Iat, r.Exp, sum)), nil
}

// Sign returns a signed envelope for the given operation and arguments.
func Sign(key []byte, op string, args any, nonce string, now time.Time) (Envelope, error) {
	raw, err := json.Marshal(args)
	if err != nil {
		return Envelope{}, err
	}

	if nonce == "" {
		nonce, err = NewNonce()
		if err != nil {
			return Envelope{}, err
		}
	}

	req := Request{
		Op:    op,
		Args:  raw,
		Nonce: nonce,
		Iat:   now.Unix(),
		Exp:   now.Add(DefaultTTL).Unix(),
	}

	canonical, err := Canonical(req)
	if err != nil {
		return Envelope{}, err
	}

	return Envelope{Request: req, Sig: sign(key, canonical)}, nil
}

func sign(key, canonical []byte) string {
	mac := hmac.New(sha256.New, key)
	mac.Write(canonical)

	return hex.EncodeToString(mac.Sum(nil))
}

// Verifier checks signatures, validity windows and nonce reuse.
type Verifier struct {
	key    []byte
	ttl    time.Duration
	nonces NonceStore
	now    func() time.Time
}

func NewVerifier(key []byte, nonces NonceStore) *Verifier {
	return &Verifier{key: key, ttl: DefaultTTL, nonces: nonces, now: time.Now}
}

// WithClock overrides the clock — used by tests and by the `verify` CLI.
func (v *Verifier) WithClock(f func() time.Time) *Verifier { v.now = f; return v }

// Verify authenticates the envelope. Only after this returns nil may a handler
// look at Envelope.Request.Args.
func (v *Verifier) Verify(env Envelope) error {
	if env.Request.Nonce == "" {
		return ErrMissingNonce
	}

	canonical, err := Canonical(env.Request)
	if err != nil {
		return err
	}

	expected := sign(v.key, canonical)
	if !hmac.Equal([]byte(expected), []byte(env.Sig)) {
		return ErrBadSignature
	}

	now := v.now()
	iat := time.Unix(env.Request.Iat, 0)
	exp := time.Unix(env.Request.Exp, 0)

	if now.Before(iat.Add(-MaxClockSkew)) || now.After(exp.Add(MaxClockSkew)) {
		return ErrExpired
	}

	// Reject absurd windows outright: a 10 minute TTL is a configuration bug,
	// not something to be tolerant about.
	if exp.Sub(iat) > 2*v.ttl {
		return ErrExpired
	}

	if v.nonces.Seen(env.Request.Nonce) {
		return ErrReplay
	}

	return nil
}

// NonceStore remembers recently seen nonces.
type NonceStore interface {
	Seen(nonce string) bool
}

// MemoryNonces is a bounded in-memory store. The agent restarts are rare and a
// restart invalidates in-flight envelopes anyway, so durability is unnecessary;
// the signature + TTL window close the gap.
type MemoryNonces struct {
	mu     sync.Mutex
	ttl    time.Duration
	now    func() time.Time
	seen   map[string]time.Time
	maxEnt int
}

func NewMemoryNonces(ttl time.Duration) *MemoryNonces {
	return &MemoryNonces{ttl: ttl, now: time.Now, seen: map[string]time.Time{}, maxEnt: 100_000}
}

func (m *MemoryNonces) Seen(nonce string) bool {
	m.mu.Lock()
	defer m.mu.Unlock()

	now := m.now()
	for k, exp := range m.seen {
		if now.After(exp) {
			delete(m.seen, k)
		}
	}

	if _, ok := m.seen[nonce]; ok {
		return true
	}

	if len(m.seen) >= m.maxEnt {
		// Degrade safely: refuse new requests rather than growing without bound.
		return true
	}

	m.seen[nonce] = now.Add(m.ttl)

	return false
}

// NewNonce returns a 128-bit random identifier.
func NewNonce() (string, error) {
	b := make([]byte, 16)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}

	return hex.EncodeToString(b), nil
}

// LoadKey reads an HMAC key from disk and enforces 0600 root ownership by size
// and content sanity. File permission checks live in the agent's startup path.
func LoadKey(path string) ([]byte, error) {
	raw, err := osReadFile(path)
	if err != nil {
		return nil, err
	}

	key := bytes.TrimSpace(raw)
	if len(key) < 32 {
		return nil, fmt.Errorf("envelope: key at %s is too short (%d bytes)", path, len(key))
	}

	return key, nil
}
