# Plan: M1 — Shared pool, live

| Field | Value |
|---|---|
| Slice | M1 |
| PRD | [prd.md](prd.md) (Approved 2026-10-17) |
| Status | Approved (2026-10-17, by the user; the three open points in §11 accepted as proposed) |
| Date | 2026-10-17 |

## 1. Approach summary
One Laravel app on PostgreSQL, with Docker Compose running web, app, database and websocket services. Every write to a pool goes through a single transaction that first takes a row lock on that pool's row. Under the lock the app reloads the pool's ledger, recomputes `remaining` with a pure calculation pipeline, grants `min(requested, remaining)` and stores the result. The commit order is therefore the FCFS order. Each stored entry (including a zero-grant one) takes the next number in a per-pool sequence, the pool `version`. Live screens apply broadcast updates strictly in version order and fall back to one snapshot fetch when there is a gap. **The single most important idea: the pool lock plus a per-pool version number. The lock makes concurrency correct, and the version makes live updates correct.** Everything M2–M4 need (full schema, a three-step calculation pipeline split by owner, config, API and UI shell) is laid down now so those slices only add behaviour.

## 2. Rules check
At drafting time no rules were defined (`workflow/rules/` had only a README; no `constitution.md` or `boundaries.md`). On 2026-10-17 the user added one: `workflow/rules/laravel-boost.md` (created by tasks T007–T009; it requires Laravel Boost documentation search, schema inspection and tinker before any Laravel code is written). The design complies: it adds no code, only tooling and process. The design was checked against the higher sources instead: `requirement.md` §0, D1–D12 and the approved PRD. No conflicts found.

**Re-run on 2026-10-17, at the end of the task list (T056).** Read: `workflow/rules/laravel-boost.md` (the only rule; `workflow/constitution.md` and `workflow/boundaries.md` do not exist). Result:
- Rule 1 (`search-docs` before Laravel code) and rule 5 (the query is named in each task note): checked mechanically over T010–T056. 41 task notes name the Boost documentation query used; the others are JavaScript, CSS, shell or documentation tasks, or add attributes to an existing Blade element (T050), and say so in their note.
- Rule 2: the real schema was read with `database-schema` and `database-query`. Boost 2.10.3 has no `tinker` tool among its ten, so behaviour checks used `php artisan tinker`, which Boost's own guidelines prescribe. `last-error` was used once.
- Rule 3: `application-info` was called when the server became available in the session; before that, Boost's server was called over stdio from the shell, with the same tools.
- Rule 4: one `search-docs` call failed ("Invalid Request") and was retried with simpler wording. The server was never unreachable once connected.
- Rule 6: no conflict with `requirement.md` §0, the PRD or this plan. Where Boost's generic guidelines differ from the tasks (it says to create documentation files and verification scripts only when asked, and not to change dependencies without approval), the approved tasks asked for `docs/api-contract.md`, the README and `scripts/*.sh`, and Reverb required moving Guzzle and psr7 back one major version (T002), so the plan and tasks won.
- Verified outcomes against the PRD IDs: every requirement ID in PRD §10 appears in the Coverage table of `tasks.md`, which has no empty cell.

| Source | Complies? | How |
|---|---|---|
| requirement.md §0 (instant, never wrong, sunlight) | Yes | Pending state + server-confirmed totals (DD2, DD8); tokens only for sunlight (M4 audits). |
| D9 (Laravel, PostgreSQL, Docker Compose) | Yes | §3, DD9 |
| D12 (Blade + Alpine + Vite, Reverb + Echo, `pool.{id}` channel, version rules) | Yes | DD7, DD8 |
| PRD non-goals (no expiry, status, corrections, restock behaviour) | Yes | Steps are no-ops; endpoints return 501 (§6). |

## 3. Technical context
| Item | Choice |
|---|---|
| Language / runtime | PHP ≥ 8.3 (8.4 image), Node 22 for the asset build only |
| Framework(s) | Laravel (latest stable at scaffold time, 12.x or newer, pinned by `composer.lock`), Laravel Reverb, Laravel Echo + pusher-js, Blade, Alpine.js, Vite |
| Storage | PostgreSQL 16 (database `app`, plus `app_test` for tests) |
| Testing | Pest on PostgreSQL; Pest `concurrency` group using `Http::pool` against the running stack; `node --test` for pure JS modules; a Node live-latency script; shell smoke script |
| Target platforms | Evergreen mobile and desktop browsers; Linux Docker host. 360 px and 1280 px are the checked widths. |
| Constraints | Confirmed result ≈ 1 s (p95 on local stack); other screens ≤ 2 s; online-only; no auth; no deployment |

## 4. Architecture

```
Browser (Blade + Alpine)                    Docker Compose
 ├─ HTTP  ──────────────► nginx ──► app (php-fpm) ──► postgres
 │   POST /api/pools/1/allocations              │  1 tx: lock pool row → recompute → insert → bump version
 │   GET  /api/pools/1  (snapshot)              │
 └─ WS    ◄───────────── reverb ◄── broadcast ──┘  after commit, off the request path, failures swallowed
     channel pool.1, event pool.updated
```

**Components and responsibilities**
- **`AllocationService`** is the only write path for allocations. It owns the lock, the idempotency check, the grant and the version bump (DD1–DD3). M3's corrections and restocks will go through the same lock.
- **`PoolCalculator`** is a pure function of `(Ledger, Clock, RulesConfig)`. It runs three steps, then sums. Each step is its own class so M2 and M3 edit different files:
  1. `ApplyCorrections` (M3 owns; identity in M1)
  2. `ApplyExpiry` (M2 owns; identity in M1)
  3. sum → `remaining`; then `ComputeStatus` (M2 owns; returns `[]` in M1)
- **`LedgerRepository`** loads the pool's restocks, allocations and corrections into a `Ledger`.
- **`SnapshotService`** builds `{version, remaining, log, viewer}` inside one read-only repeatable-read transaction, so version, remaining and log always agree.
- **`PoolUpdated` event** broadcasts `{version, remaining, entry}` on `pool.{id}` after commit.
- **`RulesConfig`** loads and validates `config/pool_rules.php` at boot (tiers, windows, status thresholds).
- **Browser:** `pool-state.js` (pure reducer), `form-rules.js` (pure caps), `log-renderer.js` (pure), `connection.js` (state machine and polling), Alpine components glue them to the DOM.

**Where the hard rules are enforced**
| Rule | Enforced in |
|---|---|
| Never negative, FCFS | `AllocationService` under the pool row lock (server); form cap (client, convenience only) |
| Exactly-once | `AllocationService` idempotency lookup inside the lock; unique `(pool_id, idempotency_key)` as a safety net |
| Order of live updates | `pools.version` assigned under the lock; client reducer applies `n+1` only |
| `effective_at` not client-set | Controller ignores any client time; `Clock` reads receipt time |

