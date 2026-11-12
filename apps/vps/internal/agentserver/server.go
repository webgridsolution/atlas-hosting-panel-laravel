// Package agentserver exposes the registry over a Unix socket.
//
// Trust model: the socket lives at /run/atlas/agent.sock, owned root:atlas with
// mode 0660, and the server *also* verifies SO_PEERCRED on every connection. A
// file mode alone is not enough — a root-owned process could still connect, and
// we want an explicit, auditable check rather than an implicit one.
package agentserver

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"os"
	"strconv"
	"syscall"
	"time"

	"atlas/internal/envelope"
	"atlas/internal/journal"
	"atlas/internal/ops"
)

// Config is everything the server needs.
type Config struct {
	SocketPath  string
	AllowedUID  uint32 // typically the `atlas` panel user
	KeyFile     string
	JournalPath string
	MaxBody     int64
	Logger      *slog.Logger
}

// Server wires envelope verification, the journal and the registry together.
type Server struct {
	cfg      Config
	verifier *envelope.Verifier
	registry *ops.Registry
	journal  *journal.Journal
	log      *slog.Logger
}

func New(cfg Config) (*Server, error) {
	if cfg.MaxBody == 0 {
		cfg.MaxBody = 1 << 20 // 1 MiB is generous for a typed operation
	}
	if cfg.Logger == nil {
		cfg.Logger = slog.Default()
	}

	key, err := envelope.LoadKey(cfg.KeyFile)
	if err != nil {
		return nil, err
	}

	j, err := journal.Open(cfg.JournalPath)
	if err != nil {
		return nil, err
	}

	return &Server{
		cfg:      cfg,
		verifier: envelope.NewVerifier(key, envelope.NewMemoryNonces(2*time.Minute)),
		registry: ops.NewRegistry(nil), // replaced below; registry needs the system
		journal:  j,
		log:      cfg.Logger,
	}, nil
}

// WithSystem finishes construction. Split from New so the socket, key and
// journal are validated before any privileged object is created.
func (s *Server) WithSystem(system ops.System) *Server {
	s.registry = ops.NewRegistry(system)

	return s
}

