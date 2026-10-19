# Tasks: M3 — Supervisor corrections and restock

| Field | Value |
|---|---|
| Slice | M3 |
| PRD | [prd.md](prd.md) |
| Plan | [plan.md](plan.md) |
| Status | Approved |
| Date | 2026-10-20 |

## Conventions
- Read before every task: `workflow/rules/*` (`laravel-boost.md`), `CLAUDE.md`, the task's `Refs` in [plan.md](plan.md) and [prd.md](prd.md), and `.ai/rules/index.md` if that directory exists (it did not on 2026-10-20).
- **Laravel Boost is mandatory for every PHP, Blade, config, migration and Pest task.** Before creating or editing such a file: search the Boost documentation (`search-docs`) for the installed versions, inspect the real schema with `database-schema` / `database-query` instead of guessing, and name the Boost query in the task's completion note. Queries suggested per task are in plan §9 ("Boost queries"). Boost has no `tinker` tool in the installed version; use `php artisan tinker --execute '…'` if a behaviour check is needed. Pure JavaScript, CSS, shell and documentation tasks say so in their note instead.
- Everything runs in Docker. Shorthand (add `--user "$(id -u):$(id -g)"` to `APP` when a command writes files):
  - `APP` = `docker compose run --rm app`
  - `NODE` = `docker compose --profile tools run --rm node`
  - Unit + feature tests: `APP php artisan test --compact` (add a file path or `--filter=` for the narrowest run). Concurrency: `APP php artisan test --group=concurrency` against the running stack (`docker compose up -d --wait`). JS: `NODE npm test`.
- After editing PHP, run `APP vendor/bin/pint --format agent` on the named files (the folder is not a git repository, so `--dirty` does not work; M2 recorded this). After editing assets, run `NODE npm run build` if the UI does not show the change.
- The folder is not a git repository; do not run `git init`. Progress is tracked by ticking markers in this file.
- Stop at each checkpoint for human review. Do not start the next phase before the user says so.
- Where a plan file path is named, use it exactly. Files not listed in plan §4 are marked "(added)".
- **Contracts only grow (AC-US10.3).** Never rename or remove an M1/M2 field, except the removal of the `PUT /capacity` stub that the PRD and plan approved (PQ3, DD11). Exactly one migration is added (T002). No endpoint beyond plan §6 is added. If something looks missing, mark the task `[!]` and report it.
- **Approved test changes (plan §11):** the four M3 `->todo()` tests in `tests/Unit/CalculatorPendingTest.php` become real tests of the same names (T007 converts three, T011 converts "refuses a correction that raises an allocation beyond the free units" and then removes the empty file); the 501 checks for the M3 endpoints in `tests/Feature/RolesAndStubsTest.php` are removed in T019. No other test is deleted or weakened; if an M1/M2 test must change for another reason, change its assertion only to the new additive shape, and log it in plan §12.
- Time in tests: use `FrozenClock` / the `freezeClock()` helper and `calculateAt()` with explicit instants. Never `sleep()` except where a task says real time is needed (T021).
- Worked data used by many tests (PRD §7 setup): capacity 100; Ana (Bronze) Consumed 40 at 09:00, Ben (Silver) Consumed 50 at 10:00, Cara (Gold) Consumed 10 at 11:00; remaining 0; Dana is the supervisor; status thresholds 0 / 10 / 30.

---

## Phase 0 — Setup

### [x] T001 Confirm the M1/M2 baseline is green and Boost is reachable
- **Refs:** laravel-boost rules 3–4, AC-US10.2
- **Depends on:** none
- **Files:** none (check only)
- **Do:** Start the stack if needed (`docker compose up -d --wait`). Call Boost `application-info` and note the installed Laravel, Pest and Reverb versions in the task note. Open `.ai/rules/index.md` if it exists. Run the unit + feature suite, the JS suite and the concurrency group once and record the numbers (tests, pending, JS pass count, concurrency).
- **Verify:** `APP php artisan test --compact` → all pass (the four M3 todos show as pending); `NODE npm test` → all pass; `APP php artisan test --group=concurrency` → all pass.
- **Done when:** the baseline numbers are written in the task note, so later regressions are visible.
- **Out of scope:** any code change.
- Note: Boost `application-info`: Laravel 13.37.0, Pest 4.7.8, Reverb 1.12.0, PHP 8.4, pgsql, Boost 2.10.3. `.ai/rules` absent. Baseline: PHP 271 passed + 4 todos (1017 assertions); JS 109 pass; concurrency 8 passed (721 assertions).

---

## Phase 1 — Foundation (blocks everything after)

### [x] T002 Add the M3 migration, model fields and factories
- **Refs:** DD3, FR6.8, FR6.11, plan §5, AC-US10.3
- **Depends on:** T001
- **Files:** `database/migrations/2026_10_21_000001_add_m3_columns_to_ledger_tables.php` (create), `app/Models/Correction.php` (edit), `app/Models/Restock.php` (edit), `database/factories/CorrectionFactory.php` (create; there is none yet), `database/factories/RestockFactory.php` (edit), `tests/Feature/SchemaContractTest.php` (edit)
- **Do:** Read the real schema first (`database-schema` filter `corrections` and `restocks`). Migration `up()`: add `corrections.from_amount` (integer, nullable), backfill `UPDATE corrections c SET from_amount = a.granted FROM allocations a WHERE a.id = c.allocation_id`, set NOT NULL, add `CHECK (from_amount >= 0)` named `corrections_from_amount_check`; add `restocks.idempotency_key` (text, nullable) and a unique index `restocks_pool_id_idempotency_key_unique` on `(pool_id, idempotency_key)`. `down()` reverses all of it. Follow the style of `2026_10_17_000005_create_corrections_table.php` (raw `DB::statement` for CHECKs). Add `from_amount` to `Correction`'s fillable and integer cast, `idempotency_key` to `Restock`'s fillable; give `Correction` the `HasFactory` trait like the other models and write `CorrectionFactory` (pool, allocation, supervisor, `kind` amend, `new_amount`, `from_amount`, reason, times, `idempotency_key` uuid) with `void()` and `amend(int $to)` states. `RestockFactory` gets `idempotency_key` default null.
- **Verify:** `APP php artisan migrate:fresh --seed --force` → succeeds; `APP php artisan test --compact tests/Feature/SchemaContractTest.php tests/Feature/SeedersTest.php` → pass, with new cases: `from_amount` NOT NULL and rejects −1; `restocks.idempotency_key` nullable, two rows with NULL key allowed, two rows with the same non-null key in one pool rejected; the M1 CHECKs on `corrections` still hold.
- **Done when:** the schema has the two columns and the contract test proves them (AC-US7.2 of M1 stays true, plus DD3).
- **Out of scope:** any service, repository or UI change.
- Note: Boost queries: search-docs `migrations modifying columns add column` and `eloquent factories`; database-schema `corrections`. Migration uses raw DB::statement (house style, like the corrections migration). Fixture fallout: raw correction inserts in LedgerRepositoryTest, LedgerSequenceTest and LogEntryResourceTest, plus the correctionRow helper in SchemaContractTest, gained `from_amount` (NOT NULL by design); assertions unchanged.

### [x] T003 Add the trailing entry fields and load entry times in `LedgerRepository`
- **Refs:** DD2, plan §4, plan §5
- **Depends on:** T002
- **Files:** `app/Pool/Calculation/AllocationEntry.php` (edit), `app/Pool/Calculation/RestockEntry.php` (edit), `app/Pool/Calculation/CorrectionEntry.php` (edit), `app/Pool/LedgerRepository.php` (edit), `tests/Feature/LedgerRepositoryTest.php` (edit)
- **Do:** `AllocationEntry` gets two trailing optional parameters `public ?int $correctedFrom = null, public bool $voided = false`. `RestockEntry` and `CorrectionEntry` get a trailing optional `public ?CarbonImmutable $at = null`. Every existing constructor call and test must keep working unchanged. In `LedgerRepository::load` also select and map `recorded_at` for restocks and `effective_at` for corrections (parse like allocations do: timestamptz text with microseconds). Keep the plain-row reading (no Eloquent models) as the class docblock explains.
- **Verify:** `APP php artisan test --compact tests/Feature/LedgerRepositoryTest.php tests/Unit` → all existing cases pass; new cases: a loaded restock has `at` equal to its `recorded_at` with microseconds; a loaded correction has `at` equal to its `effective_at`.
- **Done when:** the ledger carries the time of every entry kind and nothing existing changed.
- **Out of scope:** the new `Ledger` methods (T004), any step behaviour.
- Note: Boost query: search-docs `eloquent factories` (same session as T002; no new API used). Loaded restock `at` = recorded_at and correction `at` = effective_at with microseconds. The new test inserts timestamps as strings, because binding a CarbonImmutable through the query builder drops microseconds.

