#!/usr/bin/env bash
#
# Builds the signed release tarball the installer downloads.
#
#   ./deploy/build-release.sh 0.9.0 /path/to/signing-private.pem
#
# Output in dist/:
#   atlas-0.9.0-linux-amd64.tar.gz         the payload
#   atlas-0.9.0-linux-amd64.tar.gz.sha256  checksum
#   atlas-0.9.0-linux-amd64.tar.gz.sig     openssl signature
#
# The payload contains: the agent and installer binaries, the panel application
# (without vendor/ — composer install runs on the target), the systemd units,
# nginx blocks and the PHP-FPM pool.

set -Eeuo pipefail

VERSION="${1:?usage: build-release.sh <version> <signing-key.pem>}"
KEY="${2:?usage: build-release.sh <version> <signing-key.pem>}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VPS="${ROOT}/../vps"
DIST="${ROOT}/../../dist"
STAGE="${DIST}/atlas-${VERSION}"

ARCH="$(go env GOARCH 2>/dev/null || echo amd64)"
case "$(uname -m)" in
    aarch64|arm64) ARCH=arm64 ;;
esac

mkdir -p "$DIST"
rm -rf "$STAGE"
mkdir -p "$STAGE"/{bin,panel,systemd,nginx,php-fpm}

echo "==> building Go binaries"
( cd "$VPS" && CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o "${STAGE}/bin/atlas-agent" ./cmd/atlas-agent )
( cd "$VPS" && CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o "${STAGE}/bin/atlas-install" ./cmd/atlas-install )

echo "==> copying the panel"
rsync -a --exclude node_modules --exclude vendor --exclude .env \
      --exclude storage/logs --exclude storage/framework/cache \
      "${ROOT}/" "${STAGE}/panel/"

echo "==> copying service definitions"
cp "${ROOT}"/deploy/systemd/*.service "${STAGE}/systemd/"
cp "${ROOT}"/deploy/nginx/*.conf.tmpl "${STAGE}/nginx/"
cp "${ROOT}"/deploy/php-fpm/*.conf.tmpl "${STAGE}/php-fpm/"
cp "${ROOT}"/deploy/install.sh "${STAGE}/install.sh"

chmod +x "${STAGE}"/bin/* "${STAGE}/install.sh"

cat > "${STAGE}/VERSION" <<EOF
version=${VERSION}
built_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
commit=${COMMIT:-unknown}
EOF

echo "==> packaging"
TARBALL="${DIST}/atlas-${VERSION}-linux-${ARCH}.tar.gz"
tar -C "$DIST" -czf "$TARBALL" "atlas-${VERSION}"
( cd "$DIST" && sha256sum "$(basename "$TARBALL")" > "$(basename "$TARBALL").sha256" )

echo "==> signing"
openssl dgst -sha256 -sign "$KEY" -out "${TARBALL}.sig" "$TARBALL"

echo
echo "Release built:"
ls -lh "$TARBALL" "${TARBALL}.sha256" "${TARBALL}.sig"
echo
echo "Publish all three files under ${ATLAS_BASE:-https://releases.atlas.example}/${VERSION}/"
