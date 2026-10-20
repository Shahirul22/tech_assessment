# Shared Pool

A live, shared pool of units that operators draw from on first come, first served terms, built in four milestones (M1 to M4) and now complete.

An operator picks their name, taps a quick amount or types a number, and sees at once how many they got ("4 granted", or "6 of 8 granted" if someone else got in first). The remaining number and the activity log update on every open screen within about a second. Two people can never be granted the same unit, and the pool never goes below zero. Temporary requests hand their units back when the window ends, a supervisor can restock and correct, and a request whose answer is lost is resent by itself without ever being counted twice. The screens are built for a phone in bright light: large type, 7:1 contrast, 48 px targets, and every state shown in words and a shape.

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

Operators can request units. Dana, the supervisor, sees the pool and the log and a **Supervisor** tab (restock, correct or void, audit; see "Corrections and restock (M3)"); a supervisor's request is refused (`403`). The pool, "Main warehouse", starts with 100 units, recorded as an initial restock event.

## Demo walkthrough (10 minutes)

Use this to see everything working. It needs two browser windows (Ana and Ben), and for step 2 a window at 360 px (DevTools device toolbar, width 360). The waiting is about 2 minutes (step 3) and 7 seconds (step 6).

**0. Reset and start with short windows** (so an expiry takes 2 minutes instead of 24 hours):

```bash
docker compose down -v
bash scripts/demo-expiry.sh        # builds, starts the stack with Bronze 2 min, Silver 4 min, Gold 6 min, and seeds 100 units
```

Open http://localhost:8080 in two windows. Pick **Ana** in the first and **Ben** in the second. Both say "Live" and remaining 100. (To start over later without waiting for a rebuild: `docker compose run --rm app php artisan migrate:fresh --seed --force`.)

