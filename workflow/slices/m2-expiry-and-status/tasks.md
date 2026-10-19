# Tasks: M2 — Expiry and status

| Field | Value |
|---|---|
| Slice | M2 |
| PRD | [prd.md](prd.md) |
| Plan | [plan.md](plan.md) |
| Status | Done (2026-10-20, by the user's instruction; T027 manual checks accepted on the user's word) |
| Date | 2026-10-19 |

## Conventions
- Read before every task: `workflow/rules/*` (`laravel-boost.md`), `CLAUDE.md`, the task's `Refs` in [plan.md](plan.md) and [prd.md](prd.md), and `.ai/rules/index.md` if that directory exists.
- **Laravel Boost is mandatory for every PHP, Blade, config and Pest task.** Before creating or editing such a file: search the Boost documentation (`search-docs`) for the installed versions, inspect the real schema with `database-schema` / `database-query` instead of guessing, and name the Boost query in the task's completion note. Queries suggested per task are in plan §9 ("Boost queries"). Boost has no `tinker` tool in the installed version; use `php artisan tinker --execute '…'` if a behaviour check is needed. Pure JavaScript, CSS, shell and documentation tasks say so in their note instead.
- Everything runs in Docker. Shorthand (add `--user "$(id -u):$(id -g)"` to `APP` when a command writes files):
  - `APP` = `docker compose run --rm app`
  - `NODE` = `docker compose --profile tools run --rm node`
  - Unit + feature tests: `APP php artisan test --compact` (add a file path or `--filter=` for the narrowest run). Concurrency: `APP php artisan test --group=concurrency` against the running stack (`docker compose up -d --wait`). JS: `NODE npm test`.
- After editing PHP, run `APP vendor/bin/pint --dirty --format agent` before finishing the task. After editing the PHP-served assets, run `NODE npm run build` if the UI does not show the change.
- The folder is not a git repository; do not run `git init`. Progress is tracked by ticking markers in this file.
- Stop at each checkpoint for human review. Do not start the next phase before the user says so.
- Where a plan file path is named, use it exactly. Test files not listed in plan §4 are marked "(added)".
- **Changes only grow M1 contracts (AC-US9.3).** Never rename or remove an M1 field. No table, column or migration is added in M2. No endpoint beyond plan §6 is added. If something looks missing, mark the task `[!]` and report it.
- The four M2 `->todo()` tests in `tests/Unit/CalculatorPendingTest.php` (expiry exactly at, expiry 1 ms before, tier window after a tier change, status levels) are replaced by real tests of the same names in the tasks named below; the owning task deletes its todo line in the same change. The M3 todos stay untouched (approved in plan §11).
- Time in tests: use `FrozenClock` / `freezeClock()` and `calculateAt()` with explicit instants. Never `sleep()` except where a task says real time is needed (T016).

---

## Phase 0 — Setup

### [x] T001 Confirm the M1 baseline is green and Boost is reachable
- **Note (2026-10-19):** Boost `application-info` (no docs query needed; check-only task): Laravel 13.37.0, Pest 4.7.8, PHPUnit 12.5.33, Reverb 1.12.0, PHP 8.4, pgsql, Boost 2.10.3. No `.ai/rules`. Baseline: `APP php artisan test --compact` → 195 passed (742 assertions), 8 todos (4 M2 + 4 M3; Checkpoint 1 text says "five M3 todos", real count is four); `NODE npm test` → 80 pass, 0 fail; concurrency group → 6 passed (380 assertions).
- **Refs:** laravel-boost rules 3–4, AC-US9.2
- **Depends on:** none
- **Files:** none (check only)
- **Do:** Start the stack if needed (`docker compose up -d --wait`). Call Boost `application-info` and note the installed Laravel, Pest and Reverb versions in the task note. Open `.ai/rules/index.md` if it exists. Run the full unit + feature suite, the JS suite and the concurrency group once to record the baseline.
- **Verify:** `APP php artisan test --compact` → all pass (the M3 todos show as pending); `NODE npm test` → all pass; `APP php artisan test --group=concurrency` → all pass.
- **Done when:** the baseline numbers (tests, pending, JS) are written in the task note, so later regressions are visible.
- **Out of scope:** any code change.

---

## Phase 1 — Calculation foundation (blocks everything after)

### [x] T002 Extend `RulesConfig` with window seconds, expiry time, status lookup and `window_scale`
- **Refs:** FR5.1, FR4, FR14, DD1, DD7, DD8, AC-US7.4 (carry), plan §6 "Rules config"
- **Depends on:** T001
- **Files:** `app/Pool/Config/RulesConfig.php` (edit), `config/pool_rules.php` (edit), `phpunit.xml` (edit), `tests/Unit/RulesConfigTest.php` (edit)
- **Do:** Add `'window_scale' => (float) env('POOL_WINDOW_SCALE', 1.0)` to the config with a comment that it is a demo switch. In `RulesConfig` add: `windowSeconds(string $tier): int` (`round(window_hours * 3600 * scale)`), `expiresAt(string $tier, CarbonImmutable $effectiveAt): CarbonImmutable` (add whole elapsed seconds, not wall-clock days), `statusFor(int $heldUnits): string` (highest level whose `min_units <= $heldUnits`, inclusive), `lowestStatus(): string`. `fromArray()` validates `window_scale` (numeric, `0 < x <= 1`; missing means 1) and throws `InvalidRulesConfig` naming the key `window_scale`. Follow the existing `validatedTiers` style. Set `POOL_WINDOW_SCALE=1` in `phpunit.xml` so a demo value can never leak into tests.
- **Verify:** `APP php artisan test --compact tests/Unit/RulesConfigTest.php` → pass, including new cases: bronze `windowSeconds` = 86400; `expiresAt` of T = T+24 h; `statusFor` 9/10/29/30 → standard/elevated/elevated/high; `window_scale` of 0, 1.5, "abc" each fail naming `window_scale`; scale 0.0013889 gives bronze 120 s.
- **Done when:** FR5.1 numbers and the config validation hold in unit tests.
- **Note (2026-10-19):** Boost query: `search-docs` "configuration environment variables env" (laravel/framework 13.x; `env()` only inside config files). `RulesConfigTest` 29 passed. `pint --dirty` needs git (folder is not a repo), so Pint was run on the named files instead.
- **Out of scope:** the steps, the calculator, any UI text.

### [x] T003 Add `expiresAt` to `AllocationEntry`, the effective ledger to `PoolState`, and `PoolCalculator::calculateAt`
- **Refs:** NFR-M2.1, DD1, plan §5, plan §6 "Calculation contract"
- **Depends on:** T002
- **Files:** `app/Pool/Calculation/AllocationEntry.php` (edit), `app/Pool/Calculation/PoolState.php` (edit), `app/Pool/Calculation/PoolCalculator.php` (edit), `tests/Unit/PoolCalculatorTest.php` (edit)
- **Do:** `AllocationEntry` gets a last constructor parameter `public ?CarbonImmutable $expiresAt = null`. `PoolState` gets a last parameter `public ?Ledger $effective = null`. In `PoolCalculator`, move the body of `calculate()` into `calculateAt(Ledger $ledger, CarbonImmutable $at): PoolState` (corrections, then `ApplyExpiry` with `$at`, then sum, then `ComputeStatus` with `$at`; put the effective ledger in the returned state) and make `calculate($ledger)` call `calculateAt($ledger, $this->clock->now())`. Existing constructors and callers must keep working with no change.
- **Verify:** `APP php artisan test --compact tests/Unit/PoolCalculatorTest.php` → all existing cases pass unchanged; one new case: `calculateAt` with the identity steps returns the same remaining as `calculate`, and `$state->effective` is the ledger the steps returned.
- **Done when:** the instant is an argument of the calculation (NFR-M2.1) and M1 numbers are unchanged (AC-US7.3 of M1: restock 100, grants 3, 3, 4 → 90).
- **Out of scope:** expiry or status behaviour.
- **Note (2026-10-19):** Boost query: `search-docs` "configuration environment variables env" (shared with T002; no framework API used beyond plain PHP). `PoolCalculatorTest` and unit suite pass; new case covers `calculateAt` and `effective`.

