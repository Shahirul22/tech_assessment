#!/usr/bin/env bash
# Starts the stack with short expiry windows so a Temporary allocation can be seen ending in a couple of minutes
# (FR14, AC-US8.1).
#
#   bash scripts/demo-expiry.sh
#
# Windows become Bronze 2 min, Silver 4 min, Gold 6 min. Nothing in the code changes: the demo file only sets
# POOL_WINDOW_SCALE for the app and migrate services. Passing -f turns off the automatic docker-compose.override.yml,
# so it is named here as well.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

docker compose -f docker-compose.yml -f docker-compose.override.yml -f docker-compose.demo.yml up -d --build --wait

cat <<'MESSAGE'

Demo windows are on: Bronze 2 min, Silver 4 min, Gold 6 min.
  Open http://localhost:8080 as Ana (Bronze), choose Temporary, request 1, and watch the holding end in 2 minutes.
  Or run the measured check:  docker compose --profile tools run --rm node node tests/Live/expiry-demo.mjs

Go back to the real windows (24 h, 48 h, 72 h):
  docker compose up -d --wait
MESSAGE
