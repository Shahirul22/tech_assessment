# Plan: M2 — Expiry and status

| Field | Value |
|---|---|
| Slice | M2 |
| PRD | [prd.md](prd.md) (Approved 2026-10-19) |
| Status | Approved (2026-10-19, by the user; the three items in §11 accepted as proposed) |
| Date | 2026-10-19 |

## 1. Approach summary
M2 fills in the two steps M1 left empty and adds the thin API and UI parts that show them. `ApplyExpiry` marks each Temporary allocation inactive once `now >= effective_at + window(tier_at_log)`; `ComputeStatus` sums what each operator holds (Consumed plus active Temporary) against the config thresholds. Both run inside the existing pure `PoolCalculator`, which gets one new entry point, `calculateAt(Ledger, instant)`, so the same ledger can be evaluated at any instant. **The single most important idea: expiry is never stored. Everything (remaining, holdings, status, the "expired" log entries, the next time a screen must refresh) is derived from the ledger and one instant, and every write path evaluates the ledger at an instant that never moves backwards.** Screens learn about expiry without a broadcast: the snapshot tells them the next expiry time (`next_expiry_at`) and the server's time, and each screen refetches one snapshot when that moment arrives.

## 2. Rules check
Read before designing: `workflow/rules/laravel-boost.md` (the only rule file; `workflow/constitution.md` and `workflow/boundaries.md` do not exist), `CLAUDE.md` project rules. `.ai/rules` was not checked because it is not part of the workflow documents; the implementation tasks must open `.ai/rules/index.md` if it exists (Boost project rule).

| Rule (cite file / ID) | Complies? | How |
|---|---|---|
| laravel-boost rule 1 (`search-docs` before Laravel code) | Yes, by task design | This plan writes no code. Every Laravel task in §10 names the `search-docs` query to run first (listed in §9 "Boost queries"). |
| laravel-boost rule 2 (real schema via `database-schema` / `database-query`, tinker for behaviour, `last-error` when debugging) | Yes, by task design | M2 adds no table or column (§5). The query-heavy tasks (T-2.4 `LogQuery`) must read the real schema first. Boost 2.10.3 has no `tinker` tool; `php artisan tinker` is used instead (as recorded in the M1 plan §2). |
| laravel-boost rule 3 (`application-info` at session start) | Yes, by task design | Task T-0.1 calls it before any Laravel work. |
| laravel-boost rule 4 (never continue without Boost; start the stack if unreachable) | Yes | Same stack as M1; T-0.1 confirms Boost is reachable. |
| laravel-boost rule 5 (each task note names the documentation query) | Yes, by task design | Part of every Laravel task's Verify line. |
| laravel-boost rule 6 (Boost never overrides `requirement.md` §0, the PRD or the plan; conflict → stop and ask) | Yes, with one item raised | One conflict inside the PRD itself is raised in §11 (FR5.9 against AC-US2.8 under a request overtaking another). Nothing in Boost's guidance conflicts. |
| `CLAUDE.md`: stay in the existing directory structure, no dependency changes without approval, no documentation files unless requested, Pint before finishing | Yes | New PHP files go under existing `app/Pool/...`; no new package; the only docs touched are `docs/api-contract.md` and the README that M1 already created and the PRD asks to keep (AC-US7.5 carries forward); Pint is in each PHP task's Verify. |
| `CLAUDE.md`: Pest tests; do not delete tests | Yes | The `->todo()` tests in `CalculatorPendingTest.php` for M2 are **replaced by real tests** (PRD AC-US9.1 requires it). That is converting placeholders the M1 plan wrote for exactly this purpose, not deleting coverage; the M3 todos stay. Flagged in §11 so it is approved with the plan. |
| `requirement.md` §0, D3, D4, D8, D10, D12 | Yes | §7 DD1–DD9. |
| Approved PRD M2 non-goals (no corrections, restock, status history, tier-change UI, sunlight audit) | Yes | Nothing in §4–§6 touches them. |

**Re-run at the end of implementation (2026-10-19, T028).** `workflow/rules/laravel-boost.md` and `CLAUDE.md` were re-read. Mechanical checks: every PHP, Blade, config and test task note (T002–T017, T023) names a Boost query or says it is a documentation task (T015, T017 also say so); `grep -c "Boost query" tasks.md` → 32; every requirement and acceptance-criterion ID in PRD §10 appears in the tasks Coverage table with no empty cell. Boost calls actually made: `application-info` (T001); `search-docs` "configuration environment variables env" / "testing time carbon freeze" / "config files" (T002; the time-freezing query returned nothing relevant, so its result was reused as "no framework API needed" for T003–T017, as the Boost guidelines allow when results are sufficient); `search-docs` "blade directives" / "blade displaying data escaping" / "blade conditionals" (T023); `database-schema` for allocations, restocks and corrections (T013). **Not used:** `database-query`, tinker, `last-error`, `browser-logs` (no debugging session needed; behaviour was proved by tests, which the Boost guidelines prefer over tinker). No Boost call failed. `.ai/rules` does not exist. **T027** was closed on 2026-10-20 on the user's word that the manual browser checks are done; the assistant did not run them.

