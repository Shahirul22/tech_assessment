# Shared Pool

A live, shared pool of units that operators draw from on first come, first served terms. This is milestone **M1**: the shared pool, live, and the foundation that M2–M4 build on.

An operator picks their name, asks for some units and sees at once how many they got ("4 granted", or "6 of 8 granted" if someone else got in first). The remaining number and the activity log update on every open screen within about a second. Two people can never be granted the same unit, and the pool never goes below zero.

## Run it

You need Docker with Compose. Nothing else (no PHP, Composer or Node on the host).

`.env.docker` is not in the repository (it holds keys), and Compose needs it. Create it first. The keys are generated fresh and are for local use only:

```bash
K=$(openssl rand -hex 10)
cat > .env.docker <<EOF
APP_NAME="Shared Pool"
APP_ENV=local
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=true
APP_URL=http://localhost:8080
LOG_CHANNEL=stderr
LOG_LEVEL=debug
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=app
DB_USERNAME=app
DB_PASSWORD=secret
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=116625
REVERB_APP_KEY=$K
REVERB_APP_SECRET=$(openssl rand -hex 10)
REVERB_HOST=reverb
REVERB_PORT=8081
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8081
VITE_REVERB_APP_KEY=$K
VITE_REVERB_HOST=localhost
VITE_REVERB_PORT=8081
VITE_REVERB_SCHEME=http
EOF
```

Then start it:

```bash
docker compose up --build
```

Then open **http://localhost:8080**. Live updates use a websocket on port **8081**. Stop with `Ctrl+C`, or `docker compose down`. `docker compose down -v` also deletes the database, so the next start is from nothing.

The first start takes a few minutes (it builds the image). It creates the database, runs the migrations and seeds the demo data. To start in the background and wait until everything is healthy:

```bash
docker compose up -d --build --wait
```

## Demo users and the role switch

There is no login. Pick who you are in the **Acting as** box at the top; the choice is remembered in the browser. This is a role switch for the demo, **not security**: the API trusts an `X-Demo-User` header.

| Name | Role | Tier |
|---|---|---|
| Ana | operator | bronze |
| Ben | operator | silver |
| Cara | operator | gold |
| Dana | supervisor | none |

Operators can request units. Dana, the supervisor, sees the pool and the log and a **Supervisor** tab that only says *Placeholder — arrives in M3*; a supervisor's request is refused (`403`). The pool, "Main warehouse", starts with 100 units, recorded as an initial restock event.

## Test it

```bash
bash scripts/verify.sh
```

One command, five steps, stops at the first failure, about a minute:

1. **smoke**: the stack starts from nothing and serves the seeded pool
2. **tests**: unit and feature tests (Pest, on a real PostgreSQL database)
3. **concurrency**: 50 parallel requests of 3 units against a pool of 100 grant exactly 100 units, one partial grant of 1, remaining 0, and the 95th-percentile response time stays at or under 1 s. This test and its siblings run over real HTTP against the running stack.
4. **js**: the browser logic (live-update ordering, form rules, log text, connection states, safe retry)
5. **live**: a POST reaches a subscribed websocket client within 2 s, ten times in a row

`verify.sh` **erases the demo data** while it runs and restores the fresh seed when it finishes.

The pieces on their own (shorthand: `APP` = `docker compose run --rm app`, `NODE` = `docker compose --profile tools run --rm node`):

```bash
APP php artisan test                       # unit + feature
APP php artisan test --group=concurrency   # needs the stack running
NODE npm ci                                # once, on a fresh clone: installs node_modules for the two lines below
NODE npm test                              # JS
NODE node tests/Live/live-latency.mjs      # live updates, consumes 10 units
bash scripts/smoke.sh                      # SMOKE_RESET=1 reseeds first
```

The live-latency script and the concurrency tests make allocations. To get the demo pool back to 100 units: `docker compose run --rm app php artisan migrate:fresh --seed`.

