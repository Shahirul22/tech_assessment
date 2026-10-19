# Plan: M3 — Supervisor corrections and restock

| Field | Value |
|---|---|
| Slice | M3 |
| PRD | [prd.md](prd.md) (Approved 2026-10-20) |
| Status | Approved (2026-10-20, by the user; §11 items 1 and 2 and the approvals listed there accepted as proposed) |
| Date | 2026-10-20 |

## 1. Approach summary
M3 adds two supervisor writes (restock, correct or void) beside the operator submit, all three going through the same pool lock and the same ledger numbering, and makes the correction step of the calculator real. **The single most important idea: a correction never changes a stored row. It is one more ledger entry, and the pool, the statuses, the log and the audit view are all derived from "original allocations + all corrections" by the same pure pipeline (corrections → expiry → sum and status).** Corrections apply per allocation, last one by `seq` wins, so lowering, raising, voiding and repeated corrections need no special cases. A raise is checked under the lock against the pool as it stands now (D7); everything about the past ("overdrawn in hindsight", status before and after) is computed on read by replaying the corrected ledger up to each point, in commit order, at the same non-decreasing instant M2 already uses (this is the ordering-key decision the M1 carry-in asked for).

## 2. Rules check
Read before designing: `workflow/rules/laravel-boost.md` (the only rule file; `workflow/constitution.md` and `workflow/boundaries.md` do not exist), the `CLAUDE.md` project rules, the M1 and M2 plans (contracts and §11 carry-ins). `.ai/rules` does not exist (checked 2026-10-20). Boost was called for this plan: `application-info` (Laravel 13.37, Pest 4.7, Reverb 1.12, PostgreSQL), `search-docs` "database transactions pessimistic locking lockForUpdate" / "migrations modifying columns add column" / "validation rules integer prohibited" / "route model binding scoping" / "broadcasting events after commit", and `database-schema` for `corrections` and `restocks`.