### Project structure (files this slice creates)
```
.
├── docker-compose.yml               # nginx, app, migrate (one-shot), postgres, reverb; `tools` profile: node
├── docker/
│   ├── php/Dockerfile               # multi-stage: node build → composer install → php-fpm
│   ├── php/www.conf                 # php-fpm pool: pm.max_children = 60
│   ├── nginx/default.conf
│   └── postgres/init.sql            # creates app_test
├── docker-compose.override.yml      # dev bind mounts + named volumes for vendor and public/build (added 2026-10-17)
├── .env.docker                      # demo-only keys (APP_KEY, REVERB_*), committed on purpose
├── CLAUDE.md                        # Boost-generated guidelines + hard Boost requirement (added 2026-10-17, user request)
├── .mcp.json                        # laravel-boost MCP server, run through `docker compose exec` (added 2026-10-17)
├── workflow/rules/laravel-boost.md  # workflow rule: use Boost before writing Laravel code (added 2026-10-17)
├── app/
│   ├── Pool/
│   │   ├── AllocationService.php    # submit(Pool, User, SubmitAllocation): AllocationResult
│   │   ├── SnapshotService.php
│   │   ├── LedgerRepository.php
│   │   ├── Calculation/
│   │   │   ├── PoolCalculator.php
│   │   │   ├── Ledger.php  RestockEntry.php  AllocationEntry.php  CorrectionEntry.php  PoolState.php
│   │   │   └── Steps/ApplyCorrections.php  ApplyExpiry.php  ComputeStatus.php
│   │   ├── Config/RulesConfig.php  InvalidRulesConfig.php
│   │   ├── Log/LogEntry.php  LogQuery.php
│   │   └── Exceptions/IdempotencyKeyConflict.php  PoolBusy.php
│   ├── Support/Clock.php  SystemClock.php  FrozenClock.php
│   ├── Events/PoolUpdated.php
│   ├── Http/Controllers/Api/  PoolController, AllocationController, LogController, UserController, NotImplementedController
│   ├── Http/Controllers/DashboardController.php
│   ├── Http/Middleware/ResolveDemoUser.php  RequireRole.php
│   ├── Http/Resources/LogEntryResource.php  AllocationResource.php
│   ├── Models/User.php  Pool.php  Restock.php  Allocation.php  Correction.php
│   └── Providers/PoolServiceProvider.php   # binds Clock, loads + validates RulesConfig eagerly
├── config/pool_rules.php  broadcasting.php  reverb.php
├── database/migrations/…  database/seeders/{UserSeeder,PoolSeeder}.php  database/factories/…
├── routes/api.php  routes/web.php  routes/channels.php
├── resources/
│   ├── views/dashboard.blade.php  layouts/app.blade.php
│   ├── css/tokens.css  app.css
│   └── js/app.js  echo.js  pool-state.js  form-rules.js  log-renderer.js  connection.js  api.js
│       └── __tests__/pool-state.test.js  form-rules.test.js  log-renderer.test.js  connection.test.js  submit-flow.test.js
├── tests/
│   ├── Unit/PoolCalculatorTest.php  RulesConfigTest.php  CalculatorPendingTest.php
│   ├── Feature/SubmitAllocationTest.php  PartialGrantTest.php  IdempotencyTest.php  LogAndSnapshotTest.php
│   │            BroadcastTest.php  RolesAndStubsTest.php  SchemaContractTest.php  DashboardViewTest.php
│   ├── Concurrency/ParallelAllocationTest.php     # group: concurrency
│   └── Live/live-latency.mjs
├── scripts/smoke.sh  verify.sh
├── docs/api-contract.md             # contract for every endpoint M1–M4 need
└── README.md                        # assumptions, run, test, manual checks (final polish in M4)
```

## 5. Data model

All times are `timestamptz(6)`. Tier and type are validated strings (not DB enums) so config drives tiers (DD11).

```sql
users(
  id bigserial PK, name text NOT NULL,
  role text NOT NULL CHECK (role IN ('operator','supervisor')),
  tier text NULL,                                   -- 'bronze'|'silver'|'gold', validated against config
  CHECK ((role = 'operator') = (tier IS NOT NULL)),
  created_at, updated_at)

pools(
  id bigserial PK, name text NOT NULL,
  version bigint NOT NULL DEFAULT 0,                -- last ledger seq; bumped only under the row lock
  created_at, updated_at)

restocks(                                           -- capacity = Σ restocks (D8); seed = seq 1
  id bigserial PK, pool_id bigint NOT NULL REFERENCES pools,
  seq bigint NOT NULL, amount int NOT NULL CHECK (amount > 0),
  actor_id bigint NULL REFERENCES users,            -- NULL for the seed
  note text NULL, recorded_at timestamptz NOT NULL,
  UNIQUE (pool_id, seq))

allocations(
  id bigserial PK, pool_id bigint NOT NULL REFERENCES pools,
  seq bigint NOT NULL,
  operator_id bigint NOT NULL REFERENCES users,
  type text NOT NULL DEFAULT 'consumed' CHECK (type IN ('consumed','temporary')),
  requested int NOT NULL CHECK (requested >= 1),
  granted int NOT NULL CHECK (granted >= 0 AND granted <= requested),   -- 0 is recorded (PQ2)
  tier_at_log text NOT NULL,
  effective_at timestamptz NOT NULL,                -- server receipt time (DD13)
  recorded_at timestamptz NOT NULL,
  idempotency_key text NOT NULL,
  UNIQUE (pool_id, seq), UNIQUE (pool_id, idempotency_key),
  INDEX (pool_id, effective_at), INDEX (operator_id))

corrections(                                        -- empty in M1; M3 adds behaviour
  id bigserial PK, pool_id bigint NOT NULL REFERENCES pools,
  seq bigint NOT NULL,
  allocation_id bigint NOT NULL REFERENCES allocations,
  supervisor_id bigint NOT NULL REFERENCES users,
  kind text NOT NULL CHECK (kind IN ('amend','void')),
  new_amount int NULL,                              -- amend: new granted units (>= 1); void: NULL
  CHECK ((kind = 'amend' AND new_amount >= 1) OR (kind = 'void' AND new_amount IS NULL)),
  reason text NOT NULL,
  effective_at timestamptz NOT NULL, recorded_at timestamptz NOT NULL,
  idempotency_key text NOT NULL,
  UNIQUE (pool_id, seq), UNIQUE (pool_id, idempotency_key))
```

