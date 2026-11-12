#!/bin/sh
#
# Atlas — one-command bootstrap installer.
#
#   curl -fsSL https://<this-site>/install.sh -o install.sh
#   sudo sh install.sh --license ATLS-XXXX-XXXX-XXXX
#
# What this script does, and deliberately does not do:
#
#   • It is a *downloader*, not an installer. It resolves the release that this
#     server publishes, verifies the artefact's SHA-256, and hands over to the
#     signed Go installer, which performs every privileged change and keeps a
#     resumable state file. Keeping the privileged logic in the signed binary
#     means this file can be inspected and re-read without re-auditing the
#     installer.
#   • Nothing is executed as root straight off the network: the tarball is
#     written to a private temp directory, its checksum is checked, and the
#     extracted binary is what runs.
#   • It refuses to continue on an operating system the project has not
#     validated end to end. Overstating support is how hosting panels break
#     servers.
#
# Environment:
#   ATLAS_BASE   Override the release origin (defaults to the URL this script
#                was published from, baked in below).
#
set -eu

BASE_DEFAULT="__ATLAS_BASE__"
BASE="${ATLAS_BASE:-$BASE_DEFAULT}"

log()  { printf '%s\n' "$*" >&2; }
fail() { printf 'error: %s\n' "$*" >&2; exit 1; }

# ---- preconditions --------------------------------------------------------

[ "$(id -u)" = "0" ] || fail "run this as root (sudo sh install.sh --license ...)"

for tool in curl sha256sum tar uname; do
    command -v "$tool" >/dev/null 2>&1 || fail "missing required tool: $tool"
done

[ -r /etc/os-release ] || fail "cannot read /etc/os-release — this is not a supported Linux system"
# shellcheck disable=SC1091
. /etc/os-release

case "${ID:-}:${VERSION_ID:-}" in
    ubuntu:22.04|ubuntu:24.04) ;;
    *)
        log "Detected: ${PRETTY_NAME:-unknown}"
        fail "Atlas is validated on Ubuntu 22.04 and 24.04 only. Refusing to install on ${ID:-unknown} ${VERSION_ID:-}."
        ;;
esac

case "$(uname -m)" in
    x86_64|amd64)  ARCH=amd64 ;;
    aarch64|arm64) ARCH=arm64 ;;
    *) fail "unsupported architecture: $(uname -m)" ;;
esac

OS_TAG="ubuntu-${VERSION_ID}"

# ---- resolve the artifact -------------------------------------------------

log "→ Release origin: $BASE"
MANIFEST="$(mktemp)"
trap 'rm -f "$MANIFEST"' EXIT INT TERM

curl -fsSL --proto '=https' --tlsv1.2 --max-time 30 \
    "$BASE/api/v1/releases/latest?format=tsv" -o "$MANIFEST" \
    || fail "could not fetch the release manifest from $BASE (network, TLS or no published release)"

# Columns: os, arch, filename, sha256, size, url
ROW="$(awk -F '\t' -v os="$OS_TAG" -v arch="$ARCH" '$1 == os && $2 == arch { print; exit }' "$MANIFEST")"
if [ -z "$ROW" ]; then
    ROW="$(awk -F '\t' -v arch="$ARCH" '$2 == arch { print; exit }' "$MANIFEST")"
    [ -n "$ROW" ] || fail "no build published for $OS_TAG/$ARCH"
    log "! No build tagged $OS_TAG — using the $ARCH build, which the installer will re-verify."
fi

FILE="$(printf '%s' "$ROW" | cut -f3)"
SHA="$(printf '%s' "$ROW" | cut -f4)"
URL="$(printf '%s' "$ROW" | cut -f6)"

[ -n "$FILE" ] && [ -n "$SHA" ] && [ -n "$URL" ] || fail "manifest row is incomplete; refusing to continue"
case "$SHA" in
    *[!0-9a-f]*|"") fail "manifest checksum is not a lowercase hex SHA-256; refusing to continue" ;;
esac
[ "${#SHA}" -eq 64 ] || fail "manifest checksum is ${#SHA} characters, expected 64; refusing to continue"

case "$FILE" in
    */*|..) fail "manifest filename contains a path separator: $FILE" ;;
esac

# ---- download and verify --------------------------------------------------

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"; rm -f "$MANIFEST"' EXIT INT TERM

log "→ Downloading $FILE"
curl -fsSL --proto '=https' --tlsv1.2 --max-time 900 --retry 3 --retry-delay 2 \
    "$URL" -o "$WORK/$FILE" || fail "download failed: $URL"

log "→ Verifying SHA-256"
printf '%s  %s\n' "$SHA" "$WORK/$FILE" | (cd "$WORK" && sha256sum -c - >/dev/null) \
    || fail "checksum mismatch — the download is corrupt or has been tampered with. Nothing was installed."

# ---- hand over to the real installer --------------------------------------

tar -xzf "$WORK/$FILE" -C "$WORK" || fail "the release archive could not be unpacked"

BIN="$(find "$WORK" -maxdepth 3 -type f -name 'atlas-install' | head -n 1)"
[ -n "$BIN" ] || fail "atlas-install was not found in the archive"
chmod +x "$BIN"

log "→ Starting the installer"
exec "$BIN" "$@"