## What M1 does, and what comes next

**In M1:** requests with strict first-come-first-served order, partial grants and zero grants (recorded and shown in the log), safe retries (the same idempotency key is counted once), live updates with a fallback refresh, a whole-pool activity log with your own entries marked, a pending state while waiting for the server, and an offline state that sends nothing.

**Declared now, built later:** the full database schema, a calculation pipeline whose expiry, status and correction steps do nothing yet, the rules config (tiers, windows, status thresholds, validated at start-up), and API endpoints for restock, capacity, corrections and audit that answer `501 not_implemented`. Every endpoint is written down in [docs/api-contract.md](docs/api-contract.md).

| Milestone | Adds |
|---|---|
| M2 | Temporary allocations expire and hand their units back; status levels; expiry entries in the log |
| M3 | A supervisor restocks, corrects or voids an allocation; recalculation; the audit view |
| M4 | Sunlight theme, one-thumb flow with quick amounts, final README and demo script |

## Expiry and status (M2)

A request can now be **Temporary**: choose "Temporary" next to the amount and the units come back to the pool by themselves when the operator's window ends. The window depends on the operator's tier when the request was logged (Bronze 24 h, Silver 48 h, Gold 72 h; a later change of tier does not move it). The banner says when they return ("4 granted · returns 10:00 tomorrow"), **Your temporary holdings** shows each holding with "expires in …", the **Status** line shows your own level (standard, elevated, high, from the units you hold), and an **Expiry** row appears in the log when units return. Expiry writes nothing and broadcasts nothing: every reading works out what is held at that moment, and the screen refreshes itself at the next expiry. The new fields are in [docs/api-contract.md](docs/api-contract.md) ("M2 (additive)").

**See an expiry in two minutes.** The demo stack shrinks every window (Bronze 2 min, Silver 4 min, Gold 6 min) without changing any code:

```bash
bash scripts/demo-expiry.sh                                               # starts the stack with short windows
docker compose --profile tools run --rm node node tests/Live/expiry-demo.mjs   # optional: submits, waits, prints the measured delay
docker compose up -d --wait                                               # back to 24 h / 48 h / 72 h
```

Open http://localhost:8080 as Ana, choose Temporary, request 1 and watch the holding count down. About two minutes later the holding disappears, the remaining number goes up, the status line updates and an Expiry row appears, all without a reload. `scripts/verify.sh` does not run the demo script (it needs the short-window stack and takes minutes); the unit, feature, concurrency and JS tests cover the same rules with exact times.

**Manual checks for M2** (use the demo stack, and reset the data first with `docker compose run --rm app php artisan migrate:fresh --seed`):

| # | Check | How | Expected |
|---|---|---|---|
| 7 | Two browsers at expiry | Ana in one window, Ben in another. Ana requests Temporary 4. Wait for the window to end. | Within about 2 s of the time shown, both windows show remaining +4, Ana's holding is gone and her status updates, and an Expiry row appears in both. |
| 8 | Narrow and wide layout | Repeat check 2 with the type choice, status line and holdings panel visible. | `scrollWidth <= innerWidth` at 360 px and 1280 px. |
| 9 | Live service down at expiry | `docker compose stop reverb`, then wait for an expiry. | The screen still corrects at expiry (the expiry timer fetches a snapshot) and, failing that, by the 10 s refresh. |
| 10 | Wrong device clock | Set the device clock one hour ahead, reload. | "expires in …" and the moment the holding disappears follow the server's time, not the device's. |

**Working assumptions for M2** (from the milestone PRD):

- **A-M2.1** At exactly `expires_at` the units are already back; 1 ms earlier they are still held.
- **A-M2.2** A request is judged at its own server receipt time, so first-come-first-served and expiry use one clock reading.
- **A-M2.3** A zero-grant Temporary request holds nothing, never expires and has no Expiry row.
- **A-M2.4** "Expires in" is coarse (hours from 2 h, then minutes) and counts down on the server's time.
- **A-M2.5** "Holdings" are your own temporary units that are still held; consumed units never return, so they are not listed.
- **A-M2.6** There is no screen for changing a tier; the tier-change case is tested by changing the data directly.