## 3. Technical context
Unchanged from M1 (PHP 8.4, Laravel 12.x, PostgreSQL 16, Blade + Alpine + Vite, Reverb + Echo, Pest, `node --test`). Only differences:

| Item | Choice |
|---|---|
| New dependencies | None. |
| Time arithmetic | `CarbonImmutable`, absolute (elapsed) seconds, not wall-clock days, so a daylight-saving change cannot move an expiry. T-1.3 tests the boundary with microsecond precision. |
| Testing | Pest on PostgreSQL with `FrozenClock` for everything time-based; the concurrency test seeds past allocations directly in the database so an expiry falls a few seconds into the test (§9). `node --test` with fake timers for the screen. |
| Constraints | p95 submit ≤ 1 s still (NFR2); expiry visible on other screens ≤ 2 s (NFR6). |

## 4. Architecture

```
Browser                                   App
 snapshot { server_time, next_expiry_at,   SnapshotService ─ calculateAt(now) ─┬─ remaining
            viewer{status,holdings,…},                                          ├─ statuses → ViewerView
            log[…incl. expiry] }                                                └─ effective ledger → ExpiryLog (derived entries)
 timer at next_expiry_at ─► GET /api/pools/1  (one snapshot, no websocket message)
 POST /allocations ─► AllocationService (pool lock) ─ evaluates at evaluationInstant(receipt, ledger)
```

**Components and what changes**
- **`RulesConfig`** gains `windowSeconds(tier)`, `expiresAt(tier, effectiveAt)`, `statusFor(units)`, `lowestStatus()` and one new validated key, `window_scale` (see DD7).
- **`PoolCalculator`** gains `calculateAt(Ledger, CarbonImmutable): PoolState`; `calculate()` becomes `calculateAt($ledger, $clock->now())`. `PoolState` carries the post-step ledger (`effective`) so callers can build holdings and expiry entries without recomputing.
- **`ApplyExpiry`** (M2 owns) sets `active` and `expiresAt` on every Temporary entry; **`ComputeStatus`** (M2 owns) returns status name by operator id. `ApplyCorrections` is untouched (M3).
- **`Expiries`** (new, pure, `app/Pool/Calculation/Expiries.php`) reads an effective ledger: `expired(Ledger): list<AllocationEntry>`, `nextDue(Ledger): ?CarbonImmutable`, `holdings(Ledger, operatorId): list<AllocationEntry>` (active Temporary with units > 0, soonest first).
- **`ViewerView`** (new, `app/Pool/ViewerView.php`) builds the `viewer` block for the snapshot **and** the submit response from `(PoolState, ?User, RulesConfig)`, so both always agree.
- **`AllocationService`** evaluates the ledger at `evaluationInstant` (DD2) for the grant, the "after" state and the replay.
- **`LogQuery`** adds derived `expiry` entries, orders them with a cursor (DD4), and fills `expires_at` on allocation entries.
- **Browser:** `time-left.js` (pure text and clock-offset helpers), `expiry-timer.js` (schedules one refetch), `pool-state.js` (keys the log by `cursor`, holds `viewer`, `serverOffsetMs`, `nextExpiryAt`), `log-renderer.js` (expiry text), plus form, status line and holdings in the dashboard.

**Where the hard rules are enforced**
| Rule | Enforced in |
|---|---|
| Expiry boundary: held before `expires_at`, returned at it | `ApplyExpiry` (`now < expiresAt` means active) |
| Window uses the logged tier | `ApplyExpiry` reads `AllocationEntry::tierAtLog`, never the user's current tier |
| No write at expiry | There is no code that writes at expiry; `ExpiryNoWriteTest` snapshots row counts and `pools.version` across the boundary |
| Pool never negative with expiry in flight | `AllocationService` under the pool lock, at a non-decreasing instant (DD2) |
| Replay identical after expiry | Replay evaluates the as-of ledger at the original instant (DD3) |
| Status from the corrected, expired ledger | `ComputeStatus` runs on the output of `ApplyExpiry` (pipeline order stays corrections → expiry → sum/status) |