// Handler returns the HTTP handler, useful for tests that skip the socket.
func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/dispatch", s.handleDispatch)
	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"ok":true}`))
	})

	return mux
}

func (s *Server) handleDispatch(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)

		return
	}

	body := http.MaxBytesReader(w, r.Body, s.cfg.MaxBody)

	var env envelope.Envelope
	dec := json.NewDecoder(body)
	dec.DisallowUnknownFields()

	if err := dec.Decode(&env); err != nil {
		s.deny(w, "malformed envelope: "+err.Error())

		return
	}

	// 1. Authenticate before anything else is interpreted.
	if err := s.verifier.Verify(env); err != nil {
		code := http.StatusUnauthorized
		if errors.Is(err, envelope.ErrReplay) {
			code = http.StatusConflict
		}
		s.denyWithCode(w, code, "envelope rejected: "+err.Error())

		return
	}

	// 2. Dispatch.
	res, err := s.registry.Dispatch(r.Context(), env.Request.Op, env.Request.Args)
	if err != nil {
		s.record(env, "error", err.Error())
		writeJSON(w, http.StatusUnprocessableEntity, map[string]any{
			"ok":    false,
			"error": err.Error(),
		})

		return
	}

	s.record(env, "ok", "")
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "result": res})
}

func (s *Server) deny(w http.ResponseWriter, reason string) {
	s.denyWithCode(w, http.StatusBadRequest, reason)
}

func (s *Server) denyWithCode(w http.ResponseWriter, code int, reason string) {
	s.log.Warn("agent: request refused", "reason", reason)
	_, _ = s.journal.Append(" dispatch", "peer", "", "denied", reason, time.Now())
	writeJSON(w, code, map[string]any{"ok": false, "error": reason})
}

func (s *Server) record(env envelope.Envelope, result, detail string) {
	hash := envelopeHash(env.Request.Args)
	if _, err := s.journal.Append(env.Request.Op, "panel", hash, result, detail, time.Now()); err != nil {
		// A missing audit record must never silently pass: log loudly. The
		// operation already happened, so failing the request would be worse.
		s.log.Error("agent: journal write failed", "err", err, "op", env.Request.Op)
	}
}

func envelopeHash(args json.RawMessage) string {
	canonical, err := envelope.Canonical(envelope.Request{Args: args, Op: "x"})
	if err != nil {
		return "-"
	}

	// 16 hex chars is plenty to correlate with the panel's own log without
	// storing argument bodies (which may contain secrets).
	sum := fmt.Sprintf("%x", sha(canonical))

	return sum[:16]
}

// Listen starts the Unix socket server and blocks until ctx is cancelled.
func (s *Server) Listen(ctx context.Context) error {
	_ = os.Remove(s.cfg.SocketPath)
	if err := os.MkdirAll(dirOf(s.cfg.SocketPath), 0o750); err != nil {
		return err
	}

	ln, err := net.Listen("unix", s.cfg.SocketPath)
	if err != nil {
		return err
	}

	if err := os.Chmod(s.cfg.SocketPath, 0o660); err != nil {
		return err
	}

	// The group must exist for this to work; the installer creates it.
	if gid, err := lookupGroup("atlas"); err == nil {
		_ = os.Chown(s.cfg.SocketPath, 0, gid)
	}

	srv := &http.Server{
		Handler:           s.PeerGuard(s.Handler()),
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       30 * time.Second,
		WriteTimeout:      120 * time.Second, // backups can take a while
		IdleTimeout:       60 * time.Second,
	}

	go func() {
		<-ctx.Done()
		_ = srv.Close()
	}()

	s.log.Info("agent listening", "socket", s.cfg.SocketPath, "allowed_uid", s.cfg.AllowedUID)

	err = srv.Serve(ln)
	if errors.Is(err, http.ErrServerClosed) {
		return nil
	}

	return err
}

// PeerGuard rejects connections whose peer UID is not the panel's.
func (s *Server) PeerGuard(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		uid, err := PeerUID(r.Context())
		if err != nil {
			s.denyWithCode(w, http.StatusForbidden, "cannot determine peer credentials")

			return
		}

		if uid != s.cfg.AllowedUID {
			s.denyWithCode(w, http.StatusForbidden, "peer uid "+strconv.Itoa(int(uid))+" is not authorised")

			return
		}

		next.ServeHTTP(w, r)
	})
}

// peerUIDKey is the context key used by the listener to pass the UID through.
type ctxKey struct{}

// PeerUID returns the UID recorded for this connection.
func PeerUID(ctx context.Context) (uint32, error) {
	uid, ok := ctx.Value(ctxKey{}).(uint32)
	if !ok {
		return 0, fmt.Errorf("agentserver: no peer credentials on the connection")
	}

	return uid, nil
}

// UcredFromConn reads SO_PEERCRED from a Unix connection.
func UcredFromConn(c *net.UnixConn) (uint32, error) {
	raw, err := c.SyscallConn()
	if err != nil {
		return 0, err
	}

	var (
		uid  uint32
		cerr error
	)

	if err := raw.Control(func(fd uintptr) {
		cred, err := syscall.GetsockoptUcred(int(fd), syscall.SOL_SOCKET, syscall.SO_PEERCRED)
		if err != nil {
			cerr = err

			return
		}
		uid = cred.Uid
	}); err != nil {
		return 0, err
	}

	return uid, cerr
}

func writeJSON(w http.ResponseWriter, code int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(v)
}

func dirOf(path string) string {
	for i := len(path) - 1; i >= 0; i-- {
		if path[i] == '/' {
			return path[:i]
		}
	}

	return "."
}
