// Command atlas-agent is the root-privileged helper. It serves one Unix socket,
// accepts signed operations from the panel user, verifies them, executes an
// allowlisted operation, and appends the outcome to a hash-chained journal.
//
// It is deliberately small and has no dynamic loading, no plugins and no
// scripting surface.
package main

import (
	"context"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"os/signal"
	"os/user"
	"strconv"
	"syscall"
	"time"

	"atlas/internal/agentserver"
	"atlas/internal/execx"
	"atlas/internal/journal"
	"atlas/internal/ops"
)

var version = "0.9.0"

func main() {
	var (
		socket     = flag.String("socket", "/run/atlas/agent.sock", "unix socket path")
		keyFile    = flag.String("key", "/etc/atlas/agent.key", "HMAC key shared with the panel")
		journalPth = flag.String("journal", "/var/log/atlas/agent-journal.jsonl", "hash-chained audit journal")
		panelUser  = flag.String("panel-user", "atlas", "unix user allowed to call the agent")
		verifyOnly = flag.String("verify-journal", "", "verify the journal at this path and exit")
		listOps    = flag.Bool("list-ops", false, "print the operation allowlist and exit")
		showVer    = flag.Bool("version", false, "print version and exit")
	)
	flag.Parse()

	if *showVer {
		fmt.Println("atlas-agent", version)

		return
	}

	if *verifyOnly != "" {
		n, err := journal.Verify(*verifyOnly)
		if err != nil {
			fmt.Fprintf(os.Stderr, "journal INVALID after %d entries: %v\n", n, err)
			os.Exit(1)
		}
		fmt.Printf("journal OK: %d entries, chain intact\n", n)

		return
	}

	if *listOps {
		// The registry needs a System only to execute, not to enumerate.
		for _, op := range ops.NewRegistry(nil).Ops() {
			fmt.Println(op)
		}

		return
	}

	log := slog.New(slog.NewJSONHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelInfo}))

	if os.Geteuid() != 0 {
		log.Error("atlas-agent must run as root")
		os.Exit(1)
	}

	u, err := user.Lookup(*panelUser)
	if err != nil {
		log.Error("panel user does not exist", "user", *panelUser, "err", err)
		os.Exit(1)
	}

	uid, err := strconv.ParseUint(u.Uid, 10, 32)
	if err != nil {
		log.Error("cannot parse uid", "err", err)
		os.Exit(1)
	}

	if err := checkKeyPermissions(*keyFile); err != nil {
		log.Error("key file is not safe", "path", *keyFile, "err", err)
		os.Exit(1)
	}

	runner := execx.New(90*time.Second,
		"useradd", "usermod", "userdel", "groupadd", "setquota",
		"systemctl", "a2ensite", "a2dissite", "apache2ctl",
		"tar", "mysql", "doveadm", "pdns_control", "lego", "crontab", "rclone",
	)

	system := ops.NewRealSystem(runner, ops.OSFileSystem{}, ops.DefaultPaths())

	srv, err := agentserver.New(agentserver.Config{
		SocketPath:  *socket,
		AllowedUID:  uint32(uid),
		KeyFile:     *keyFile,
		JournalPath: *journalPth,
		Logger:      log,
	})
	if err != nil {
		log.Error("cannot start", "err", err)
		os.Exit(1)
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	if err := srv.WithSystem(system).Listen(ctx); err != nil {
		log.Error("server stopped", "err", err)
		os.Exit(1)
	}
}

// checkKeyPermissions refuses a key that other users can read.
func checkKeyPermissions(path string) error {
	info, err := os.Stat(path)
	if err != nil {
		return err
	}

	if info.Mode().Perm()&0o077 != 0 {
		return fmt.Errorf("key file mode is %o, must be 0600 or stricter", info.Mode().Perm())
	}

	if info.Size() < 32 {
		return fmt.Errorf("key file is only %d bytes", info.Size())
	}

	return nil
}