| # | Step | Expected |
|---|---|---|
| 1 | **Two browsers in sync.** As Ana, type 4 and press **Request**. | Ana sees "Pending" and then a full-width **4 granted** banner. Within 2 s Ben's window shows remaining 96 and a new log row, no reload. The row says "You" only in Ana's window. |
| 2 | **One thumb, at 360 px.** As Ana at 360 px width, tap the quick amount **5**, then **Request** (two taps, no keyboard). Try **+**, **−** and **All (N)**. | **5 granted**, remaining 91. The amount, the quick amounts and the button fit on the first screen, every control is at least 48 px, and nothing scrolls sideways. At 1 the **−** button is dashed and struck through (not usable); **All (91)** fills 91. |
| 3 | **A Temporary request and its expiry.** As Ana choose **Temporary**, tap **1**, **Request**. | "1 granted · returns HH:MM", and under **Your temporary holdings** "1 · expires in 1 min" or similar. Remaining 90 in both windows. After about 2 minutes, with no reload: the holding disappears, remaining is 91 again, the status line updates and an **Expiry** row appears in both windows. (`bash scripts/demo-expiry.sh` also prints the measured version: `docker compose --profile tools run --rm node node tests/Live/expiry-demo.mjs`.) |
| 4 | **A partial grant.** `docker compose stop reverb` (the screens stop getting live updates; Ben's keeps showing 91 for up to 10 s). As Ana request 85. Within 10 s, as Ben, request 10. Then `docker compose start reverb`. | Ana: "85 granted". Ben: **6 of 10 granted** (the server gives what is left and says so). Both windows end at remaining 0, and the form says "0 left" with every amount control unusable. |
| 5 | **Restock and correction.** Open a third window as **Dana**, **Supervisor** tab. Restock 50 with the note "Delivery". Back on the **Pool** tab press **Correct** on Ana's "85 granted" row, **Amend** to 20 with the reason "Counted wrong". Press **Correct** on the same row again and amend it to 500 (reason "Test"). Then restock 400 and amend the same row to 500 again. | Restock: **Added 50 units. Remaining: 50** and the other windows show 50 within 2 s. Amend: "Entry … changed 85 → 20. Remaining: 115". The raise is refused as text, **Refused — Needs 480 more units, 115 are free. Restock first.**, and nothing is recorded. After the restock (515) the raise is accepted and remaining is 35. **Audit** lists each correction with the reason. |
| 6 | **A lost answer, resent by itself.** As Ana open DevTools, Network, **Block request URL** `/api/pools/1/allocations`. Request 1. | "Pending", then "Retrying automatically, attempt 1 of 3", then 2 and 3, then **Not confirmed** with a **Retry** button after about 7 s. Remaining has not changed. Remove the block and press **Retry**: "1 granted" and exactly one new row in the log, however many sends were made (the same key is counted once). |
| 7 | **Airplane mode.** As Ana set the network to **Offline** (DevTools, or airplane mode). | The indicator says **Offline**, the button is disabled and the screen says "You are offline — nothing was sent". Quick amounts still fill the field. Go back online: nothing is sent by itself, the indicator returns to **Live** and remaining and the log match the server after one refresh. |

When you are done: `docker compose up -d --wait` returns to the real windows (24 h, 48 h, 72 h).

## Test it

```bash
bash scripts/verify.sh
```

One command, five steps, stops at the first failure, about a minute:

1. **smoke**: the stack starts from nothing and serves the seeded pool
2. **tests**: unit and feature tests (Pest, on a real PostgreSQL database)
3. **concurrency**: 50 parallel requests of 3 units against a pool of 100 grant exactly 100 units, one partial grant of 1, remaining 0, and the 95th-percentile response time stays at or under 1 s. This test and its siblings run over real HTTP against the running stack.
4. **js**: the browser logic (live-update ordering, form rules, log text, connection states, the retry runner, the banner wording, quick amounts and the stepper, and the colour-contrast test of every design token)
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

**Browser audits (M4).** Two scripts drive a real Chromium against the running stack. They are **not** part of `verify.sh` and they run **on the host**, not in Docker, because they need a real browser (`playwright-core` is a development dependency; it downloads no browser, so it uses a Chromium you already have; set `CHROMIUM_PATH` if it is not in `~/.cache/ms-playwright/`, and make sure Chromium's system libraries, for example `libasound2`, are installed). Run `NODE npm ci` once first.

```bash
node tests/Live/ui-audit.mjs       # 48 px targets, 8 px gaps, 7:1 text contrast, 24 px key numbers, no horizontal scroll, at 360 and 1280 px
node tests/Live/offline-flow.mjs   # offline, lost answers, exactly 4 sends with one key, Retry, 422/409: at most one allocation per submit
```

Both print one PASS or FAIL line per check and exit 1 on any failure. `ui-audit.mjs` records about 3 units, `offline-flow.mjs` about 4; reseed afterwards if the numbers matter.

## What each milestone added

| Milestone | Adds |
|---|---|
| M1 | The shared pool: strict first-come-first-served requests, partial and zero grants, safe retries (the same idempotency key is counted once), live updates with a fallback refresh, a whole-pool activity log, a pending state and an offline state that sends nothing. Every endpoint is written down in [docs/api-contract.md](docs/api-contract.md). |
| M2 | Temporary allocations expire and hand their units back; status levels; expiry entries in the log |
| M3 | A supervisor restocks, corrects or voids an allocation; recalculation; the audit view |
| M4 | One-thumb requests (quick amounts and a stepper), full-width result banners, six clearly worded request states with automatic resend of a lost answer, the sunlight standard (measured), the 360 px layout, this README and its demo script. No server change. |

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

## Corrections and restock (M3)

**Dana, the supervisor, can now add units and fix past requests.** Pick Dana and open the **Supervisor** tab. It has a **Restock** form (a number of units and an optional note), a **Correct or void an entry** form, and the **Audit** view. To correct something, go back to the **Pool** tab and press **Correct** on a log row: the form is filled with the entry number and what it holds now. Choose **Amend** and a new amount, or **Void**, give a reason, and press the button. Nothing is ever edited: every correction is one more log entry next to the original, which stays readable ("Ana: 40 granted · corrected to 20", or "· voided"). The supervisor tab replaces the M1 placeholder, and `PUT /capacity` no longer exists: capacity only goes up, by restock. The new fields and endpoints are in [docs/api-contract.md](docs/api-contract.md) ("M3 (additive)").

**Walkthrough** (reset the data first with `docker compose run --rm app php artisan migrate:fresh --seed`; open Ana and Dana in two windows):

1. As Ana, request 100: remaining is 0.
2. As Dana, restock 50 with the note "Delivery": both windows show 50 and a "Dana added 50 units · Delivery" row.
3. As Dana, press **Correct** on Ana's row, **Amend** to 20 with the reason "Counted wrong": both windows show 130. Ana's status goes from high to elevated, and the log shows the correction and Ana's original row marked "corrected to 20".
4. As Dana, try to raise Ana's entry to 500: refused as text, "Needs 480 more units, 130 are free. Restock first." Nothing was recorded. Restock more and try again: accepted.
5. As Dana, **Void** the entry with a reason: its units come back and Ana's status is standard.
6. Open **Audit**: one row per correction (who, which entry, from → to, the reason, and Ana's status before → after at the moment it was corrected and at the later points where it differs). If a raise would have taken the pool below 0 at an earlier time, the audit says "Overdrawn in hindsight: lowest -20 between 10:00 and 13:00". That flag is only a warning about the past: it never blocks anything and never changes today's remaining.

**How a correction is judged.** Lowering or voiding is always accepted. Raising is accepted only if the units that are free right now cover it, and one unit more is refused. A Temporary entry whose window has already ended is history only: correcting it never changes the current remaining and needs no free units. A correction never moves `expires_at`; at expiry exactly the corrected units come back.

**Manual checks for M3** (reset the data first):

| # | Check | How | Expected |
|---|---|---|---|
| 11 | Two browsers while the supervisor restocks | Ana in one window, Ben in another, Dana in a third. Dana restocks 50. | Ana's and Ben's windows show remaining +50 and the restock row within 2 s, no reload. |
| 12 | Lower, refuse, raise, void | Follow steps 3 to 5 of the walkthrough with Ana and Ben watching. | Each window updates within 2 s. Ana's status and holdings follow. The refusal is words on Dana's screen and changes nothing anywhere. Ben's window shows nothing about Ana's status. |
| 13 | Audit | Open the Audit view after a lowering. | One row with the original, the corrected amount, the reason and the status before → after. With no corrections it says "No corrections yet". |
| 14 | Narrow and wide layout | As in check 2, on the Supervisor tab and the Pool tab with the new rows. | `scrollWidth <= innerWidth` at 360 px and 1280 px. |
| 15 | Live service down | `docker compose stop reverb`, then Dana restocks or corrects. | Dana's screen updates from the answer; the other window catches up on the 10 s refresh. |
| 16 | Offline | Set the network to Offline on Dana's window and fill a restock or a correction. | The button is disabled and says "You are offline — nothing was sent". Going back online sends nothing by itself. |

**Working assumptions for M3** (from the milestone PRD):

- **A-M3.1** A restock is a whole number of units, 1 or more, with no upper limit in the demo.
- **A-M3.2** The reason of a correction is part of the log entry everyone sees. The audit view and the hindsight flag are for supervisors only.
- **A-M3.3** Any seeded supervisor may restock or correct; the acting supervisor is recorded.
- **A-M3.4** Expiry rows follow the corrected units: a void leaves none, a lowered entry shows the lowered units.
- **A-M3.5** What users see is commit order (`seq`). A write is evaluated at its receipt time but never earlier than the newest entry already stored, whatever its kind, so each reply stays consistent with the ledger.

**Things to know:**

- **A refused correction records nothing**, so sending the same request again is judged afresh: refused again while the pool is unchanged, accepted if a restock arrived in between. A recorded correction or restock is replayed exactly.
- **"Overdrawn in hindsight" shows the whole stretch below 0.** In the worked example the pool is already -10 after Ben's 10:00 entry and -20 after Cara's at 11:00, so the audit shows 10:00 to 13:00 with the lowest level -20, which contains 11:00 to 13:00.
- **A voided entry is final.** It cannot be corrected again; other entries can be corrected as often as needed, each judged against what the entry holds now.
- **Idempotency keys are unique per kind of entry**, and the screen makes a new one for every action.
- M3 supersedes two earlier statements: the supervisor tab is no longer a placeholder, and the capacity endpoint is gone.

## Decisions and trade-offs

The twelve decisions the product was built on (they come from the requirements, `requirement.md` section 3), each with the reason in plain words.

- **D1 One shared pool, strict first come, first served.** Requests are served in the order the server commits them, never by a device's clock, because phone clocks disagree and nobody could prove who was first.
- **D2 The pool never goes negative.** The form caps a request at what you last saw as left, and the server grants `min(requested, remaining)` whatever the screen said, so a stale screen can get a partial grant ("4 of 6 granted") but can never overdraw.
- **D3 Tier is separate from status.** The tier (bronze, silver, gold) is written on each allocation when it is logged, so a later change of tier does not rewrite old windows; status is something else, worked out from what you hold.
- **D4 Expiry is worked out when read.** Temporary units come back when their window ends because every reading calculates what is held at that moment; there is no scheduled job that can be late or forgotten, and each screen simply refetches at the next expiry it knows of.
- **D5 Online only, no queue.** A request that was never sent is not held for later, because a queued request could be granted hours after the person walked away; the screen says "Pending" or "Offline" instead of guessing a total. M4 adds one narrow exception that holds nothing for later: a request that was already sent and got no answer is resent automatically, up to 3 times, with the same key, while the device is online.
- **D6 A time box, delivered in milestones, no deployment.** Each milestone runs on its own with tests for the hard rules, and a role switch stands in for a login, because the aim is to show the rules working, not an account system.
- **D7 Corrections are added, never edited.** A correction changes an amount or voids an entry and is one more log entry with the supervisor and the reason; lowering frees units at once, raising is refused unless the free units cover it, because "never negative" has no exceptions. Past grants stay final.
- **D8 Capacity only goes up, by restock.** A supervisor adds logged restock events and there is no daily reset; consumed units are gone for good and temporary units are held until they expire.
- **D9 Laravel and PostgreSQL in Docker Compose.** One command starts everything, and the database gives the row locking the one-at-a-time write path relies on.
- **D10 Status is shown, not enforced.** Your level (standard, elevated, high) comes from the units you hold now and changes when a correction changes them; it never stops anyone from requesting.
- **D11 "Never negative" is about now.** A correction is accepted or refused by what the pool can cover right now; if it would have put an earlier moment below zero, the audit shows "overdrawn in hindsight" as a warning about the past and blocks nothing.
- **D12 Blade, Alpine and Reverb for the screen and live updates.** One small channel carries a version, the remaining and the new log entry; a screen applies versions in order, fetches a snapshot on a gap or reconnect, polls every 10 s while the socket is down, and shows its own request as pending until the server answers.

Other decisions, from the milestone PRDs:

- **Units** are whole numbers, one unit type per pool. A request must be a whole number of at least 1.
- **Order and time:** `effective_at` is the server's receipt time; a time sent by a client is ignored.
- **Zero grants** are stored and shown ("None granted — 0 of 3, none left").
- **Same idempotency key, different request** (other amount, operator or type) is refused with `409` and records nothing.
- **Only operators submit.** A supervisor's request gets `403`.
- **Tiers and thresholds** are placeholders in `config/pool_rules.php`: bronze 24 h, silver 48 h, gold 72 h; status levels at 0, 10 and 30 units. An invalid file stops start-up and names the bad key.
- **"About a second"** means: on this local stack, the 95th percentile of submit-to-confirmed time is at most 1 s during the 50-request test.
- **Live updates** arrive within 2 s; a screen that loses its connection shows "Reconnecting…" and refreshes about every 10 s.
- **The M4 request states** are Pending, Offline, Retrying automatically (attempt n of 3), Not confirmed, Retrying and Confirmed. Resends wait 1 s, 2 s and 4 s and each send times out after 3 s. Only a lost answer is resent: a network error, a timeout, an unreadable answer or any server 5xx (including a busy pool). A definite answer (granted, refused, conflict, invalid) is never resent.
- **The sunlight standard** is one theme, not a switch: text at least 7:1 against its background, key numbers at least 24 px, every tappable control at least 48 px with 8 px between neighbours, and no state shown by colour alone (each has words and a shape).

Accepted risks, so nobody is surprised:

- **Remaining is derived, not stored.** Every write reloads the pool's whole history under the lock. That is exact and simple, and fine at demo scale (hundreds of entries); it gets slower as history grows.
- **Nothing survives a reload.** A request that was sent but not answered is forgotten if the page is closed or reloaded (no queue, D5), although the server may have stored it. Look at the log and ask again if it is missing. Switching to another user while a resend is waiting stops that resend and drops its late answer in the same way.
- **Up to about 19 s before the manual Retry appears** when every send hangs until its 3 s timeout (4 sends of 3 s plus 7 s of waiting); about 7 s when the network fails at once.
- **`effective_at` can differ from commit order** by a few milliseconds under heavy contention. The log and the ledger are ordered by commit order (`seq`), and that drives recalculation.
- **The websocket channel is public and `X-Demo-User` is not authentication.** Fine for a demo with no real users; do not deploy it as is.
- **`.env.docker` is not committed.** Create it as described in "Run it". Its values are for local demo use only; nothing here is deployed.
- **The browser audits run on the host,** not in Docker, and need a Chromium on the machine; the contrast of every design colour is also proved in `npm test` without a browser.
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
| 4 | Airplane mode | In DevTools, set the network to Offline (or use airplane mode). Tap a quick amount, then try to request. | The indicator says "Offline"; the quick amounts and the stepper still fill the field, but the button is disabled with "You are offline — nothing was sent". Go back online: nothing is sent by itself. |
| 5 | Lost answer, resent by itself, then Retry | In DevTools, Network, Block request URL `/api/pools/1/allocations`. Request 2. After "Not confirmed" appears, remove the block and press Retry. | "Pending", then "Retrying automatically, attempt 1 of 3", 2 and 3 (1 s, 2 s and 4 s apart), then "Not confirmed" with a Retry button after about 7 s, and nothing more is sent by itself. Retry: "2 granted", and the log has exactly one entry for it. |
| 6 | Added latency | In DevTools, throttle with 100 ms of extra latency. Request 1. | The Pending row shows within 100 ms and the result is confirmed in about 1 s. |
| 17 | Offline during the resends | As check 5, but set the network to Offline while the banner says "Retrying automatically". | Resending stops at once and the banner says "Not confirmed" with "You are offline — nothing was sent". No further request is made. Go online and press Retry: the result arrives once, with one log entry. |
| 18 | Greyscale | DevTools, Rendering, Emulate vision deficiency: Achromatopsia. Step through Pending, Retrying automatically, Not confirmed, Retrying (press Retry), Offline and Confirmed. | Each state is told apart by its words and by its shape alone: hollow ring, ring with an arrow, triangle, ring with a dot, square with a bar, tick. The status, own-entry marker and the holdings are readable without colour. |
| 19 | Keyboard | Press Tab through the operator screen and then the supervisor screen. | Every control shows a visible focus ring and has a text name (including "Decrease", "Increase" and "Correct entry #n"). |
| 20 | Touch targets and contrast | `node tests/Live/ui-audit.mjs` (see "Test it"). | Six PASS lines: targets at least 48 px with 8 px gaps, text at least 7:1, key numbers at least 24 px, no sideways scroll at 360 and 1280 px, submit above the log. |

Results of the runs are at the end of this file.

## Results of the M1 run

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

## Results of the M4 run

Run on 2026-10-20 on the local Docker stack, starting from `docker compose down -v`.

**Method.** The automated steps are the commands below. The walkthrough and the manual checks were driven in a headless Chromium (Playwright) by the assistant that built M4, with DevTools-style network emulation, so they exercise the real page, the real websocket and the real server, but they are not a person on a phone. Repeat them in a real browser and on a real device before relying on them.

`bash scripts/verify.sh`: **5 of 5 steps passed** (smoke; **405** unit and feature tests, 1704 assertions; the concurrency group, including **50 parallel requests of 3 against 100 with a 95th percentile of 0.709 s** (the test asserts exactly 100 granted and one partial grant of 1; p95 limit 1 s) and the mixed case with a lowering and a restock at 0.676 s; **210** JS tests; **10 of 10 live updates within 2 s**, median 37 ms). A replayed idempotency key is counted once (`it counts one idempotency key sent 10 times in parallel exactly once`, in the same group).

`node tests/Live/ui-audit.mjs`: **all six checks PASS** at 360 and 1280 px as Ana and as Dana (both tabs), with every banner state shown (nine request states, five supervisor ones). Smallest tap target **48.0 px** with at least 8 px between neighbours (421 elements); lowest text contrast **7.17:1** (1412 text elements, none skipped); 58 key-number measurements at or above 24 px; no horizontal scroll (36 measurements); the submit button is above the log at 360 x 640. `npm test` also proves every colour pair in `tokens.css` is at least 7:1 (borders and focus ring at least 3:1).

`node tests/Live/offline-flow.mjs`: **5 of 5 cases PASS**: offline sends nothing; a lost answer after the server stored it is resent once with the same key and shows the original result with one allocation; when every send fails there are exactly 4 sends with one key, then "Not confirmed" with Retry, nothing more by itself, and Retry settles it with one allocation; ten offline/online toggles plus Retry taps leave at most one allocation and one key; a 422 and a 409 are never resent.

**Demo walkthrough** (steps 0 to 7 above, followed from `docker compose down -v` and `bash scripts/demo-expiry.sh`; the stack was up 31 s after the command):

| Step | Result | Measured |
|---|---|---|
| 1 Two browsers in sync | PASS | "4 granted"; Ben's window showed 96 after 173 ms; "You" only in Ana's window |
| 2 One thumb at 360 px | PASS | "5 granted" after two taps; banner 292 px wide, the full width inside the 328 px request panel's padding; `scrollWidth` 360 = `innerWidth`; at 1 the **−** button is disabled, dashed and struck through; All (91) filled 91 |
| 3 Temporary and expiry | PASS | "1 granted · returns 16:24 today", holding "1 · expires in 1 min"; expired after **121 s** (window 120 s): remaining back to 91 in the other window, holding gone, status "standard", Expiry row in the log |
| 4 Partial grant | PASS | Ana "85 granted"; Ben, still showing 91, "6 of 10 granted"; after `docker compose start reverb` both showed 0 and "0 left" with every amount control disabled |
| 5 Restock and correction | PASS | "Added 50 units. Remaining: 50" (Ben 50 after 93 ms); "Entry #4 changed 85 → 20. Remaining: 115"; "Refused — Needs 480 more units, 115 are free. Restock first."; "Added 400 units. Remaining: 515"; "Entry #4 changed 20 → 500. Remaining: 35"; the audit listed both corrections with Ana "high → elevated" |
| 6 Lost answer | PASS | "Retrying automatically, attempt 1 of 3" at 86 ms, attempt 2 at 1.06 s, attempt 3 at 3.08 s, "Not confirmed" with Retry at 7.07 s; remaining unchanged; Retry gave "1 granted" and exactly one new allocation |
| 7 Airplane mode | PASS | "Offline", button disabled, "You are offline — nothing was sent", a quick amount still filled the field; back online "Live" and no request was made |

Steps 1 to 3 and steps 4 to 7 were two runs of the same script (the first stopped before step 4 because of a mistake in the script, not in the page); step 5 was run again from a re-created state (remaining 91 after Ana's 4 and 5) with the wording above. The script took 121 s of waiting plus about 35 s of steps; a person doing the same by hand is within the 10 minutes.

**Manual checks:**

| # | Check | Result | Measured |
|---|---|---|---|
| 4 | Airplane mode | PASS | as step 7 |
| 5 | Lost answer, resent by itself, then Retry | PASS | as step 6 |
| 6 | Added latency (100 ms, Chrome DevTools protocol) | PASS | Pending after 2 to 3 ms, confirmed after 108 to 111 ms (three runs) |
| 17 | Offline during the resends | PASS | with Offline set during "attempt 2 of 3": "Not confirmed — You are offline — nothing was sent" with Retry; 2 sends before and after, still 2 six seconds later and after going online; Retry gave "2 granted", 1 key, 1 allocation |
| 18 | Greyscale | PASS | the page rendered with a CSS grayscale filter (the same effect as the DevTools Achromatopsia setting): Pending (hollow ring), Retrying automatically (ring with an arrow), Retrying (ring with a dot), Not confirmed (triangle), Offline (square with a bar) and Confirmed (tick) were told apart by words and by shape |
| 19 | Keyboard | PASS | Tab through Ana's screen (9 stops), Dana's pool tab (8) and supervisor tab (10): every stop has a text name ("Decrease", "Increase", "Correct entry #10", "Units to add", …) and a visible focus ring |
| 20 | Touch targets and contrast | PASS | `ui-audit.mjs` as above |

Checks 1 to 3 and 7 to 16 were not repeated in M4; the layout part of check 2 and 8 is covered by the audit (no horizontal scroll at 360 and 1280 px).

Known limits of this run: the checks were not done on a real phone or in sunlight; the greyscale check used a CSS filter, not the DevTools vision-deficiency setting; and `verify.sh` does not run the two audit scripts (they need a host browser).

