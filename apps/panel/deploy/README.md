# Deploying the Atlas panel

The panel is installed by `atlas-install` (Go). This directory holds the pieces
that installer puts on the machine, plus the release builder.

```
deploy/
├── install.sh              curl | sudo bash bootstrap: verifies signature, runs atlas-install
├── build-release.sh        builds + signs the release tarball
├── nginx/
│   ├── atlas-whm.conf.tmpl      {{ADMIN_PORT}}  — WHM console
│   └── atlas-service.conf.tmpl  {{PANEL_PORT}}  — customer panel, {{WEBMAIL_PORT}} — webmail
├── php-fpm/
│   └── atlas-panel.conf.tmpl   pool `atlas` → /run/php/atlas-panel.sock
└── systemd/
    ├── atlas-agent.service runs as root, socket 0660 root:atlas
    └── atlas-panel.service scheduler (schedule:work); requests go through php-fpm
```

## Ports

2087 / 2083 / 2096 are **illustrative**. Every one is configurable:

```bash
atlas-install --license ATLS-... --admin-port 8443 --panel-port 8444 --webmail-port 8445
```

The installer renders the nginx blocks with the chosen ports, opens them in the
firewall and prints the real URLs at the end. If you change them later, change
`.env`, the nginx `listen` lines and the firewall together.

## Building a release

```bash
./deploy/build-release.sh 0.9.0 /secure/offline/release-signing-key.pem
```

Produces `dist/atlas-0.9.0-linux-amd64.tar.gz` plus `.sha256` and `.sig`.
Publish all three. Customers download one script:

```bash
curl -fsSL https://get.atlas.example/install.sh | sudo bash -s -- --license ATLS-XXXX-XXXX-XXXX-XXXX
```

`install.sh` refuses to do anything unless the checksum **and** the OpenSSL
signature verify against `/etc/atlas/release.pub`.

## What runs where

| Process | User | Listens on | Talks to |
|---|---|---|---|
| nginx | www-data | 2087, 2083, 2096, 80, 443 | php-fpm socket |
| php-fpm pool `atlas-panel` | atlas | unix socket | MySQL on 127.0.0.1 |
| atlas-panel (scheduler) | atlas | — | MySQL, agent socket |
| atlas-agent | root | /run/atlas/agent.sock (0660) | system binaries via allowlist |

The panel process cannot read `/etc/shadow`, cannot write `/home`, and has no
path to the machine except the agent's 24 allowlisted operations.

## Customer sites

Sites are **not** served by nginx. Apache 2.4 + PHP-FPM (one pool per account)
serves customer domains on 80/443; nginx only serves the panel. That separation
means a panel bug cannot take a customer's website down, and vice versa.