### Project structure (files this slice creates or changes)
```
.
├── config/pool_rules.php                      # + window_scale (env POOL_WINDOW_SCALE, default 1)
├── phpunit.xml                                # POOL_WINDOW_SCALE=1 for tests
├── docker-compose.demo.yml                    # NEW: sets POOL_WINDOW_SCALE for the short-window demo (DD7)
├── app/Pool/
│   ├── Config/RulesConfig.php  InvalidRulesConfig.php   # windowSeconds, expiresAt, statusFor, lowestStatus, window_scale
│   ├── Calculation/
│   │   ├── PoolCalculator.php                 # calculateAt()
│   │   ├── PoolState.php                      # + effective ledger
│   │   ├── AllocationEntry.php                # + expiresAt
│   │   ├── Expiries.php                       # NEW
│   │   └── Steps/ApplyExpiry.php  ComputeStatus.php     # real
│   ├── ViewerView.php                         # NEW
│   ├── AllocationService.php  AllocationResult.php      # evaluation instant, replay at original instant
│   ├── SnapshotService.php                    # server_time, next_expiry_at, viewer, derived log
│   └── Log/LogEntry.php  LogQuery.php         # derived expiry entries, cursor, expires_at
├── app/Http/Controllers/Api/AllocationController.php  LogController.php
├── app/Http/Resources/AllocationResource.php  LogEntryResource.php
├── resources/js/
│   ├── time-left.js  expiry-timer.js          # NEW
│   ├── pool-state.js  log-renderer.js  api.js  dashboard.js
│   └── __tests__/time-left.test.js  expiry-timer.test.js  (+ changes to pool-state, log-renderer, submit-flow, viewer tests)
├── resources/views/dashboard.blade.php        # type choice, window hint, status, holdings
├── resources/css/app.css                      # holdings list, type choice (tokens only)
├── tests/Unit/PoolCalculatorTest.php  ExpiryStepTest.php  ComputeStatusTest.php  RulesConfigTest.php  CalculatorPendingTest.php
├── tests/Feature/ExpiryFlowTest.php  ExpiryNoWriteTest.php  TierWindowTest.php  ViewerSnapshotTest.php  ExpiryLogTest.php
│                 IdempotencyTest.php  LogAndSnapshotTest.php  SubmitAllocationTest.php  DashboardViewTest.php   # extended
├── tests/Concurrency/ParallelExpiryTest.php   # group: concurrency
├── tests/Live/expiry-demo.mjs                 # runs against the demo stack
├── scripts/demo-expiry.sh  verify.sh          # demo helper; verify runs the new tests
├── docs/api-contract.md                       # additive fields (see §6)
└── README.md                                  # demo walkthrough for expiry (short section)
```

## 5. Data model
**No schema change.** `allocations.type`, `tier_at_log`, `effective_at` and the config were laid down in M1 for exactly this (M1 plan §5 checklist). `expires_at` and status are derived, never stored; no column, no index, no migration. `SchemaContractTest` already guards the columns M2 reads.

Value-object changes (PHP, in memory only):
```php
final readonly class AllocationEntry {            // existing fields unchanged; one added at the end
    public function __construct(/* id, seq, operatorId, type, units, tierAtLog, effectiveAt, bool $active = true, */
                                public ?CarbonImmutable $expiresAt = null) {}   // set by ApplyExpiry for temporary, else null
}
final class PoolState { public function __construct(public int $remaining, public int $version,
                                                    public array $statuses = [], public ?Ledger $effective = null) {} }
```

## 6. Interface contracts
All changes are **additions**; no M1 field changes meaning (AC-US9.3). Detail goes into `docs/api-contract.md`.

**Calculation contract** (PHP)
```php
PoolCalculator::calculateAt(Ledger $ledger, CarbonImmutable $at): PoolState   // corrections → ApplyExpiry($at) → sum; ComputeStatus($at)
PoolCalculator::calculate(Ledger $ledger): PoolState                          // = calculateAt($ledger, $clock->now())
// ApplyExpiry::apply(Ledger, CarbonImmutable $now, RulesConfig): Ledger
//    for each temporary entry with units > 0:  expiresAt = rules->expiresAt(tierAtLog, effectiveAt);  active = $now < expiresAt
//    consumed entries: active stays true, expiresAt null
// ComputeStatus::compute(Ledger, CarbonImmutable, RulesConfig): array<int,string>
//    held(op) = Σ units of consumed + Σ units of active temporary;  status = highest level with min_units <= held (inclusive, A4)
//    operators with no allocation are absent from the array; callers use rules->lowestStatus()
// RulesConfig::expiresAt(string $tier, CarbonImmutable $effectiveAt): CarbonImmutable   // effectiveAt + windowSeconds(tier) seconds
```

**Snapshot `GET /api/pools/{pool}`** adds:
```json
{"server_time":"2026-10-19T10:00:00.123456Z",
 "next_expiry_at":"2026-10-20T09:58:00.000000Z",          // earliest future expires_at of any active temporary in the pool, or null
 "viewer":{"operator_id":1,"status":"elevated","tier":"bronze","window_seconds":86400,
           "holdings":[{"allocation_seq":12,"units":4,"expires_at":"…"}]},   // own active temporary, soonest first; supervisor: status null, holdings []
 "log":[ … ]}
```
`status` is the lowest level's name for an operator with no allocations, and `null` for a supervisor or an unknown viewer.

**Log entry shape** (additive): every entry gets `cursor` (string); `allocation` gets `expires_at` (null for consumed or a zero grant); `expiry` gets `operator` and its `at` is the allocation's `expires_at`.
```json
{"seq":null,"cursor":"13x12","kind":"expiry","at":"2026-10-20T09:58:00.000000Z",
 "operator":{"id":1,"name":"Ana"},"allocation_seq":12,"units":4}
{"seq":12,"cursor":"12","kind":"allocation", …,"type":"temporary","expires_at":"2026-10-20T09:58:00.000000Z"}
```

