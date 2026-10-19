#!/usr/bin/env bash
# One command from a clean checkout to a working stack (F1, AC-US7.1).
#
#   bash scripts/smoke.sh
#
# Builds and starts everything, waits until the app answers, then checks the seeded data over HTTP: the pool has
# 100 units remaining and its log shows the initial stock as a restock event of 100. Prints SMOKE OK and exits 0,
# or prints what was wrong and exits 1.
#
# The check expects a freshly seeded database. Allocations made since (for example by tests/Live/live-latency.mjs)
# lower the remaining number. Run with SMOKE_RESET=1 to reseed first (this ERASES the demo data), or by hand:
# `docker compose run --rm app php artisan migrate:fresh --seed`.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

BASE_URL="${SMOKE_URL:-http://localhost:8080}"
WAIT_SECONDS="${SMOKE_WAIT_SECONDS:-60}"

fail() {
    echo "SMOKE FAILED: $*" >&2
    exit 1
}

# The two questions asked of the snapshot JSON on stdin. jq if the host has it, otherwise Python 3.
if command -v jq >/dev/null 2>&1; then
    remaining_of() { jq -r '.remaining'; }
    restocks_of_100() { jq -r '[.log[] | select(.kind == "restock" and .amount == 100)] | length'; }
elif command -v python3 >/dev/null 2>&1; then
    remaining_of() { python3 -c 'import json, sys; print(json.load(sys.stdin)["remaining"])'; }
    restocks_of_100() {
        python3 -c 'import json, sys; print(sum(1 for e in json.load(sys.stdin)["log"] if e.get("kind") == "restock" and e.get("amount") == 100))'
    }
else
    fail "needs jq or python3 on the host to read JSON"
fi

echo "Starting the stack (docker compose up -d --build --wait)..."
docker compose up -d --build --wait || fail "docker compose could not start the stack"

if [ "${SMOKE_RESET:-0}" = "1" ]; then
    echo "Reseeding the demo data (SMOKE_RESET=1)..."
    docker compose run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp app php artisan migrate:fresh --seed --force >/dev/null \
        || fail "could not reseed the database"
fi

echo "Waiting for ${BASE_URL}/up (up to ${WAIT_SECONDS} s)..."
deadline=$((SECONDS + WAIT_SECONDS))
until [ "$(curl -s -o /dev/null -w '%{http_code}' "${BASE_URL}/up" || true)" = "200" ]; do
    [ "$SECONDS" -lt "$deadline" ] || fail "${BASE_URL}/up did not answer 200 within ${WAIT_SECONDS} s"
    sleep 1
done

snapshot="$(curl -sf -H 'X-Demo-User: 1' "${BASE_URL}/api/pools/1")" || fail "GET ${BASE_URL}/api/pools/1 failed (is the database seeded?)"

remaining="$(printf '%s' "$snapshot" | remaining_of)"
[ "$remaining" = "100" ] || fail "remaining is ${remaining}, expected 100 (a freshly seeded pool; see the note at the top of this script)"

restocks="$(printf '%s' "$snapshot" | restocks_of_100)"
[ "$restocks" -ge 1 ] || fail "the log has no restock entry of 100 units"

echo "SMOKE OK (remaining ${remaining}, initial stock of 100 in the log)"
