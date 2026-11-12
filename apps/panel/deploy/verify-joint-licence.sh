#!/usr/bin/env bash
#
# End-to-end licence verification: a real central service, a real panel, real
# HTTP, real Ed25519 signatures. Nothing is mocked.
#
#   deploy/verify-joint-licence.sh
#
# What it proves, in order:
#   1. a key issued by the vendor activates a panel that has never been licensed
#   2. the panel stores a token whose signature it can verify offline
#   3. a heartbeat refreshes that token without human input
#   4. revoking the licence at the vendor turns the panel read-only at the next
#      check — and the customer's account, website and data are still there
#   5. the panel keeps working on the last known-good token while the vendor is
#      unreachable
#
# It uses throwaway databases under /tmp and two loopback ports. It never
# touches the developer databases in apps/*/database.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CENTRAL="${CENTRAL_DIR:-$ROOT/../central}"
PANEL="$ROOT"
WORK="${WORK:-/tmp/atlas-joint}"
CENTRAL_PORT="${CENTRAL_PORT:-8111}"
PANEL_PORT="${PANEL_PORT:-8112}"

CENTRAL_URL="http://127.0.0.1:${CENTRAL_PORT}"
PANEL_URL="http://127.0.0.1:${PANEL_PORT}"

mkdir -p "$WORK"
rm -f "$WORK/central.sqlite" "$WORK/panel.sqlite"
touch "$WORK/central.sqlite" "$WORK/panel.sqlite"

pass=0
fail=0
green() { printf '\033[32m%s\033[0m\n' "$*"; }
red()   { printf '\033[31m%s\033[0m\n' "$*"; }

check() { # check <label> <actual> <expected>
  if [ "$2" = "$3" ]; then
    green "  PASS  $1"
    pass=$((pass + 1))
  else
    red   "  FAIL  $1 — expected [$3], got [$2]"
    fail=$((fail + 1))
  fi
}

contains() { # contains <label> <haystack> <needle>
  case "$2" in
    *"$3"*) green "  PASS  $1"; pass=$((pass + 1));;
    *) red "  FAIL  $1 — output did not contain [$3]"; fail=$((fail + 1));;
  esac
}

cleanup() {
  [ -n "${CENTRAL_PID:-}" ] && kill "$CENTRAL_PID" 2>/dev/null || true
  [ -n "${PANEL_PID:-}" ] && kill "$PANEL_PID" 2>/dev/null || true
}
trap cleanup EXIT

wait_for() { # wait_for <url> <label>
  for _ in $(seq 1 60); do
    if curl -fsS -o /dev/null "$1" 2>/dev/null; then return 0; fi
    sleep 0.5
  done
  red "  FAIL  $2 did not start at $1"
  exit 1
}

echo
echo "== 1. Vendor: fresh database, issue a real key =="
( cd "$CENTRAL" && DB_CONNECTION=sqlite DB_DATABASE="$WORK/central.sqlite" \
    php artisan migrate:fresh --seed --force >/dev/null )

ISSUED="$( cd "$CENTRAL" && DB_CONNECTION=sqlite DB_DATABASE="$WORK/central.sqlite" \
    php artisan atlas:licence:issue owner@atlas.test --plan=professional --json )"

KEY="$(printf '%s' "$ISSUED" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["key"];')"
KID="$(printf '%s' "$ISSUED" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["kid"];')"
PUB="$(printf '%s' "$ISSUED" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["public_key"];')"

check "a key was issued" "$([ -n "$KEY" ] && echo yes || echo no)" "yes"

echo
echo "== 2. Panel: fresh database, installed but not licensed =="
( cd "$PANEL" && DB_CONNECTION=sqlite DB_DATABASE="$WORK/panel.sqlite" \
    php artisan migrate:fresh --force >/dev/null
  cd "$PANEL" && DB_CONNECTION=sqlite DB_DATABASE="$WORK/panel.sqlite" \
    php artisan db:seed --force >/dev/null
  cd "$PANEL" && DB_CONNECTION=sqlite DB_DATABASE="$WORK/panel.sqlite" \
    php artisan tinker --execute='App\Models\Licence::query()->delete(); cache()->flush();' >/dev/null )

( cd "$CENTRAL" && DB_CONNECTION=sqlite DB_DATABASE="$WORK/central.sqlite" \
    php artisan serve --host=127.0.0.1 --port="$CENTRAL_PORT" >"$WORK/central.log" 2>&1 ) &
CENTRAL_PID=$!