**Things to know:**

- **Evaluation instant.** A request is evaluated at its receipt time, but never earlier than the newest allocation already stored. Requests received a few milliseconds apart can commit in the other order; evaluating at a time that never goes backwards keeps every reply consistent with the ledger, and a replay returns exactly the original reply. The stored `effective_at` is still the receipt time.
- **An Expiry row can move up one place.** It sits just above the newest stored entry received at or before the expiry. If a request received before that moment commits after the row was first shown, the row shifts by one place. It is never duplicated or lost.
- **Background browser tabs** throttle timers, so a hidden tab may show an expiry late. It corrects when the tab is shown again or at the next refresh; the 2 s target applies to a visible screen.
- M2 supersedes two M1 statements: the form now has a type choice (M1 had none), and `viewer.status` and `viewer.holdings` are filled in.

## Assumptions and accepted trade-offs

Decisions taken for M1 (from the product requirements and the milestone PRD):

- **Units** are whole numbers, one unit type per pool. A request must be a whole number of at least 1; the form caps it at the remaining last shown, and the server grants `min(requested, remaining)` whatever the screen said.
- **Order** is the order requests commit on the server, never device clocks. `effective_at` is the server's receipt time; a time sent by a client is ignored.
- **Types:** the API accepts `type` (`consumed` by default, or `temporary`). The M1 form has no selector, so every request from the screen is consumed.
- **Zero grants** are stored and shown ("Ben requested 3, granted 0").
- **Same idempotency key, different request** (other amount, operator or type) is refused with `409` and records nothing.
- **Only operators submit.** A supervisor's request gets `403`.
- **Tiers and thresholds** are placeholders in `config/pool_rules.php`: bronze 24 h, silver 48 h, gold 72 h; status levels at 0, 10 and 30 units. An invalid file stops start-up and names the bad key.
- **"About a second"** means: on this local stack, the 95th percentile of submit-to-confirmed time is at most 1 s during the 50-request test.
- **Live updates** arrive within 2 s; a screen that loses its connection shows "Reconnecting…" and refreshes about every 10 s.

Accepted risks, so nobody is surprised:

- **Remaining is derived, not stored.** Every write reloads the pool's whole history under the lock. That is exact and simple, and fine at demo scale (hundreds of entries); it gets slower as history grows.
- **`effective_at` can differ from commit order** by a few milliseconds under heavy contention. The log and the ledger are ordered by commit order (`seq`). M3 must choose which one drives recalculation.
- **`.env.docker` is not committed.** Create it as described in "Run it". Its values are for local demo use only; nothing here is deployed.
- **The websocket channel is public and `X-Demo-User` is not authentication.** Fine for a demo with no real users; do not deploy it as is.
- **Several browser checks are manual** (below). The logic behind them is covered by automated tests.

## Versions

Laravel 13.37.0 on PHP 8.4, Laravel Reverb 1.12.0, Pest 4.7.8, PostgreSQL 16, Node 22 (assets only), Alpine.js 3.17.4, Laravel Echo 2.6.1 with pusher-js 8.6.0, Vite 8.3.4, Laravel Boost 2.10.3 (development only). Exact versions are pinned in `composer.lock` and `package-lock.json`.

## Working with Claude Code