| Rule (cite file / ID) | Complies? | How |
|---|---|---|
| laravel-boost rule 1 (`search-docs` before Laravel code) | Yes, by task design | This plan writes no code. Every Laravel task in §10 names the `search-docs` query to run first (§9 "Boost queries"). The queries above informed the design (lock inside a transaction, additive migration, scoped binding). |
| laravel-boost rule 2 (real schema via `database-schema`; tinker for behaviour; `last-error` when debugging) | Yes | `database-schema` was read for `corrections` and `restocks` (confirms no `idempotency_key` on restocks and no "from" amount on corrections, §5). Tasks that touch tables re-read it. Boost 2.10.3 has no `tinker` tool, so `php artisan tinker` is used (as recorded in M1 plan §2). |
| laravel-boost rule 3 (`application-info` at session start) | Yes | Called this session; task T-0.1 calls it again at the start of implementation. |
| laravel-boost rule 4 (never continue without Boost) | Yes | Boost was reachable. T-0.1 confirms again. |
| laravel-boost rule 5 (each task note names the documentation query) | Yes, by task design | Part of every Laravel task's Verify line. |
| laravel-boost rule 6 (Boost never overrides `requirement.md` §0, the PRD or the plan) | Yes, with items raised | Nothing in Boost guidance conflicts. Two PRD matters are raised in §11 (refused-replay wording, hindsight window wording). |
| `CLAUDE.md`: existing directory structure, no new base folders, no dependency changes | Yes, one item | New PHP goes under `app/Pool/...` (one new sub-folder `app/Pool/Audit/`, inside the existing base folder); no new package. |
| `CLAUDE.md`: "no documentation files unless requested" | Yes | Only `docs/api-contract.md` and `README.md` (both exist, both carried by the PRD's F3/F5 hand-off) are edited. |
| `CLAUDE.md`: do not delete tests | Yes, with approval items in §11 | The four M3 `->todo()` tests become real tests with the same names; the 501 stub checks in `RolesAndStubsTest` change because the endpoints are real. Both flagged in §11 so approval covers them. |
| `CLAUDE.md`: "Do not change existing tables" is not a Boost rule; M1 F2 asks for no change to existing tables | Deviation, flagged | Two nullable-safe columns are added (§5, DD3). M1 plan §11 already named a `corrections` change as likely and "M3 owner". Flagged in §11. |
| `requirement.md` §0, D1, D2, D7, D8, D10, D11, D12 | Yes | §7 DD1–DD12. |
| Approved PRD M3 non-goals | Yes | Nothing in §4–§6 changes anything but amount/void, adds capacity only by restock, moves `expires_at`, or touches sunlight/one-thumb work. |

**Re-run of the rules check at the end (T-5.4), 2026-10-20 (task T032):** `workflow/rules/laravel-boost.md` and `CLAUDE.md` were read again. `workflow/constitution.md`, `workflow/boundaries.md` and `.ai/rules` still do not exist. Boost calls actually made while implementing: `application-info` (session start; Laravel 13.37.0, Pest 4.7.8, Reverb 1.12.0); `search-docs` `migrations modifying columns add column` and `eloquent factories` (T002), `database transactions pessimistic locking lockForUpdate` and `broadcasting events after commit` (T005), `validation rules integer prohibited`, `route model binding scoping` and `validation required_if prohibited_if` (T016, T017), `blade directives` and `blade components` (T027); `database-schema` filtered on `corrections` (T002). Skipped or not used: `search-docs` `Pest todo` and `testing travel time` (listed in §9; not run, the Pest tests used the project's existing helpers and `FrozenClock`), `database-query` (never needed: the schema read and the tests were enough), `last-error` and `browser-logs` (no failure needed them; page errors were read from Playwright in the browser checks), Boost has no `tinker` tool so `php artisan tinker` was used only to reset the demo pool after manual checks, never to check behaviour. Pure PHP and JavaScript tasks say in their note that no Boost query applied. Nothing in Boost's guidance conflicted with `requirement.md` §0, the PRD or this plan (rule 6); the one conflict found was inside this plan (see §12, 2026-10-20 row 1) and is reported, not resolved silently.

## 3. Technical context
Unchanged from M1 and M2 (PHP 8.4, Laravel 13.x, PostgreSQL, Blade + Alpine + Vite, Reverb + Echo, Pest 4, `node --test`). Only differences:

| Item | Choice |
|---|---|
| New dependencies | None. |
| Storage | Two additive columns (§5); no new table. |
| Concurrency | Same single pool-row lock (`lockForUpdate` in a READ COMMITTED transaction, 5 s lock timeout → `503 pool_busy`) for operator submits, restocks and corrections. |
| Time | The evaluation instant becomes non-decreasing across **all** ledger entries (restocks and corrections too, not only allocations), DD2. |
| Testing | Pest on PostgreSQL with `FrozenClock`; pure unit tests for the correction step, the replay and the status history; real parallel HTTP for the races; `node --test` for the screens. |
| Constraints | p95 ≤ 1 s for submit, restock and correction (NFR2); other screens ≤ 2 s (NFR6). The audit view is a read that replays the ledger; it is not on any write path. |

## 4. Architecture

```
Supervisor screen ──POST restock / correction──► RestockService / CorrectionService ─┐
Operator screen   ──POST allocation────────────► AllocationService ──────────────────┤  PoolWriteGate::locked(pool, work)
                                                                                      │   (transaction, FOR UPDATE, 55P03→PoolBusy)
                                      under the lock:  LedgerRepository::load → idempotency lookup → replay | evaluate
                                                       evaluate = Ledger::evaluationInstant → PoolCalculator::calculateAt
                                                                → check (raise only) → LedgerSequence::next → insert row
                                      after commit:    PoolWriteGate::announce(pool.updated)  (deferred, never fails the request)

GET /pools/{p}        SnapshotService ─ calculateAt(now) ─┬─ remaining / viewer (status, holdings)
                                                          └─ effective ledger → LogQuery (corrected units, derived expiry entries)
GET /pools/{p}/audit  AuditService ─ HistoryReplay (hindsight windows) + StatusHistory (before/after per correction)
```

**Components and what changes**
- **`ApplyCorrections`** (M3 owns, `app/Pool/Calculation/Steps/`): pure. Sorts corrections by `seq`; for each allocation the last correction wins: `amend` sets `units = newAmount`, `void` sets `units = 0` and `voided = true`. It records `correctedFrom` (the original granted units) on the entry when anything changed. Corrections that point at an unknown allocation are ignored (cannot happen in the database, which has a foreign key).
- **`AllocationEntry`** gains two trailing optional fields (`?int $correctedFrom = null`, `bool $voided = false`). **`RestockEntry`** and **`CorrectionEntry`** gain a trailing optional `?CarbonImmutable $at = null` (restock `recorded_at`, correction `effective_at`). All defaults keep every existing constructor call and test valid (contracts only grow).
- **`Ledger`** gains `asOf(int $seq): Ledger` (entries with `seq <= $seq`; used by replay and by the audit), `instantAt(int $seq): ?CarbonImmutable` (the newest entry time among entries up to `$seq`, whatever the kind) and `evaluationInstant(CarbonImmutable $receipt): CarbonImmutable` (DD2). `AllocationService` stops carrying its own copies of this logic.
- **`PoolWriteGate`** (new, `app/Pool/PoolWriteGate.php`): `locked(Pool, Closure): mixed` (the transaction, the `lockForUpdate` on the pool row, the 55P03 → `PoolBusy` mapping) and `announce(...)` (the deferred, swallowed broadcast). Extracted from `AllocationService` with no behaviour change; the three writers use it (DD1).
- **`RestockService`** (new): `add(Pool, User $supervisor, AddRestock $command): RestockResult`. **`CorrectionService`** (new): `apply(Pool, User $supervisor, ApplyCorrection $command): CorrectionResult`; it owns the raise check (DD4). Both are idempotent under the lock (DD6).
- **`LogQuery`** / **`LogEntry`**: `correction` entries use the stored `from_amount` and carry the corrected allocation's operator; `allocation` entries show the original request and grant and carry `corrected_to` and `voided`; derived `expiry` entries and `expires_at` come from the **effective** ledger (corrected units), so a void leaves no expiry entry and a lowered entry shows the lowered units (A-M3.4). `LogQuery::latest(...)` receives the effective ledger from `SnapshotService` instead of reading allocations a second time.
- **`AuditService`** (new, `app/Pool/Audit/`): builds the supervisor audit view from the full ledger and the correction rows. Two pure helpers: **`HistoryReplay`** (remaining after every point in commit order, and the "overdrawn in hindsight" windows) and **`StatusHistory`** (the corrected operator's status before and after one correction, at chosen points) (DD7, DD8).
- **Controllers:** `RestockController`, `CorrectionController`, `AuditController` replace the three stubs; the `PUT /capacity` stub and `NotImplementedController` are removed (PQ3).
- **Browser:** `log-renderer.js` renders corrected/voided markers and the correction text; `pool-state.js` treats a `correction` event as "bump version and fetch one snapshot" (DD9); new `supervisor.js` (Alpine component state), `supervisor-rules.js` (pure form rules), `audit-view.js` (pure text builders); `api.js` gets three calls; `dashboard.blade.php` replaces the placeholder panel.

**Where the hard rules are enforced**
| Rule | Enforced in |
|---|---|
| Pool never negative (D2) | Raise check inside the lock against the freshly loaded ledger (`CorrectionService`); allocations unchanged. A raise needing exactly the free units passes; one more is refused. |
| Corrections, restocks and submits are ordered by one commit order (D1, FR6.10) | `PoolWriteGate::locked` + `LedgerSequence::next` for every writer; `LedgerSequenceTest` already fails if other code writes `pools.version` and is extended to the new writers. |
| Raise refused only against the current balance; expired entry is history only (D7, D11) | `CorrectionService::needsPoolCheck`: a raise is checked unless the entry is a Temporary one whose window has ended at the evaluation instant. |
| Append-only (D7, FR6.8) | Only `INSERT` into `corrections`; no `UPDATE`/`DELETE` of any ledger row exists in `app/` (a new arch-style test greps for it). |
| Original kept (FR8) | `allocations` rows are never touched; the log and audit read both. |
| Idempotent writers (FR6.11) | Key lookup inside the lock before any evaluation; `UNIQUE (pool_id, idempotency_key)` on the table as the backstop. |
| Only supervisors write (FR6.9) | Existing `role:supervisor` middleware on the three routes; `role:operator` on submit is unchanged. |

### Project structure (files this slice creates or changes)
```
.
├── database/migrations/2026_10_21_000001_add_m3_columns_to_ledger_tables.php   # NEW: corrections.from_amount, restocks.idempotency_key
├── database/factories/CorrectionFactory.php  RestockFactory.php                # from_amount, idempotency_key
├── app/Models/Correction.php  Restock.php                                      # fillable + casts
├── app/Pool/
│   ├── PoolWriteGate.php                      # NEW: lock + transaction + announce
│   ├── RestockService.php  AddRestock.php  RestockResult.php                   # NEW
│   ├── CorrectionService.php  ApplyCorrection.php  CorrectionResult.php        # NEW
│   ├── AllocationService.php                  # uses PoolWriteGate and Ledger::evaluationInstant/asOf (no behaviour change)
│   ├── LedgerRepository.php                   # loads restock.recorded_at, correction.effective_at
│   ├── SnapshotService.php                    # passes the effective ledger to LogQuery
│   ├── Calculation/
│   │   ├── Ledger.php  AllocationEntry.php  RestockEntry.php  CorrectionEntry.php   # asOf, instantAt, evaluationInstant; trailing fields
│   │   └── Steps/ApplyCorrections.php         # real
│   ├── Audit/AuditService.php  HistoryReplay.php  StatusHistory.php            # NEW
│   ├── Exceptions/CorrectionRefused.php  AllocationVoided.php                  # NEW (422 codes)
│   └── Log/LogEntry.php  LogQuery.php                                          # correction/allocation/expiry fields from the effective ledger
├── app/Http/Controllers/Api/RestockController.php  CorrectionController.php  AuditController.php   # NEW
│                           NotImplementedController.php                         # REMOVED
├── app/Http/Resources/LogEntryResource.php    # correction operator/kind/from/to; allocation corrected_to/voided
├── routes/api.php                             # three real routes; capacity stub removed
├── resources/js/
│   ├── supervisor.js  supervisor-rules.js  audit-view.js                      # NEW
│   ├── api.js  pool-state.js  log-renderer.js  dashboard.js
│   └── __tests__/supervisor-rules.test.js  audit-view.test.js  (+ changes to log-renderer, pool-state tests)
├── resources/views/dashboard.blade.php        # supervisor panel replaces the placeholder
├── resources/css/app.css                      # supervisor forms, audit list, hindsight flag (tokens only)
├── tests/Unit/CorrectionStepTest.php  LedgerInstantTest.php  HistoryReplayTest.php  StatusHistoryTest.php  CalculatorPendingTest.php (removed)
├── tests/Feature/RestockTest.php  CorrectionTest.php  CorrectionIdempotencyTest.php  CorrectionExpiryTest.php  AuditTest.php
│                 CorrectionLogTest.php  RolesAndStubsTest.php  SchemaContractTest.php  LedgerSequenceTest.php  BroadcastTest.php   # last five extended
├── tests/Concurrency/ParallelCorrectionTest.php   # group: concurrency
├── scripts/verify.sh                          # runs the new files
├── docs/api-contract.md                       # restock, correction, audit; capacity removed; log entry fields
└── README.md                                  # supervisor walkthrough and manual checks (short section)
```

## 5. Data model
Two additive changes in one migration (everything else M3 needs is already in M1's tables, M1 plan §5):

```sql
ALTER TABLE corrections ADD COLUMN from_amount integer NULL;
UPDATE corrections c SET from_amount = a.granted FROM allocations a WHERE a.id = c.allocation_id;   -- table is empty today; kept so the migration is safe anywhere
ALTER TABLE corrections ALTER COLUMN from_amount SET NOT NULL;
ALTER TABLE corrections ADD CONSTRAINT corrections_from_amount_check CHECK (from_amount >= 0);

ALTER TABLE restocks ADD COLUMN idempotency_key text NULL;          -- NULL for the seed
CREATE UNIQUE INDEX restocks_pool_id_idempotency_key_unique ON restocks (pool_id, idempotency_key);   -- several NULLs are allowed
```

- `corrections.from_amount` = the units the entry held right before this correction (the original grant for the first one, the previous correction's amount after that, never changed afterwards). It makes each correction a self-contained audit record (FR6.8) and spares the log a window query. It is written under the lock from the effective ledger.
- `restocks.idempotency_key` gives restocks the same replay rule as submits and corrections (FR6.11, NFR5). The seeded restock keeps NULL.
- Nothing is stored for status, hindsight flags, or "voided": all are derived (DD12 of M1 still holds). `voided` = the last correction of the entry is `void`.
- Unchanged and relied on: `corrections.seq/allocation_id/supervisor_id/kind/new_amount/reason/effective_at/recorded_at/idempotency_key` and the two CHECKs (amend ≥ 1; void has no amount). A key is unique **per table** per pool; a UUID per user action is assumed (documented in the contract).

Value-object changes (in memory only):
```php
final readonly class AllocationEntry { /* existing fields */ public ?int $correctedFrom = null, public bool $voided = false }
final readonly class RestockEntry    { public int $id, public int $seq, public int $amount, public ?CarbonImmutable $at = null }
final readonly class CorrectionEntry { /* existing fields */ public ?CarbonImmutable $at = null }
```

## 6. Interface contracts
All changes are **additions** except the removal of the `PUT /capacity` stub (PRD PQ3, F3). Detail goes into `docs/api-contract.md`. Conventions as before: `X-Demo-User` header, `Idempotency-Key` header (required on writes), error body `{"error":{"code","message","details"}}`, ISO-8601 UTC times with microseconds.

### POST /api/pools/{pool}/restocks
- **Purpose / PRD refs:** FR10, FR10.1, FR6.9–FR6.11, NFR1, NFR2, NFR5.
- **Request:** role `supervisor`; `Idempotency-Key` required. Body `{"amount": 50, "note": "Delivery"}`; `note` optional (string ≤ 255).
- **Response `201`** (new) or **`200` + `Idempotent-Replayed: true`** (same key and payload):
```json
{"restock":{"id":9,"seq":14,"actor":{"id":4,"name":"Dana"},"amount":50,"note":"Delivery","recorded_at":"…"},
 "pool":{"version":14,"remaining":50}}
```
- **Errors:** `400 missing_idempotency_key` · `401 unknown_user` · `403 forbidden_role` · `404` pool unknown · `409 idempotency_key_conflict` (same key, different actor, amount or note; nothing recorded) · `422 validation_failed` (`amount` not a JSON integer ≥ 1 and ≤ 2147483647; `details.fields`) · `503 pool_busy`.

### POST /api/pools/{pool}/allocations/{seq}/corrections
- **Purpose / PRD refs:** FR6, FR6.1–FR6.12, FR7, FR8, FR15, NFR1, NFR2, NFR5. `{seq}` is the entry number shown in the log (the allocation's `seq` inside this pool), so the screen needs no hidden id (DD10).
- **Request:** role `supervisor`; `Idempotency-Key` required. Body `{"kind":"amend","amount":20,"reason":"Counted wrong"}` or `{"kind":"void","reason":"Duplicate entry"}`. `reason` required, trimmed, 1–500 characters. `amount` is required for `amend` (JSON integer 1–2147483647) and not allowed for `void` (0 is not an amount: use `void`).
- **Response `201`** or **`200` + `Idempotent-Replayed: true`:**
```json
{"correction":{"id":3,"seq":15,"allocation_seq":1,"operator":{"id":1,"name":"Ana"},"kind":"amend",
   "from":40,"to":20,"reason":"Counted wrong","supervisor":{"id":4,"name":"Dana"},"effective_at":"…"},
 "pool":{"version":15,"remaining":20}}
```
`to` is `0` for a void. `pool` is the pool as it stood right after this correction, at the correction's evaluation instant.
- **Errors:** the writer errors above, plus `404` allocation unknown or in another pool · `409 idempotency_key_conflict` (same key, different allocation, kind, amount, reason or supervisor) · `422 validation_failed` (reason blank, amount invalid, amount on a void, **amount equal to the entry's current units** with `details.fields.amount = "no_change"`) · `422 allocation_voided` (the entry was voided; final, PQ4) · `422 insufficient_free_units` with `details {"needed":20,"free":0,"allocation_seq":1}` and message "Needs 20 more units, 0 are free. Restock first."; nothing is recorded in any 422.

### GET /api/pools/{pool}/audit
- **Purpose / PRD refs:** FR16, FR15, FR7.2, FR8, PQ2. Role `supervisor`. Reads one consistent view (REPEATABLE READ, no lock).
- **Response `200`:**
```json
{"version":15,"server_time":"…",
 "corrections":[{"seq":15,"allocation_seq":1,"operator":{"id":1,"name":"Ana"},"kind":"amend","from":40,"to":20,
   "reason":"Counted wrong","supervisor":{"id":4,"name":"Dana"},"at":"…",
   "original":{"requested":40,"granted":40},
   "status":{"before":"high","after":"elevated",
     "points":[{"label":"when corrected","seq":15,"at":"…","before":"high","after":"elevated"},
               {"label":"after Ana's request #12","seq":12,"at":"…","before":"high","after":"elevated"}]}}],
 "hindsight":[{"from":{"seq":2,"at":"…"},"to":{"seq":14,"at":"…"},"lowest":-20,"corrections":[15]}]}
```
`corrections` are in commit order (oldest first). `status.before/after` are the corrected operator's status at the correction moment; `points` lists the first point always and then only the later points where `before != after` (DD8). `hindsight` is empty when the corrected history never goes below 0. An empty history gives `{"corrections":[],"hindsight":[]}`.
- **Errors:** `401`, `403 forbidden_role`, `404`.

### Existing contracts that grow
- **Log entry `correction`** (shape from M1 stays, fields added): `{"seq":15,"cursor":"15","kind":"correction","at":"…","supervisor":{…},"operator":{"id":1,"name":"Ana"},"allocation_seq":1,"change":"amend"|"void","from":40,"to":20,"reason":"…"}`.
- **Log entry `allocation`:** adds `corrected_to` (int or null; the current units when corrections changed them) and `voided` (bool). `requested` and `granted` keep their original values (FR8). `expires_at` follows the corrected units.
- **Log entry `expiry`:** now derived from the effective ledger: `units` is the corrected amount; none for a voided entry.
- **Broadcast `pool.updated`:** payload shape unchanged; `entry` can now be a `restock` or `correction` entry. Sent after commit, for every stored entry.
- **Snapshot and submit response:** unchanged shape. `viewer.status` and `viewer.holdings` already come from the effective ledger, so a correction shows up in them with no change.
- **Removed:** `PUT /api/pools/{pool}/capacity` (stub). Capacity is raised only by restocks.

### Calculation contract (PHP)
```php
ApplyCorrections::apply(Ledger $ledger): Ledger
//  corrections sorted by seq; per allocation id the LAST one wins:
//    amend → units = newAmount;  void → units = 0, voided = true
//  sets correctedFrom = original units when the result differs; allocations, restocks and corrections lists otherwise unchanged
Ledger::asOf(int $seq): Ledger                                  // entries with seq <= $seq, all three lists
Ledger::instantAt(int $seq): ?CarbonImmutable                   // newest `at`/effectiveAt among entries with seq <= $seq
Ledger::evaluationInstant(CarbonImmutable $receipt): CarbonImmutable   // max($receipt, instantAt(PHP_INT_MAX))
RestockService::add(Pool, User $supervisor, AddRestock): RestockResult         // RestockResult(Restock, PoolState, bool $replayed)
CorrectionService::apply(Pool, User $supervisor, ApplyCorrection): CorrectionResult   // CorrectionResult(Correction, PoolState, bool $replayed)
HistoryReplay::points(Ledger $full, CarbonImmutable $now): list<HistoryPoint>   // HistoryPoint(?int seq, int anchor, CarbonImmutable at, string kind, int remaining)
HistoryReplay::overdrawn(array $points): list<HindsightWindow>
StatusHistory::forCorrection(Ledger $full, int $correctionSeq, CarbonImmutable $now): StatusChange   // before/after + points
```

### Client modules (pure, unit-tested)
```js
// supervisor-rules.js
restockProblem(amountText)                         // null or a short text; integer >= 1
correctionProblem({kind, amountText, reasonText, currentUnits})   // null or text: blank reason, 0/negative/fractional, "no change"
// audit-view.js
auditRows(audit, viewerTz)                         // text rows: "Dana · 40 → 20 · Counted wrong · Ana: high → elevated"
hindsightText(window)                              // "Overdrawn in hindsight: lowest -20 between 10:00 and 13:00"
// pool-state.js
applyEvent(state, event)                           // restock: as before; correction: returns {needsSnapshot: true} after bumping the version
```

## 7. Key design decisions
| ID | Decision | Alternatives considered | Why this one | PRD refs |
|---|---|---|---|---|
| DD1 | Extract `PoolWriteGate` (lock, transaction, lock-timeout mapping, deferred announce) from `AllocationService` and use it for all three writers. | Copy the lock code into each service (three copies of the rule that keeps the pool correct); a base class for the services (couples unrelated services). | One place enforces "one writer at a time"; M1/M2 tests (the 50×3 race, the expiry race) guard the refactor. | FR6.10, D1, NFR1 |
| DD2 | The evaluation instant for **every** write is `max(receipt time, newest time of any ledger entry)`, where entry time is `effective_at` for allocations and corrections and `recorded_at` for restocks. Replays and the audit use the same `instantAt(seq)`. | Keep M2's rule (allocations only): a correction received before an allocation but committed after it would be evaluated at an earlier instant, so units that expired for the allocation would be held again for the correction and the reply's `remaining` would jump backwards. | One non-decreasing clock for the whole ledger keeps each reply consistent with the ledger it was served from, and a replay reproduces it. **This is the ordering-key decision of the M1 carry-in:** history is walked in `seq` (commit) order; time only decides expiry, through this instant. | NFR1, D1, M1 carry-in, FR6.11 |
| DD3 | Add `corrections.from_amount` and `restocks.idempotency_key` in one migration (§5). | Derive "from" with a window function on every log read; no restock key (a lost response would double a restock); a separate idempotency table (second source of truth). | A correction stays a complete audit record; restocks get the same replay rule as the other writers. Small, additive, reversible. Deviates from "no change to existing tables" (M1 F2), flagged in §11. | FR6.8, FR6.11, NFR5, F2 |
| DD4 | The raise check runs under the lock, on `calculateAt(ledger, instant)`: `delta = new − currentUnits` (from the effective entry); refuse when `delta > remaining` **unless** the entry is Temporary and its window has ended at the instant (`instant >= rules.expiresAt(tierAtLog, effectiveAt)`, computed from the stored entry, so a zero-grant entry works too). Lowering, voiding and expired-entry corrections never consult the pool. | Check against a pre-lock read (races a submit); check against `remaining` for expired entries (blocks a history-only change, contradicts D7/D11); refuse a raise of an expired entry only if it would dip the past (D11 says the past is not checked). | The only reader of the balance is the one under the lock, so the pool can never go negative (D2) however a correction and a submit interleave. | FR6.1–FR6.6, D2, D7, D11, NFR1 |
| DD5 | `ApplyCorrections` is "last correction per allocation by `seq` wins". A voided entry cannot be corrected again (`422 allocation_voided`). A correction with the entry's current amount is `422 validation_failed` (`no_change`). | Sum of deltas (order-dependent rounding of intent, harder to audit); allow un-void (PQ4 says final); allow a no-op (clutters the log and the audit). | Matches PQ4 exactly; the pure step is trivial to test; every correction judged against the current corrected amount. | FR6, FR6.12, PQ4, NFR-M3.1 |
| DD6 | Idempotency for restocks and corrections: key looked up inside the lock **before** anything is evaluated; same payload returns the stored result with the pool as of that entry (`Ledger::asOf(seq)` at `instantAt(seq)`); a different payload is `409`. A **refused** request stores nothing, so a replay of a refused request is evaluated again (see §11 item 1). | Store refusals (a ledger row or table for non-events; contradicts "records nothing", AC-US3.1); replay answered from a cache (second source of truth). | Same mechanism as M1/M2 submit replay, identical by construction even after the entry is corrected again (AC-US7.4). | FR6.11, NFR5, AC-US7.4 |
| DD7 | "Overdrawn in hindsight" is computed on read by `HistoryReplay`: walk stored entries in `seq` order plus derived expiry points (placed by the DD4-of-M2 anchor rule, so the audit and the log agree); at each point evaluate `calculateAt(full.asOf(cutoff) with ALL corrections, instant)`; a **window** starts at the first point with remaining < 0 and ends at the first later point with remaining ≥ 0 (a restock or an expiry). Each window lists the corrections that raise an entry at or before it. Never blocks anything. | Store a flag per correction (stale as soon as a later restock lands); recompute on every snapshot (cost on the hot read); check the past on write (D11 forbids). | The flag is always true to the current ledger. Cost grows with the ledger and is paid only by the supervisor's audit read (fine at demo scale, §11). | FR15, D11, NFR3 |
| DD8 | Status history per correction `C` (PQ2): `StatusHistory` compares the corrected operator's status with the corrections `seq < C` ("before") and `seq <= C` ("after") at: the correction moment; each of that operator's own entries from the corrected one on; each of their expiries; and now. Only differing points are listed after the first. Other operators are never listed (FR7.1). | Compare "with C" and "without C" using all other corrections (later corrections of the same entry would hide C's effect); show every point (noise); store snapshots at write time (stale). | Shows what C changed at the time it was made, which is what the supervisor audits; one rule gives the "now" and the "downstream" points (PRD AC-US6.1). | FR7, FR7.1, FR7.2, FR16, PQ2 |
| DD9 | A `correction` event makes the client bump its version and fetch **one snapshot**; a `restock` event applies as an ordinary entry (remaining and log). The supervisor's own screen updates from the HTTP response and also refreshes. | Put the corrected operator's new status and the changed rows in the event (a second place that computes status and expiry entries); client-side recompute (breaks "server only computes"). | A correction can change a row, a status, holdings and a derived expiry entry at once; one snapshot fixes all of them in the 2 s budget, and it is the same path as a gap. | FR17, NFR6, D12 |
| DD10 | The correction route addresses the allocation by its **`seq`** within the pool (`/allocations/{seq}/corrections`), looked up through `$pool->allocations()`. | By database id (the log does not show ids; the screen would carry a hidden field); implicit model binding on `id` with `scopeBindings` (works, but the number a supervisor sees is `seq`). | The number in the log is the number in the URL; a wrong pool or seq is a plain `404`. | FR18, AC-US2.5 |
| DD11 | `PUT /capacity` is removed from the routes and the contract; `NotImplementedController` is deleted. | Keep it as an alias of restock (a second way to do one thing); implement set-capacity (needs a lowering rule D8 does not give). | PQ3 (accepted 2026-10-20). | FR10.2, PQ3, F3 |
| DD12 | Expiry log entries and `expires_at` are derived from the **effective** ledger passed to `LogQuery`; the derived entry keeps M2's anchor rule. | Keep reading stored `granted` in `LogQuery` (a void would still show an expiry; a lowered one the wrong units). | A-M3.4: derived entries follow the corrected ledger with no extra code path. | FR3.4, A-M3.4, FR6.6 |
| DD13 | Supervisor screen: log rows of kind `allocation` get a "Correct" button for supervisors; it fills the correction form (entry number, current units, new amount or void, reason). Restock form beside it; the audit view below. Pending, offline and refusal text reuse the M1 submit-flow states. | A separate supervisor page (new route and shell); free entry of the entry number (typing under pressure). | Fewest new parts; M4 can polish the same elements. | FR18, FR18.1, NFR3 |

## 8. Requirement-to-design traceability
| PRD ID | Where it is handled |
|---|---|
| FR10, FR10.1 | `RestockService`, `RestockController`, `restocks` row, `restock` log entry, DD6 |
| FR10.2 | DD11, validation (`amount ≥ 1`, no lowering path) |
| FR6, FR6.1, FR6.3, FR6.4 | `CorrectionService`, `ApplyCorrections` (DD4, DD5) |
| FR6.2 | `CorrectionService` raise check; `CorrectionRefused` → `422 insufficient_free_units` (DD4) |
| FR6.5 | `CorrectionService::needsPoolCheck` (DD4); `CorrectionExpiryTest` |
| FR6.6 | `ApplyCorrections` keeps `effectiveAt`/`tierAtLog`, so `ApplyExpiry` recomputes the same `expires_at` on the corrected units |
| FR6.7 | No age check anywhere; `CorrectionTest` corrects the oldest entry |
| FR6.8 | `corrections` insert-only row with `from_amount`, `supervisor_id`, `reason`, times (DD3); append-only guard test |
| FR6.9 | `role:supervisor` middleware on the three routes; `RestockTest`, `CorrectionTest` 403 cases |
| FR6.10 | `PoolWriteGate`, `LedgerSequence::next` (DD1, DD2) |
| FR6.11 | DD6; `CorrectionIdempotencyTest`, `RestockTest` |
| FR6.12 | DD5; request validation and `AllocationVoided` |
| FR7, FR7.1, FR7.2 | `PoolCalculator` pipeline with real `ApplyCorrections`; `StatusHistory` (DD8) |
| FR8 | `allocations` rows untouched; log shows original and `corrected_to`/`voided`; audit `original` block |
| FR3.4 | `LogEntry::fromCorrection`, `LogEntryResource`, `log-renderer.js` (text labels) |
| FR15 | `HistoryReplay::overdrawn` (DD7), `audit.hindsight`, `audit-view.js` |
| FR16 | `AuditService`, `AuditController`, `audit-view.js` |
| FR17 | `PoolWriteGate::announce`, DD9, `pool-state.js` |
| FR18, FR18.1 | DD13; `supervisor.js`, `supervisor-rules.js`, `dashboard.blade.php` |
| F3 | Real `ApplyCorrections`; four todos → `CorrectionStepTest`; stubs replaced; contracts additive (§6) |
| NFR1 | DD1, DD2, DD4; `ParallelCorrectionTest` |
| NFR2 | One extra INSERT per write; no new query inside the lock beyond the shared ledger load; p95 asserted in `ParallelCorrectionTest` |
| NFR3 | CSS uses M1 tokens; manual check list (README) |
| NFR5 | DD6 |
| NFR6 | DD9; `BroadcastTest`, `pool-state.test.js`, manual two-browser check |
| NFR-M3.1 | `ApplyCorrections` is pure and ordered by `seq`; `CorrectionStepTest` shuffles input order |
| NFR4 | Out of scope (M4); every new row carries a text label |

## 9. Test strategy
| Level | What it proves | Maps to AC | Notes |
|---|---|---|---|
| **Unit (hard rule) `CorrectionStepTest`** | The four former todos, now real: lowering frees units (40 → 20: remaining 0 → 20); a raise leaves units as the step computes (the refusal is the service's job, tested below); a void frees all; an expired Temporary entry changes history only (current remaining unchanged). Last correction per allocation wins; input order does not matter; unknown allocation ignored. | AC-US2.1, 4.1, 5.1, FR7 | `FrozenClock`; pure |
| Unit `LedgerInstantTest` | `instantAt` and `evaluationInstant` include restocks and corrections; non-decreasing; `asOf` filters all three lists. | DD2, AC-US7.1, 7.4 | |
| Unit `HistoryReplayTest` | The §4 example: Ana 60, Ben 50, Cara 10, restock +50 at 13:00 → remaining 40, −10, −20, 30; window starts at Ben's entry (−10), lowest −20 at 11:00, ends at the restock; no window when no raise; an expiry point can end a window; window lists the raising correction. | AC-US3.4, 5.2 | Data from the PRD example |
| Unit `StatusHistoryTest` | Ana 28 then 5, first lowered to 20: point after the second request high → elevated; correction moment elevated → elevated; only the corrected operator; threshold edges (30 → 29, 10 → 9). | AC-US6.1, 6.2 | Thresholds 0/10/30 |
| Unit `PoolCalculatorTest`, `ComputeStatusTest`, `ExpiryStepTest` (existing) | Unchanged results. | AC-US10.2 | Must pass untouched |
| **Feature (hard rule) `CorrectionTest`** | The §4 scenarios: lower 40 → 20 frees 20 and Cara then gets 20; raise 40 → 60 with 0 free refused (`insufficient_free_units`, needed 20, free 0, nothing recorded, version unchanged); restock +50 then the same raise accepted (remaining 30); raise by exactly the free units accepted, by one more refused; void; correction of the oldest entry; validation cases (blank reason, 0, fractional, `no_change`, amount on a void); unknown or other-pool allocation → 404; voided entry → `allocation_voided`; operator → 403. | AC-US2.1–2.5, 3.1–3.3, 4.1, 4.3 (PQ4) | Real PostgreSQL, `FrozenClock` |
| Feature `CorrectionExpiryTest` | Expired Temporary corrected: current remaining and status unchanged, history changed, raise accepted with 0 free; active Temporary lowered 4 → 2: +2 now, +2 more at exactly T+24h; raised 4 → 6 with 2 free: `expires_at` unchanged, 6 return; void of an active one: nothing returns at expiry and no expiry entry. | AC-US4.2, 5.1–5.4 | Advance `FrozenClock` ±1 ms |
| Feature `CorrectionIdempotencyTest` | Replay returns the identical body after the entry was corrected again; different amount, entry, kind, reason or supervisor → 409 and nothing recorded; 10 replays record one row. | AC-US7.4 | |
| Feature `RestockTest` | Restock +50 raises remaining and logs the entry with actor and note; Ben can then take 50; invalid amounts → 422; operator → 403; replay and conflict; seq unique and gapless with allocations. | AC-US1.1–1.5 | |
| Feature `AuditTest` | The audit lists corrections oldest first with original, corrected, supervisor, reason, status before/after; first point always, later points only if different; hindsight window for the §4 example; empty state; operator → 403; uncorrected entries absent. | AC-US6.1–6.5, 3.4 | |
| Feature `CorrectionLogTest` | Log shows the correction entry (from → to, voided), the original allocation entry with `corrected_to`/`voided`, derived expiry entries following corrected units, `before=` paging still works. | AC-US2.1, 4.1, FR3.4, A-M3.4 | |
| Feature `SchemaContractTest`, `LedgerSequenceTest` (extended) | New columns and constraints present; sequence numbers unique and gapless across restocks, allocations and corrections; no code outside `LedgerSequence` writes `pools.version`; no `UPDATE`/`DELETE` of ledger tables in `app/`. | AC-US7.2, AC-US10.2 | |
| Feature `RolesAndStubsTest` (changed), `BroadcastTest` (extended) | The three M3 endpoints are real (the 501 checks for them are removed; supervisor/operator role checks stay); `PUT /capacity` is a 404/405 route; restock and correction events are sent after commit with the right version and entry; failure to broadcast does not fail the request. | AC-US8.1–8.3, US10.2 | |
| Feature `DashboardViewTest` (extended) | Supervisor sees the restock form, the correction form and the audit container instead of the placeholder; operator sees none; no horizontal-scroll markup issues. | AC-US9.1 | |
| **Concurrency (hard rule) `ParallelCorrectionTest`** | (a) 20 rounds on a fresh pool: remaining 20, a raise needing 20 and a request of 20 sent at once: exactly one wins, the other gets `insufficient_free_units` or "0 of 20 granted", remaining never < 0, ledger replay in `seq` order equals each reply. (b) 50 requests of 3 with a lowering (frees 20) and a restock (+10) in the middle: total granted ≤ capacity + freed + restocked, `remaining ≥ 0` after every commit, seq unique and gapless, p95 ≤ 1 s for all three kinds. (c) Two supervisors lower the same entry at once: one succeeds, one gets `no_change`. | AC-US7.1, 7.2, 7.3, 7.5 | Group `concurrency`; own pool; cleans up |
| Concurrency (existing) 50×3, 6+6 of 10, 10× same key, `ParallelExpiryTest` | Unchanged results. | AC-US10.2 | |
| JS `supervisor-rules.test.js` | Restock and correction form problems (blank reason, 0, negative, fractional, "no change", void with amount). | AC-US1.3, 2.4 | `node --test` |
| JS `audit-view.test.js` | Row text, hindsight text, empty state. | AC-US6.3, 3.4 | Fixed time zone |
| JS `log-renderer.test.js`, `pool-state.test.js` (extended) | Correction and restock text with labels and the voided/corrected marker; own flag; a `correction` event bumps the version and requests one snapshot, a `restock` event applies directly; out-of-order and duplicate events ignored. | AC-US8.1, 8.2, FR3.4 | |
| JS `submit-flow.test.js` (existing) | Offline and pending states still pass; supervisor writes reuse them. | AC-US9.4, 2.6 | |
| Manual (README) | Two browsers on the stack: Dana restocks and corrects, Ana's and Ben's screens update within 2 s; 360 px and 1280 px without horizontal scroll; `reverb` stopped: the 10 s refresh still catches up; offline state on the supervisor forms. | AC-US8.1, 8.3, 9.2, 9.4 | |
| `scripts/verify.sh` | Runs all of the above in M1's order, plus the new files. | AC-US10.2 | |

**Boost queries to run before the Laravel tasks** (named in each task note): `database transactions pessimistic locking lockForUpdate` (T-1.2, T-2.x); `migrations modifying columns add column` and `database-schema` for `corrections`/`restocks` (T-1.1); `validation rules integer prohibited` (T-2.2, T-3.1); `route model binding scoping` (T-3.1); `broadcasting events after commit` (T-1.2, T-2.4); `eloquent factories` (T-1.1); `Pest todo` and `testing travel time` (T-1.4, tests); `blade directives` / `blade components` (T-4.5).

## 10. Task outline

- **Phase 0 — Setup**
  - T-0.1 Call Boost `application-info`; open `.ai/rules/index.md` if present; confirm the M1 and M2 suites are green (`APP php artisan test`, `NODE npm test`, and `--group=concurrency`) as the baseline.
- **Phase 1 — Foundation (blocks everything after; US2–US5, US7 server logic)**
  - T-1.1 Migration for `corrections.from_amount` and `restocks.idempotency_key`; models, factories, `LedgerRepository` loading the entry times; `SchemaContractTest` additions.
  - T-1.2 `PoolWriteGate`; refactor `AllocationService` onto it with no behaviour change; the full M1/M2 suite must stay green.
  - T-1.3 `Ledger::asOf/instantAt/evaluationInstant`, entry trailing fields; move `AllocationService`'s instant logic onto them; `LedgerInstantTest`.
  - T-1.4 `ApplyCorrections` real + `CorrectionStepTest` (the four former todos become real tests); remove the empty `CalculatorPendingTest.php`.
  - T-1.5 [P] `HistoryReplay` + `HistoryReplayTest`.
  - T-1.6 [P] `StatusHistory` + `StatusHistoryTest`.
- **Phase 2 — Writers (US1, US2, US3, US4, US5, US7)**
  - T-2.1 `AddRestock`, `RestockResult`, `RestockService`; `RestockTest` (service level).
  - T-2.2 `ApplyCorrection`, `CorrectionResult`, `CorrectionRefused`, `AllocationVoided`, `CorrectionService` (raise check, replay, conflict); `CorrectionTest` service cases, `CorrectionExpiryTest`, `CorrectionIdempotencyTest`.
  - T-2.3 `LogEntry`, `LogQuery`, `LogEntryResource`, `SnapshotService`: corrected entries, effective-ledger expiry entries; `CorrectionLogTest`; adjust `LogAndSnapshotTest`/`LogEntryResourceTest`/`BroadcastTest` shapes.
  - T-2.4 Announce restock and correction entries through `PoolWriteGate::announce`; `BroadcastTest` additions.
- **Phase 3 — API and audit (US1–US6)**
  - T-3.1 `RestockController`, `CorrectionController`, routes (stubs and `NotImplementedController` removed, capacity route gone), validation; HTTP-level cases in `RestockTest`/`CorrectionTest`; update `RolesAndStubsTest`.
  - T-3.2 `AuditService`, `AuditController`; `AuditTest`.
  - T-3.3 `docs/api-contract.md` additions and the capacity removal.
  - T-3.4 `ParallelCorrectionTest` (races a, b, c) and the append-only / numbering guards in `LedgerSequenceTest`; first run against the Compose stack; tune only if p95 > 1 s.
- **Phase 4 — Screens (US8, US9)** (can start after T-3.1 and T-3.2 fix the contract; pure modules [P] earlier)
  - T-4.1 [P] `supervisor-rules.js` + test.
  - T-4.2 [P] `audit-view.js` + test.
  - T-4.3 `log-renderer.js`: correction, restock and voided/corrected text + tests.
  - T-4.4 `pool-state.js` (correction event → snapshot) + `api.js` calls + tests.
  - T-4.5 `supervisor.js`, `dashboard.blade.php`, `app.css`, `dashboard.js`: restock form, correction form with "Correct" buttons, audit view, pending/offline/refusal text; `DashboardViewTest`.
- **Phase 5 — Hardening and docs**
  - T-5.1 `scripts/verify.sh` runs the new files; README supervisor walkthrough and the manual check list.
  - T-5.2 Run the full suite and the manual checks (two browsers, 360 px, 1280 px, `reverb` stopped, offline).
  - T-5.3 Re-run the traceability self-check (every PRD ID has a task and a test).
  - T-5.4 Re-run the rules check; log changes in §12.

**Checkpoints**
- **After Phase 1:** unit tests prove the correction step, the instant rule, the hindsight windows and the status history; the four former todos are real and pass; the M1/M2 suites are green after the `PoolWriteGate` refactor.
- **After Phase 2:** at service level, restock, lower, raise (refused, then accepted after a restock), void and the expired/active Temporary cases pass; the log shows the entries.
- **After Phase 3:** with `curl` as Dana: restock, correct, refused raise, audit; as Ana: 403. `ParallelCorrectionTest` passes on the stack.
- **After Phase 4:** in two browsers Dana restocks and corrects, Ana's and Ben's screens update; the audit view shows before and after.
- **After Phase 5:** `scripts/verify.sh` is green and the manual checks are done.

## 11. Risks, deviations and open items
| Item | Type | Mitigation or justification | Owner / status |
|---|---|---|---|
| **1. The PRD contradicts itself on replaying a refused correction.** The edge-case list says "replay of a refused correction returns the same refusal, not a new attempt", but AC-US3.1 says a refused correction records nothing (no log entry, no version change), so there is nothing to replay from. | needs decision | The plan stores nothing for a refusal (DD6): a retry with the same key is evaluated again, so it gives the same refusal while the pool is unchanged and is accepted if a restock arrived in between (which is what a supervisor retrying after restocking wants). Proposed PRD wording: "a replay of a refused correction is evaluated afresh; recorded corrections are replayed exactly". Alternative: store refusals as ledger rows (changes the log, the version and AC-US3.1). The plan does not edit the PRD (the user approved the wording and the PRD edge case was updated on 2026-10-20). | Resolved by user, 2026-10-20 (proposal accepted) |
| **2. PRD example wording of the hindsight window.** AC-US3.4 and `requirement.md` §4 say the pool was −20 "from 11:00 to 13:00". Replaying the corrected history, the pool is already −10 after Ben's 10:00 entry and −20 after Cara's at 11:00, so the flagged stretch is **10:00 to 13:00 with lowest −20**, which contains 11:00–13:00. | needs decision (small) | `HistoryReplayTest` asserts: window from Ben's entry to the restock, lowest −20, and that 11:00–13:00 lies inside it. Proposed: accept as is; no PRD edit needed unless you want the window cut to the −20 stretch only. | Resolved by user, 2026-10-20 (proposal accepted) |
| Two columns are added to existing tables (`corrections.from_amount`, `restocks.idempotency_key`) although M1 F2 aimed at no change to existing tables | deviation | M1 plan §11 named a `corrections` change as likely ("M3 owner"); the restock key is needed for FR6.11. Both are additive and safe (the correction table is empty; the restock key is NULL for the seed). | Approved by user, 2026-10-20 |
| The four M3 `todo` tests are replaced by real tests of the same names and the empty `CalculatorPendingTest.php` is removed | deviation from `CLAUDE.md` "do not delete tests without approval" | PRD AC-US10.1 requires it; M1/M2 reserved the placeholders for this. | Approved by user, 2026-10-20 |
| `RolesAndStubsTest` currently checks that the M3 endpoints return 501; those checks are removed for the endpoints M3 builds | deviation | PRD AC-US10.2 allows exactly this ("tests that M1 marked as waiting for M3"). The role checks stay. | Approved by user, 2026-10-20 |
| The audit view replays the ledger for every correction and every point (cost grows with ledger size × corrections) | risk | Only the supervisor's audit read pays it; none of it is on a write path. Fine at demo scale (hundreds of rows); if it grows, cache per `(version)`. | Accepted |
| The raise check and the `after` state load the whole ledger under the lock (M1 DD12) | risk | Same cost as a submit; p95 is asserted in `ParallelCorrectionTest`. | Implementer |
| A correction can add or remove a derived `expiry` entry in the past (void of an expired entry removes it; a raise of a zero-grant expired entry adds one) | deviation (minor) | Follows A-M3.4; the entry is derived, so it is never duplicated; the screen replaces by `cursor` after the snapshot a correction triggers. | Accepted |
| A key is unique per table, so one UUID may be used once as an allocation key and once as a restock key without a conflict | risk (minor) | The screen makes a new UUID per action; documented in the contract. | Accepted |
| `status.points` use the corrections set `< C` / `≤ C`, so a later correction of the same entry is not reflected in an earlier correction's audit row | design note | Intended (DD8): each row shows what that correction changed when it was made; the log and the current status show the combined effect. | Accepted |
| Browser timers and background tabs, `X-Demo-User` and dev secrets | deviation (accepted in M1/M2) | Unchanged. | Accepted |

## 12. Change log
| Date | Change | Reason | Approved by |
|---|---|---|---|
| 2026-10-20 | Plan created from the approved M3 PRD. | New slice. | User, 2026-10-20 (approved with §11 proposals) |
| 2026-10-20 | **Log `correction` entry:** plan §6 shows `"change":"amend"\|"void","from":40,"to":20`, but M1's shape already has `change: {"from","to"}` (with `to` null for a void, asserted by an M1 test), and AC-US10.3 says no existing field may change meaning. The implementation keeps `change` as it was (its `from` now comes from the stored `from_amount`) and adds `operator` and `correction_kind` (`amend` or `void`). `docs/api-contract.md` documents it. | Plan §6 contradicted itself and the PRD's AC-US10.3; the additive form satisfies the higher document. | Raised by the implementer; **needs the user's confirmation** |
| 2026-10-20 | **Signatures added beyond the plan:** `Ledger::anchorAt(CarbonImmutable): int` (M2's anchor rule in one place); `HistoryReplay::overdrawn(array $points, Ledger $full)` (the windows need the corrections to name the raising ones); `HistoryPoint` gained `allocationSeq`; `StatusHistory::forCorrection()` gained an optional `$operatorName` for the point labels; `RestockResource` and `CorrectionResource` (not in §4's tree); the pool-state flag `snapshotWanted`. | Needed to build what the tasks describe; all within the plan's contracts. | Implementer, within each task's intent |
| 2026-10-20 | **Tests changed because the schema or wording changed (additive only):** raw `corrections` inserts in `LedgerRepositoryTest`, `LedgerSequenceTest`, `LogEntryResourceTest` and the `SchemaContractTest` helper gained `from_amount` (NOT NULL by design, DD3); `LogEntryResourceTest` and `BroadcastTest` expected entry shapes gained `corrected_to`, `voided`, `operator`, `correction_kind`; the restock and correction text assertions in `log-renderer.test.js` changed to the PRD wording (AC-US2.1: "Dana changed Ana's request #12: 40 → 20 · Counted wrong"; restock "Dana added 25 units"; the seed reads "+100 units · Initial stock"); in `DashboardViewTest` the M1 test that required one form and no restock or correction controls now checks the request form and that nothing mentions capacity, and the placeholder test checks the supervisor tab instead. No test was deleted or weakened apart from the approved ones in §11. | Each is the new additive shape or the PRD's own wording. | Implementer; **listed here for the user's review** |
| 2026-10-20 | **`PUT /capacity` answers `404 not_found`** (not 405) once removed, because the path no longer exists; `RolesAndStubsTest` asserts that. | Follows from removing the route (PQ3, DD11). | Implementer |
| 2026-10-20 | **T009 Verify wording:** the task text says Ana (28 then 5, first lowered to 20) is "elevated → elevated" at the correction moment; with thresholds 0/10/30 she holds 33 (high) before and 25 (elevated) after, as PRD AC-US6.1 says. `StatusHistoryTest` asserts the AC. | The task sentence was a slip; the PRD governs. | Implementer; no document edited |
| 2026-10-20 | **The Compose stack serves assets from the image,** so `docker compose up -d --build --wait` was run once for the browser checks (T030) to show the new screens. The demo data was reset to the fresh seed afterwards. | Operational, no code change. | Implementer |