- `remaining` and statuses are **not stored**; they are derived (DD12). Expiry is derived from `effective_at + window(tier_at_log)` in M2, so no column is needed.
- The log is a union of the three ledger tables ordered by `seq`, plus M2's derived `expiry` entries.
- **Seq rule:** every row in `restocks`, `allocations` and `corrections` takes `pool.version + 1`, and `pools.version` is set to it, in the same locked transaction.

**Schema-completeness checklist for M2/M3 (AC-US7.2, checked by `SchemaContractTest`)**
| Later need | Present in |
|---|---|
| M2: type, tier at log time, effective time | `allocations.type`, `tier_at_log`, `effective_at` |
| M2: tier windows, thresholds | `config/pool_rules.php` |
| M3: correction pointing at original, supervisor, reason, amount/void | `corrections.*` |
| M3: restock with actor | `restocks.actor_id`, `note` |
| M3: corrections share the order and version | `corrections.seq`, `pools.version` |
| M3: idempotent corrections | `corrections.idempotency_key` |

## 6. Interface contracts

Conventions for all endpoints (full detail in `docs/api-contract.md`):
- JSON under `/api`. Acting user is chosen with header `X-Demo-User: <user id>` (seeded users, no password, A10). Missing or unknown → `401 unknown_user`.
- Error body: `{"error": {"code": "<snake_case>", "message": "<text>", "details": {…}}}`.
- Times are ISO-8601 UTC with microseconds.

**Log entry shape** (all four kinds are in the contract now, FR3.1; M1 emits `allocation` and `restock`)
```json
{"seq":12,"kind":"allocation","at":"2026-10-17T10:00:00.123456Z",
 "operator":{"id":1,"name":"Ana"},"type":"consumed","requested":3,"granted":3,"outcome":"full"}
{"seq":1,"kind":"restock","at":"…","actor":null,"amount":100,"note":"Initial stock"}
{"seq":20,"kind":"correction","at":"…","supervisor":{"id":4,"name":"Dana"},"allocation_seq":12,
 "change":{"from":3,"to":2},"reason":"…"}                      // M3
{"seq":null,"kind":"expiry","at":"…","allocation_seq":12,"units":3}   // M2, derived
```
`outcome` is `full` (granted = requested), `partial` (0 < granted < requested) or `none` (granted = 0).

### POST /api/pools/{pool}/allocations
- **Purpose / PRD refs:** submit a request. FR1, FR1.2–FR1.9, NFR1, NFR5.
- **Request:** header `Idempotency-Key: <uuid>` (required), `X-Demo-User` (role `operator`). Body `{"requested": 3, "type": "consumed"}`; `type` is optional (default `consumed`). Any client-sent time field is ignored.
- **Response `201`** (new) or **`200`** with `Idempotent-Replayed: true` (same key, same payload; returns the stored result):
```json
{"allocation":{"id":57,"seq":12,"operator":{"id":1,"name":"Ana"},"type":"consumed",
  "requested":3,"granted":3,"outcome":"full","tier_at_log":"bronze",
  "effective_at":"…","recorded_at":"…"},
 "pool":{"version":12,"remaining":88}}
```
- **Errors:** `400 missing_idempotency_key` · `401 unknown_user` · `403 forbidden_role` · `404` pool unknown · `409 idempotency_key_conflict` (same key, different operator, amount or type; nothing recorded) · `422 validation_failed` (`requested` not an integer ≥ 1, or unknown `type`; `details.fields`) · `503 pool_busy` with `Retry-After: 1` (lock wait exceeded 5 s).
- **Idempotency:** the key is unique per pool; the lookup happens inside the pool lock, so two parallel requests with one key cannot both be created.

### GET /api/pools/{pool}
- **Purpose:** snapshot (initial load, gap recovery, reconnect, poll). FR2, FR3, FR11.
- **Response `200`:**
```json
{"pool":{"id":1,"name":"Main warehouse"},"version":12,"remaining":88,
 "log":[ /* newest 100 entries, newest first */ ],"log_has_more":false,
 "viewer":{"operator_id":1,"status":null,"holdings":[]}}
```
`viewer.status` and `viewer.holdings` are `null` / `[]` in M1 and are filled in by M2 (additive).
- **Errors:** `401`, `404`. Reads one consistent view (DD2) and takes no lock.

### GET /api/pools/{pool}/log?before={seq}&limit={n}
- **Purpose:** older log pages ("Show older"). FR3.
- **Response `200`:** `{"entries":[…],"has_more":true}`, newest first, `limit` ≤ 100 (default 50).
- **Errors:** `401`, `404`, `422` (bad `before`/`limit`).

### GET /api/users
- **Purpose:** seeded users for the role switch. FR9, A10.
- **Response `200`:** `{"users":[{"id":1,"name":"Ana","role":"operator","tier":"bronze"},…{"id":4,"name":"Dana","role":"supervisor","tier":null}]}`. No auth needed.

### Stubs returning `501` (declared now so M2–M4 do not change the contract's shape; AC-US6.2, AC-US7.5)
| Endpoint | Slice that implements it | Role |
|---|---|---|
| `POST /api/pools/{pool}/restocks` | M3 | supervisor |
| `PUT /api/pools/{pool}/capacity` | M3 | supervisor |
| `POST /api/pools/{pool}/allocations/{allocation}/corrections` | M3 | supervisor |
| `GET /api/pools/{pool}/audit` | M3 | supervisor |

Body of every stub: `{"error":{"code":"not_implemented","message":"Available in M3.","details":{"milestone":"M3"}}}`. They change nothing and write no rows.

### Broadcast event: channel `pool.{id}`, name `pool.updated`
- **Payload:** `{"version":12,"remaining":88,"entry":{…log entry shape…}}`
- **Rules:** sent only after the transaction commits; sent for every stored entry (including a zero grant); failure to broadcast is logged and never fails the request. Expiry sends nothing (M2).

### Calculation contract (PHP, used by M2 and M3)
```php
interface Clock { public function now(): CarbonImmutable; }

final class Ledger { // immutable
  /** @param list<RestockEntry> $restocks  @param list<AllocationEntry> $allocations  @param list<CorrectionEntry> $corrections */
  public function __construct(public array $restocks, public array $allocations, public array $corrections = []) {}
}
// AllocationEntry: id, seq, operatorId, type, units (granted after corrections), active (false once expired),
//                  tierAtLog, effectiveAt

final class PoolState { public function __construct(public int $remaining, public int $version, public array $statuses = []) {} }

final class PoolCalculator {
  public function calculate(Ledger $ledger): PoolState;          // corrections → expiry → sum; statuses from ComputeStatus
  public function grantFor(PoolState $state, int $requested): int; // max(0, min($requested, $state->remaining))
}
// remaining = Σ restock.amount − Σ units(type = consumed) − Σ units(type = temporary AND active)
// ApplyCorrections::apply(Ledger): Ledger                                   (M3)
// ApplyExpiry::apply(Ledger, CarbonImmutable $now, RulesConfig): Ledger     (M2)
// ComputeStatus::compute(Ledger, CarbonImmutable $now, RulesConfig): array<int,string>  (M2)
```

