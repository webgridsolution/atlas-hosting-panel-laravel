// Package licensing verifies the Ed25519 licence token issued by the central
// platform and decides what the panel is allowed to do.
//
// Two rules dominate the design:
//
//  1. Verify offline. The token is self-contained and signed; a central outage
//     must never stop a paying customer's panel from working.
//  2. Degrade, never destroy. No state in this package can stop a website, drop
//     a database or delete a file. The worst outcome is read-only.
package licensing

import (
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"strconv"
	"strings"
	"time"
)

const Prefix = "atlas-lic-v1"

// MaxTokenAge bounds how long a token can be trusted after issue. It matches
// the 30-day window the central platform signs.
const MaxTokenAge = 30 * 24 * time.Hour

var (
	ErrMalformed   = errors.New("licence token is malformed")
	ErrUnknownKey  = errors.New("licence token was signed with an unknown key id")
	ErrSignature   = errors.New("licence signature does not verify")
	ErrExpired     = errors.New("licence token has expired")
	ErrNotYetValid = errors.New("licence token is not valid yet")
)

// Claims is the signed payload.
type Claims struct {
	Version      int            `json:"v"`
	KeyID        string         `json:"kid"`
	LicenceID    string         `json:"lic"`
	CustomerID   string         `json:"cus"`
	Edition      string         `json:"ed"`
	Fingerprint  string         `json:"fp"`
	Entitlements map[string]any `json:"ent"`
	Status       string         `json:"sts"`
	IssuedAt     int64          `json:"iat"`
	NotBefore    int64          `json:"nbf"`
	Expires      int64          `json:"exp"`
	TokenID      string         `json:"jti"`
}

// Token is a verified claims set plus the raw token text.
type Token struct {
	Claims Claims
	Raw    string
}

// Verify checks the signature against the pinned key set and the time window.
// `keys` maps kid -> public key; shipping two keys is how rotation works.
func Verify(raw string, keys map[string]ed25519.PublicKey, now time.Time) (Token, error) {
	parts := strings.Split(raw, ".")
	if len(parts) != 3 || parts[0] != Prefix {
		return Token{}, ErrMalformed
	}

	payload, err := decodeSegment(parts[1])
	if err != nil {
		return Token{}, ErrMalformed
	}

	var claims Claims
	if err := json.Unmarshal(payload, &claims); err != nil {
		return Token{}, ErrMalformed
	}

	if claims.Version != 1 || claims.KeyID == "" {
		return Token{}, ErrMalformed
	}

	key, ok := keys[claims.KeyID]
	if !ok {
		return Token{}, fmt.Errorf("%w: %s", ErrUnknownKey, claims.KeyID)
	}

	signature, err := decodeSegment(parts[2])
	if err != nil {
		return Token{}, ErrMalformed
	}

	signed := []byte(Prefix + "." + parts[1])
	if !ed25519.Verify(key, signed, signature) {
		return Token{}, ErrSignature
	}

	// A 5 minute skew allowance matches the issuer's nbf backdating.
	if now.Before(time.Unix(claims.NotBefore, 0).Add(-5 * time.Minute)) {
		return Token{}, ErrNotYetValid
	}
	if now.After(time.Unix(claims.Expires, 0)) {
		return Token{}, ErrExpired
	}

	// Refuse tokens claiming an absurd lifetime even if correctly signed: it
	// means the issuer is misconfigured and we should not trust the window.
	if claims.Expires-claims.IssuedAt > int64(MaxTokenAge/time.Second)+3600 {
		return Token{}, fmt.Errorf("%w: validity window is longer than policy allows", ErrMalformed)
	}

	return Token{Claims: claims, Raw: raw}, nil
}

func decodeSegment(s string) ([]byte, error) {
	return base64.RawURLEncoding.DecodeString(strings.TrimRight(s, "="))
}

// Mode is what the panel is allowed to do right now.
type Mode string

const (
	ModeActive   Mode = "active"
	ModeGrace    Mode = "grace"
	ModeReadOnly Mode = "read_only"
	ModeInvalid  Mode = "invalid"
)

// State is the evaluation result, including a human explanation for the banner.
type State struct {
	Mode   Mode
	Reason string
	// DaysLeft is negative once the paid period has ended.
	DaysLeft int
	Status   string
}

// Evaluate turns claims + local policy into an operating mode.
//
//   - active      → full operation
//   - grace       → full operation, warning banner
//   - read_only   → no writes; websites, mail, DNS and backups keep running
//   - invalid     → same as read_only, but the banner says the licence is broken
func Evaluate(claims Claims, now time.Time, graceDays int, lastSeen time.Time) State {
	state := State{Status: claims.Status}

	expires := time.Unix(claims.Expires, 0)
	state.DaysLeft = int(expires.Sub(now).Hours() / 24)

	// A clock that moved backwards is a tampering signal, not a timezone bug:
	// the panel recorded a later time than "now".
	if !lastSeen.IsZero() && now.Add(5*time.Minute).Before(lastSeen) {
		state.Mode = ModeReadOnly
		state.Reason = "the system clock moved backwards; reconnect to the licence server to continue"

		return state
	}

	switch claims.Status {
	case "revoked":
		state.Mode = ModeReadOnly
		state.Reason = "this licence has been revoked; contact support"

		return state
	case "suspended":
		state.Mode = ModeReadOnly
		state.Reason = "this licence is suspended; contact billing"

		return state
	}

	if now.Before(expires) {
		state.Mode = ModeActive
		state.Reason = "licence is active"

		return state
	}

	graceEnds := expires.AddDate(0, 0, graceDays)
	if graceDays > 0 && now.Before(graceEnds) {
		state.Mode = ModeGrace
		state.Reason = fmt.Sprintf("licence expired %s ago; %d grace day(s) remain",
			now.Sub(expires).Round(24*time.Hour).String(), int(graceEnds.Sub(now).Hours()/24))

		return state
	}

	state.Mode = ModeReadOnly
	state.Reason = "licence period and grace window have ended; the panel is read-only until it is renewed"

	return state
}

// AllowsWrites is the single predicate the panel's middleware uses.
func (s State) AllowsWrites() bool {
	return s.Mode == ModeActive || s.Mode == ModeGrace
}

// Entitlement reads an integer entitlement (max_accounts etc). Missing values
// are 0, which the panel treats as "not included".
func (c Claims) Entitlement(key string) int {
	v, ok := c.Entitlements[key]
	if !ok {
		return 0
	}

	switch t := v.(type) {
	case float64:
		return int(t)
	case int:
		return t
	case string:
		n, _ := strconv.Atoi(t)

		return n
	default:
		return 0
	}
}

// FingerprintMatches checks that the token was issued for this machine.
// An empty claim means the licence has no binding yet, which is allowed only
// right after activation.
func (c Claims) FingerprintMatches(fingerprint string) bool {
	if c.Fingerprint == "" {
		return true
	}

	return c.Fingerprint == fingerprint
}