**`GET /api/pools/{pool}/log?before={cursor}&limit={n}`**: `before` now accepts a cursor string. A bare integer `N` is still valid and means "older than stored entry N" (M1 behaviour). A cursor from an expiry entry has the form `{anchor}x{allocation_seq}`. `422` for anything else. Clients take the cursor from the last row they hold (the `cursor` field).

**`POST /api/pools/{pool}/allocations`** response adds `allocation.expires_at` and a `viewer` block (`status`, `tier`, `window_seconds`, `holdings`), computed from the same ledger as `pool`. A replay returns the same block as the first reply (DD3). Request and errors unchanged.

**Broadcast `pool.updated`**: payload shape unchanged (the entry now carries `cursor` and `expires_at`). **Expiry broadcasts nothing** (D12).

**Rules config:** `window_scale` (float, `0 < x ≤ 1`, default 1, from `POOL_WINDOW_SCALE`). Invalid values fail startup naming the key `window_scale` (extends AC-US7.4).

### Client modules (pure, unit-tested)
```js
// time-left.js
serverOffsetMs(serverTimeIso, clientNowMs)          // serverTime - clientNow, taken when a snapshot arrives
timeLeftText(msLeft)                                // >= 2 h: "3 h"; 1 min to 2 h: "45 min"; < 1 min: "under 1 min"; <= 0: "now"
windowText(seconds)                                 // 86400 -> "24 h", 120 -> "2 min"
returnTimeText(expiresIso, serverNowMs, timeZone)   // "10:00 today", "10:00 tomorrow", or "20 Oct 10:00"
// expiry-timer.js
createExpiryTimer({ onDue, setTimer, clearTimer, nowMs })  // .schedule(dueAtMs) replaces any pending timer; fires onDue once, 250 ms after the due instant
// pool-state.js: state gains viewer, viewerVersion, serverOffsetMs, nextExpiryAt; log is keyed by entry.cursor
nextExpiryAt(state)                                 // min(snapshot value, expires_at of newer allocation entries) still in the future
```

## 7. Key design decisions
| ID | Decision | Alternatives considered | Why this one | PRD refs |
|---|---|---|---|---|
| DD1 | `ApplyExpiry` is the single source of "active": `now < effective_at + window(tier_at_log)`; the step also writes `expiresAt` on the entry for everyone downstream. Time arithmetic in whole elapsed seconds on `CarbonImmutable`. | Compute expiry again in SQL, in the log and in the viewer (three copies of the boundary); a stored `expires_at` column (a second source of truth that M3 corrections would have to keep in step, and it needs a migration, breaking F2). | One place decides the boundary, so the 1 ms tests prove it for remaining, status, holdings and log at once. | FR5.1, FR5.2, FR5.4, FR5.5, NFR-M2.1 |
| DD2 | Every write evaluates the ledger at `evaluationInstant = max(receipt time, latest effective_at in the ledger)` (never earlier than something already committed). The grant, the "after" state and the response all use it. | Evaluate at the request's own receipt time: a request received before an expiry but committed after one received after it would count the same units twice (held by the old holder and by the new one) and report a negative remaining; evaluate at processing time: contradicts "receipt time" and makes results depend on lock waits. | Keeps the pool from ever going below 0 when requests overtake each other inside the lock wait, and the instant is recoverable from the ledger. Differs from the literal wording of FR5.9 only in that overtaking window (milliseconds); raised in §11. | FR5.9, NFR1, D2, AC-US2.8 |
| DD3 | A replay recomputes with the as-of ledger (`seq <= original`) at the original's instant, which equals `max(effective_at over entries with seq <= original)`. No schema change. | Store the first reply (a column or table, breaks F2 and adds a write); recompute with the current clock (changes after expiry, violating FR5.8); the M1 plan's own suggestion ("clock fixed at `effective_at`"), which is the same as this except in the overtaking case. | Identical to the first reply by construction; the `ViewerView` block is derived the same way. | FR5.8, NFR5, AC-US7.1 |
| DD4 | Expiry log entries are derived from the effective ledger and ordered by an **anchor**: the highest `seq` of any stored entry whose `at <= expires_at`. Sort key (newest first): `(anchor, rank, allocation_seq)` with stored entries `(seq, 0, 0)` and expiries `(anchor, 1, allocation_seq)`, so an expiry sits directly above the entry it follows. `cursor` is `"<seq>"` for stored and `"<anchor>x<allocation_seq>"` for expiry. Anchors for all expired entries come from one grouped query. | Order the whole log by time (breaks "log order equals commit order", M1 AC-US5.2, because `effective_at` can be a few ms out of `seq` order); give expiry a fake `seq` (collides with the shared sequence, M1 `LedgerSequence`); append expiries on top until reload (violates "placed by their expiry time", PRD PQ3). | Keeps M1's commit order for stored entries, places expiry by time, and gives paging a total order. | FR3.2, FR3.3, PQ3, AC-US5.1, AC-US5.3 |
| DD5 | The client refetches **one snapshot** when `next_expiry_at` arrives (timer = `next_expiry_at` − `serverOffset` + 250 ms), and again whenever the snapshot says the next expiry is still in the future. Newer allocation entries (from events or the own result) can only move the timer earlier. The 10 s poll stays as the fallback. | Server pushes an expiry event (D12 says nothing is broadcast; needs a scheduler); poll every second (load); client computes expiry alone (wrong when device clock is off). | Follows D12 exactly; the server's own clock decides, so a device clock 1 h off still works (AC-US3.4). | FR5.10, NFR6, D12 |
| DD6 | `viewer` is one block built by `ViewerView` for both the snapshot and the submit response; the screen applies the block from a submit result only if its version is not older than the viewer data it already has (`viewerVersion`). | Re-fetch a snapshot after every own submit (extra request, flicker); compute status in the browser (second implementation of A4). | Own status and holdings change the instant the operator is granted units, with no extra round trip, and the numbers still come from the server only. | FR2, FR4.2, FR13, FR12 |
| DD7 | Demo windows: new config key `window_scale` (env `POOL_WINDOW_SCALE`, default 1). `docker-compose.demo.yml` sets it to `0.0013889` (24 h → 2 min, 48 h → 4 min, 72 h → 6 min). The tier hours and the validation of M1 stay as they are; the UI shows the real window it receives (`window_seconds`). Tests pin the scale to 1. | Change `window_hours` for the demo (edits the real placeholders); a clock override in the app (a hidden global the plan avoids, DD5 of M1); a fast-forward button (new feature, not in the PRD). | Zero code edit for the evaluator and no test depends on it. | FR14, PQ2, AC-US8.1 |
| DD8 | Status shown only for the viewing operator; held units = Consumed units + active Temporary units, per PQ1. | Temporary only (rejected by the user, PQ1). | Done. | FR4, PQ1 |
| DD9 | The M2 pending tests are replaced by real tests in new files (`ExpiryStepTest`, `ComputeStatusTest`, `TierWindowTest`); `CalculatorPendingTest` keeps only the M3 todos. | Keep the todos and add tests elsewhere (duplicate names that show as pending forever). | The M1 plan reserved those names for M2. | F3, AC-US9.1 |