### Rules config: `config/pool_rules.php`
```php
return [
  'tiers' => ['bronze' => ['window_hours' => 24], 'silver' => ['window_hours' => 48], 'gold' => ['window_hours' => 72]],
  'status_levels' => [ // strictly ascending min_units; first level starts at 0
    ['name' => 'standard', 'min_units' => 0], ['name' => 'elevated', 'min_units' => 10], ['name' => 'high', 'min_units' => 30]],
];
```
`RulesConfig::fromArray()` throws `InvalidRulesConfig` naming the bad key (`tiers.silver.window_hours`, `status_levels.2.min_units`, …) for a missing or non-positive window, fewer or more than 3 levels, a first `min_units` ≠ 0, or non-ascending thresholds. `PoolServiceProvider::boot()` resolves it eagerly, so the app refuses to start on bad config.

### Client modules (pure, unit-tested)
```js
// pool-state.js — reducer. Returns { state, effect } where effect is null | 'snapshot' | 'wait'
applyEvent(state, event)      // event.version <= state.version → ignored; === +1 → applied; > +1 → buffered, then 'snapshot' after 500 ms if the gap is not filled
applySnapshot(state, snap)    // replaces version, remaining, log; drops buffered events <= snap.version
applyOwnResult(state, res)    // same rule as applyEvent, using res.pool.version
// form-rules.js
maxAmount(remaining)          // = max(0, remaining)
validateAmount(raw, remaining) // { ok, value, message } — integer, 1 ≤ value ≤ remaining
// log-renderer.js
renderEntry(entry, viewerId)  // { label, text, own } for allocation | restock | correction | expiry
```