### [x] T004 Implement `ApplyExpiry` with the boundary tests
- **Refs:** FR5, FR5.1–FR5.5, NFR-M2.1, DD1, AC-US2.1, AC-US2.3, AC-US2.4, AC-US2.7, M2 pending tests (expiry)
- **Depends on:** T003
- **Files:** `app/Pool/Calculation/Steps/ApplyExpiry.php` (edit), `tests/Unit/ExpiryStepTest.php` (create, "added"), `tests/Unit/CalculatorPendingTest.php` (edit: remove the two expiry todos)
- **Do:** For each allocation with `type === 'temporary'` and `units > 0`: `expiresAt = $rules->expiresAt($entry->tierAtLog, $entry->effectiveAt)` and `active = $now < $expiresAt` (strictly: at exactly `expiresAt` the units are back). Consumed entries and zero-unit entries are returned unchanged (`expiresAt` stays null). Entries are `readonly`: build new `AllocationEntry` objects. Use `tierAtLog` only, never the user's current tier. Tests (with `calculateAt` and the real `ApplyExpiry`): Temporary 4 at T, Bronze, pool 10 → remaining 6 at T+24 h−1 ms, 10 at T+24 h, 10 at T+24 h+1 ms; Consumed 4 → 6 at T+1000 h; partial grant (requested 6, units 4) returns 4; zero grant holds nothing; two entries expiring at the same instant both return; a case that crosses a daylight-saving change (set the PHP timezone of the test to one with a change and assert the boundary is still exactly 24 elapsed hours). Name the two replacing tests exactly "returns the units of a temporary allocation at exactly expires_at" and "keeps the units of a temporary allocation held 1 ms before expires_at".
- **Verify:** `APP php artisan test --compact tests/Unit/ExpiryStepTest.php tests/Unit/CalculatorPendingTest.php` → expiry tests pass, the remaining pending list no longer shows the two expiry todos.
- **Done when:** AC-US2.1, AC-US2.3, AC-US2.4, AC-US2.7 hold at unit level.
- **Out of scope:** status, the service, the log.
- **Note (2026-10-19):** Boost query: `search-docs` "testing time carbon freeze" (no Laravel API needed; Carbon `addSeconds` adds elapsed seconds, proven by the Europe/Berlin test). Two M1 tests in `PoolCalculatorTest.php` assumed identity expiry and were adapted in intent: the expired-entry case now uses `calculateAt` at T+24 h, and the identity-steps case no longer asserts `ApplyExpiry` is identity. Unit suite: 63 passed, 6 todos.

### [x] T005 [P] Implement `ComputeStatus` with the threshold tests
- **Refs:** FR4, FR4.1, FR4.2, DD8, PQ1, AC-US4.1, AC-US4.2, AC-US4.5, AC-US4.6, M2 pending test (status)
- **Depends on:** T003
- **Files:** `app/Pool/Calculation/Steps/ComputeStatus.php` (edit), `tests/Unit/ComputeStatusTest.php` (create, "added"), `tests/Unit/CalculatorPendingTest.php` (edit: remove the status todo)
- **Do:** Input is the ledger **after** `ApplyExpiry`. `held(operator)` = Σ `units` of Consumed entries + Σ `units` of entries with `active === true` of type Temporary, per operator id. Return `array<int, string>` of status name by operator id using `$rules->statusFor($held)`. Operators with no allocations are absent (the caller uses `lowestStatus()`). Tests (through `calculateAt` with the real steps): a data-provider for held 9, 10, 29, 30 → standard, elevated, elevated, high; Temporary 12 elevated at E−1 ms and standard at E; Consumed 12 and no Temporary → elevated; 30 → 29 after a part expires drops from high to elevated; operator with no allocations absent. Name the replacing test "moves an operator through the three status levels at the configured thresholds".
- **Verify:** `APP php artisan test --compact tests/Unit/ComputeStatusTest.php tests/Unit/CalculatorPendingTest.php` → pass; the status todo is gone from the pending list.
- **Done when:** AC-US4.1, AC-US4.2, AC-US4.5, AC-US4.6 hold at unit level.
- **Out of scope:** how status is shown or returned by the API.
- **Note (2026-10-19):** Boost query: `search-docs` "testing time carbon freeze" (no Laravel API needed; pure PHP step). `ComputeStatusTest` + pending list pass; the status todo is gone. The identity-steps case in `PoolCalculatorTest.php` no longer asserts ComputeStatus returns [] (now real) and was renamed accordingly. Note: I ran Pint on whole `app` and `tests` once; it only reordered imports in two unrelated test files (`ParallelAllocationTest.php`, `LedgerSequenceTest.php`); formatting only.

### [x] T006 [P] Add `Expiries` (expired, next due, holdings)
- **Refs:** FR13, FR3.2, FR3.3, FR5.10, plan §4 components
- **Depends on:** T003
- **Files:** `app/Pool/Calculation/Expiries.php` (create), `tests/Unit/ExpiriesTest.php` (create, "added")
- **Do:** A pure class over an **effective** ledger (the output of `ApplyExpiry`). `expired(Ledger): list<AllocationEntry>`: Temporary entries with `units > 0` and `active === false`, ordered by `expiresAt` then `seq`. `nextDue(Ledger): ?CarbonImmutable`: the earliest `expiresAt` among active Temporary entries with units, or null. `holdings(Ledger, int $operatorId): list<AllocationEntry>`: that operator's active Temporary entries with units, soonest `expiresAt` first (ties by `seq`). To get an effective ledger in the tests, run `ApplyExpiry` first.
- **Verify:** `APP php artisan test --compact tests/Unit/ExpiriesTest.php` → pass: zero grants and Consumed never listed; ordering; `nextDue` null when none and the earliest when several; holdings only for the named operator.
- **Done when:** the three derivations are tested.
- **Out of scope:** the log query, resources, SQL.
- **Note (2026-10-19):** Boost query: `search-docs` "testing time carbon freeze" (pure PHP class, no Laravel API). `ExpiriesTest` passes; unit suite 77 passed, 5 todos.

### [x] T007 Evaluate writes at a non-decreasing instant and make replays identical
- **Refs:** FR5.8, FR5.9, NFR1, NFR5, DD2, DD3, plan §11 (accepted FR5.9 wording), AC-US2.5, AC-US7.1
- **Depends on:** T004, T005
- **Files:** `app/Pool/AllocationService.php` (edit), `app/Pool/AllocationResult.php` (edit only if needed to carry the instant)
- **Do:** In `submitUnderLock`: `evaluationInstant = max($receipt, the latest effective_at among $ledger->allocations)` (receipt itself when the ledger has no allocations). Use `calculator->calculateAt($ledger, $instant)` for the grant and for the "after" state (replace the two `calculate()` calls). In `replay`: build the as-of ledger as now (`seq <= existing->seq`) and calculate at `max(effective_at over the as-of allocations)` (this includes the replayed entry itself, so it equals the original instant). Add a short PHPDoc on why the instant never goes backwards (a request received earlier can commit after one received later). `effective_at` stored on the allocation stays the receipt time (FR1.7). No new query inside the lock.
- **Verify:** `APP php artisan test --compact tests/Feature/SubmitAllocationTest.php tests/Feature/PartialGrantTest.php tests/Feature/IdempotencyTest.php tests/Feature/BroadcastTest.php` → all existing tests still pass.
- **Done when:** M1 behaviour is unchanged and the instant rule is in place (new tests follow in T008 and T009).
- **Out of scope:** the viewer block, the log, the controller.
- **Note (2026-10-19):** Boost query: `search-docs` "testing time carbon freeze" (no Laravel API used; DB transaction/lock code unchanged). Submit, partial, idempotency and broadcast feature tests: 42 passed. `AllocationResult` needed no change.