## 8. Requirement-to-design traceability
| PRD ID | Where it is handled |
|---|---|
| FR5, FR5.1 | `ApplyExpiry`, `RulesConfig::expiresAt` (DD1) |
| FR5.2 | `ApplyExpiry` boundary `now < expiresAt` (DD1) |
| FR5.3 | `ApplyExpiry` leaves Consumed untouched |
| FR5.4 | `calculateAt` sum ignores inactive Temporary; no write path (§4 rules table) |
| FR5.5 | `ApplyExpiry` uses `tierAtLog` |
| FR5.6 | Type choice in `dashboard.blade.php`; `viewer.window_seconds` + `windowText()` |
| FR5.7 | `allocation.expires_at` in the response; `returnTimeText()`; banner text in `api.js` / `dashboard.js` |
| FR5.8 | DD3; `AllocationService::replay` |
| FR5.9 | DD2; `AllocationService::evaluationInstant` |
| FR5.10 | DD5: `next_expiry_at`, `server_time`, `expiry-timer.js`, polling fallback |
| FR2 | `viewer.status` in snapshot and submit response; status line in the Pool panel |
| FR4, FR4.1, FR4.2 | `ComputeStatus`, `RulesConfig::statusFor` (DD8) |
| FR4.3 | Status line text "Status: …"; no code reads status to limit a request |
| FR13 | `Expiries::holdings`, `viewer.holdings`, holdings panel, `timeLeftText()` |
| FR3.2, FR3.3 | `Expiries::expired`, `LogQuery` derived entries, `LogEntryResource`, `log-renderer.js` (DD4) |
| F3 | DD9; whole M1 suite still runs (`scripts/verify.sh`) |
| FR14 | DD7; `docker-compose.demo.yml`, `scripts/demo-expiry.sh`, README section |
| NFR1 | DD2; `ParallelExpiryTest` |
| NFR2 | No new query inside the lock; M1 p95 test re-run with Temporary requests mixed in |
| NFR3 | CSS uses M1 tokens; manual check list |
| NFR5 | DD3; `IdempotencyTest` additions |
| NFR6 | DD5; `expiry-timer.test.js`, `expiry-demo.mjs` |
| NFR-M2.1 | `calculateAt` and the steps take the instant as an argument; `FrozenClock` tests |
| NFR4 | Out of scope (M4); status and expiry rows carry text labels |

