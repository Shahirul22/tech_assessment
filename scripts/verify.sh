#!/usr/bin/env bash
# Proves the whole automated surface in one command (F7, AC-US7.6).
#
#   bash scripts/verify.sh
#
# Runs five steps in order and stops at the first failure, with one PASS or FAIL line per step:
#   1. smoke        the stack starts from nothing and serves the seeded pool
#   2. tests        unit and feature tests (Pest, real PostgreSQL)
#   3. concurrency  50 parallel requests of 3 against 100, and the other parallel cases, against the running stack
#   4. js           the browser modules (node --test)
#   5. live         a POST reaches a websocket subscriber within 2 s, ten times
#
# The 2-minute expiry demo (scripts/demo-expiry.sh and tests/Live/expiry-demo.mjs) is not part of this: it needs the
# short-window stack and takes minutes. The expiry rules themselves are covered by steps 2 to 4.
#
# This ERASES the demo data: step 1 reseeds it first, and it is restored to the fresh seed at the end (step 5 makes
# allocations). It does not cover the manual browser checklist in the README.

set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

USER_ID="$(id -u):$(id -g)"
APP=(docker compose run --rm --user "$USER_ID" -e HOME=/tmp app)
NODE=(docker compose --profile tools run --rm --user "$USER_ID" -e HOME=/tmp -e npm_config_cache=/tmp/npm-cache node)

LOG_DIR="$(mktemp -d)"
trap 'rm -rf "$LOG_DIR"' EXIT

passed=0
total=5

# step <number> <name> <summary pattern> <command...>
# Runs the command, hides its output unless it fails, and prints one line.
step() {
    local number=$1 name=$2 pattern=$3
    shift 3

    local log="$LOG_DIR/step-$number.log" started=$SECONDS

    if "$@" >"$log" 2>&1; then
        local detail
        detail="$(sed 's/\x1b\[[0-9;]*m//g' "$log" | grep -E "$pattern" | tail -1 | sed 's/^[[:space:]]*//')"
        printf 'PASS  %d/%d  %-12s %3ds  %s\n' "$number" "$total" "$name" "$((SECONDS - started))" "$detail"
        passed=$((passed + 1))
    else
        printf 'FAIL  %d/%d  %-12s %3ds\n' "$number" "$total" "$name" "$((SECONDS - started))"
        echo "----- last output of the failing step -----"
        sed 's/\x1b\[[0-9;]*m//g' "$log" | tail -40
        echo "-------------------------------------------"
        echo "verify: FAILED at step $number ($passed of $total passed)"
        exit 1
    fi
}

# Whatever happens, leave the demo data as freshly seeded: the live step allocates units.
restore_demo_data() {
    "${APP[@]}" php artisan migrate:fresh --seed --force >/dev/null 2>&1 || true
}
trap 'restore_demo_data; rm -rf "$LOG_DIR"' EXIT

step 1 smoke        'SMOKE OK'          env SMOKE_RESET=1 bash scripts/smoke.sh
step 2 tests        'Tests:'            "${APP[@]}" php artisan test
step 3 concurrency  'p95'               "${APP[@]}" php artisan test --group=concurrency
# A fresh checkout has no node_modules on the host, and the live step needs pusher-js from there.
step 4 js           '^# pass'           "${NODE[@]}" sh -c 'test -d node_modules || npm ci --no-audit --no-fund; npm test'
step 5 live         'within 2000 ms'    "${NODE[@]}" node tests/Live/live-latency.mjs

echo "verify: all $total steps passed"