( cd "$PANEL" && DB_CONNECTION=sqlite DB_DATABASE="$WORK/panel.sqlite" \
    ATLAS_CENTRAL_URL="$CENTRAL_URL" \
    ATLAS_LICENSE_KEYS="{\"$KID\":\"$PUB\"}" \
    php artisan serve --host=127.0.0.1 --port="$PANEL_PORT" >"$WORK/panel.log" 2>&1 ) &
PANEL_PID=$!

wait_for "$CENTRAL_URL/up" "central"
wait_for "$PANEL_URL/up" "panel"

health() { curl -fsS "$PANEL_URL/health" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["licence"];'; }

check "the panel starts unlicensed" "$(health)" "unlicensed"

echo
echo "== 3. Operator activates the key from the WHM screen =="
JAR="$WORK/cookies.txt"; rm -f "$JAR"
TOKEN="$(curl -fsS -c "$JAR" "$PANEL_URL/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')"
curl -fsS -b "$JAR" -c "$JAR" -o /dev/null -X POST "$PANEL_URL/login" \
  -d "_token=$TOKEN" -d "email=admin@atlas.test" -d "password=password"

TOKEN="$(curl -fsS -b "$JAR" -c "$JAR" "$PANEL_URL/whm/licence" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')"
BODY="$WORK/activate.html"
curl -fsS -b "$JAR" -c "$JAR" -o "$BODY" -L "$PANEL_URL/whm/licence/activate" \
  -d "_token=$TOKEN" --data-urlencode "key=$KEY"

contains "the WHM screen reports success" "$(cat "$BODY")" "Licence activated"
check "the panel is active" "$(health)" "active"

echo
echo "== 4. Heartbeat: the panel refreshes its own token =="
TOKEN="$(curl -fsS -b "$JAR" -c "$JAR" "$PANEL_URL/whm/licence" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')"
BODY="$WORK/refresh.html"
curl -fsS -b "$JAR" -c "$JAR" -o "$BODY" -L "$PANEL_URL/whm/licence/refresh" -d "_token=$TOKEN"

contains "the panel reports a successful check" "$(cat "$BODY")" "Checked with the central service"
check "the panel is still active" "$(health)" "active"

echo
echo "== 5. Vendor revokes; the next check must be read-only, not data loss =="
( cd "$CENTRAL" && DB_CONNECTION=sqlite DB_DATABASE="$WORK/central.sqlite" \
    php artisan tinker --execute="App\Models\License::where('key', '$KEY')->update(['status' => 'revoked']);" >/dev/null )

TOKEN="$(curl -fsS -b "$JAR" -c "$JAR" "$PANEL_URL/whm/licence" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')"
curl -fsS -b "$JAR" -c "$JAR" -o /dev/null -L "$PANEL_URL/whm/licence/refresh" -d "_token=$TOKEN"

check "the panel went read-only" "$(health)" "read_only"

ACCOUNTS="$( cd "$PANEL" && DB_CONNECTION=sqlite DB_DATABASE="$WORK/panel.sqlite" \
    php artisan tinker --execute='echo App\Models\Account::count();' | tail -2 | head -1 | tr -d '[:space:]')"
DOMAINS="$( cd "$PANEL" && DB_CONNECTION=sqlite DB_DATABASE="$WORK/panel.sqlite" \
    php artisan tinker --execute='echo App\Models\Domain::count();' | tail -2 | head -1 | tr -d '[:space:]')"

check "provisioned accounts survived the revocation" "$ACCOUNTS" "2"
check "customer websites survived the revocation" "$DOMAINS" "2"

echo
echo "== 6. Vendor unreachable: the panel keeps its last known-good token =="
kill "$CENTRAL_PID" 2>/dev/null || true
sleep 0.5

TOKEN="$(curl -fsS -b "$JAR" -c "$JAR" "$PANEL_URL/whm/licence" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')"
BODY="$WORK/offline.html"
curl -fsS -b "$JAR" -c "$JAR" -o "$BODY" -L "$PANEL_URL/whm/licence/refresh" -d "_token=$TOKEN"

contains "the operator is told the vendor is unreachable" "$(cat "$BODY")" "unreachable"
check "the state is unchanged (still read-only from the revocation)" "$(health)" "read_only"

echo
echo "------------------------------------------------------------"
if [ "$fail" -eq 0 ]; then
  green "All $pass checks passed: activate, heartbeat, revoke, offline."
else
  red "$fail of $((pass + fail)) checks failed. Logs: $WORK/central.log $WORK/panel.log"
  exit 1
fi