## 9. Test strategy
| Level | What it proves | Maps to AC | Notes |
|---|---|---|---|
| **Unit (hard rule) `ExpiryStepTest`** | Temporary 4 at T, Bronze: remaining 6 at T+24h−1ms; 10 at T+24h; 10 at T+24h+1ms. Consumed never returns. Partial grant returns granted units. Zero grant holds nothing. Two entries expiring at the same instant both return. Boundary across a daylight-saving change. | AC-US2.1, 2.3, 2.4, 2.7 | `FrozenClock`; `calculateAt` with explicit instants |
| Unit `ComputeStatusTest` | 9 → standard, 10 → elevated, 29 → elevated, 30 → high; 12 elevated until E−1ms then standard at E; no allocations → absent, `lowestStatus()` used; Consumed 12 → elevated (PQ1); 30 → 29 drops to elevated. | AC-US4.1, 4.2, 4.5, 4.6 + edge | Data-provider |
| Unit `RulesConfigTest` (extended) | `window_scale` outside `(0, 1]` or non-numeric fails naming the key; `windowSeconds`, `expiresAt`, `statusFor` values. | AC-US7.4 (carry), FR5.1 | |
| Unit `PoolCalculatorTest` (existing) | M1 numbers unchanged (restock 100, grants 3, 3, 4 → 90). | AC-US9.2 | Must pass untouched |
| Feature `TierWindowTest` | Silver logs at T, tier changed to Gold afterwards: expires T+48h; a new one expires T2+72h. | AC-US6.1, 6.2 | Changes the user row directly |
| Feature `ExpiryFlowTest` | Temporary request stored, response has `expires_at` = T+24h and "granted" numbers; remaining 0 at T+24h−1ms refuses Ben, at +1ms grants him 3; two Temporary expiring together; default type Consumed; unknown type → 422. | AC-US1.1, 1.3, 1.4, 2.5 | Advance `FrozenClock` between requests |
| Feature `ExpiryNoWriteTest` | Row counts of every ledger table and `pools.version` are equal before and after the boundary, and after reading the snapshot twice. | AC-US2.2 | |
| Feature `ViewerSnapshotTest` | Own holdings sorted soonest first, only own, none for Consumed; status label; supervisor gets null/[]; `server_time`, `next_expiry_at` correct and null when none; holding gone at E; M1 fields still present with the same meaning. | AC-US3.1, 3.3, 4.3, 4.5, 9.3 + edge cases | |
| Feature `ExpiryLogTest` | No expiry entry at E−1ms; one at E, naming Ana, units, `allocation_seq`; exactly once over repeated reads and `before=` paging; none for Consumed or zero grant; position follows DD4 (including an out-of-order `effective_at` fixture); integer `before` still works; bad cursor → 422. | AC-US5.1, 5.3, 5.4, 2.7 | |
| Feature `IdempotencyTest` (extended) | Replay at T+25h returns the identical body including `viewer` and `pool.remaining`; different amount/operator/type → 409. | AC-US7.1, 7.2 | |
| Feature `SubmitAllocationTest`, `LogAndSnapshotTest`, `BroadcastTest` (existing) | Still pass; broadcast entry carries `cursor` and `expires_at`. | AC-US9.2 | Adjust only assertions on exact entry shapes |
| Feature `DashboardViewTest` (extended) | Type choice present, Consumed default, status line and holdings container present; no type selector for supervisors. | AC-US1.2 | |
| **Concurrency (hard rule) `ParallelExpiryTest`** | A holder of 40 units is inserted directly so it expires about 3 s into the test; requests of 3 arrive in waves of 5 every ~600 ms across the boundary (50 total). The pool is never below 0; replaying the ledger in `seq` order, each response's `remaining` equals the calculator at its evaluation instant; units come back for requests after E and not before. | AC-US2.8, NFR1 | Group `concurrency`; own pool; cleans up |
| Concurrency (existing) 50×3, 6+6 of 10, 10× same key | Unchanged results; p95 ≤ 1 s with half the requests Temporary. | AC-US9.2, NFR2 | Add a Temporary-mix variant |
| JS `time-left.test.js` | Text at 3 h, 2 h, 1 h 59 min, 59 s, 0; `windowText`; `returnTimeText` today/tomorrow/other in a fixed time zone; offset makes a device 1 h ahead agree with the server. | AC-US3.4, 1.1, 1.2 | `node --test` |
| JS `expiry-timer.test.js` | Fires once 250 ms after the due instant; replacing the schedule cancels the old; due in the past fires at once; no timer when `null`; long delays are capped below the timer limit and re-armed. | AC-US2.6, 3.2 | Fake timers |
| JS `pool-state.test.js` (extended) | Cursor keys: expiry entry upserted once, order kept; viewer from a newer snapshot replaces; own result's viewer ignored when a newer snapshot already set it; `nextExpiryAt` only moves earlier from events. | AC-US5.3, 3.2 | |
| JS `log-renderer.test.js` (extended) | Expiry reads "Ana: 4 units returned (#12)" with label "Expiry", own flag for Ana only, allocation shows "temporary". | AC-US5.1, 5.2, 5.5 | |
| JS `submit-flow.test.js` (extended) | Request carries `type`; done banner for temporary says "4 granted · returns …"; partial grant states the return time; zero grant states none. | AC-US1.1, 1.3 | |
| Script `tests/Live/expiry-demo.mjs` | Against the demo stack: a Temporary request returns, and the snapshot shows the units back within 2 s of `expires_at`, the holding gone and one expiry entry. | AC-US2.6 (server half), AC-US8.1 | Refuses to run unless `viewer.window_seconds <= 600` |
| Manual (README) | Two browsers on the demo stack: expiry visible on both; 360 px and 1280 px without horizontal scroll; killing `reverb` still corrects at expiry; device clock 1 h ahead. | AC-US2.6, 3.4, 5.5, 8.1 | |
| `scripts/verify.sh` | Runs all of the above in M1's order, plus the new files. | AC-US9.2 | |

