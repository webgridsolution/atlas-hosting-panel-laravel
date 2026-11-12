#!/usr/bin/env bash
#
# Atlas one-command installer — bootstrap stage.
#
#   curl -fsSL https://get.atlas.example/install.sh | sudo bash -s -- --license ATLS-....
#
# This script only: (1) checks the machine, (2) downloads the release and its
# signature, (3) verifies both, (4) runs the real installer. Everything else —
# packages, services, accounts, firewall — is done by `atlas-install`, which is
# written in Go and tested.
#
# It is deliberately boring and read-only until verification succeeds.

set -Eeuo pipefail

ATLAS_BASE="${ATLAS_BASE:-https://releases.atlas.example}"
ATLAS_VERSION="${ATLAS_VERSION:-0.9.0}"
ATLAS_TRUSTED_KEYS="${ATLAS_TRUSTED_KEYS:-/etc/atlas/release.pub}"

WORKDIR="$(mktemp -d /tmp/atlas-install.XXXXXX)"
trap 'rm -rf "$WORKDIR"' EXIT

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m  %s\n' "$*" >&2; }
die()  { printf '\033[1;31mxx\033[0m  %s\n' "$*" >&2; exit 1; }

# ---- 1. preconditions -------------------------------------------------------

[ "$(id -u)" -eq 0 ] || die "run as root: sudo bash install.sh --license ATLS-..."

case "$(uname -m)" in
    x86_64)  ARCH=amd64 ;;
    aarch64) ARCH=arm64 ;;
    *) die "unsupported architecture $(uname -m); Atlas builds for amd64 and arm64" ;;
esac

if [ ! -r /etc/os-release ]; then
    die "cannot identify this operating system"
fi

# shellcheck disable=SC1091
. /etc/os-release

case "${ID:-}:${VERSION_ID:-}" in
    ubuntu:24.04|ubuntu:22.04) : ;;
    *) die "$PRETTY_NAME is not supported. Tested platforms: Ubuntu 24.04 LTS and 22.04 LTS. Nothing else is claimed." ;;
esac

for bin in curl sha256sum openssl; do
    command -v "$bin" >/dev/null 2>&1 || die "$bin is required"
done

mem_mb=$(( $(awk '/MemTotal/ {print $2}' /proc/meminfo) / 1024 ))
[ "$mem_mb" -ge 1800 ] || die "${mem_mb} MB RAM; at least 1800 MB is required for Apache, MariaDB and PHP-FPM"

log "Target: ${PRETTY_NAME} / ${ARCH} / ${mem_mb} MB RAM"

# ---- 2. download ------------------------------------------------------------

TARBALL="atlas-${ATLAS_VERSION}-linux-${ARCH}.tar.gz"

log "Downloading ${TARBALL}"
curl -fsSL --retry 3 --proto '=https' --tlsv1.2 \
    -o "${WORKDIR}/${TARBALL}" "${ATLAS_BASE}/${ATLAS_VERSION}/${TARBALL}"
curl -fsSL --retry 3 --proto '=https' --tlsv1.2 \
    -o "${WORKDIR}/${TARBALL}.sha256" "${ATLAS_BASE}/${ATLAS_VERSION}/${TARBALL}.sha256"
curl -fsSL --retry 3 --proto '=https' --tlsv1.2 \
    -o "${WORKDIR}/${TARBALL}.sig" "${ATLAS_BASE}/${ATLAS_VERSION}/${TARBALL}.sig"

# ---- 3. verify --------------------------------------------------------------

log "Verifying checksum"
( cd "$WORKDIR" && sha256sum -c "$(basename "${TARBALL}").sha256" ) || die "checksum mismatch — refusing to install"

log "Verifying signature"
if [ -r "$ATLAS_TRUSTED_KEYS" ]; then
    openssl dgst -sha256 -verify "$ATLAS_TRUSTED_KEYS" \
        -signature "${WORKDIR}/${TARBALL}.sig" "${WORKDIR}/${TARBALL}" >/dev/null \
        || die "release signature is not valid for the configured key"
else
    die "no trusted release key at ${ATLAS_TRUSTED_KEYS}. Download it from https://atlas.example/security and place it there."
fi

log "Unpacking"
tar -xzf "${WORKDIR}/${TARBALL}" -C "$WORKDIR"

# ---- 4. hand over -----------------------------------------------------------

INSTALLER="${WORKDIR}/atlas-${ATLAS_VERSION}/bin/atlas-install"
[ -x "$INSTALLER" ] || die "release does not contain bin/atlas-install"

log "Starting the installer (this is the part that changes the machine)"
# --payload defaults to the release root (the parent of bin/), so the installer
# finds nginx/, php-fpm/, systemd/ and panel/ next to the binaries.
exec "$INSTALLER" --payload "${WORKDIR}/atlas-${ATLAS_VERSION}" "$@"