### [x] T008 Prove expiry through the submit path (flow, no-write, replay)
- **Refs:** FR5.3, FR5.4, FR5.7 (server half), FR5.8, FR5.9, NFR5, AC-US1.1, AC-US1.3, AC-US1.4, AC-US2.2, AC-US2.5, AC-US7.1, AC-US7.2
- **Depends on:** T007
- **Files:** `tests/Feature/ExpiryFlowTest.php` (create, "added"), `tests/Feature/ExpiryNoWriteTest.php` (create, "added"), `tests/Feature/IdempotencyTest.php` (edit)
- **Do:** Use the existing helpers in `tests/Pest.php` (`poolWithRemaining`, `operatorNamed`, `freezeClock`) and advance the `FrozenClock`. `ExpiryFlowTest`: Ana (Bronze) submits Temporary 4 at T against pool 10 (201, granted 4, remaining 6, stored `type = temporary`); at T+24 h−1 ms with the pool fully taken Ben's request of 3 is granted 0; at T+24 h+1 ms it is granted 3; two Temporary entries expiring together both return; default type is Consumed; unknown type → 422 with nothing stored; partial Temporary (requested 6, only 4 left) stores granted 4. `ExpiryNoWriteTest`: record counts of `restocks`, `allocations`, `corrections` and `pools.version`; move the clock across the boundary; call the snapshot endpoint twice; counts and version are identical. `IdempotencyTest`: replay of a Temporary key at T+25 h returns a body identical to the first reply (`allocation` and `pool.remaining`), one allocation exists; the same key with a different amount, operator or type → 409 and nothing recorded.
- **Verify:** `APP php artisan test --compact tests/Feature/ExpiryFlowTest.php tests/Feature/ExpiryNoWriteTest.php tests/Feature/IdempotencyTest.php` → pass.
- **Done when:** AC-US2.2, AC-US2.5, AC-US7.1 and AC-US7.2 pass; AC-US1.1/1.3/1.4 pass for the server half.
- **Out of scope:** `viewer` in the response (T011) and `expires_at` (T011).
- **Note (2026-10-19):** Boost query: `search-docs` "testing time carbon freeze" (feature tests use existing `tests/Pest.php` helpers; no new API). `ExpiryFlowTest`, `ExpiryNoWriteTest`, `IdempotencyTest`: 18 passed.

### [x] T009 [P] Prove the window follows the tier at log time
- **Refs:** FR5.5, D3, AC-US6.1, AC-US6.2, M2 pending test (tier window)
- **Depends on:** T007
- **Files:** `tests/Feature/TierWindowTest.php` (create, "added"), `tests/Unit/CalculatorPendingTest.php` (edit: remove the tier-window todo)
- **Do:** Ben is Silver and submits Temporary 5 at T (stored `tier_at_log = silver`). Update Ben's user row to `gold` directly. Assert the units are still held at T+48 h−1 ms and returned at T+48 h (not T+72 h), reading remaining through the snapshot endpoint at an advanced `FrozenClock`. Ben (now Gold) then submits Temporary 2 at T2 and it expires at T2+72 h. Name the test "takes the expiry window from tier_at_log after the operator's tier changes".
- **Verify:** `APP php artisan test --compact tests/Feature/TierWindowTest.php tests/Unit/CalculatorPendingTest.php` → pass; only the M3 todos remain pending.
- **Done when:** AC-US6.1 and AC-US6.2 pass and no M2 todo remains in `CalculatorPendingTest.php` (AC-US9.1).
- **Out of scope:** any UI to change a tier.
- **Note (2026-10-19):** Boost query: `search-docs` "testing time carbon freeze". `TierWindowTest` passes; the tier-window todo is gone. Full suite: 242 passed, 4 todos.

**Checkpoint 1 (passed 2026-10-19: 242 passed, 4 M3 todos pending; the text below says five, the real count is four):** `APP php artisan test --compact` is green; the pending list shows only the five M3 todos; unit tests prove the ±1 ms boundaries, thresholds and tier window; submits and replays behave as in AC-US2.5 and AC-US7.1.

---

## Phase 2 — API and log (US1 server, US3, US4, US5, US7)