**Boost queries to run before the Laravel tasks** (named in each task note): `service container singleton binding` and `config env helper` (T-1.1, T-1.5); `Carbon immutable add seconds` / `date and time testing` (T-1.3); `eloquent query builder union group by` and `database transactions` (T-2.4); `API resources` (T-2.2); `routing validation rules regex` (T-2.5); `testing travel time` (feature tests); `Pest todo` (T-1.4); `blade directives` / `blade components` (T-3.6).

## 10. Task outline

- **Phase 0 — Setup**
  - T-0.1 Call Boost `application-info`; open `.ai/rules/index.md` if present; confirm the M1 suite is green (`APP php artisan test`, `NODE npm test`) as the baseline.
- **Phase 1 — Calculation foundation (blocks everything after; US2, US4, US6 server logic)**
  - T-1.1 `RulesConfig`: `window_scale`, `windowSeconds`, `expiresAt`, `statusFor`, `lowestStatus`; `config/pool_rules.php`; `phpunit.xml`; `RulesConfigTest` additions.
  - T-1.2 `AllocationEntry::expiresAt`, `PoolState::effective`, `PoolCalculator::calculateAt` (and `calculate()` delegating).
  - T-1.3 `ApplyExpiry` + `ExpiryStepTest` (boundaries ±1 ms, daylight-saving case).
  - T-1.4 [P] `ComputeStatus` + `ComputeStatusTest`; convert the M2 todos in `CalculatorPendingTest` (leave M3 todos).
  - T-1.5 [P] `Expiries` (expired, nextDue, holdings) + unit tests.
  - T-1.6 `AllocationService`: `evaluationInstant`, grant and "after" at that instant, replay at the original instant; `ExpiryFlowTest`, `TierWindowTest`, `ExpiryNoWriteTest`, `IdempotencyTest` additions.
- **Phase 2 — API and log (US1 server, US3, US4, US5, US7)**
  - T-2.1 `ViewerView`.
  - T-2.2 `AllocationResource`/`AllocationController`: `expires_at`, `viewer`; update `AllocationResult` if needed.
  - T-2.3 `SnapshotService`: `server_time`, `next_expiry_at`, `viewer`, evaluate at the clock instant; `ViewerSnapshotTest`.
  - T-2.4 `LogEntry` + `LogQuery`: derived expiry entries, anchors by one grouped query, cursor ordering, `expires_at` on allocation entries; `LogEntryResource` (`cursor`, `operator`, `expires_at`).
  - T-2.5 `LogController` cursor validation (integer or `NxM`); `ExpiryLogTest`; adjust `LogAndSnapshotTest`/`BroadcastTest`/`LogEntryResourceTest` shapes.
  - T-2.6 `docs/api-contract.md` additions.
  - T-2.7 `ParallelExpiryTest` (and the Temporary-mix variant of the 50×3 test); first run against the Compose stack; tune only if p95 > 1 s.
- **Phase 3 — Screens (US1 client, US2, US3, US4, US5)** (can start after T-2.3 and T-2.4 fix the contract; pure modules [P] earlier)
  - T-3.1 [P] `time-left.js` + `time-left.test.js`.
  - T-3.2 [P] `expiry-timer.js` + `expiry-timer.test.js`.
  - T-3.3 `pool-state.js`: cursor keys, `viewer`, `viewerVersion`, `serverOffsetMs`, `nextExpiryAt` + tests.
  - T-3.4 [P] `log-renderer.js` expiry text and "temporary" marker + tests.
  - T-3.5 `api.js` (send `type`, banner text with return time) + `submit-flow.test.js`.
  - T-3.6 `dashboard.blade.php`, `app.css`, `dashboard.js`: type choice and window hint, status line, holdings panel with a tick, expiry timer wiring, "Show older" by cursor; `DashboardViewTest`.
- **Phase 4 — Demo, hardening, docs**
  - T-4.1 `docker-compose.demo.yml`, `scripts/demo-expiry.sh`, `tests/Live/expiry-demo.mjs`.
  - T-4.2 `scripts/verify.sh` and README (expiry demo, manual checks, the assumptions A-M2.1–A-M2.6).
  - T-4.3 Run the full suite and the manual checks (two browsers on the demo stack, 360 px, 1280 px, `reverb` stopped, device clock 1 h ahead).
  - T-4.4 Re-run the rules check and the traceability self-check; log changes in §12.

**Checkpoints**
- **After Phase 1:** unit tests prove the ±1 ms boundaries, status levels and tier window; the former M2 todos are real passing tests; M3 todos still pending.
- **After Phase 2:** with `curl`, a Temporary request returns `expires_at` and `viewer`; the snapshot shows holdings, status, `next_expiry_at` and (after the boundary in a test) one expiry entry.
- **After Phase 3:** one browser: request Temporary, see holding, status and return time on the screen.
- **After Phase 4:** on the demo stack two browsers watch a Bronze window of 2 minutes end: remaining up, holding gone, status drops, "Expiry" row appears; `scripts/verify.sh` is green.