### [x] T004 Add `Ledger::asOf`, `instantAt` and `evaluationInstant`
- **Refs:** DD2, NFR1, AC-US7.1, AC-US7.4, plan §6 "Calculation contract"
- **Depends on:** T003
- **Files:** `app/Pool/Calculation/Ledger.php` (edit), `tests/Unit/LedgerInstantTest.php` (create)
- **Do:** Add to `Ledger`: `asOf(int $seq): Ledger` (a new ledger with only the entries whose `seq <= $seq`, in all three lists); `instantAt(int $seq): ?CarbonImmutable` (the newest time among entries with `seq <= $seq`: `effectiveAt` for allocations, `at` for restocks and corrections; entries with a null `at` are skipped; null when none); `evaluationInstant(CarbonImmutable $receipt): CarbonImmutable` (`max($receipt, instantAt(PHP_INT_MAX))`). Pure and immutable like the rest of the class.
- **Verify:** `APP php artisan test --compact tests/Unit/LedgerInstantTest.php` → pass: `asOf` filters restocks, allocations and corrections; `instantAt` counts a restock and a correction that are newer than every allocation; null `at` is skipped; `evaluationInstant` returns the receipt when it is newest and the newest entry time when an earlier-received request commits later; the instant never decreases as `seq` grows.
- **Done when:** the non-decreasing instant rule of DD2 is proved at unit level.
- **Out of scope:** changing `AllocationService` (T006).
- Note: Boost query: none needed (pure PHP value-object logic; no Laravel API). Added one helper not named in the task, `Ledger::anchorAt(CarbonImmutable): int` (highest seq whose time is at or before the instant, 0 when none), used by T008/T009 to place expiry points by M2's anchor rule.

### [x] T005 Extract `PoolWriteGate` and move `AllocationService` onto it with no behaviour change
- **Refs:** DD1, FR6.10, NFR1, AC-US10.2
- **Depends on:** T001
- **Files:** `app/Pool/PoolWriteGate.php` (create), `app/Pool/AllocationService.php` (edit)
- **Do:** `PoolWriteGate::locked(Pool $pool, Closure $work): mixed` runs `DB::transaction` (READ COMMITTED, the same as today), re-reads the pool row with `lockForUpdate()`, calls `$work($lockedPool)`, and maps a `QueryException` with SQLSTATE `55P03` to `PoolBusy` (the code now in `AllocationService::submit` and `isLockTimeout`). `PoolWriteGate::announce(int $poolId, int $version, int $remaining, LogEntry $entry): void` holds the deferred, swallowed broadcast now in `AllocationService::announce` (`PoolUpdated` event, `defer`, `rescue(..., report: true)`). `AllocationService` calls the gate and keeps its public `submit()` signature and behaviour exactly. Read `AllocationService` first; keep the receipt time taken before waiting for the lock.
- **Verify:** `APP php artisan test --compact` → everything that passed in T001 still passes; `docker compose up -d --wait && APP php artisan test --group=concurrency` → all pass, with the same p95 line.
- **Done when:** one class owns "one writer at a time" and the full M1/M2 suite is unchanged (DD1).
- **Out of scope:** the evaluation-instant change (T006), restocks and corrections.
- Note: Boost queries: search-docs `database transactions pessimistic locking lockForUpdate` and `broadcasting events after commit`. Full suite 279 passed + 4 todos; concurrency 8 passed, 50x3 p95 0.753 s (baseline run 0.7 s).

### [x] T006 Move `AllocationService` onto the `Ledger` instant methods
- **Refs:** DD2, DD3 (M2), FR6.11, AC-US10.2
- **Depends on:** T004, T005
- **Files:** `app/Pool/AllocationService.php` (edit), `tests/Unit/LedgerInstantTest.php` (edit, only if a case is missing)
- **Do:** Replace the private `evaluationInstant` / `latestEffectiveAt` with `$ledger->evaluationInstant($receipt)`, and the inline as-of filtering in `replay` with `$ledger->asOf($existing->seq)` and `$asOf->instantAt($existing->seq) ?? $existing->effective_at`. Behaviour for M1/M2 ledgers must not change. If an M1/M2 test now fails because a fixture restock's `recorded_at` (real time) is later than a frozen clock, fix the fixture so its `recorded_at` is at or before the frozen instant; do not change the rule. Record any such fixture fix in plan §12.
- **Verify:** `APP php artisan test --compact` → all pass as in T001; `APP php artisan test --group=concurrency` → all pass.
- **Done when:** one place (the ledger) decides the evaluation instant for submits, and replays still match (M2 AC-US7.1).
- **Out of scope:** restock and correction code.
- Note: Boost query: same two as T005 (no new Laravel API). No M1/M2 fixture needed fixing: nothing failed, so nothing to log in plan §12 for this.

### [x] T007 Implement `ApplyCorrections` and `CorrectionStepTest`
- **Refs:** FR6, FR6.1, FR6.4, FR6.6, FR7, NFR-M3.1, DD5, AC-US2.1, AC-US4.1, AC-US5.1, AC-US10.1
- **Depends on:** T003
- **Files:** `app/Pool/Calculation/Steps/ApplyCorrections.php` (edit), `tests/Unit/CorrectionStepTest.php` (create), `tests/Unit/CalculatorPendingTest.php` (edit: remove three todos)
- **Do:** Sort corrections by `seq`; for each allocation id the last correction wins: `amend` sets `units = newAmount`, `void` sets `units = 0` and `voided = true`; set `correctedFrom` to the original units on any entry the result changes; entries without corrections are returned as they are; a correction naming an unknown allocation id is ignored. Build new `AllocationEntry` objects (they are readonly). Keep `effectiveAt`, `tierAtLog`, `type` so `ApplyExpiry` recomputes the same `expires_at` on the corrected units. Real tests with the names of the three todos: "frees units when a correction lowers an allocation" (40 → 20, pool 100, remaining 0 → 20, Ben and Cara unchanged), "frees the units of a voided allocation" (remaining 0 → 40), "changes history only when an expired allocation is corrected" (Temporary 4 at T, evaluate at T+30 h with a correction to 2: current remaining unchanged, corrected units 2 on the entry). Also: last correction wins; shuffled correction input gives the same output; unknown allocation ignored; an expired Temporary raised from 4 to 9 leaves current remaining unchanged.
- **Verify:** `APP php artisan test --compact tests/Unit/CorrectionStepTest.php tests/Unit/CalculatorPendingTest.php tests/Unit/PoolCalculatorTest.php` → pass; pending list shows only the "refuses a correction…" todo.
- **Done when:** the correction step is real and pure (F3, NFR-M3.1, AC-US10.1 for these three tests).
- **Out of scope:** the refusal rule (T011), history replay, any service.
- Note: Boost query: none needed, pure PHP step with no Laravel API. ApplyExpiry now carries `correctedFrom`/`voided` through (it rebuilds entries; without this the corrected marker was lost). The corrected ledger keeps the input corrections list as is, so the shuffle test compares allocations.