The project uses [Laravel Boost](https://laravel.com/docs/boost), an MCP server that gives an AI coding assistant the real documentation for the installed versions, the live database schema and more. Because the app runs in Docker, `.mcp.json` starts Boost inside the container: `docker compose exec -T app php artisan boost:mcp`. So the stack must be running (`docker compose up -d --wait`).

- The first time Claude Code opens this folder it asks you to approve the project MCP server `laravel-boost`. Approve it once.
- `CLAUDE.md` starts with hard project rules: use Boost's documentation search before writing Laravel code, inspect the real schema instead of guessing, and name the documentation query in every task note. The same rules are in `workflow/rules/laravel-boost.md`, which the workflow skills read before every task.
- The product requirements are never edited. The PRD, plan and task list for this milestone are in `workflow/slices/m1-shared-pool/`.

## Manual checks

The automated suite covers the logic. These checks need a browser. Run `docker compose up -d --wait` first and, before you start, reset the data: `docker compose run --rm app php artisan migrate:fresh --seed`.

| # | Check | How | Expected |
|---|---|---|---|
| 1 | Two browsers in sync | Open http://localhost:8080 in two windows. Pick Ana in one and Ben in the other. Request 4 as Ana. | Both windows show remaining 96 and a new log row within 2 s, no reload. Ana's row is marked "You" only in Ana's window. |
| 2 | Narrow and wide layout | Open the page, make the window 360 px wide, then 1280 px. In the browser console run `document.documentElement.scrollWidth <= window.innerWidth`. | `true` at both widths. At 360 px the name picker, the indicator, the remaining number and the Request button are on the first screen. At 1280 px the form and remaining sit beside the log. |
| 3 | Live service goes down | `docker compose stop reverb`. Request 1 as Ana from the other window. Then `docker compose start reverb`. | The indicator says "Reconnecting…" (word and shape, not just colour). The other window updates within about 10 s. After the restart it says "Live" and both windows match. |
| 4 | Airplane mode | In DevTools, set the network to Offline (or use airplane mode). Type 3. | The indicator says "Offline"; the button is disabled with "You are offline — nothing was sent". Go back online: nothing is sent by itself. |
| 5 | Lost answer, safe retry | In DevTools, throttle the network heavily, request 2, and drop the connection before the answer. | "Not confirmed — Retry". Press Retry once back online: "2 granted", and the log has exactly one entry for it. |
| 6 | Added latency | In DevTools, throttle with 100 ms of extra latency. Request 1. | The Pending row shows at once and the result is confirmed in about 1 s. |

Results from the last full run are at the end of this file.

## Results of the last full run

Run on 2026-10-17 on the local Docker stack, starting from `docker compose down -v`.

`bash scripts/verify.sh`: **5 of 5 steps passed** (smoke; 189 unit and feature tests and 8 pending ones for M2 and M3; 50 parallel requests of 3 against 100 with a 95th-percentile of **0.79 s**; 80 JS tests; 10 of 10 live updates within 2 s, median 38 ms).

The manual checks above, in order. These were driven in a headless Chromium (Playwright) by the assistant that built M1, with DevTools-style network emulation, so they exercise the real page, the real websocket and the real server, but they are not a person on a phone. Repeat them in a real browser and on a real device before relying on them.

| # | Check | Result | Measured |
|---|---|---|---|
| 1 | Two browsers in sync | PASS | both windows updated 144 ms after the request; "You" only in Ana's window |
| 2 | 360 px and 1280 px layout | PASS | `scrollWidth <= innerWidth` at both; at 360 px the picker, indicator, remaining and Request button all fit on the first screen |
| 3 | Live service goes down | PASS | "Reconnecting…" shown; other window updated by the fallback refresh after about 10 s; "Live" again and in step with the server after the restart |
| 4 | Airplane mode | PASS | "Offline", button disabled, "You are offline — nothing was sent"; nothing sent after coming back online |
| 5 | Lost answer, safe retry | PASS | "Not confirmed — Retry", then "2 granted"; the same key was reused; one log entry |
| 6 | Added latency | PASS | 100 ms added: Pending row after 36 ms, confirmed after 139 ms. Slow 3G (400 ms, 400 kbps): Pending after 45 ms, confirmed after 500 ms |

Not covered by M1: the contrast and touch-target audit and the sunlight theme (M4).