## 11. Risks, deviations and open items
| Item | Type | Mitigation or justification | Owner / status |
|---|---|---|---|
| **PRD FR5.9 read literally conflicts with AC-US2.8 / NFR1** when a request received just before an expiry commits after one received just after it: the first would see the old holder's units still held and the second's grant counted too, giving a negative remaining at that instant. | decision | DD2 uses a non-decreasing evaluation instant, so the pool never goes negative; it differs from FR5.9 only inside that window of milliseconds. Proposed PRD wording: "units that expire at or before the request's evaluation instant (its receipt time, or a later one if an earlier-received request had already committed) count as returned". The plan does not edit the PRD. | Accepted by user, 2026-10-19 |
| An expiry entry's position can move up by one row if a request received before `expires_at` commits after the entry was first shown (the anchor in DD4 changes). It is never duplicated or lost (the screen replaces by `cursor`, and a new commit bumps the version, so the screen takes a full snapshot). PRD PQ3 said "never move afterwards". | deviation (minor) | Accepted trade-off; documented in the README. The alternative (time order for everything) breaks commit order for stored entries. | Accepted by user, 2026-10-19 |
| `CalculatorPendingTest` M2 `todo` tests are replaced by real tests | deviation from `CLAUDE.md` "do not delete tests without approval" | PRD AC-US9.1 requires it; the M1 plan reserved the placeholders for M2. Same names are kept in the new files so nothing disappears. | Accepted by user, 2026-10-19 |
| Derived log reads every expired Temporary allocation of the pool and one grouped query for anchors on each snapshot | risk | Same order of cost as M1's whole-ledger read under DD12 (hundreds of rows at demo scale). If it grows: cache keyed by `(version, floor(now / next change))`. | Accepted |
| `next_expiry_at` only knows active Temporary allocations; a new allocation announced by an event moves the timer earlier only through `entry.expires_at` | risk | `pool-state.test.js` covers it; the 10 s poll is the fallback. | Implementer |
| Browser timers are throttled in background tabs | risk | The next snapshot or poll corrects on return to the tab; the PRD target (2 s) applies to a visible screen. Stated in the README. | Accepted |
| Demo setting changes tier windows for the whole stack | risk | Only `docker-compose.demo.yml` sets it; README says it is a demo switch and the UI shows the real window. | Accepted |
| Window arithmetic with `window_scale` rounds to whole seconds | risk | `windowSeconds` rounds; scale 1 gives exact hours, which is all tests use. | Implementer |
| `X-Demo-User` / dev secrets / public channel | deviation (accepted in M1) | Unchanged. | Accepted |

## 12. Change log
| Date | Change | Reason | Approved by |
|---|---|---|---|
| 2026-10-19 | Plan created from the approved M2 PRD. | New slice. | User, 2026-10-19 (approved with §11 proposals) |
| 2026-10-19 | Divergences found during implementation (all within the approved contracts; no PRD or plan content changed): (1) `AllocationService` takes `RulesConfig`, so the broadcast entry of a temporary allocation carries `expires_at` (the screen lowers its next-expiry timer from it). (2) `LogQuery::latest($pool, $limit, $now, ?$cursor)` (argument order), and the anchor query is one Postgres `VALUES` + `GREATEST` statement. (3) New `app/Support/ApiTime.php` (one timestamp format) and `Expiries` injected into `ViewerView`. (4) `pool-state.js` and `createSubmitFlow` take a second clock, `epochNow` (Date.now), beside the gap clock, because server times are on the epoch clock; `refresh({rerunIfBusy})` re-runs once when an expiry fires during a fetch; `oldestSeq` removed. (5) M1 tests adapted to the new contracts: expired-entry and identity-steps cases in `PoolCalculatorTest`, the viewer block in `LogAndSnapshotTest`, whole-entry arrays in `LogEntryResourceTest` and `BroadcastTest`, "no selector" in `DashboardViewTest` (PQ1). (6) T013's tinker check replaced by the real-database feature tests of T014. (7) `pint --dirty` needs git, so Pint ran on named files; one run over `app` and `tests` reordered imports in `ParallelAllocationTest.php` and `LedgerSequenceTest.php` (formatting only). (8) Checkpoint 1 text says five M3 todos; there are four. (9) The Checkpoint 2 `curl` wrote one Temporary 4 into the dev pool; `verify.sh` reseeded it. (10) T027 (manual browser checks) is blocked: no real browser can be started here (missing `libasound.so.2`); T024's browser check was replaced by a simulation. | Reality differed from the documents in small ways; recorded, not rewritten. | Not yet reviewed by the user |
| 2026-10-20 | T027 marked done and tasks Status set to Done on the user's instruction ("assume all has been checked"). The assistant had not run the browser checks. | User decision. | User, 2026-10-20 |