### [x] T008 [P] Implement `HistoryReplay` (hindsight windows)
- **Refs:** FR15, DD7, D11, AC-US3.4, AC-US5.2
- **Depends on:** T004, T007
- **Files:** `app/Pool/Audit/HistoryReplay.php` (create), `app/Pool/Audit/HistoryPoint.php` (create), `app/Pool/Audit/HindsightWindow.php` (create), `tests/Unit/HistoryReplayTest.php` (create)
- **Do:** `HistoryReplay::points(Ledger $full, CarbonImmutable $now): list<HistoryPoint>` where `HistoryPoint(?int $seq, int $anchor, CarbonImmutable $at, string $kind, int $remaining)`. It takes every stored entry (restocks, allocations, corrections) in `seq` order and, for each, evaluates `PoolCalculator::calculateAt($full->asOf($seq) with ALL corrections of $full, $full->instantAt($seq))` (corrections apply retroactively), plus one derived expiry point per effective Temporary entry with `expiresAt <= $now`, placed by M2's anchor rule (anchor = highest `seq` whose entry time is at or before the expiry) and evaluated at the expiry instant with cutoff = anchor. Sort like the log (anchor, then stored before expiry, then allocation seq). `overdrawn(array $points): list<HindsightWindow>` with `HindsightWindow(from, to, lowest, correctionSeqs)`: a window starts at the first point with `remaining < 0` and ends at the first later point with `remaining >= 0`; `lowest` is the minimum inside it; `correctionSeqs` are the corrections that raise an entry whose allocation `seq` is at or before the window's end. `PoolCalculator` is built from its dependencies (`FrozenClock`, `RulesConfig::fromArray`, the three steps) as in `tests/Unit/PoolCalculatorTest.php`; take it in the constructor. Pure: no database.
- **Verify:** `APP php artisan test --compact tests/Unit/HistoryReplayTest.php` → pass: PRD example (Ana 40 → 60 correction, Ben 50, Cara 10, restock +50 at 13:00, correction at 14:00) gives remaining 40, −10, −20, then 30 after the restock; one window from Ben's entry to the restock, `lowest` −20, 11:00–13:00 lies inside it, `correctionSeqs` lists the raise; no correction → no window; an expiry point can end a window; a lowering only → no window.
- **Done when:** AC-US3.4 and AC-US5.2 numbers hold at unit level.
- **Out of scope:** the endpoint (T018), status history.
- Note: Pure PHP, no Boost query. Deviation within intent: `overdrawn()` takes the full ledger as a second argument (`overdrawn(array $points, Ledger $full)`), because `correctionSeqs` needs the corrections and allocation numbers; `HistoryPoint` gained a trailing optional `allocationSeq` for the expiry tie-break; `HistoryReplay` also takes `RulesConfig`. PRD example gives 100, 40, -10, -20, 30, 30 and one window from seq 3 (10:00) to seq 5 (13:00), lowest -20.