## 7. Key design decisions
| ID | Decision | Alternatives considered | Why this one | PRD refs |
|---|---|---|---|---|
| DD1 | Serialise writes with `SELECT … FOR UPDATE` on the pool row inside a transaction (READ COMMITTED). Under the lock, reload the ledger and recompute. `lock_timeout` 5 s → `503 pool_busy`. | (a) Atomic `UPDATE … WHERE remaining >= n` on a stored counter: needs a stored total, which conflicts with a ledger that M2/M3 recompute. (b) Advisory locks: same effect, less visible, lock lifetime is easier to get wrong. (c) SERIALIZABLE with retries: retry storms at 50 parallel writers. (d) Redis lock: a second system, not needed. | One lock, no deadlocks, FCFS = commit order, reuses the same lock for M3 corrections and restocks. Under READ COMMITTED each statement after the lock sees all committed rows, so the recompute is exact. | FR1.2, FR1.3, NFR1, US5 |
| DD2 | `pools.version` = last ledger `seq`, bumped under the lock for every stored entry, even a zero grant. Snapshots read in one `REPEATABLE READ READ ONLY` transaction. | Timestamp ordering (clock skew, ties); a separate counter table; snapshot with several READ COMMITTED reads (can mix version n and n+1). | Gives an unambiguous order for clients and for the log, and a snapshot that agrees with itself. | FR11, FR1.8, AC-US3.5, D12 |
| DD3 | Idempotency check inside the lock. Unique `(pool_id, idempotency_key)` is the backstop. Same key with a different operator, amount or type → `409`. Zero-grant requests are stored, so a replay returns the same result. | Check before the lock (race window); a separate keys table (one more write, no benefit); Laravel cache for keys (lost on restart). | The only place that cannot race with the insert. Storing the key on the allocation keeps replays exact. | NFR5, FR1.8, PQ2, PQ3 |
| DD4 | Typed ledger tables (`restocks`, `allocations`, `corrections`) sharing the `(pool_id, seq)` scheme; the log is a union ordered by `seq`. | One generic `events` table with a JSON payload (weaker constraints, harder queries); a table per kind with no shared order. | Real constraints and foreign keys on money-like data, and one ordering for the log. | F2, FR3.1, FR1.6 |
| DD5 | The calculator is a pure pipeline of three step classes, each in its own file, with a `Clock` injected. M1 ships `ApplyCorrections` and `ApplyExpiry` as identity and `ComputeStatus` as `[]`. | One big function (M2 and M3 would conflict in one file); a stateful service; Carbon test-now only (hidden global). | Matches the M2/M3 ownership split in requirement.md §8, and tests can pin the clock. | F3, §4 Pool |
| DD6 | Demo identity: `X-Demo-User` header; the browser keeps the chosen user in `localStorage`. No sessions or CSRF. | Laravel session + cookie (CSRF token handling, harder for the parallel test and for two-browser demos); fake login page. | Stateless and testable; fits "role switch instead of login". Not a security feature, and said so in the README. | FR9, F6, A10, D6 |
| DD7 | Broadcast with Reverb on public channel `pool.{id}`, event `pool.updated`, sent via `defer()` after the response and inside try/catch. | Queue worker (extra service, extra latency); broadcast inside the transaction (can announce a rollback, lengthens the lock); SSE or polling only (D12 chose Reverb); private channel (no auth in this build). | Keeps the lock short, never announces uncommitted data, and a dead websocket service cannot fail a submit (edge case in PRD). | FR11, NFR2, NFR6, D12 |
| DD8 | Client: pure reducer; apply only version `n+1`; ignore older; hold a gap for 500 ms then fetch one snapshot (coalesced); on reconnect fetch one snapshot; poll every 10 s while the socket is not connected; own submit applied from the HTTP response; pending row shown at once, totals unchanged until confirmed. A request with an unknown outcome stays "not confirmed — retry" and retries with the **same** key. | Optimistic total update (can show a wrong number); apply events in arrival order (out-of-order bursts break the total); a new key per retry (duplicates). | Directly implements D5 and D12 and makes "never wrong" testable as plain functions. | FR11, FR11.1, FR12, FR12.1, NFR2, NFR5 |
| DD9 | Compose services: `nginx`, `app` (php-fpm, `pm.max_children = 60`), `migrate` (one-shot `migrate --force --seed`), `postgres` (healthcheck), `reverb`. Assets are built into the image. The app and reverb wait for `migrate` to complete. | `artisan serve` (single-threaded, can't show the race); Octane/FrankenPHP (more moving parts); Sail (heavier, dev-oriented); Vite dev server in Compose (not one-command stable). | One command `docker compose up --build`; real parallel request handling for the concurrency test. | F1, US5 |
| DD10 | Tests: Pest on a real PostgreSQL `app_test`; the concurrency test fires `Http::pool` requests at the running stack (`STACK_URL=http://nginx`) in group `concurrency` (excluded from the default run); pure JS tested with `node --test`; a Node script measures broadcast latency. | SQLite tests (cannot show locks); sequential in-process "concurrency" (proves nothing); Playwright (heavy for a time box). | Follows requirement.md §5 "Testing it honestly". | F7, NFR1, NFR6 |
| DD11 | Tier and type are strings validated by `RulesConfig` and model casts, not DB enums. | DB enums (a migration per new tier). | Tiers are config placeholders (A2). | F4, D3 |
| DD12 | `remaining` is derived on every write by loading the pool's whole ledger under the lock; no stored or cached total. | Stored running total (second source of truth, breaks M3 recalculation); SQL SUM only (cannot express expiry or corrections). | Correct by construction. Cost grows with ledger size; fine at demo scale (see §11). | F3, NFR1 |
| DD13 | `effective_at` = `Clock::now()` read at the start of the request (server receipt time), never from the client. Log and ledger order is `seq`. | Read time inside the lock (monotonic with seq, but is processing time, not receipt time). | Follows FR1.7 literally. Consequence for M3 noted in §11. | FR1.7, AC-US1.6 |
| DD14 | Server-rendered first paint: Blade embeds the initial snapshot JSON; Alpine takes over. | Client-only fetch on load (blank flash on a phone). | Feels instant; also covers page reload mid-session. | NFR2, NFR3 |

## 8. Requirement-to-design traceability
| PRD ID | Where it is handled |
|---|---|
| FR1 | `POST /allocations`; `AllocationController`; `AllocationService` (DD1) |
| FR1.1 | `form-rules.js`; dashboard form; server still enforces (DD1) |
| FR1.2 | `AllocationService` pool lock; `PoolCalculator::grantFor` (DD1) |
| FR1.3 | Same; `granted` CHECK constraint; never-negative test over the whole ledger |
| FR1.4 | `outcome` field; `log-renderer.js` and result banner text |
| FR1.5 | No waitlist table or state anywhere (`PartialGrantTest` asserts no extra rows) |
| FR1.6 | `allocations` columns (§5) |
| FR1.7 | `Clock` at request start; client time ignored (DD13) |
| FR1.8 | Zero grants stored and broadcast; `outcome = none` (DD2, DD3) |
| FR1.9 | `type` defaults to `consumed`; `dashboard.blade.php` has no type field |
| FR2 | Snapshot `remaining`; Alpine remaining display; broadcast payload |
| FR3 | `LogQuery`; `GET /api/pools/{pool}`; `GET …/log`; `log-renderer.js` own highlight |
| FR3.1 | Log entry contract (four kinds); `log-renderer.js` fixtures |
| FR11 | `PoolUpdated` (DD7); `pool-state.js`, `connection.js` (DD8) |
| FR11.1 | `connection.js` state → indicator text |
| FR12 | Alpine submit flow: pending row before fetch resolves (DD8) |
| FR12.1 | `navigator.onLine` and connection state disable submit with an offline message; nothing queued |
| FR9 | `GET /api/users`; `ResolveDemoUser`; `RequireRole`; role switch and placeholder tab (DD6) |
| FR10 | `PoolSeeder` stores the initial restock at seq 1; four 501 stubs |
| F1 | `docker-compose.yml`, Dockerfile, `scripts/smoke.sh` (DD9) |
| F2 | Migrations and checklist in §5; `SchemaContractTest` |
| F3 | `PoolCalculator` and steps; `CalculatorPendingTest` (todo) |
| F4 | `RulesConfig`, `PoolServiceProvider`, `RulesConfigTest` |
| F5 | §6 contract; `docs/api-contract.md`; `NotImplementedController` |
| F6 | `tokens.css`; role switch; placeholder supervisor tab |
| F7 | §9; `scripts/verify.sh`; README |
| NFR1 | DD1, DD12; `ParallelAllocationTest` |
| NFR2 | Pending row; deferred broadcast keeps the lock short; p95 measurement (DD7, DD8) |
| NFR3 | Mobile-first CSS with fluid layout; 360 px / 1280 px checks |
| NFR5 | DD3 and DD8 |
| NFR6 | DD7 and DD8; `live-latency.mjs` |
| NFR4 | Out of scope in M1 (tokens only, F6) |

## 9. Test strategy
| Level | What it proves | Maps to AC | Notes |
|---|---|---|---|
| Unit (Pest) `PoolCalculatorTest` | restock 100 + grants 3, 3, 4 → remaining 90; `grantFor` boundaries (equal, over, zero remaining) | AC-US7.3, AC-US2.4 | `FrozenClock`; steps are identity |
| Unit `CalculatorPendingTest` | Placeholders for M2 expiry boundaries (±1 ms), statuses, and M3 corrections | AC-US7.3 | Written with `->todo()`, so they show as pending, not failing |
| Unit `RulesConfigTest` | Valid config loads; missing window, non-positive window, wrong level count, non-ascending thresholds each fail naming the key | AC-US7.4 | Data-provider cases |
| Feature `SubmitAllocationTest` | Full grant, remaining and log update; validation cases (0, −1, 1.5, "abc", missing); stored fields; `tier_at_log` stays after the operator's tier changes; client `effective_at`/`Date` ignored; default type | AC-US1.1, 1.4, 1.5, 1.6, 4.7, 5.3 | Real PostgreSQL; `FrozenClock` |
| Feature `PartialGrantTest` | Two requests of 6 against 10 → 6 then 4; stale request against 0 → `none`, stored and in log; 10 of 10; 1 of 1 twice; 11 vs 10 → 10; no waitlist rows | AC-US2.1–2.4 + edge cases | Sequential here; true parallel in the concurrency test |
| Feature `IdempotencyTest` | Replay ×10 counts once and returns the stored result; replay of a `none` result; same key different amount/operator/type → 409 and nothing stored | AC-US4.2 (server half), 4.3, 4.6 | |
| Feature `LogAndSnapshotTest` | Whole-pool log, newest first; seeded restock is entry seq 1; pagination; snapshot is consistent; empty-log case | AC-US3.2, edge cases | |
| Feature `BroadcastTest` | Event fired after commit, with version, remaining and entry; fired for a zero grant; not fired on a 409 or a validation error; a failing broadcaster does not fail the request | AC-US3.1 (server half), edge case | `Event::fake` plus a throwing broadcaster |
| Feature `RolesAndStubsTest` | `/api/users`; supervisor submit → 403; the four stubs return the documented 501 body and write nothing | AC-US6.1, 6.2, 7.5 | Iterates a list of stub routes |
| Feature `SchemaContractTest` | Columns and constraints in §5 exist (including the CHECKs) | AC-US7.2 | Uses the `Schema` facade and `pg_constraint` |
| Feature `DashboardViewTest` | Dashboard HTML embeds the snapshot and has no type selector | AC-US4.7, FR1.9 | |
| **Concurrency (named hard-rule test)** `ParallelAllocationTest::50 parallel requests of 3 against 100` | 100 granted in total; exactly one request `partial` (1 of 3); 33 `full`; 16 `none`; remaining 0; replaying the ledger in `seq` order never goes below 0 and equals log order; p95 submit-to-confirmed ≤ 1 s | AC-US5.1, 5.2, 4.5, NFR1 | Group `concurrency`; `Http::pool`; creates its own pool and cleans up; run against `http://nginx` |
| Concurrency `…two parallel requests of 6 against 10` | One gets 6, the other 4 | AC-US2.1 | |
| Concurrency `…same key sent 10× in parallel` | One allocation | AC-US4.3 | |
| JS `pool-state.test.js` | Out-of-order, duplicate and gap handling; one coalesced snapshot; own result vs broadcast of the same entry counted once | AC-US3.5 | `node --test` |
| JS `form-rules.test.js` | Cap at remaining; 0 remaining disables; rejects 0, negatives, decimals | AC-US1.2, 1.3 | |
| JS `log-renderer.test.js` | All four kinds render with a text label; own entries flagged only for the owner | AC-US3.3, 3.2 | |
| JS `connection.test.js` | Live → reconnecting → offline states; 10 s polling only while not live; one snapshot on reconnect | AC-US3.4 | Fake timers |
| JS `submit-flow.test.js` | Pending state set before the fetch resolves; totals unchanged until confirmed; offline blocks and queues nothing; retry reuses the same key | AC-US4.1, 4.2 (client half), 4.4 | |
| Script `tests/Live/live-latency.mjs` | A client subscribed to `pool.1` receives the event ≤ 2 s after a POST | AC-US3.1, NFR6 | Run from the `tools` profile |
| Script `scripts/smoke.sh` | One command brings the stack up; snapshot says `remaining: 100` and a restock of 100 | AC-US7.1 | Also used by `verify.sh` |
| Manual (README checklist) | Two browsers in sync; 360 px and 1280 px, no horizontal scroll; killing `reverb` shows "reconnecting" then recovery; airplane mode shows offline; 100 ms added latency still confirms ≈ 1 s | AC-US3.1, 3.4, 3.6, 4.1, 4.4, 4.5 | M4 repeats the airplane/360 px checks as its own done-when |
| `scripts/verify.sh` | Runs unit + feature, concurrency, JS, live-latency and smoke in order | AC-US7.6 | |

## 10. Task outline

- **Phase 0 — Setup**
  - T-0.1 Scaffold Laravel in the repo root without touching `requirement.md`, `workflow/`, `.claude/` (research: confirm current stable Laravel and Reverb versions, pin them).
  - T-0.2 [P] Install and configure Reverb, Echo, pusher-js, Alpine, Vite, Pest.
  - T-0.3 [P] Dockerfile, php-fpm and nginx config, `docker/postgres/init.sql`.
  - T-0.4 `docker-compose.yml` and `.env.docker` (postgres, migrate, app, nginx, reverb, `tools` node).
  - T-0.5 Pest on PostgreSQL `app_test`, excluded `concurrency` group, `RefreshDatabase`.
- **Phase 1 — Foundation (blocks everything after)**
  - T-1.0 Install Laravel Boost (dev), run `boost:install` for Claude Code with the MCP server going through Docker, and write `CLAUDE.md` plus `workflow/rules/laravel-boost.md` with the hard Boost requirement (added 2026-10-17, user request; tasks.md T007–T009).
  - T-1.1 [P] `Clock`, `SystemClock`, `FrozenClock` and container binding.
  - T-1.2 [P] `config/pool_rules.php`, `RulesConfig`, `InvalidRulesConfig`, `PoolServiceProvider`, `RulesConfigTest`.
  - T-1.3 Migrations for users, pools, restocks, allocations, corrections, with all constraints.
  - T-1.4 Models, factories, `UserSeeder`, `PoolSeeder` (initial restock of 100 at seq 1, version 1; idempotent seeding).
  - T-1.5 `SchemaContractTest`.
  - T-1.6 Calculation value objects, `PoolCalculator` and the three step classes (identity), `PoolCalculatorTest`, `CalculatorPendingTest`.
  - T-1.7 `LedgerRepository`.
  - T-1.8 [P] Error format, exception mapping, `ResolveDemoUser`, `RequireRole`.
  - T-1.9 [P] `docs/api-contract.md`, routes, `NotImplementedController`, `RolesAndStubsTest` (stubs part).
  - T-1.10 `GET /api/users`.
- **Phase 2 — Submit, partial grants, idempotency, concurrency (US1, US2, US4 server, US5) (P1)**
  - T-2.1 `AllocationService` (lock, idempotency, grant, version bump, 503 on lock timeout).
  - T-2.2 `AllocationController`, request validation, `AllocationResource`.
  - T-2.3 [P] `SubmitAllocationTest`, `PartialGrantTest`, `IdempotencyTest`.
  - T-2.4 `ParallelAllocationTest` (three cases) and a first run against the Compose stack.
  - T-2.5 Tune php-fpm and lock settings if p95 > 1 s.
- **Phase 3 — Snapshot, log, live updates (US3 server)**
  - T-3.1 `LogEntry`, `LogQuery`, `LogEntryResource`, `SnapshotService` (repeatable-read).
  - T-3.2 `PoolController@show`, `LogController`, `LogAndSnapshotTest`.
  - T-3.3 `PoolUpdated` event, deferred after-commit broadcast with swallowed failures, `routes/channels.php`, `BroadcastTest`.
  - T-3.4 `tests/Live/live-latency.mjs`.
- **Phase 4 — Dashboard UI (US1–US4 client, US6)** (can start once Phase 1 contracts are fixed; [P] with Phases 2–3 up to T-4.7)
  - T-4.1 [P] `tokens.css`, `app.css`, layout and viewport setup.
  - T-4.2 [P] `pool-state.js` + `pool-state.test.js`.
  - T-4.3 [P] `form-rules.js` + `form-rules.test.js`.
  - T-4.4 [P] `log-renderer.js` + `log-renderer.test.js`.
  - T-4.5 [P] `connection.js` + `connection.test.js`.
  - T-4.6 `api.js` and the submit flow (pending, result, retry with the same key, offline) + `submit-flow.test.js`.
  - T-4.7 `DashboardController`, `dashboard.blade.php` with embedded snapshot, role switch, placeholder supervisor tab, `DashboardViewTest`.
  - T-4.8 `echo.js` wiring, connection indicator, "Show older".
- **Phase 5 — Hardening and docs**
  - T-5.1 `scripts/smoke.sh`, `scripts/verify.sh`.
  - T-5.2 README: how to run, test and demo; assumptions (PRD §8, §11 here); manual checklist.
  - T-5.3 Run the full suite; run manual checks at 360 px and 1280 px, two browsers, killing `reverb`.
  - T-5.4 Re-run the rules check and the traceability self-check; log any plan changes in §12.

**Checkpoints**
- **After Phase 1:** `docker compose up --build` works; `GET /api/users` and the 501 stubs respond; calculator and config tests pass; M2/M3 pending tests are listed as pending.
- **After Phase 2:** the 50×3 run against the live stack grants exactly 100 with one partial grant of 1 (demo the numbers with `curl` or the test).
- **After Phase 3:** a POST shows up on a subscribed websocket client in ≤ 2 s.
- **After Phase 4:** two browsers: submit on one, watch the other update; role switch works; narrow and wide layouts work.
- **After Phase 5:** `scripts/verify.sh` is green and the README checklist is done.

## 11. Risks, deviations and open items
| Item | Type | Mitigation or justification | Owner / status |
|---|---|---|---|
| p95 ≤ 1 s with 50 requests queued on one lock | risk | Keep the transaction to one lock, one ledger read, one insert and one update; broadcast outside it; `pm.max_children = 60`; T-2.5 tunes if the measure fails. | Implementer; checked in T-2.4 |
| Supervisors and submit: the PRD did not say whether a supervisor may submit allocations | decision | Supervisors get `403 forbidden_role` on submit (they only see the placeholder tab in M1). Recorded in the PRD as A-M1.3. | Resolved by user, 2026-10-17 |
| `PUT /capacity` and `POST /restocks` overlap (capacity is set by restock events, D8) | decision | PRD FR10 lists both endpoints, so both are declared as 501 stubs. M3 may merge them; no M1 behaviour depends on it. | Resolved by user, 2026-10-17 |
| `effective_at` (receipt time) can be a few ms out of `seq` order under contention | decision / risk (M3) | Log and ledger order use `seq`. M2 uses `effective_at` for expiry start (D4). M3 must choose the ordering key for "downstream" status recalculation. | Resolved by user, 2026-10-17; carry into the M3 plan |
| Whole-ledger load under the lock grows with history | risk | Accepted at demo scale (hundreds of rows). If needed later: cache a running total keyed by `version`. | Accepted |
| `corrections` columns are designed before M3 | risk | The table is empty in M1, so a nullable-column migration by M3 is cheap. Aim is none, but F2 "no change to existing tables" is best effort for this table. | M3 owner |
| Laravel/Reverb version drift | risk | T-0.1 pins versions in lockfiles; research item at the start of the task list. | Implementer |
| A replay returns the pool as it stood right after the original allocation, which becomes approximate once M2 expires temporary entries by the clock | decision / risk (M2) | Accepted by the user on 2026-10-19 for M1. **Carry into the M2 plan:** when replaying, compute the pool with the clock fixed at the original request's `effective_at`, not the current time, so a replay stays identical without a schema change. | M2 plan owner |
| Nothing in the database stops a restock, an allocation and a correction from sharing a `seq` (uniqueness is per table; the shared sequence is a code rule) | risk (M3) | Mitigated on 2026-10-19: every writer takes its number from `App\Pool\LedgerSequence::next()` inside the pool's transaction (one atomic `UPDATE … RETURNING`); `LedgerSequenceTest` checks that numbers are unique and gapless across the three tables and fails if any other code in `app/` writes `pools.version`. **Carry into the M3 plan:** restock and correction writers must use `LedgerSequence`. | M3 plan owner |
| `X-Demo-User` and the public websocket channel are not security | deviation (accepted) | A10 and D6 say role switch only, no deployment. The README states it plainly. | Accepted |
| Dev secrets (`APP_KEY`, Reverb keys) in `.env.docker` | deviation (accepted) | Demo-only values, no deployment (D6); the README says so. | Accepted |
| The PRD header said "Plan: Not yet created" | housekeeping | PRD Plan field now links to this file. | Done, 2026-10-17 |
| Several browser checks (360 px, two browsers, airplane mode) are manual | risk | Written as a README checklist; automated pieces cover the pure logic and broadcast timing. | Accepted |

## 12. Change log
| Date | Change | Reason | Approved by |
|---|---|---|---|
| 2026-10-17 | Added Laravel Boost to the Foundation phase (install, Claude Code setup with the MCP server run through Docker, `CLAUDE.md` with a hard "use Boost" requirement, and `workflow/rules/laravel-boost.md`). Plan §2, §4 structure and §10 outline updated. | User request: get Laravel best practices into every Laravel task. No PRD requirement changes. | User |
| 2026-10-17 | Added `docker-compose.override.yml` (dev bind mounts, named volumes for `vendor` and `public/build`). | The first task list ran tests and Boost inside containers that could not see host edits; DD9 is otherwise unchanged. | Pending user review (made by the agent while adding Boost) |
| 2026-10-17 | `corrections` CHECK adds `new_amount IS NOT NULL` to the amend branch (§5). | The §5 expression evaluates to NULL for an amend with no amount, and a CHECK accepts NULL, so such a row was accepted (confirmed against Postgres). The change makes the constraint mean "amend: new granted units (>= 1)". Tested in `SchemaContractTest`. | User, 2026-10-19 (accepted on review) |
| 2026-10-17 | A replay of an idempotency key returns the pool as it stood right after the original allocation (ledger entries with `seq` <=), not the current pool (§6 POST allocations). | Makes every replay body identical to the first, as AC-US4.3 asks. Approximate once M2 adds time-based expiry for temporary entries: note for the M2 plan. | User, 2026-10-19 (accepted on review) |
| 2026-10-17 | `requested` is validated with `integer:strict` (a JSON string such as `"3"` is refused) and capped at 2147483647; `Idempotency-Key` is capped at 255 characters; the extra limits answer `422` (§6). | Strict typing avoids ambiguous input from API clients and the caps turn a database integer overflow or an oversized key into a `422` instead of a `500`. The UI sends a JSON number. Tested. | User, 2026-10-19 (accepted on review) |
| 2026-10-17 | API errors have one body for every failure on `/api/*`, including `not_found` (404), `method_not_allowed` (405) and `server_error` (500); `403` carries `details.required_role`; `docs/api-contract.md` lists all codes (§6 conventions). New `app/Http/ApiException.php` and `ApiErrorRenderer.php`. | §6 fixed the error body shape but not the codes for framework errors. Needed so the middleware and the controller share one renderer. | Pending user review (agent, T022/T023) |
| 2026-10-17 | `ResolveDemoUser` runs before route-model binding (`prependToPriorityList`), so an unknown caller gets `401` before any `404`. | Otherwise an unidentified caller could learn which pool ids exist. Tested. | Pending user review (agent, T036) |
| 2026-10-17 | `SnapshotService::logPage()` and a shared consistent-read helper; the log endpoint reads in the same `REPEATABLE READ READ ONLY` transaction as the snapshot (§4 SnapshotService). `SET TRANSACTION ISOLATION LEVEL` is only issued when the service opened the transaction. | Postgres refuses it after the first query of a transaction, so a caller's own transaction (the test harness) owns the level. A concurrency test proves the isolation matters (it fails 5 of 5 without it). | Pending user review (agent, T035/T036) |
| 2026-10-17 | `LedgerRepository` reads plain rows with only the needed columns instead of Eloquent models (§4 LedgerRepository, DD12). | Hydrating and casting every row was the largest part of the time the pool lock is held (8.4 ms of a 9.75 ms submit at 151 entries) and grows with the ledger. Submit is 6.2 ms now and the 50x3 p95 is 0.65-0.80 s (was up to 0.91 s). No behaviour change. | Pending user review (agent, T053) |
| 2026-10-17 | Added `app/Pool/AllocationOutcome.php` (full/partial/none) and `app/Http/Resources/AllocationResource.php`/`LogEntryResource.php` over a `LogEntry` DTO with factories (§4 structure). | Shared by the controller, the snapshot, the log and the broadcast so the entry shape is defined once. | Pending user review (agent, T030/T034) |
| 2026-10-17 | Test files marked "(added)" beyond §4: `LogEntryResourceTest`, `SeedersTest`, `PoolUpdatedTest`, `viewer.test.js`, extra cases in `ParallelAllocationTest` (self-consistent snapshots under load; `503 pool_busy`), shared helpers in `tests/Pest.php`, a `Concurrency` testsuite in `phpunit.xml`, and a `package.json` `test` glob. | Each covers behaviour the listed tests did not reach (log shapes, idempotent seeding, event contract, safe `localStorage`, the isolation level, the 503 path). The directory form of `node --test` fails on Node 22, hence the glob. | Pending user review (agent) |
| 2026-10-17 | Client modules added or extended beyond §4: `viewer.js` and `dashboard.js` (the Alpine component), `applyOlderPage` / `oldestSeq` / `checkGap` in `pool-state.js`; the submit flow dispatches `{type: 'status'}` and `{type: 'state'}`; the key falls back to `getRandomValues` where `crypto.randomUUID` is missing; one clock (`performance.now()`) for the reducer and the submit flow. | Glue and helpers the plan left open. The fallback keeps the demo working from a phone over plain http. A browser run found that mixed clocks stopped a stale screen from ever fetching a snapshot; a regression test covers it. | Pending user review (agent, T045-T050) |
| 2026-10-17 | Front end uses hand-written CSS from `tokens.css` instead of the scaffold's Tailwind import; the scaffold's font download was removed from `vite.config.js`; the scaffold's unused `welcome.blade.php` and the cache/jobs migrations were deleted. | The plan lists `tokens.css` and `app.css`; the font download made the image build depend on the network; the cache/jobs tables are not part of §5 (sessions, cache and queue use `file` and `sync`). The Tailwind packages remain installed but unused. | Pending user review (agent, T013/T040) |
| 2026-10-17 | Docker (§4 structure, DD9): a third `web` (nginx) image stage that carries `public/` and the built assets; nginx re-resolves `app` through Docker's DNS; healthchecks on every service; `.dockerignore`; the compose `node` service loads `.env.docker`; the asset build reads `VITE_*` from `.env.docker`. | nginx needs the static assets; without re-resolving it answered `502` after the `app` container was recreated; the browser and the PHP containers need different Reverb host values (`localhost:8081` vs `reverb:8081`). | Pending user review (agent, T004/T005/T025/T039) |
| 2026-10-17 | The dev override's `app` entrypoint refreshes `public/build` from a pristine copy in the image, makes `storage/` and `bootstrap/cache/` writable, and creates `.env` from `.env.docker` when missing; the image creates Pest's `.temp` cache directories. | The `public-build` volume kept stale assets and a stale Vite manifest after a rebuild (404 on the page's CSS/JS); a git checkout does not keep `storage/` writable for php-fpm; Pest and phpdotenv reported a warning on every test in a fresh checkout. Found by following the README on a clean copy. | Pending user review (agent, T046/T054) |
| 2026-10-17 | `scripts/verify.sh` reseeds the demo data first (`SMOKE_RESET=1`), restores it on exit and runs `npm ci` when `node_modules` is missing; `smoke.sh` has the opt-in reset (§4 `scripts/`). | `live-latency` consumes units and `smoke.sh` asserts `remaining == 100`; a fresh clone has no `node_modules` on the host. | Pending user review (agent, T052-T054) |
| 2026-10-17 | `ParallelAllocationTest` checks "log order equals commit order" through `recorded_at` and by comparing each response's `pool.remaining` with a replay of the ledger, because the log endpoint did not exist yet in Phase 2 (§9). | The same property is also covered by `LogAndSnapshotTest` and the concurrent snapshot test. | Pending user review (agent, T031) |
| 2026-10-17 | The scaffold's `CLAUDE.md`, `AGENTS.md` and `README.md` were not copied into the repo; Boost writes `CLAUDE.md` only when the file exists, so an empty one was created first. `.mcp.json` runs `docker compose exec -T app php artisan boost:mcp`; the server was approved in Claude Code and verified natively on 2026-10-17. | The scaffold's `CLAUDE.md` tells agents to install PHP on the host, which contradicts the Docker-only rule. | Pending user review (agent, T001/T008) |
| 2026-10-19 | New `app/Pool/LedgerSequence.php`: the one place ledger numbers are taken (`UPDATE pools SET version = version + 1 … RETURNING version`, inside a transaction, rolled back with it). `AllocationService` uses it instead of computing `version + 1` itself (§4 components, §5 "Seq rule"). Tests: `LedgerSequenceTest` (unique and gapless across the three tables; fails if other code writes `pools.version`) and a guard case in the concurrency group. | Review decision 4 of the M1 hand-over: the schema keeps `seq` unique only per table, so M3's new writers could otherwise give two entries one number (seen once in testing: the screen showed 99 rows instead of 100). Enforced in code, as proposed, not with a schema change. | User, 2026-10-19 (proposal accepted) |