### [x] T010 Build `ViewerView`
- **Refs:** FR2, FR4, FR4.3, FR13, FR5.6, DD6, DD8, plan §6 snapshot `viewer`
- **Depends on:** T006
- **Files:** `app/Pool/ViewerView.php` (create), `tests/Unit/ViewerViewTest.php` (create, "added")
- **Do:** `ViewerView::for(PoolState $state, ?User $viewer): array` (inject `RulesConfig`). For an operator: `['operator_id' => id, 'status' => $state->statuses[id] ?? $rules->lowestStatus(), 'tier' => tier, 'window_seconds' => $rules->windowSeconds(tier), 'holdings' => [['allocation_seq' => seq, 'units' => n, 'expires_at' => ISO-8601 UTC with microseconds]]]` using `Expiries::holdings($state->effective, id)`. For a supervisor or null: `operator_id` null (as M1 does), `status` null, `tier` null, `window_seconds` null, `holdings` `[]`. Time format `Y-m-d\TH:i:s.u\Z`.
- **Verify:** `APP php artisan test --compact tests/Unit/ViewerViewTest.php` → pass: holdings only own and sorted soonest first, Consumed not listed, no allocations → lowest status and empty holdings, supervisor → nulls and `[]`.
- **Done when:** one builder serves both the snapshot and the submit response (DD6).
- **Out of scope:** wiring it anywhere.
- **Note (2026-10-19):** Boost query: `search-docs` "testing time carbon freeze" (no Laravel API beyond container resolution). Also added `app/Support/ApiTime.php` (one shared timestamp format helper, small decision within the task's intent) and `Expiries` is injected into `ViewerView`. `ViewerViewTest`: 5 passed.

### [x] T011 Add `expires_at` and `viewer` to the submit response
- **Refs:** FR5.7, FR2, FR4, DD3, DD6, AC-US1.1, AC-US7.1, AC-US9.3
- **Depends on:** T010, T008
- **Files:** `app/Http/Resources/AllocationResource.php` (edit), `app/Http/Controllers/Api/AllocationController.php` (edit), `app/Pool/AllocationResult.php` (edit if the controller needs more), `tests/Feature/SubmitAllocationTest.php` (edit)
- **Do:** `allocation.expires_at` = `RulesConfig::expiresAt(tier_at_log, effective_at)` formatted like the other times when `type = temporary` and `granted > 0`, else null. The controller adds `viewer` (`ViewerView::for($result->pool, $request->user())`) next to `pool` in `additional()`. Replays use the as-of state already inside `AllocationResult`, so the block is identical to the first reply. No existing field changes.
- **Verify:** `APP php artisan test --compact tests/Feature/SubmitAllocationTest.php tests/Feature/IdempotencyTest.php` → pass, with new assertions: Temporary 4 by Ana at T shows `expires_at = T + 24 h`; Consumed shows null; a zero-grant Temporary shows null; `viewer.holdings` has the new holding and `viewer.status` is present; replay at T+25 h has the same `viewer`.
- **Done when:** AC-US1.1 (server half) and AC-US7.1 pass with the new fields; M1 fields untouched (AC-US9.3).
- **Out of scope:** the snapshot, the log.
- **Note (2026-10-19):** Boost query: `search-docs` "testing time carbon freeze" (no new framework API; Eloquent resource gets one more key). Full suite 249 passed, 4 todos. Replay at T+25 h returns the same `viewer`.

### [x] T012 Add `server_time`, `next_expiry_at` and `viewer` to the snapshot
- **Refs:** FR2, FR4.2, FR13, FR5.10, DD5, DD6, AC-US3.1, AC-US3.3, AC-US4.3, AC-US4.5, AC-US9.3
- **Depends on:** T010
- **Files:** `app/Pool/SnapshotService.php` (edit), `tests/Feature/ViewerSnapshotTest.php` (create, "added")
- **Do:** In `build()`: take `$now = clock->now()` once, `$state = calculator->calculateAt($ledger, $now)`, return `server_time` (ISO, microseconds), `next_expiry_at` (`Expiries::nextDue($state->effective)` or null) and `viewer` from `ViewerView`. Keep the repeatable-read transaction and all M1 keys. Inject `Clock` and `ViewerView`. The `log` still comes from `LogQuery` (T013 adds expiry entries). Update the PHPDoc array shape.
- **Verify:** `APP php artisan test --compact tests/Feature/ViewerSnapshotTest.php tests/Feature/LogAndSnapshotTest.php` → pass. New cases: own holdings only and soonest first; Consumed never in holdings; status label for Ana (elevated at 10 held) and none about Ana for Ben; supervisor gets `status` null and `holdings` `[]`; `server_time` equals the frozen clock; `next_expiry_at` is the earliest future expiry and null when none; at E the holding is gone and remaining is up; operator with no allocations → lowest status, empty holdings.
- **Done when:** AC-US3.1, AC-US3.3, AC-US4.3, AC-US4.5 and AC-US9.3 hold.
- **Out of scope:** expiry entries in `log`.
- **Note (2026-10-19):** Boost query: `search-docs` "testing time carbon freeze". `ViewerSnapshotTest` + `LogAndSnapshotTest`: 24 passed. One M1 assertion in `LogAndSnapshotTest` (viewer block for an operator with no allocations) changed from `status: null` to the lowest status plus the new `tier` and `window_seconds` keys: M1 had status as a null placeholder for M2.

### [x] T013 Derive expiry log entries and add cursors
- **Refs:** FR3.2, FR3.3, DD4, PQ3, plan §6 "Log entry shape", AC-US5.1, AC-US5.3, AC-US5.4
- **Depends on:** T006, T012
- **Files:** `app/Pool/Log/LogEntry.php` (edit), `app/Pool/Log/LogQuery.php` (edit), `app/Http/Resources/LogEntryResource.php` (edit)
- **Do:** Read the real schema first (`database-schema` for `allocations`, `restocks`, `corrections`). `LogEntry`: `expiry(...)` also takes the operator `{id, name}` and the anchor seq; `fromAllocation(Allocation, ?CarbonImmutable $expiresAt)` adds `expires_at`; add `cursor(): string` (`"<seq>"` for stored entries, `"<anchor>x<allocation_seq>"` for expiry). `LogQuery::latest($pool, $limit, $cursor = null, $now)`: keep the three stored queries; add derived entries from the pool's Temporary allocations with `granted > 0` whose `expiresAt <= $now` (load them with their operator, compute `expiresAt` with `RulesConfig`); compute every anchor with **one** grouped query (highest `seq` among `allocations.effective_at`, `restocks.recorded_at`, `corrections.effective_at` that are `<= expiresAt`; anchor 0 if none); merge and sort descending by `(anchor, rank, allocation_seq)` where stored entries are `(seq, 0, 0)` and expiry entries `(anchor, 1, allocation_seq)`; apply the cursor as "strictly older than this key" (a bare integer `N` means `(N, 0, 0)`); fetch `limit + 1` for `has_more`. Pass `$now` from `SnapshotService` (snapshot and `logPage`) and from the broadcast path (which only sends allocation entries, so it passes a null `expiresAt`-aware factory call). `LogEntryResource`: every entry gets `cursor`; `allocation` gets `expires_at`; `expiry` gets `operator`; `expiry.at` is the expiry time. M1 keys are unchanged.
- **Verify:** `APP php artisan test --compact tests/Feature/LogAndSnapshotTest.php tests/Feature/LogEntryResourceTest.php tests/Feature/BroadcastTest.php` → the M1 tests pass after adjusting only assertions that compare whole entry arrays (they now also contain `cursor` and `expires_at`); then run `APP php artisan tinker --execute '…'` once with a seeded expired Temporary to see an `expiry` entry with the right `at` and `cursor`.
- **Done when:** the snapshot's `log` contains the derived entries in DD4 order (full tests in T014).
- **Out of scope:** the `before` parameter validation (T014), broadcasting expiry (never).
- **Note (2026-10-19):** Boost query: `database-schema` for allocations, restocks, corrections (columns, indexes, `effective_at`/`recorded_at` timestamptz(6)) plus `search-docs` "testing time carbon freeze". Decisions within intent: `LogQuery::latest($pool, $limit, $now, ?$cursor)` (required arg before the optional one); `AllocationService` now takes `RulesConfig` so the broadcast entry carries `expires_at` (T020 needs it to lower the next-expiry timer); the anchor query is one `VALUES` + `GREATEST` SQL statement (Postgres). The M1 whole-entry assertions in `LogEntryResourceTest` and `BroadcastTest` gained `cursor`/`expires_at`; the expiry resource test gained `operator`. The suggested tinker check was replaced by the real-database feature tests in T014 (Boost rules prefer tests over tinker, and tinker would create rows). Full suite 256 passed, 4 todos.

### [x] T014 Accept cursors in `LogController` and prove the expiry log
- **Refs:** FR3.2, FR3.3, DD4, AC-US2.7, AC-US5.1, AC-US5.2 (server half), AC-US5.3, AC-US5.4
- **Depends on:** T013
- **Files:** `app/Http/Controllers/Api/LogController.php` (edit), `app/Pool/SnapshotService.php` (edit `logPage` signature), `tests/Feature/ExpiryLogTest.php` (create, "added")
- **Do:** `before` validates as a string matching `^\d+(x\d+)?$` (a bare integer stays valid, `min` 1 for the integer form); anything else → 422 through the existing error format. Pass the string to `SnapshotService::logPage`. `ExpiryLogTest`: no expiry entry at E−1 ms; at E one entry naming Ana with `units` 4, `allocation_seq`, `at = E`, `cursor`; exactly one over repeated snapshots and over paging with `before=<cursor>` and `limit=1`; none for Consumed and none for a zero-grant Temporary; ordering: the expiry sits directly above the newest stored entry received at or before E and below later ones, including a fixture where an entry has an `effective_at` slightly out of `seq` order; a bare integer `before` behaves as in M1; `before=abc` → 422; Ana sees the same entry as Ben (the highlight is client-side).
- **Verify:** `APP php artisan test --compact tests/Feature/ExpiryLogTest.php tests/Feature/LogAndSnapshotTest.php` → pass.
- **Done when:** AC-US5.1, AC-US5.3, AC-US5.4 and AC-US2.7 pass (server half).
- **Out of scope:** how the screen renders it.
- **Note (2026-10-19):** Boost query: `search-docs` "testing time carbon freeze" (validation uses the existing `Validator::make` + regex rule pattern). `before` regex: `^(\d+x\d+|[1-9]\d*)$`. `ExpiryLogTest` + `LogAndSnapshotTest` pass; full suite green.

### [x] T015 [P] Document the additive contract
- **Refs:** F3, AC-US9.3, plan §6
- **Depends on:** T011, T013, T014
- **Files:** `docs/api-contract.md` (edit)
- **Do:** Document, marked "M2 (additive)": snapshot `server_time`, `next_expiry_at`, `viewer.tier`, `viewer.window_seconds`, `viewer.holdings`, `viewer.status` (values, null for a supervisor); submit response `allocation.expires_at` and `viewer`; log entry `cursor` and `expires_at`; the `expiry` entry fields (`operator`, `at`); `before` accepting a cursor string; the rule that expiry broadcasts nothing; the evaluation-instant rule (DD2) in one short paragraph. No M1 text is removed. Documentation task: no Boost query.
- **Verify:** compare against real responses: `docker compose up -d --wait`, then `curl -s -H 'X-Demo-User: 1' localhost:8080/api/pools/1 | python3 -m json.tool` → every key in the new section is present.
- **Done when:** the contract matches the responses.
- **Out of scope:** changing existing sections.
- **Note (2026-10-19):** Documentation task: no Boost query. Added one "M2 (additive)" section to `docs/api-contract.md` (no M1 text removed). Checked against a live `curl -s -H 'X-Demo-User: 1' localhost:8080/api/pools/1`: `server_time`, `next_expiry_at`, `viewer.tier`, `viewer.window_seconds`, `viewer.holdings`, `viewer.status` and log `cursor` are present; `allocation.expires_at`, `viewer` on submit and the expiry entry are covered by the T011/T014 tests.

### [x] T016 Concurrency test: requests straddling an expiry (hard rule)
- **Refs:** NFR1, FR5.9, DD2, AC-US2.8
- **Depends on:** T011, T012
- **Files:** `tests/Concurrency/ParallelExpiryTest.php` (create, "added")
- **Do:** Follow `tests/Concurrency/ParallelAllocationTest.php` (own pool, committed data, `Http::pool` against the running stack, group `concurrency`, cleans up). Create a pool with capacity 100, then insert **directly in the database** a Temporary allocation of 40 units for a Bronze operator with `effective_at = now − 24 h + ~3 s` (so it expires about 3 s into the test) and a Consumed allocation of 60, using the shared ledger sequence for `seq`. Send 50 requests of 3 units in 10 waves of 5, one wave about every 600 ms, so waves land before and after the expiry. Then assert: every response has `remaining >= 0`; replaying the ledger in `seq` order with `calculateAt` at `max(effective_at so far)` equals each response's `pool.remaining`; requests received before the expiry are not granted the 40 units and some received after are; total granted never exceeds what was free at each instant; no request returned an error other than none expected.
- **Verify:** `docker compose up -d --wait && APP php artisan test --group=concurrency --filter=ParallelExpiryTest` → pass; run it 3 times in a row → pass each time.
- **Done when:** AC-US2.8 and NFR1 hold with expiry in flight.
- **Out of scope:** changing the service to make it pass without telling the user (if it fails, mark `[!]` and report).
- **Note (2026-10-19):** Boost query: `search-docs` "testing time carbon freeze" (follows the existing concurrency test; fixture rows inserted through `LedgerSequence::next` inside a transaction). Ran 3 times in a row: pass each time (127 assertions; 25 requests received before the expiry, 25 after; 40 granted; none of the 40 to a request evaluated before the expiry). The pool version ends at 53 because the fixture adds two ledger entries.

### [x] T017 Re-run the M1 concurrency and latency targets with Temporary requests mixed in
- **Refs:** NFR2, AC-US9.2, M1 AC-US5.1, M1 AC-US4.5
- **Depends on:** T016
- **Files:** `tests/Concurrency/ParallelAllocationTest.php` (edit)
- **Do:** Add one case beside the existing 50×3 case: the same 50 requests of 3 against 100, but every second request has `type = temporary` (all Bronze). Same assertions as the existing case (100 granted in total, exactly one partial of 1, 33 full, 16 none, remaining 0, p95 submit-to-confirmed ≤ 1 s). Do not change the existing cases.
- **Verify:** `APP php artisan test --group=concurrency` → all pass (existing and new); the p95 printed or asserted is ≤ 1 s.
- **Done when:** adding expiry and status did not slow or break the M1 hard rule (NFR2).
- **Out of scope:** tuning php-fpm (only if p95 fails, and then report).
- **Note (2026-10-19):** Boost query: `search-docs` "testing time carbon freeze". Added one case; `fireTogether` gained an optional `everySecondTemporary` flag (existing cases unchanged). Concurrency group: 8 passed (721 assertions); p95 0.630 s mixed vs 0.655 s all-consumed, both ≤ 1 s.

**Checkpoint 2:** `APP php artisan test --compact` and `APP php artisan test --group=concurrency` are green. With the stack up, `curl -s -X POST -H 'X-Demo-User: 1' -H 'Idempotency-Key: demo-1' -H 'Content-Type: application/json' -d '{"requested":4,"type":"temporary"}' localhost:8080/api/pools/1/allocations` returns `allocation.expires_at` and a `viewer` with one holding; `GET /api/pools/1` shows `server_time`, `next_expiry_at`, `viewer` and `log` entries with `cursor`.

---

## Phase 3 — Screens (US1 client, US2, US3, US4, US5)

JS tasks are pure JavaScript, CSS or Blade work: the Boost query requirement applies to the Blade and PHP file in T023; the others say so in their note.

### [x] T018 [P] Add `time-left.js`
- **Refs:** FR5.6, FR5.7, FR13, A-M2.4, AC-US1.1, AC-US1.2, AC-US3.4
- **Depends on:** T001
- **Files:** `resources/js/time-left.js` (create), `resources/js/__tests__/time-left.test.js` (create)
- **Do:** Pure functions, no DOM, no `Date.now()` (take instants as arguments): `serverOffsetMs(serverTimeIso, clientNowMs)`; `timeLeftText(msLeft)` → `"3 h"` for 2 h or more, `"45 min"` for 1 min to under 2 h, `"under 1 min"` below 1 min, `"now"` at 0 or less; `windowText(seconds)` → `"24 h"` for 86400, `"2 min"` for 120, `"30 s"` below a minute; `returnTimeText(expiresIso, serverNowMs, timeZone)` → `"10:00 today"`, `"10:00 tomorrow"` or `"20 Oct 10:00"` computed in the given time zone with `Intl.DateTimeFormat`. Follow the style of `form-rules.js`.
- **Verify:** `NODE npm test` → the new tests pass: texts at 3 h, 2 h, 1 h 59 min, 59 s, 0; window texts; return text today/tomorrow/other in `UTC`; a client clock 1 h ahead gives the same time left once the offset is applied.
- **Done when:** AC-US3.4 (offset) and the text parts of AC-US1.1 and AC-US1.2 are covered.
- **Out of scope:** DOM, timers.
- **Note (2026-10-19):** Pure JavaScript, no Boost query. `NODE npm test`: new `time-left.test.js` passes (texts at 3 h, 2 h, 1 h 59 min, 59 s, 0; window texts; today/tomorrow/date in UTC and Asia/Singapore; client clock ±1 h gives the same time left with the offset).

### [x] T019 [P] Add `expiry-timer.js`
- **Refs:** FR5.10, NFR6, DD5, AC-US2.6, AC-US3.2
- **Depends on:** T001
- **Files:** `resources/js/expiry-timer.js` (create), `resources/js/__tests__/expiry-timer.test.js` (create)
- **Do:** `createExpiryTimer({ onDue, setTimer = setTimeout, clearTimer = clearTimeout, nowMs })` returns `{ schedule(dueAtMs | null), dispose() }`. `schedule` cancels any pending timer, does nothing for null, and otherwise arms a timer for `max(0, dueAtMs + 250 − nowMs())` that calls `onDue` once. Delays above 2 147 000 000 ms are capped and re-armed when they fire. The caller passes `dueAtMs` already converted to the client's clock using the server offset.
- **Verify:** `NODE npm test` → new tests (fake timers) pass: fires once 250 ms after the due instant; a new `schedule` replaces the old; due in the past fires at once; null arms nothing; the long-delay cap re-arms; `dispose` cancels.
- **Done when:** AC-US2.6 and AC-US3.2 (client half) are covered.
- **Out of scope:** fetching the snapshot.
- **Note (2026-10-19):** Pure JavaScript, no Boost query. `expiry-timer.test.js` uses a hand-made fake timer (no Node mock timers needed): fires once 250 ms after due, replace, past due, null, long-delay cap, dispose.

### [x] T020 Teach `pool-state.js` about cursors, viewer, server offset and next expiry
- **Refs:** FR5.10, FR2, FR13, DD5, DD6, AC-US3.2, AC-US5.3
- **Depends on:** T012, T013
- **Files:** `resources/js/pool-state.js` (edit), `resources/js/__tests__/pool-state.test.js` (edit)
- **Do:** Key log entries by `entry.cursor` (stored `"12"`, expiry `"13x12"`) instead of `seq`; order new entries by the same key as the server (`(anchor, rank, allocation_seq)` parsed from the cursor); replace `oldestSeq` use with `oldestCursor(state)` (the cursor of the last row, or null) and keep `oldestSeq` exported until `dashboard.js` stops using it (T024 removes the use). State gains `viewer` (from snapshot), `viewerVersion`, `serverOffsetMs` (`serverOffsetMs(snapshot.server_time, now)`), `nextExpiryAt` (snapshot value as epoch ms in server time). `applySnapshot` sets them on both the newer-version path and the same-version path (the same-version poll must also refresh `viewer`, `nextExpiryAt` and the expiry entries). `applyOwnResult` applies `result.viewer` only when `result.pool.version >= state.viewerVersion`, then sets `viewerVersion`. `applyEvent` lowers `nextExpiryAt` to `entry.expires_at` when that is earlier and in the future; it never raises it. `entryFromAllocation` includes `expires_at` and a cursor built from `seq`. Export `nextExpiryAt(state)`.
- **Verify:** `NODE npm test` → all M1 reducer tests still pass (update fixtures to carry `cursor`), and new tests pass: an expiry entry arriving in two snapshots is stored once and ordered by cursor; a newer snapshot replaces `viewer`; a stale own result does not overwrite a newer `viewer`; a duplicate or out-of-order event does not change `nextExpiryAt`; an earlier `expires_at` from an event lowers it.
- **Done when:** AC-US5.3 and AC-US3.2 hold in the reducer.
- **Out of scope:** the DOM and the timer wiring (T024).
- **Note (2026-10-19):** Pure JavaScript, no Boost query. `time-left.js` now exports `parseInstant` for reuse. Entries without a `cursor` fall back to `String(seq)` so an older payload still works; M1 fixtures now carry cursors. `oldestSeq` is still exported until T024. JS suite: 105 passed.

### [x] T021 [P] Render expiry entries and the temporary marker in `log-renderer.js`
- **Refs:** FR3.2, FR3.3, NFR4 (text, not colour), AC-US5.1, AC-US5.2, AC-US5.5
- **Depends on:** T001
- **Files:** `resources/js/log-renderer.js` (edit), `resources/js/__tests__/log-renderer.test.js` (edit)
- **Do:** `expiry`: label `"Expiry"`, text `"<operator name>: <n unit(s)> returned (#<allocation_seq>)"`, `own` true only when `entry.operator.id` equals the viewer id (same rule as allocations). `allocation` with `type === 'temporary'` appends `" · temporary"` to its text; consumed text is unchanged so M1 tests keep passing. Keep the unknown-kind fallback.
- **Verify:** `NODE npm test` → new tests pass: expiry text and label; own flag for Ana and not for Ben; temporary allocation text; the four-kinds test from M1 still passes.
- **Done when:** AC-US5.1, AC-US5.2 (render half) and AC-US5.5 (text label) are covered.
- **Out of scope:** CSS.
- **Note (2026-10-19):** Pure JavaScript, no Boost query. `log-renderer.test.js`: expiry text/label/own, temporary marker; the M1 expiry-text test, the 'never own' test and the 'labels are words' fixture were updated to the new expiry shape (operator added). JS suite: 95 passed (was 80).

### [x] T022 Send `type` and show the return time in the submit flow
- **Refs:** FR5.6, FR5.7, FR1.9 (M1) superseded by PQ1 selector, AC-US1.1, AC-US1.3, AC-US4.7 (M1 text for the form)
- **Depends on:** T018, T020
- **Files:** `resources/js/api.js` (edit), `resources/js/__tests__/submit-flow.test.js` (edit)
- **Do:** `createSubmitFlow({... getType })` reads the chosen type (default `'consumed'`) and sends it in the JSON body (`{requested, type}`); a retry resends the same type and the same key. The `done` status gains `expiresAt` (from `result.allocation.expires_at`) and the banner text builder returns `"4 granted · returns 10:00 tomorrow"` when units were granted and an `expiresAt` exists, `"4 of 6 granted · returns …"` for a partial Temporary grant, and the existing M1 wording when nothing was granted or the type is consumed. Use `returnTimeText` from T018 with the server clock offset taken from state. On a confirmed result also pass `result.viewer` to `applyOwnResult`. Keep the pending, offline and conflict behaviour as is.
- **Verify:** `NODE npm test` → M1 `submit-flow` tests still pass, and new tests pass: the request body carries `type`; retry reuses the same key and type; banner texts for full Temporary, partial Temporary, zero grant and Consumed.
- **Done when:** AC-US1.1 and AC-US1.3 text behaviour holds in tests.
- **Out of scope:** the form markup.
- **Note (2026-10-19):** Pure JavaScript, no Boost query. `api.js`: `getType` option, `type` in the body and kept in the attempt for retries, `expiresAt`/`type` on the done status, and an exported `doneText` builder (the banner getter in `dashboard.js` now calls it with the server-corrected clock). `applyOwnResult` already takes `result.viewer` (T020). JS suite: 109 passed.

### [x] T023 Add the type choice, status line, holdings list and expiry rows to the page
- **Refs:** FR5.6, FR2, FR4.3, FR13, NFR3, NFR4 (text labels), AC-US1.2, AC-US3.1, AC-US4.4, AC-US3.3
- **Depends on:** T012, T018
- **Files:** `resources/views/dashboard.blade.php` (edit), `resources/css/app.css` (edit), `tests/Feature/DashboardViewTest.php` (edit)
- **Do:** Search Boost docs for Blade first (`blade directives`). In the request panel add a two-option group "Consumed" / "Temporary" (radio inputs with visible labels, Consumed checked), bound to a `type` Alpine value (T024 provides it), and a hint under it that reads `Returns after {windowText}` only while Temporary is chosen. In the Pool panel add `<p id="status">Status: <span x-text="statusText"></span></p>` (operators only, text label). Add a "Your temporary holdings" panel (`id="holdings"`, operators only) with rows `<units> · expires in <time left>` and an empty state "No temporary holdings". Use only the existing tokens (`tokens.css`); no new colour values; row and option touch targets at least the size the M1 buttons use. No type choice or holdings for a supervisor. Do not add new JS here.
- **Verify:** `APP php artisan test --compact tests/Feature/DashboardViewTest.php` → pass with new assertions: the page has both type options with Consumed checked, `#status`, `#holdings` and its empty-state text; M1 assertions (embedded snapshot, placeholder supervisor tab) still pass. The PRD's M1 criterion "no selector" (M1 AC-US4.7) is superseded by PQ1; update that M1 assertion in this test and say so in the note.
- **Done when:** AC-US1.2 (markup), AC-US3.1 (markup) and AC-US4.4 (text label) are covered.
- **Out of scope:** behaviour (T024), contrast and touch-target audit (M4).
- **Note (2026-10-19):** Boost query: `search-docs` "blade directives" (plus "blade displaying data escaping", "blade conditionals"; the markup uses Alpine attributes only, Blade just prints the static structure). `DashboardViewTest`: 9 passed. The M1 assertion "no selector" (M1 AC-US4.7) was replaced because PQ1 supersedes it: the test now expects the two radio options with Consumed checked, plus `#status`, `#holdings`, the empty-state text and `#type-hint`. CSS uses existing tokens only.

### [x] T024 Wire the screen: type, status, holdings tick, expiry timer, cursor paging
- **Refs:** FR5.6, FR5.10, FR2, FR13, NFR6, DD5, DD6, AC-US1.2, AC-US2.6, AC-US3.2, AC-US3.4, AC-US4.1 (screen), AC-US5.2
- **Depends on:** T019, T020, T021, T022, T023
- **Files:** `resources/js/dashboard.js` (edit)
- **Do:** Pure JavaScript, no Boost query. Add Alpine state `type` ('consumed'), `statusText` (from `state.viewer.status`), `holdings` rows with `timeLeft` text, `windowHint`. Keep a `nowServerMs` that is refreshed every second with `Date.now() + state.serverOffsetMs`; compute each holding's time left from it and drop a row when it reaches 0. Create the expiry timer with `createExpiryTimer({ onDue: () => this.refresh(), nowMs: () => Date.now() })` and call `schedule(...)` with `nextExpiryAt` converted to the client clock after every snapshot, own result and event (re-arm to the minimum). `refresh()` stays the single snapshot fetch (it already coalesces); the 10 s poll stays. "Show older" uses `oldestCursor(state)` for the `before` query. Pass `getType` to `createSubmitFlow`. Remove the last use of `oldestSeq` and delete that export from `pool-state.js` if nothing else uses it. Build the assets.
- **Verify:** `NODE npm test` → all JS tests pass; `NODE npm run build` → exits 0; then in a browser at `http://localhost:8080` as Ana: choose Temporary, request 1 → banner "1 granted · returns …", the holding row and the status line appear without a reload; reload the page → the same values appear (server render). Record this check in the note.
- **Done when:** AC-US1.2, AC-US3.4 (the clock offset is used) and the on-screen halves of AC-US4.1 and AC-US5.2 work in a real browser.
- **Out of scope:** the demo stack (T025).
- **Note (2026-10-19):** Pure JavaScript, no Boost query. `NODE npm test` 109 passed; `NODE npm run build` exits 0. Deviations within intent: `pool-state` functions take an extra `epochNow` (Date.now()) beside the gap clock `now` (performance.now()), because the server offset and 'expiry in the future' checks are on the epoch clock (mixing them would give a wrong offset); `createSubmitFlow` has a matching `epochNow` option; `refresh({rerunIfBusy})` re-runs once when an expiry fires while a snapshot is already in flight; `oldestSeq` and its test were removed. Browser check: headless Chrome is installed under ~/.cache/ms-playwright but cannot start (missing libasound.so.2, and no system packages can be installed here), so I could NOT drive a real browser. Instead I ran the real `dashboard.js` in the node container against a simulated server and clock (scratch harness, not in the project): choose Temporary, request 4 → banner '4 granted · returns 10:02 today', holding row '4 · 2 min', status line and 'Returns after 2 min' hint; at +61 s 'under 1 min'; at +121 s the holding is gone, remaining back to 100, one extra snapshot fetch from the expiry timer; request body {requested:4,type:'temporary'}. The real-browser check of this task is still open and is repeated in T027.

**Checkpoint 3 (2026-10-19: JS 109 passed, build OK, wiring verified by simulation; no real browser available, see T024 note):** in one browser as Ana, request Temporary units, see the return time in the banner, the holding with "expires in …", and the status line; the log row says "temporary". `NODE npm test` and `APP php artisan test --compact` are green.

---

## Phase 4 — Demo, hardening, docs

### [x] T025 Short-window demo stack and the expiry demo script
- **Refs:** FR14, DD7, PQ2, AC-US8.1, AC-US2.6 (server half), NFR6
- **Depends on:** T024
- **Files:** `docker-compose.demo.yml` (create), `scripts/demo-expiry.sh` (create), `tests/Live/expiry-demo.mjs` (create)
- **Do:** `docker-compose.demo.yml` overrides only `environment: POOL_WINDOW_SCALE: "0.0013889"` for the `app` and `migrate` services (24 h → 2 min, 48 h → 4 min, 72 h → 6 min) and states in a comment that it is a demo switch. Because `-f` disables the automatic dev override, `scripts/demo-expiry.sh` runs `docker compose -f docker-compose.yml -f docker-compose.override.yml -f docker-compose.demo.yml up -d --build --wait`, prints the windows and how to go back (`docker compose up -d --wait`). `tests/Live/expiry-demo.mjs` (Node, run from the `tools` profile like `live-latency.mjs`): reads `GET /api/pools/1` as Ana and exits with a clear message unless `viewer.window_seconds <= 600`; submits a Temporary request of 1; polls the snapshot every 200 ms; asserts the holding is gone, remaining is back up and exactly one `expiry` entry exists within 2 s after `allocation.expires_at`; prints the measured delay.
- **Verify:** `bash scripts/demo-expiry.sh && NODE node tests/Live/expiry-demo.mjs` → exits 0 after about 2 minutes and prints a delay ≤ 2 s; then `docker compose up -d --wait` returns the stack to 24/48/72 h (`curl -s -H 'X-Demo-User: 1' localhost:8080/api/pools/1` shows `window_seconds` 86400).
- **Done when:** AC-US8.1 works without code edits and the server half of AC-US2.6 is measured.
- **Out of scope:** changing `.env.docker` defaults, any app code.
- **Note (2026-10-19):** Shell/YAML/Node task: no Boost query. `bash scripts/demo-expiry.sh` → stack up with window_seconds 120 for Ana; `docker compose --profile tools run --rm node node tests/Live/expiry-demo.mjs` → OK after 2 min 1 s, 'expired: holding gone, remaining back to 100, one expiry entry, 49 ms after expires_at', measured delay 49 ms (limit 2000 ms; polling every 200 ms, so a snapshot sees it within about one poll). The script counts expiry entries for its own allocation seq only, because earlier demo rows can also have expired. `docker compose up -d --wait` afterwards → `window_seconds` 86400 again. Note: the earlier Checkpoint 2 curl left one expired Temporary 4 (entry #2) in the dev pool; `scripts/verify.sh` reseeds the data (T026/T027).

### [x] T026 Update `scripts/verify.sh` and the README
- **Refs:** F3, FR14, A-M2.1–A-M2.6, AC-US9.2
- **Depends on:** T025
- **Files:** `scripts/verify.sh` (edit), `README.md` (edit)
- **Do:** `verify.sh` already runs unit + feature, concurrency, JS, live-latency and smoke; make sure the new test files run in it (they are picked up by the existing commands; add `ParallelExpiryTest` explicitly only if the suite filter excludes it) and do not run `expiry-demo.mjs` there (it needs the demo stack and takes minutes). README: add a short "Expiry and status (M2)" section: how to run the 2-minute demo (T025), what to look at, the manual checks (two browsers; 360 px and 1280 px without horizontal scroll; stop `reverb` and confirm the screen still corrects at expiry and by the 10 s poll; device clock 1 h ahead still shows the server's time left), the working assumptions A-M2.1–A-M2.6, the evaluation-instant rule, the one-row log shift, and that background browser tabs may refresh late. Documentation task: no Boost query.
- **Verify:** `bash scripts/verify.sh` → exits 0; the README's commands, run once on a copy of the steps, work as written.
- **Done when:** a new reader can run and check M2 from the README.
- **Out of scope:** M4's final README polish.
- **Note (2026-10-19):** Documentation/shell task: no Boost query. `verify.sh` already picks up every new test file (the concurrency step uses `--group=concurrency`, which includes `ParallelExpiryTest`), so only a comment was added saying it does not run the expiry demo. README gained 'Expiry and status (M2)': demo commands, manual checks 7–10, assumptions A-M2.1–A-M2.6, the evaluation-instant rule, the one-row log shift and the background-tab note. `bash scripts/verify.sh` → 5/5 PASS in 59 s (271 unit/feature passed + 4 todos, concurrency p95 0.745 s, 109 JS passed, live 10/10 within 2000 ms). The README's demo commands were run as written in T025.

### [x] T027 Run everything and the manual checks
- **Refs:** NFR2, NFR3, NFR6, AC-US2.6, AC-US3.2, AC-US3.4, AC-US4.1, AC-US5.2, AC-US5.5, AC-US8.1, AC-US9.2
- **Depends on:** T026
- **Files:** none (check only; record results in the note)
- **Do:** Run `bash scripts/verify.sh`. Then on the demo stack (T025) with two browsers: (1) Ana requests Temporary 4, Ben watches; at expiry both screens show remaining +4, Ana's holding disappears, her status updates, an "Expiry" row appears on both, all within about 2 s of the time shown; (2) repeat at 360 px and 1280 px, no horizontal scroll; (3) stop `reverb` (`docker compose stop reverb`) and confirm the screen still corrects at expiry; (4) set a device clock 1 h ahead and confirm "expires in" and the moment the row disappears follow the server. Restore the normal stack afterwards.
- **Verify:** `bash scripts/verify.sh` → exit 0; each manual check recorded as pass or fail with what was seen.
- **Done when:** AC-US2.6, AC-US3.2, AC-US3.4, AC-US5.5, AC-US8.1 and AC-US9.2 are confirmed. Any failure is marked `[!]` with the observation.
- **Out of scope:** fixing found problems without reporting them first.
- **Closed on the user's word (2026-10-20):** the user said to assume the manual browser checks were done. The assistant did not run them; the evidence below is what it did run. Original blocker: `bash scripts/verify.sh` → exit 0 (5/5, see T026). The four manual checks need a real browser and none is available to the assistant: the Chromium under `~/.cache/ms-playwright` cannot start (`libasound.so.2` is missing and no system packages can be installed here). What was checked instead, by simulation and API: (1) two-browser expiry at the API level: `tests/Live/expiry-demo.mjs` on the demo stack → expiry visible 49 ms after `expires_at`, holding gone, +1 unit, one `expiry` entry; the screen logic (real `dashboard.js` against a simulated server and clock) removes the holding, raises remaining and refetches at expiry. (2) 360 px / 1280 px layout: NOT checked. (3) `reverb` stopped: NOT checked in a browser; the expiry timer does not depend on Reverb (simulation passes with no socket). (4) device clock ±1 h: the simulated dashboard gives identical "expires in", banner and expiry moment at skew 0, +1 h and −1 h. **Needs:** a person to run README checks 7–10 on the demo stack (`bash scripts/demo-expiry.sh`, then `docker compose up -d --wait`), or approval to install `libasound2` so the assistant can drive headless Chromium. AC-US2.6, AC-US3.2, AC-US3.4, AC-US5.5 and AC-US8.1 are therefore only partly confirmed.

### [x] T028 Re-run the rules check and the traceability self-check
- **Refs:** plan §2, laravel-boost rules 1–6
- **Depends on:** T027
- **Files:** `workflow/slices/m2-expiry-and-status/plan.md` (edit §2 and §12 only)
- **Do:** Re-read `workflow/rules/laravel-boost.md` and `CLAUDE.md`; check mechanically that every PHP, Blade, config and test task note (T002–T017, T023) names the Boost query used; list any Boost call that failed or was skipped. Check that every PRD ID in §10 of the PRD appears in the Coverage table below with no empty cell. Record the result in plan §2 (dated) and any divergence from the plan in §12 as dated lines (never rewrite history).
- **Verify:** `grep -c "Boost query" workflow/slices/m2-expiry-and-status/tasks.md` shows a note on each Laravel task; the Coverage table has no empty cell.
- **Done when:** plan §2 has the dated re-run and §12 lists every deviation found.
- **Out of scope:** changing the PRD or `requirement.md`.
- **Note (2026-10-19):** Documentation task: no Boost query. Ran although T027 (its dependency) is blocked, because its checks do not depend on the manual browser results; plan §2 says so and that T027 is still open. Result: all PHP/Blade/config/test task notes name a Boost query (`grep -c "Boost query" tasks.md` → 32); the Coverage table covers every requirement and AC ID of PRD §10 with no empty cell (checked by script); plan §2 has the dated re-run and §12 lists the deviations.

**Checkpoint 4:** `bash scripts/verify.sh` is green; on the demo stack two browsers watch a Bronze window of 2 minutes end (remaining up, holding gone, status drops, "Expiry" row); the README checklist is done.

---

## Coverage

| PRD ID | Task(s) |
|---|---|
| FR5 | T004, T008, T016 |
| FR5.1 | T002, T004, T009, T011 |
| FR5.2 | T004, T005 |
| FR5.3 | T004, T008 |
| FR5.4 | T004, T008 |
| FR5.5 | T004, T009 |
| FR5.6 | T018, T022, T023, T024 |
| FR5.7 | T011, T018, T022 |
| FR5.8 | T007, T008, T011 |
| FR5.9 | T007, T008, T016 |
| FR5.10 | T012, T019, T020, T024, T025 |
| FR2 | T010, T012, T020, T023, T024 |
| FR4 | T002, T005, T010 |
| FR4.1 | T002, T005 |
| FR4.2 | T005, T012 |
| FR4.3 | T010, T023 |
| FR13 | T006, T010, T012, T023, T024 |
| FR3.2 | T006, T013, T014, T021 |
| FR3.3 | T006, T013, T014 |
| F3 | T003, T004, T005, T009, T015, T026 |
| FR14 | T002, T025, T026 |
| NFR1 | T007, T016 |
| NFR2 | T017 |
| NFR3 | T023, T027 |
| NFR5 | T007, T008 |
| NFR6 | T019, T024, T025, T027 |
| NFR-M2.1 | T003, T004 |
| NFR4 | N/A in M2 (M4); text labels in T021, T023 |
| AC-US1.1 | T008, T011, T018, T022 |
| AC-US1.2 | T018, T023, T024 |
| AC-US1.3 | T008, T022 |
| AC-US1.4 | T008 |
| AC-US2.1 | T004 |
| AC-US2.2 | T008 |
| AC-US2.3 | T004 |
| AC-US2.4 | T004 |
| AC-US2.5 | T008 |
| AC-US2.6 | T019, T024, T025, T027 |
| AC-US2.7 | T004, T014 |
| AC-US2.8 | T016 |
| AC-US3.1 | T012, T023 |
| AC-US3.2 | T019, T020, T024, T027 |
| AC-US3.3 | T012 |
| AC-US3.4 | T018, T024, T027 |
| AC-US4.1 | T005, T024 |
| AC-US4.2 | T005 |
| AC-US4.3 | T012 |
| AC-US4.4 | T023 |
| AC-US4.5 | T005, T012 |
| AC-US4.6 | T005 |
| AC-US5.1 | T014, T021 |
| AC-US5.2 | T021, T024, T027 |
| AC-US5.3 | T014, T020 |
| AC-US5.4 | T014 |
| AC-US5.5 | T021, T027 |
| AC-US6.1 | T009 |
| AC-US6.2 | T009 |
| AC-US7.1 | T007, T008, T011 |
| AC-US7.2 | T008 |
| AC-US8.1 | T025, T027 |
| AC-US9.1 | T004, T005, T009 |
| AC-US9.2 | T001, T017, T026, T027 |
| AC-US9.3 | T011, T012, T015 |

Plan decisions without a PRD ID, and the tasks that implement them: DD1 → T002, T004; DD2 → T007; DD3 → T007, T011; DD4 → T013, T014, T020; DD5 → T012, T019, T024; DD6 → T010, T011, T020; DD7 → T002, T025; DD8 → T005, T010; DD9 → T004, T005, T009.

## Blocked / deferred
| Task | Reason | Needs |
|---|---|---|
| none | | |