### [x] T009 [P] Implement `StatusHistory` (before/after per correction)
- **Refs:** FR7, FR7.1, FR7.2, DD8, PQ2, AC-US6.1, AC-US6.2
- **Depends on:** T004, T007
- **Files:** `app/Pool/Audit/StatusHistory.php` (create), `app/Pool/Audit/StatusChange.php` (create), `tests/Unit/StatusHistoryTest.php` (create)
- **Do:** `StatusHistory::forCorrection(Ledger $full, int $correctionSeq, CarbonImmutable $now): StatusChange` with `StatusChange(string $before, string $after, list<array{label: string, seq: ?int, at: CarbonImmutable, before: string, after: string}> $points)`. Let C be the correction and O the corrected operator. "Before" evaluates with the corrections whose `seq < C`, "after" with `seq <= C`, both retroactively. Points, in history order: the correction moment (cutoff = C's `seq`, instant `instantAt(C)`; always listed and its before/after are the `StatusChange` headline values); each stored allocation of O with `seq >=` the corrected allocation's `seq` (label "after <operator>'s request #<seq>", cutoff = that `seq`, instant `instantAt(seq)`); each expiry of O's Temporary entries (cutoff = anchor, instant = expiry); and "now" (cutoff = all, instant `$now`). After the first, list only points where before and after differ. A status is `calculateAt(...)->statuses[O] ?? rules->lowestStatus()`. Other operators are never evaluated or listed. Pure.
- **Verify:** `APP php artisan test --compact tests/Unit/StatusHistoryTest.php` → pass: Ana Consumed 28 at 09:00 and Consumed 5 at 10:30, first lowered to 20 at 14:00 → correction moment elevated → elevated; point "after Ana's request #…" at 10:30 high → elevated; now elevated → elevated is not listed; thresholds 30 → 29 (high → elevated) and 10 → 9 (elevated → standard); a void to 0 gives standard; an operator with no other entries gets only the correction moment and now.
- **Done when:** AC-US6.1 and AC-US6.2 numbers hold at unit level.
- **Out of scope:** the endpoint (T018), the hindsight windows.
- Note: Pure PHP, no Boost query. `forCorrection()` has a fourth optional argument `string $operatorName` for the point labels (the ledger holds no names). The task's Verify text says the correction moment is 'elevated → elevated' for Ana 28 + 5 lowered to 20; the numbers in AC-US6.1 and the thresholds give 33 (high) → 25 (elevated), so the tests assert high → elevated at the correction moment, at request #3 and now. Reported to the user.

**Checkpoint 1:** `APP php artisan test --compact` is green after the `PoolWriteGate` refactor and the migration; `tests/Unit` shows real passing tests for the correction step, the instant rule, the hindsight windows and the status history; only one `todo` ("refuses a correction that raises…") is left pending; the concurrency group still passes.

---

## Phase 2 — Writers (US1, US2, US3, US4, US5, US7)

### [x] T010 Implement `RestockService`
- **Refs:** FR10, FR10.1, FR6.11, NFR5, DD6, AC-US1.1, AC-US1.2, AC-US1.5
- **Depends on:** T002, T004, T005
- **Files:** `app/Pool/AddRestock.php` (create), `app/Pool/RestockResult.php` (create), `app/Pool/RestockService.php` (create), `tests/Feature/RestockTest.php` (create)
- **Do:** Model on `AllocationService`, `SubmitAllocation` and `AllocationResult`. `AddRestock(int $amount, ?string $note, string $idempotencyKey)`; `RestockResult(Restock $restock, PoolState $pool, bool $replayed)`. `RestockService::add(Pool, User $supervisor, AddRestock): RestockResult`: read the receipt time first; inside `PoolWriteGate::locked`: look up the key in `restocks` for this pool; same supervisor, amount and note → replay (pool state as of that restock: `$ledger->asOf($seq)` at `instantAt($seq) ?? recorded_at`), otherwise `IdempotencyKeyConflict`; else load the ledger, `$seq = LedgerSequence::next($locked)`, insert the `Restock` (`recorded_at` = receipt, `actor_id`, `note`, `idempotency_key`), compute the state after with the new `RestockEntry` (with `at`) at `$ledger->evaluationInstant($receipt)`. Do not announce yet (T015). Service-level tests: restock +50 on the §4 pool raises remaining 0 → 50 and Ben can then be granted 50; the entry has actor, amount, note; seq is the next number (unique, gapless with allocations); a replay (5 times) returns the original result and records one row; same key with a different amount, actor or note throws `IdempotencyKeyConflict` and records nothing.
- **Verify:** `APP php artisan test --compact tests/Feature/RestockTest.php` → pass.
- **Done when:** AC-US1.1 (server half), AC-US1.2 and AC-US1.5 hold at service level.
- **Out of scope:** the HTTP layer (T016), the broadcast (T015), validation of the amount (T016).
- Note: Boost queries: search-docs `database transactions pessimistic locking lockForUpdate` (T005 session) and `eloquent factories`. Shared test fixtures added to tests/Pest.php: `workedPool()` (the PRD §7 pool) and later the expiry and correction helpers.

### [x] T011 Implement `CorrectionService` with the §4 scenarios
- **Refs:** FR6, FR6.1–FR6.8, FR6.10–FR6.12, FR7, NFR1, DD4, DD5, DD6, AC-US2.1–AC-US2.4, AC-US3.1–AC-US3.3, AC-US3.5, AC-US4.1, AC-US10.1
- **Depends on:** T006, T007, T010
- **Files:** `app/Pool/ApplyCorrection.php` (create), `app/Pool/CorrectionResult.php` (create), `app/Pool/CorrectionService.php` (create), `app/Pool/Exceptions/CorrectionRefused.php` (create), `app/Pool/Exceptions/AllocationVoided.php` (create), `tests/Feature/CorrectionTest.php` (create), `tests/Unit/CalculatorPendingTest.php` (delete the file: its last todo is converted here)
- **Do:** `ApplyCorrection(int $allocationSeq, string $kind, ?int $amount, string $reason, string $idempotencyKey)`; `CorrectionResult(Correction $correction, PoolState $pool, bool $replayed)`. `CorrectionService::apply(Pool, User $supervisor, ApplyCorrection): CorrectionResult`; inside `PoolWriteGate::locked`, in this order: (1) key lookup in `corrections`: same supervisor, allocation, kind, amount and reason → replay as of that correction (as in T010); different → `IdempotencyKeyConflict`. (2) Find the allocation by `seq` through `$locked->allocations()`; missing → `ModelNotFoundException` (renders 404 like an unknown pool: check `ApiErrorRenderer`). (3) `$state = calculator->calculateAt($ledger, $ledger->evaluationInstant($receipt))`; the entry's current units come from `$state->effective`. (4) The entry is voided → `AllocationVoided` (code `allocation_voided`). (5) amend to the current units, or void of an entry with 0 units → validation error with `fields.amount = no_change` (same rendering as other `422 validation_failed`). (6) A raise (`new > current`) is checked: skip the check only for a Temporary entry whose window has ended at the instant (`!$instant->lessThan($rules->expiresAt($tier, $effectiveAt))`, computed from the stored allocation so a zero-grant entry works); otherwise if `new − current > $state->remaining` throw `CorrectionRefused(needed, free, allocationSeq)`. (7) `$seq = LedgerSequence::next($locked)`; insert the `Correction` (`from_amount` = current units, `new_amount` null for a void, `effective_at` = receipt, `recorded_at` = clock now); compute the state after with the new `CorrectionEntry` (with `at`). Never write to `allocations`. Tests in `CorrectionTest` (service level; HTTP cases are added in T017): names of the former todo "refuses a correction that raises an allocation beyond the free units" kept; lower 40 → 20 frees 20, Ben and Cara unchanged, Cara then gets 20, Ana's status goes high → elevated and Ben and Cara are unchanged; raise 40 → 60 with 0 free throws `CorrectionRefused` (needed 20, free 0), nothing recorded and `pools.version` unchanged; restock +50 then the same raise succeeds (remaining 30); raise by exactly the free units succeeds, by one more refused; raise needing the free units of an entry whose status moves (60 → high); void frees all 40 and Ana's status is standard; correction of the oldest entry; voided entry → `AllocationVoided`; no-op amend → `no_change`; void of a zero-grant entry → `no_change`; unknown seq → not found; a raise of a zero-grant entry (requested 3, granted 0) to 3 succeeds when 3 are free (PQ1); `from_amount` is the previous correction's amount after a second correction.
- **Verify:** `APP php artisan test --compact tests/Feature/CorrectionTest.php` → pass; `APP php artisan test --compact` → whole suite green and no pending test left in `tests/Unit`.
- **Done when:** every §4 scenario passes at service level (D7, D11), and AC-US10.1 holds: no pending M3 test remains.
- **Out of scope:** HTTP, validation of raw input (T017), expiry and replay deep cases (T012, T013), broadcast (T015).
- Note: Boost query: search-docs `database transactions pessimistic locking lockForUpdate`; database-schema `corrections`. `CalculatorPendingTest.php` removed (approved, plan §11); its last todo is now 'refuses a correction that raises an allocation beyond the free units' in CorrectionTest. No-op correction renders as `details.fields.amount = ["no_change"]` (a list, like every other 422 field). `allocation.operator` is loaded outside the lock.

### [x] T012 Test corrections around expiry
- **Refs:** FR6.5, FR6.6, D4, D7, AC-US4.2, AC-US5.1–AC-US5.4
- **Depends on:** T011
- **Files:** `tests/Feature/CorrectionExpiryTest.php` (create), `app/Pool/CorrectionService.php` (edit only to fix a failing case)
- **Do:** With `FrozenClock` and the real service on PostgreSQL. (a) Ana Temporary 4 at T (Bronze, expires T+24 h); at T+30 h Dana lowers it to 2: current remaining and Ana's status unchanged, the correction is stored, `calculateAt` at T+10 h with the corrections shows the corrected history (units 2). (b) Same expired entry raised 4 → 9 while 0 are free: accepted (no pool check), current remaining unchanged. (c) Active Temporary 4 lowered to 2 at T+1 h: remaining +2 at once and exactly +2 more at T+24 h (not +4) — assert at T+24 h−1 ms and T+24 h; `expires_at` unchanged. (d) Active Temporary 4 raised to 6 at T+1 h with 2 free: accepted, remaining −2, at T+24 h exactly 6 return. (e) Active Temporary voided at T+1 h: +4 at once, no change at T+24 h. (f) A correction exactly at `expires_at` is treated as expired (history only), 1 ms before as active.
- **Verify:** `APP php artisan test --compact tests/Feature/CorrectionExpiryTest.php` → pass.
- **Done when:** AC-US4.2 and AC-US5.1–AC-US5.4 pass, including the ±1 ms boundary.
- **Out of scope:** the log's derived expiry entries (T014).
- Note: Boost query: search-docs `database transactions pessimistic locking lockForUpdate` (no new Laravel API). All six cases pass including the ±1 ms boundary; shared helpers (expiryWorld etc.) live in tests/Pest.php.

### [x] T013 Test idempotent replay of restocks and corrections
- **Refs:** FR6.11, NFR5, AC-US7.4, AC-US1.5
- **Depends on:** T011
- **Files:** `tests/Feature/CorrectionIdempotencyTest.php` (create), `app/Pool/CorrectionService.php` / `app/Pool/RestockService.php` (edit only to fix a failing case)
- **Do:** Correction with key K lowers Ana 40 → 20 and returns pool remaining 20 at version V. Then: Dana corrects the same entry again (20 → 10) and the original key K is replayed with the same payload → the result is identical to the first (same correction, `remaining` 20, version V) and one row exists for K; replay 10 times → still one row; K with a different amount, allocation, kind, reason or supervisor → `IdempotencyKeyConflict` and nothing recorded; a refused raise is not stored, so the same key retried after a restock is evaluated again and is accepted (plan §11 item 1); a restock replay after other writes returns the original `remaining`. Replays at a later instant (clock advanced 25 h past an expiry) stay identical.
- **Verify:** `APP php artisan test --compact tests/Feature/CorrectionIdempotencyTest.php` → pass.
- **Done when:** AC-US7.4 passes.
- **Out of scope:** the HTTP headers (T016, T017).
- Note: Boost query: same as T011. All cases pass first time, including replay after the clock moved 26 h.

### [x] T014 Show corrections and corrected units in the log and snapshot
- **Refs:** FR3.4, FR8, DD12, A-M3.4, AC-US2.1, AC-US4.1, AC-US10.3
- **Depends on:** T011
- **Files:** `app/Pool/Log/LogEntry.php` (edit), `app/Pool/Log/LogQuery.php` (edit), `app/Http/Resources/LogEntryResource.php` (edit), `app/Pool/SnapshotService.php` (edit), `tests/Feature/CorrectionLogTest.php` (create), `tests/Feature/LogAndSnapshotTest.php`, `tests/Feature/LogEntryResourceTest.php`, `tests/Feature/BroadcastTest.php` (edit only the assertions on entry shapes)
- **Do:** `LogEntry::fromCorrection` uses the stored `from_amount` as `from` (not the allocation's granted), `to` = `new_amount ?? 0`, adds `operator` (the corrected allocation's operator, relation `allocation.operator`), and `change` = `amend` or `void`. `LogEntryResource` outputs those for `correction`; for `allocation` it adds `corrected_to` (current units when a correction changed them, else null) and `voided`; `requested` and `granted` keep their original values. `LogQuery::latest(...)` receives the effective `Ledger` (a new last parameter) and builds derived `expiry` entries and each allocation's `expires_at` from it, so a void leaves no expiry entry, a lowered entry shows the lowered units, and a raised zero-grant entry gets one; the anchor query is unchanged. `SnapshotService::build` passes `$state->effective`; `SnapshotService::logPage` loads the ledger and computes the state at now to pass the same. Read `LogQuery` and `LogEntry` in full first and keep paging by cursor working. Tests in `CorrectionLogTest`: the correction entry (from 40, to 20, reason, supervisor, operator Ana) sits at its `seq`; Ana's original allocation entry still shows requested 40 granted 40 with `corrected_to` 20; a void shows `voided: true` and no expiry entry; derived expiry follows the corrected units; paging with `before=` still returns each entry once; existing M1/M2 field values are unchanged.
- **Verify:** `APP php artisan test --compact tests/Feature/CorrectionLogTest.php tests/Feature/LogAndSnapshotTest.php tests/Feature/LogEntryResourceTest.php tests/Feature/ExpiryLogTest.php tests/Feature/BroadcastTest.php` → pass.
- **Done when:** the log shows originals, corrections and corrected derived entries; contracts only grew (AC-US10.3).
- **Out of scope:** announcing restocks and corrections (T015), the audit view.
- Note: Boost query: search-docs `eloquent factories` not needed; Eloquent eager loading only. Deviation from plan §6, logged in plan §12 at T032: the M1 correction log entry already has `change: {from, to}` (to = null for a void, asserted by an M1 test), so the plan's `change: "amend"|"void"` would break AC-US10.3. `change` is unchanged (its `from` now comes from the stored from_amount); the kind is added as `correction_kind` and the corrected operator as `operator`. Existing shape assertions in LogEntryResourceTest and BroadcastTest were extended additively (corrected_to, voided, operator, correction_kind). `LogQuery::latest` takes the effective ledger as a required last argument; its constructor now takes `Expiries` instead of `RulesConfig`.

### [x] T015 Announce restocks and corrections after commit
- **Refs:** FR17, NFR6, D12, AC-US8.1, AC-US8.3
- **Depends on:** T010, T011, T014
- **Files:** `app/Pool/RestockService.php` (edit), `app/Pool/CorrectionService.php` (edit), `tests/Feature/BroadcastTest.php` (edit)
- **Do:** After a non-replayed commit, call `PoolWriteGate::announce` with the new version, the remaining and the `LogEntry` (`fromRestock` with the actor relation loaded, `fromCorrection` with `supervisor` and `allocation.operator` loaded), exactly like `AllocationService::submit` does. A replay announces nothing. Tests with the existing event fake pattern in `BroadcastTest`: a restock sends one `pool.updated` with the right version, remaining and a `restock` entry; a correction sends one with a `correction` entry; the event is sent after the transaction committed (a rolled-back refusal sends nothing); a broadcaster that throws does not fail the request and is reported.
- **Verify:** `APP php artisan test --compact tests/Feature/BroadcastTest.php tests/Feature/RestockTest.php tests/Feature/CorrectionTest.php` → pass.
- **Done when:** AC-US8.1 and AC-US8.3 (server half) hold.
- **Out of scope:** the browser (T026).
- Note: Boost queries: search-docs `broadcasting events after commit` (T005 session). Replays, refusals and validation errors announce nothing; a throwing broadcaster is reported and does not fail the write.

**Checkpoint 2:** at service level, restock, lower, raise (refused, then accepted after a restock), void and the expired/active Temporary cases pass; the log and snapshot show originals, corrections and corrected expiry entries; `pool.updated` goes out for restocks and corrections. `APP php artisan test --compact` is green.

---

## Phase 3 — API and audit (US1–US6)

### [x] T016 Add the restock endpoint
- **Refs:** FR10, FR10.1, FR10.2, FR6.9, FR6.11, AC-US1.1–AC-US1.5, plan §6 "POST /restocks"
- **Depends on:** T010, T015
- **Files:** `app/Http/Controllers/Api/RestockController.php` (create), `routes/api.php` (edit), `tests/Feature/RestockTest.php` (edit)
- **Do:** Model on `AllocationController::store`. Required `Idempotency-Key` header (`400 missing_idempotency_key`); validate `amount` (`required`, `integer:strict`, `min:1`, `max:2147483647`) and `note` (`nullable`, `string`, `max:255`); map `IdempotencyKeyConflict` → `409 idempotency_key_conflict` and `PoolBusy` → `503 pool_busy` with `Retry-After: 1`; respond `201`, or `200` + `Idempotent-Replayed: true`, with `{"restock":{id, seq, actor:{id,name}, amount, note, recorded_at},"pool":{version, remaining}}` (use `ApiTime::format`). In `routes/api.php` replace the `POST pools/{pool}/restocks` stub with the controller inside the existing `role:supervisor` group.
- **Verify:** `APP php artisan test --compact tests/Feature/RestockTest.php` → pass with new HTTP cases: Dana +50 gives `201` and remaining 50 (the §4 pool at 0); operator Ana → `403 forbidden_role`, nothing recorded; amount 0, −1, 1.5, "50", missing → `422 validation_failed` with `details.fields`; no note accepted; replay → `200` with the header and the same body; same key different amount → `409`; no key → `400`.
- **Done when:** AC-US1.1–AC-US1.5 pass over HTTP.
- **Out of scope:** the screen (T027, T028).
- Note: Boost queries: search-docs `validation rules integer prohibited` and `route model binding scoping`. Restock HTTP cases were added to RestockTest (27 tests). `RestockResource` added (wrap `restock`), like AllocationResource.

### [x] T017 Add the correction endpoint
- **Refs:** FR6, FR6.2, FR6.9, FR6.11, FR6.12, DD10, AC-US2.1–AC-US2.5, AC-US3.1, AC-US3.2, plan §6 "POST …/corrections"
- **Depends on:** T011, T015
- **Files:** `app/Http/Controllers/Api/CorrectionController.php` (create), `routes/api.php` (edit), `tests/Feature/CorrectionTest.php` (edit)
- **Do:** Route `POST pools/{pool}/allocations/{seq}/corrections` (the parameter is the allocation's `seq` in this pool, DD10) inside `role:supervisor`, replacing the stub. Validate with `Validator::make` like `AllocationController`: `kind` required in `amend,void`; `amount` `required_if:kind,amend`, `prohibited_if:kind,void`, `integer:strict`, `min:1`, `max:2147483647`; `reason` required, trimmed non-blank, 1–500 chars; `seq` a positive integer. Map: `CorrectionRefused` → `422 insufficient_free_units` with `details {needed, free, allocation_seq}` and message "Needs {n} more units, {m} are free. Restock first."; `AllocationVoided` → `422 allocation_voided`; `IdempotencyKeyConflict` → `409`; `PoolBusy` → `503`; unknown allocation → `404`. Response `201` or `200` + `Idempotent-Replayed: true`: `{"correction":{id, seq, allocation_seq, operator:{id,name}, kind, from, to, reason, supervisor:{id,name}, effective_at},"pool":{version, remaining}}`. Check how `ApiErrorRenderer` shapes validation errors and reuse it; for `no_change` produce `details.fields.amount = "no_change"`.
- **Verify:** `APP php artisan test --compact tests/Feature/CorrectionTest.php` → pass with HTTP cases: §4 lower 40 → 20 over HTTP gives remaining 20; raise 40 → 60 with 0 free gives `422 insufficient_free_units` with needed 20, free 0 and no new row and unchanged `pools.version`; after a restock +50 the same request gives `201` with remaining 30; blank reason, amount 0, fractional, negative, amount on a void, equal amount → `422 validation_failed`; unknown or other-pool seq → `404`; voided entry → `422 allocation_voided`; operator → `403`; no key → `400`; replay → `200` + header.
- **Done when:** AC-US2.1–AC-US2.5, AC-US3.1 and AC-US3.2 pass over HTTP.
- **Out of scope:** the screen, the audit endpoint.
- Note: Boost queries: search-docs `validation rules integer prohibited`, `validation required_if prohibited_if`, `route model binding scoping`. The route is `allocations/{seq}/corrections` with `where('seq', '[1-9][0-9]{0,17}')`, so a non-numeric or zero seq is a plain 404. Validation reads only the fields that were sent (`$request->only`) and `amount` is `nullable` so `amount: null` on a void is accepted. `CorrectionResource` added.

### [x] T018 Add `AuditService` and the audit endpoint
- **Refs:** FR7.1, FR7.2, FR8, FR15, FR16, DD7, DD8, AC-US3.4, AC-US6.1–AC-US6.5, plan §6 "GET /audit"
- **Depends on:** T008, T009, T014
- **Files:** `app/Pool/Audit/AuditService.php` (create), `app/Http/Controllers/Api/AuditController.php` (create), `routes/api.php` (edit), `tests/Feature/AuditTest.php` (create)
- **Do:** `AuditService::build(Pool): array` reads in one `REPEATABLE READ READ ONLY` transaction like `SnapshotService::readConsistently` (reuse or copy its helper without changing it), loads the ledger and the `corrections` rows with `supervisor` and `allocation.operator`, and returns the plan §6 shape: `corrections` oldest first, each with `original {requested, granted}`, `from`, `to`, reason, supervisor, `at`, and `status {before, after, points}` from `StatusHistory`; `hindsight` from `HistoryReplay::overdrawn(points(...))`; plus `version` and `server_time`. Uncorrected entries do not appear; an empty history gives empty lists. Route `GET pools/{pool}/audit` in the `role:supervisor` group replaces the stub. Tests: the §4 example (lower 40 → 20) lists one row with original 40/40, from 40, to 20, "Counted wrong", Dana, Ana high → elevated; Ana 28 then 5 example shows the later point high → elevated and omits unchanged points; Ben and Cara never appear in any status block; PRD AC-US3.4 data (restock +50 at 13:00, raise Ana to 60 at 14:00) gives one hindsight window with lowest −20 containing 11:00–13:00, correction accepted, current remaining 30; several corrections in commit order; empty state; operator → `403`; unknown pool → `404`.
- **Verify:** `APP php artisan test --compact tests/Feature/AuditTest.php` → pass.
- **Done when:** AC-US3.4 and AC-US6.1–AC-US6.5 pass.
- **Out of scope:** the screen (T024, T028).
- Note: Boost query: search-docs `database transactions pessimistic locking lockForUpdate` (read-only REPEATABLE READ helper copied from SnapshotService, unchanged). Audit response also lists `to.seq = null` for an expiry window end. Ran the §4 example and the PRD AC-US3.4 data (window seq 3 → seq 5, lowest -20, correction 6).

### [x] T019 Remove the capacity stub and `NotImplementedController`; update the stub test
- **Refs:** FR10.2, DD11, PQ3, F3, AC-US10.2
- **Depends on:** T016, T017, T018
- **Files:** `routes/api.php` (edit), `app/Http/Controllers/Api/NotImplementedController.php` (delete), `tests/Feature/RolesAndStubsTest.php` (edit)
- **Do:** Delete the `PUT pools/{pool}/capacity` route and the (now unused) `NotImplementedController`; confirm nothing else references it (CodeGraph `NotImplementedController`). In `RolesAndStubsTest` remove the 501 checks for the three endpoints M3 built and the capacity one; keep the role checks and `/api/users`; add a case that `PUT /api/pools/1/capacity` is not routed (404 or 405 in the API's error body shape) and that an operator cannot reach restock, correction or audit.
- **Verify:** `APP php artisan route:list --path=api` → no capacity route; `APP php artisan test --compact` → whole suite green.
- **Done when:** no 501 stub remains and the approved test change is the only one (plan §11).
- **Out of scope:** any other test or route.
- Note: Boost query: none needed beyond T016 (route removal). CodeGraph showed NotImplementedController's only callers were two routes. PUT /capacity now answers 404 (not 405: the path no longer exists). RolesAndStubsTest: the 501 checks became supervisor-route role checks, a nothing-written check for operators, and the capacity 404 case.

### [x] T020 Document the new contracts
- **Refs:** F3, FR10.2, AC-US10.3, plan §6
- **Depends on:** T019
- **Files:** `docs/api-contract.md` (edit)
- **Do:** Documentation task (no Boost query). Add the restock, correction and audit endpoints exactly as plan §6 (request, response, errors, idempotency, role), the grown log entry shapes (`correction`, `allocation` with `corrected_to` and `voided`, `expiry` from corrected units), the note that the broadcast `entry` can be a restock or correction, the statement that idempotency keys are unique per table per pool (use a new UUID per action), and the removal of `PUT /capacity`. Remove the "501 / M3" rows. Do not rewrite anything else.
- **Verify:** `grep -n "capacity\|501" docs/api-contract.md` → no live capacity endpoint and no M3 501 row; each new endpoint has request, response and error sections.
- **Done when:** AC-US10.3 is checkable from the document.
- **Out of scope:** README (T029).
- Note: Documentation task, no Boost query. docs/api-contract.md: three endpoints documented, 501 rows removed, new 422 codes, an 'M3 (additive)' section (log fields, keys per table, evaluation instant, broadcast), the Ledger methods in the calculation contract. The log `correction` entry keeps `change {from,to}` and gains `correction_kind` (see T014).

### [x] T021 Write the concurrency tests for corrections, restocks and submits
- **Refs:** NFR1, NFR2, FR6.10, AC-US7.1, AC-US7.2, AC-US7.3, AC-US7.5
- **Depends on:** T017, T016
- **Files:** `tests/Concurrency/ParallelCorrectionTest.php` (create)
- **Do:** Group `concurrency`, real HTTP against the running stack, own pool and users, cleaned up afterwards; model on `tests/Concurrency/ParallelAllocationTest.php` and `ParallelExpiryTest.php` (read both first, reuse their helpers). (a) 20 rounds on a fresh pool: remaining 20 (Ana holds 40 of 100 and others the rest), Dana's raise Ana 40 → 60 (needs 20) and Ben's request of 20 sent at the same moment: exactly one wins (either the correction is `201` and Ben is "0 of 20 granted", or Ben is granted 20 and the correction is `422 insufficient_free_units`); never both; remaining never below 0; replaying the ledger in `seq` order through the calculator gives each reply's `remaining`. (b) 50 parallel requests of 3 on a pool with 100, with a lowering that frees 20 and a restock of +10 sent in the middle: total granted ≤ capacity + 20 + 10, remaining ≥ 0 after every commit, `seq` unique and gapless across restocks, allocations and corrections, log order equals commit order, p95 submit-to-confirmed ≤ 1 s for operator requests, the restock and the correction (print a `p95` line). (c) Two supervisors lower the same entry 40 → 20 with different keys at once: one `201`, one `422 validation_failed` (`no_change`); remaining rises by 20 once. The 50×3 and expiry tests stay unchanged.
- **Verify:** `docker compose up -d --wait && APP php artisan test --group=concurrency` → all pass, including the new file, printing the `p95` line.
- **Done when:** AC-US7.1, AC-US7.2, AC-US7.3 and AC-US7.5 pass on the real stack. If p95 exceeds 1 s, fix the code (lock hold time) only after reporting.
- **Out of scope:** changing M1/M2 concurrency tests.
- Note: Boost query: none needed (tests drive the HTTP stack). Results against the stack: raise vs request, 20 rounds, correction won 2 and request won 18 (never both); 52 requests (50x3 + lowering + restock) all 201, p95 0.690 s, restock 0.459 s, correction 0.215 s; two supervisors: one 201, one 422 no_change, remaining +20 once. Needs the helpers of ParallelAllocationTest, like ParallelExpiryTest does.

### [x] T022 Guard numbering and append-only in `LedgerSequenceTest`
- **Refs:** FR6.8, FR6.10, D7, AC-US7.2
- **Depends on:** T016, T017
- **Files:** `tests/Feature/LedgerSequenceTest.php` (edit)
- **Do:** Read the file first and extend it: restocks, allocations and corrections written through their services share one gapless, unique sequence; a source scan of `app/` finds no `UPDATE` or `DELETE` (query builder `->update(`/`->delete(`, `Model::destroy`, raw SQL) on `allocations`, `restocks` or `corrections` (the only allowed `UPDATE` on `pools` is inside `LedgerSequence`, as the existing guard already checks).
- **Verify:** `APP php artisan test --compact tests/Feature/LedgerSequenceTest.php` → pass.
- **Done when:** append-only and one-numbering are enforced by a test.
- **Out of scope:** changing `LedgerSequence`.
- Note: Boost query: none needed. The source scan was checked against three deliberate offenders (raw UPDATE, query-builder delete, model update); all three are caught. postRestock/postCorrection helpers moved to tests/Pest.php so LedgerSequenceTest can use them.

**Checkpoint 3:** with `curl` as Dana (`X-Demo-User` of the seeded supervisor) and a fresh `Idempotency-Key` each time: restock +50 → `201`; correct entry 1 down → `201`; raise beyond free → `422 insufficient_free_units`; `GET /api/pools/1/audit` shows the correction with status before/after; the same calls as Ana → `403`; `PUT /capacity` is not routed. `APP php artisan test --compact` and `--group=concurrency` pass.

---

## Phase 4 — Screens (US8, US9)

### [ ] T023 [P] Add `supervisor-rules.js` (form problems)
- **Refs:** FR18.1, AC-US1.3, AC-US2.4, plan §6 "Client modules"
- **Depends on:** T001
- **Files:** `resources/js/supervisor-rules.js` (create), `resources/js/__tests__/supervisor-rules.test.js` (create)
- **Do:** Pure functions, like `form-rules.js`. `restockProblem(amountText)` returns null or a short text ("Enter a whole number of units, 1 or more") for blank, 0, negative, fractional, non-numeric. `correctionProblem({kind, amountText, reasonText, currentUnits})`: null or text for a blank reason ("Give a reason"), an amount that is blank, 0 ("Use Void to remove all units"), negative, fractional or non-numeric (for `amend`), an amount equal to `currentUnits` ("No change"), an amount given with `void`, and `currentUnits` of 0 with `void` ("Nothing to void"). Read `form-rules.js` and its test first and follow their style. Pure JavaScript task: no Boost query.
- **Verify:** `NODE npm test` → the new test file passes with every case above.
- **Done when:** the form rules match the server's validation (T017).
- **Out of scope:** the DOM, the network.

### [ ] T024 [P] Add `audit-view.js` (text builders)
- **Refs:** FR15, FR16, AC-US3.4, AC-US6.3
- **Depends on:** T001
- **Files:** `resources/js/audit-view.js` (create), `resources/js/__tests__/audit-view.test.js` (create)
- **Do:** Pure functions. `auditRows(audit, timeZone)` turns `audit.corrections` into rows `{seq, text, points}` where text reads "Dana · #1 Ana · 40 → 20 · Counted wrong · Ana: high → elevated" (a void reads "voided (40 → 0)"), and each status point reads "<label>: high → elevated" with the time formatted like `returnTimeText` in `time-left.js`. `hindsightText(window, timeZone)` returns "Overdrawn in hindsight: lowest -20 between 10:00 and 13:00". An empty audit gives an empty-state text ("No corrections yet"). Text only, no colour meaning. Pure JavaScript task: no Boost query.
- **Verify:** `NODE npm test` → the new test file passes (fixed time zone; amend, void, no points, hindsight, empty).
- **Done when:** AC-US6.3 and the hindsight text are testable without a browser.
- **Out of scope:** the DOM.

### [ ] T025 Render corrections, restocks and the corrected/voided marker
- **Refs:** FR3.4, AC-US2.1, AC-US4.1
- **Depends on:** T014
- **Files:** `resources/js/log-renderer.js` (edit), `resources/js/__tests__/log-renderer.test.js` (edit)
- **Do:** Read `log-renderer.js` and its test first. `renderEntry` for `correction` reads "Dana changed Ana's request #1: 40 → 20 · Counted wrong" (a void: "Dana voided Ana's request #1 (40 → 0) · …"), label "Correction", `own` true when the viewer is the corrected operator; `restock` reads "Dana added 50 units · Delivery" (no note: no dot), label "Restock"; an `allocation` entry with `corrected_to` adds "corrected to 20" and with `voided` adds "voided" as text (not colour alone) while still showing the original request and grant. Existing texts for other kinds do not change. Pure JavaScript task: no Boost query.
- **Verify:** `NODE npm test` → pass, including: correction amend, void, own highlight for Ana only, restock with and without note, corrected and voided allocation rows, M1/M2 cases unchanged.
- **Done when:** FR3.4 text labels are tested.
- **Out of scope:** supervisor forms.

### [ ] T026 Handle correction events and add supervisor API calls
- **Refs:** FR17, NFR6, DD9, D12, AC-US8.1, AC-US8.2
- **Depends on:** T015, T017, T018
- **Files:** `resources/js/pool-state.js` (edit), `resources/js/api.js` (edit), `resources/js/__tests__/pool-state.test.js` (edit), `resources/js/__tests__/submit-flow.test.js` (edit only if a shared helper changes)
- **Do:** In `pool-state.js` `applyEvent`: a `restock` event applies like any entry (remaining, log, version); a `correction` event bumps the version and returns that a snapshot is needed (follow how a gap already requests one; the dashboard then fetches one snapshot); out-of-order and duplicate events are still ignored. In `api.js` add `postRestock`, `postCorrection` and `fetchAudit` (same header handling as `fetchSnapshot` and the submit flow: `X-Demo-User`, a fresh `Idempotency-Key` per user action, the existing timeout and offline message; no queueing). Read both files and their tests first. Pure JavaScript task: no Boost query.
- **Verify:** `NODE npm test` → pass, including: restock event raises remaining once; correction event requests a snapshot and does not change remaining by itself; stale and duplicate events ignored; the new api calls send the right method, path, headers and body and surface `422` details and the offline message.
- **Done when:** AC-US8.2 holds in the unit tests and the other M1/M2 JS tests pass unchanged.
- **Out of scope:** the DOM and the Alpine component.

### [ ] T027 Build the supervisor panel markup and styles
- **Refs:** FR18, NFR3, DD13, AC-US9.1, AC-US9.2
- **Depends on:** T019
- **Files:** `resources/views/dashboard.blade.php` (edit), `resources/css/app.css` (edit), `tests/Feature/DashboardViewTest.php` (edit)
- **Do:** Replace the "Placeholder — arrives in M3" paragraph in the `#supervisor-view` section with: a restock form (amount, note, submit), a correction form (entry number shown read-only, current units, "Amend" or "Void" choice, new amount, reason, submit), a result/refusal region for readable text, and an audit section (list container plus an empty-state line). Use the existing design tokens and the classes of the operator form; every control and message is text, not colour alone. Alpine bindings (`x-model`, `x-on:click`) name the component members that T028 will add; keep them consistent with `dashboard.js`. Operators never get this markup (it stays under the existing `showSupervisor` / `isSupervisor` gating). Update `DashboardViewTest`: the placeholder text is gone; the supervisor panel contains the restock form, the correction form and the audit container; the operator request form is still present for operators.
- **Verify:** `APP php artisan test --compact tests/Feature/DashboardViewTest.php` → pass; `NODE npm run build` → succeeds.
- **Done when:** the panel exists and is gated (AC-US9.1); layout at 360 px and 1280 px is checked in T030.
- **Out of scope:** behaviour (T028), contrast and touch-size audit (M4).

### [ ] T028 Wire the supervisor component
- **Refs:** FR18, FR18.1, FR10, AC-US9.3, AC-US9.4, AC-US2.6, AC-US8.1
- **Depends on:** T023, T024, T025, T026, T027
- **Files:** `resources/js/supervisor.js` (create), `resources/js/dashboard.js` (edit)
- **Do:** `supervisor.js` exports the supervisor part of the Alpine state (read `dashboard.js` first to see how `poolDashboard()` composes pure modules and how `createSubmitFlow` handles pending, offline and results; reuse that pattern). Restock and correct submits: validate with `supervisor-rules.js`; show "pending" within 100 ms; refuse with the offline text and queue nothing when offline; send once with a fresh key; on `201/200` update from the response (remaining, version) and request one snapshot; on `422 insufficient_free_units` show "Needs 20 more units, 0 are free. Restock first." from `details`; other errors show their message. Each allocation row in the log gets a "Correct" button for supervisors that fills the correction form (entry number and current units, from the log entry's `corrected_to ?? granted`). Fetch and render the audit (`fetchAudit`, `auditRows`, `hindsightText`) on opening the supervisor tab and after each successful write. Wire `dashboard.js` so a snapshot is fetched when `applyEvent` asks for one. Pure JavaScript task: no Boost query.
- **Verify:** `NODE npm test` → all pass; `NODE npm run build` → succeeds; manual smoke (in T030) as Dana: restock, a refused raise, a lowering, the audit rows.
- **Done when:** AC-US9.3 and AC-US9.4 behaviour is in place (offline: no guess, nothing queued), verified manually in T030.
- **Out of scope:** the sunlight and one-thumb UX (M4).

**Checkpoint 4:** in two browsers on the stack, Dana (supervisor) restocks and corrects and Ana's and Ben's screens update within 2 s; a refused raise shows the text; the audit shows before and after.

---

## Phase 5 — Hardening and docs

### [ ] T029 Update `scripts/verify.sh` and the README
- **Refs:** AC-US10.2, AC-US8.1, AC-US9.2
- **Depends on:** T028, T021
- **Files:** `scripts/verify.sh` (edit), `README.md` (edit)
- **Do:** Documentation and script task (no Boost query). Make sure `verify.sh` runs the new PHP, concurrency and JS tests in its existing order (it runs the whole suites, so check rather than add); add a short README section: the supervisor walkthrough (restock, lower, refused raise, restock + raise, void, audit), the manual checks (two browsers, 360 px and 1280 px, `reverb` stopped, offline) and the M3 assumptions A-M3.1–A-M3.5. Do not rewrite other sections.
- **Verify:** `bash scripts/verify.sh` → ends with its success line and each step passes.
- **Done when:** one command runs everything.
- **Out of scope:** the final README (M4).

### [ ] T030 Run the full suite and the manual checks
- **Refs:** AC-US10.2, AC-US8.1, AC-US8.3, AC-US9.2, AC-US9.4, AC-US2.6, AC-US1.1, NFR3, NFR6
- **Depends on:** T029
- **Files:** none (run and record)
- **Do:** Run `bash scripts/verify.sh`. Then the manual checks from the README on the Compose stack: two browsers (Ana, Ben) while Dana restocks, lowers, is refused on a raise, restocks and raises, and voids — each screen updates within 2 s with no reload and Ana's status and holdings follow; supervisor screens and new log entries at 360 px and 1280 px with no horizontal page scroll; `docker compose stop reverb` and a correction: the other screen catches up on the ~10 s refresh; browser offline: the supervisor forms show the offline state and send nothing. Record what was run and what could not be run. If no real browser can be started here (as M2 found), say so and mark the manual items `[!]` for the user; do not claim they were done.
- **Verify:** `bash scripts/verify.sh` → green; manual results written in the task note.
- **Done when:** the suite is green and the manual checks are done or explicitly handed to the user.
- **Out of scope:** fixing M4 work (contrast, touch size).

### [ ] T031 Traceability self-check
- **Refs:** plan §10, PRD §10
- **Depends on:** T030
- **Files:** `workflow/slices/m3-supervisor-corrections/tasks.md` (edit: tick, notes only)
- **Do:** Documentation task. Check mechanically that every ID in PRD §10 appears in the Coverage table below with no empty cell, that every task note on a Laravel task names a Boost query (`grep -c "Boost query"`), and that every test file named in plan §4 exists.
- **Verify:** a script over PRD §10 IDs and this file reports no missing ID; `ls` of the plan §4 test files shows them all.
- **Done when:** the result is in the task note.
- **Out of scope:** changing the PRD or plan.

### [ ] T032 Re-run the rules check and log deviations
- **Refs:** laravel-boost rules 1–6, plan §2, plan §12
- **Depends on:** T031
- **Files:** `workflow/slices/m3-supervisor-corrections/plan.md` (edit §2 "Re-run" line and §12 only)
- **Do:** Documentation task. Re-read `workflow/rules/laravel-boost.md` and `CLAUDE.md`; list each Boost call actually made (and any skipped or failed); record the dated re-run in plan §2 and any divergence from the plan (for example fixture fixes from T006) as dated lines in §12, never rewriting history.
- **Verify:** plan §2 has a dated "Re-run" paragraph; §12 lists each deviation.
- **Done when:** the plan matches reality.
- **Out of scope:** any code change.

**Checkpoint 5:** `bash scripts/verify.sh` is green; the README walkthrough and manual checks are done or handed over; the plan's rules check is re-run.

---

## Coverage
| PRD ID | Task(s) |
|---|---|
| FR10 | T010, T016, T028 |
| FR10.1 | T010, T014, T016 |
| FR10.2 | T016, T019, T020 |
| FR6 | T007, T011, T017 |
| FR6.1 | T007, T011 |
| FR6.2 | T011, T017 |
| FR6.3 | T011 |
| FR6.4 | T007, T011 |
| FR6.5 | T011, T012 |
| FR6.6 | T007, T012 |
| FR6.7 | T011 |
| FR6.8 | T002, T011, T022 |
| FR6.9 | T016, T017 |
| FR6.10 | T005, T011, T021, T022 |
| FR6.11 | T010, T011, T013 |
| FR6.12 | T011, T017 |
| FR7 | T007, T009 |
| FR7.1 | T009, T018 |
| FR7.2 | T009, T018 |
| FR8 | T014, T018 |
| FR3.4 | T014, T025 |
| FR15 | T008, T018, T024 |
| FR16 | T018, T024 |
| FR17 | T015, T026 |
| FR18 | T027, T028 |
| FR18.1 | T023, T028 |
| F3 | T007, T011, T019, T020, T030 |
| NFR1 | T011, T021 |
| NFR2 | T021 |
| NFR3 | T027, T030 |
| NFR5 | T010, T013 |
| NFR6 | T015, T026, T030 |
| NFR-M3.1 | T007 |
| NFR4 | N/A in M3 (verified in M4) |
| AC-US1.1 | T010, T015, T016, T030 |
| AC-US1.2 | T010, T016 |
| AC-US1.3 | T016, T023 |
| AC-US1.4 | T016 |
| AC-US1.5 | T010, T013, T016 |
| AC-US2.1 | T007, T011, T014, T017 |
| AC-US2.2 | T011 |
| AC-US2.3 | T011, T017 |
| AC-US2.4 | T011, T017, T023 |
| AC-US2.5 | T017 |
| AC-US2.6 | T028, T030 |
| AC-US3.1 | T011, T017 |
| AC-US3.2 | T011, T017 |
| AC-US3.3 | T011 |
| AC-US3.4 | T008, T018 |
| AC-US3.5 | T011 |
| AC-US4.1 | T007, T011, T014 |
| AC-US4.2 | T012 |
| AC-US5.1 | T007, T012 |
| AC-US5.2 | T008, T012 |
| AC-US5.3 | T012 |
| AC-US5.4 | T012 |
| AC-US6.1 | T009, T018 |
| AC-US6.2 | T009, T018 |
| AC-US6.3 | T018, T024 |
| AC-US6.4 | T018 |
| AC-US6.5 | T018 |
| AC-US7.1 | T021 |
| AC-US7.2 | T021, T022 |
| AC-US7.3 | T021 |
| AC-US7.4 | T013 |
| AC-US7.5 | T021 |
| AC-US8.1 | T015, T026, T030 |
| AC-US8.2 | T026 |
| AC-US8.3 | T015, T030 |
| AC-US9.1 | T027 |
| AC-US9.2 | T027, T030 |
| AC-US9.3 | T028 |
| AC-US9.4 | T028, T030 |
| AC-US10.1 | T007, T011 |
| AC-US10.2 | T001, T005, T006, T019, T030 |
| AC-US10.3 | T014, T020 |

Plan decisions without a PRD ID, and the tasks that implement them: DD1 → T005; DD2 → T004, T006; DD3 → T002; DD4 → T011; DD5 → T007, T011; DD6 → T010, T011, T013; DD7 → T008; DD8 → T009; DD9 → T026; DD10 → T017; DD11 → T019; DD12 → T014; DD13 → T027, T028.

## Blocked / deferred
| Task | Reason | Needs |
|---|---|---|
| none | | |
