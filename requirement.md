# Field Operator Allocation Module

Working requirements for the technical assessment. §0 is the client brief and remains the source of truth; everything after it is analysis, decisions and the delivery plan derived from it.

Last updated: 2026-10-17 · Status: all questions resolved (§3). Next step: M1 design (schema, calculation contract, endpoints, Docker layout). M1 not started.

---

## 0. Client brief (verbatim)

### **Module**

**Background:**  
A client needs a responsive web module for field operators to log daily resource allocations. Each allocation affects a running unit pool that dictates operator status levels.

**Requirements:**

1. **The Operational Dashboard:**
   * Operators must be able to submit new allocations, view their live running metric, and inspect an activity log on both mobile and desktop.  
   * The client stated: *"It needs to feel instant, never show wrong totals even if multiple operators are logging actions at the exact same moment, and be effortless for someone using a phone under bright sunlight in a warehouse."*
2. **Business Logic:**
   * Certain logged allocations have variable expiration windows based on operator tiers.  
   * Periodically, supervisor adjustments can retroactively alter past entries, which must recalculate downstream status levels.

---

## 1. Problem summary

Warehouse operators request units from a **shared, limited pool**, mostly from phones. The server grants requests strictly first come, first served (FCFS) and never lets the pool go negative. Some allocations are temporary: after a window set by the operator's tier, their units go back to the pool. Supervisors can correct past entries, and the pool and operator statuses must then be recalculated from that point forward.

The client cares most about three qualities, which pull against each other:

- **Feels instant.** Operators get feedback straight away.
- **Never wrong.** Totals stay correct when several operators submit at the same moment.
- **Usable in the field.** One hand, bright sunlight, poor Wi-Fi.

### Glossary

| Term | Meaning in this doc |
|---|---|
| Pool | Shared stock of units. Its capacity is set and restocked by a supervisor. Modelled with a `pool_id`; the demo uses one pool. |
| Allocation | An operator's request for N units. Type is *Consumed* (permanent) or *Temporary* (expires). |
| Grant | The units actually given to an allocation. |
| Tier | A fixed attribute of an operator. It sets the expiry window. It is **not** the same as status. |
| Status level | A per-operator level calculated from the units the operator currently holds, compared against config thresholds (D10). Display-only. |
| Correction | A supervisor's change to a past allocation's amount, or a void. It is stored as a new record, and the original is kept. |
| Restock | A supervisor action that adds capacity to the pool. It is recorded in the activity log. |

---

## 2. Requirements

**[S]** = stated in the brief · **[I]** = inferred by us

### Functional

| ID | Requirement | Src |
|---|---|---|
| FR1 | Operators submit allocations against the pool | S |
| FR2 | Operators see a live running metric: pool remaining and their own status | S |
| FR3 | Operators view an activity log | S |
| FR4 | Status levels are calculated from allocations | S |
| FR5 | Certain allocations expire after a window that depends on tier | S |
| FR6 | Supervisors correct past entries | S |
| FR7 | Corrections recalculate later status levels | S |
| FR8 | Corrections keep the original entry (audit trail) | I |
| FR9 | Two roles: operator and supervisor | I |
| FR10 | Supervisors set and restock pool capacity | I |

### Non-functional

| ID | Requirement | Src |
|---|---|---|
| NFR1 | Totals are correct under concurrent submits. The pool never goes negative. | S |
| NFR2 | Feels instant: an action is acknowledged straight away, and the confirmed result arrives in ≤ ~1 s on a normal network | S (target I) |
| NFR3 | Responsive on mobile and desktop | S |
| NFR4 | Readable in sunlight: key numbers at ≥ 7:1 contrast, large type, touch targets ≥ 48 px, minimal typing, never colour alone | S (specifics I) |
| NFR5 | Duplicate-safe submits on flaky networks (idempotency key) | I |
| NFR6 | Other screens reflect a change within ~2 s | I |

---

## 3. Decisions made

| ID  | Decision                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D1  | **One shared pool, strict FCFS.** Order is the order in which the server commits requests, never device clocks.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| D2  | **The pool never goes negative.** • *Client:* operators can only request up to the remaining amount last shown to them. The input is capped and shows "N left". • *Server (authoritative):* grants `min(requested, remaining)` and rejects the rest. A partial grant only happens when another request took units in the meantime.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| D3  | **Tier is separate from status.** The operator's tier is recorded on each allocation at log time.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| D4  | **Expiry:** the window starts at the allocation's effective time and uses the tier recorded on it. On expiry, **granted units return to the pool**. Expiry is worked out from the clock at read time, with no scheduled job.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| D5  | **Online-only.** No offline queue: rejected or unsent requests are not held for later. Retries are idempotent. Screens refresh within ~2 s. The UI shows a visible pending/offline state instead of a guessed total.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| D6  | **Scope:** a time box of ~4–8 h, delivered as milestones (§8). Each milestone is a runnable repo with a README of assumptions and tests for the hard rules. No deployment. A role switch stands in for real login.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| D7  | **Corrections ("freeze"):** • Past grants are final. Nothing is re-granted or taken back. • A correction can change the amount or void an entry, nothing else. • **Lowering** an amount frees the units immediately. • **Raising** an amount is **refused** if it needs more than the pool has free right now. There is no exception to "never negative". The supervisor can restock (D8) first and then correct. • A correction to an already-expired entry changes history only. • Corrections are append-only, record the supervisor and a reason, and go through the same one-at-a-time write path as allocations.                                                                                                                                                                                                                                                                                           |
| D8  | **Capacity:** • Set by a supervisor and topped up with logged *restock* events. No daily reset. • *Consumed* allocations use units permanently. • *Temporary* allocations hold units until they expire.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| D9  | **Stack:** Laravel and PostgreSQL, run with Docker Compose. All of it is set up in M1. Frontend and live updates: see D12.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| D10 | **Status (OQ4, reading a):** a per-operator level based on the units the operator currently holds, mapped to thresholds in config. Display-only. A correction changes the corrected operator's status, and "downstream" means later in time.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| D11 | **Never negative applies to the current balance only (OQ6, option i):** a correction is accepted or refused by what the pool can cover now. A past dip below zero caused by a correction is shown as flagged history ("overdrawn in hindsight"), not blocked.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| D12 | **Frontend and live updates (OQ7):** • *UI:* Blade + Alpine.js, with Vite for assets. • *Transport:* Laravel Reverb (self-hosted websockets) with Laravel Echo on the client, as its own service in Docker Compose. • *Channel:* one `pool.{id}` channel with small payloads (`version`, `remaining`, the new log entry). The pool version is incremented inside the locked write transaction, so it matches commit order. • *Client:* applies version `n+1` and ignores older events. On a gap or a reconnect, it fetches one snapshot. While the socket is down, it polls every ~10 s. • *Server:* broadcasts only after the transaction commits. • *Expiry:* nothing is broadcast. Each client schedules a refetch at the next `expires_at` it can see. • *Own submit:* updated from the HTTP response, shown as pending until it arrives. • *UI state:* a visible "live / reconnecting / offline" indicator. |

---

## 4. Business rules (current understanding)

**Pool**

- `remaining = Σ restocks − units of Consumed allocations − units held by active Temporary allocations`, all calculated from the corrected ledger.

**Allocation**

- An operator requests N units, where 1 ≤ N ≤ the last known remaining.
- The server handles each pool's requests one at a time, in a single transaction. It grants `min(N, remaining)` and rejects the rest.
- A rejected remainder is not waitlisted. The operator resubmits if they still need units.
- Each allocation records: `type`, `requested`, `granted`, `operator`, `tier_at_log`, `effective_at` (server time), `recorded_at`, `idempotency_key`.
- Operators cannot backdate. `effective_at` is the time the server received the request.

**Expiry**

- Applies only to *Temporary* allocations.
- `expires_at = effective_at + window(tier_at_log)`. From that moment, the granted units count as available again.

**Status**

- Calculated from the units the operator currently holds, compared against thresholds in config (D10). Display-only: it does not restrict what an operator can do.

**Corrections (D7). Worked example, pool capacity 100:**

| Time | Operator | Requested | Granted | Remaining |
|---|---|---|---|---|
| 09:00 | Ana | 40 | 40 | 60 |
| 10:00 | Ben | 50 | 50 | 10 |
| 11:00 | Cara | 10 | 10 | 0 |

- **Lowering** Ana's entry from 40 to 20 at 14:00: Ben and Cara keep their grants. The pool goes from 0 to 20, available to the next request.
- **Raising** Ana's entry from 40 to 60 at 14:00: refused, because 0 units are free.
  - After a restock of +50, the same correction is accepted and the pool goes to 30.
  - The check looks at the current balance only (D11). The pool was at −20 between 11:00 and 13:00 in the recalculated history, and that window is flagged as "overdrawn in hindsight" rather than blocking the correction.
- **Correcting an expired entry:** past pool levels and status history are recalculated. The current remaining is unaffected.

---

## 5. Technical risks and mitigation direction

| Risk | Why it's hard | Direction |
|---|---|---|
| Concurrent submits | Lost updates. Two requests can both pass the check before either writes. Retries can double-submit. | A Postgres transaction with a row lock on the pool row (`lockForUpdate`). FCFS = commit order. Every submit carries an idempotency key. |
| Expiry | The pool changes with no user action. Boundary semantics matter. | Calculate from the clock at read time. Use an injectable clock and test the exact boundaries. |
| Retroactive corrections | Each entry has two times (when it happened, when it was recorded). A correction can race a new submit. | Store corrections as new records that point at the original. Use the same pool lock as submits. |
| Recalculating later statuses | Status history depends on every earlier entry. | Status = a pure function of the corrected ledger, the rules and the time. Recalculate on read, and cache only if needed. |
| "Instant" vs "never wrong" | Showing the new total early means showing a number the server might reject. | The operator's own action appears at once, marked pending. Totals change only after the server confirms. |
| Testing it honestly | By default Laravel tests use SQLite and run requests one after another, which can't show the race. | Run tests against Postgres. Run the concurrency test as real parallel HTTP requests against the Docker stack. |

---

## 6. Open questions

Resolved: OQ1 → D7 · OQ2 → D2 · OQ3 → D8 · OQ4 → D10 · OQ5 → D9 · OQ6 → D11 · OQ7 → D12.

No open questions remain.

---

## 7. Smaller ambiguities and working assumptions

| ID | Topic | Working assumption |
|---|---|---|
| A1 | What a "unit" is | Whole numbers, no fractions. One unit type per pool. |
| A2 | Tier names and windows | Config placeholders, e.g. Bronze 24 h / Silver 48 h / Gold 72 h |
| A3 | Which allocations expire ("certain") | The operator picks *Temporary* (expires) or *Consumed* (permanent) at submit |
| A4 | Status thresholds | 3 levels, placeholder values in config |
| A5 | Log visibility | Operators see the whole pool's log, with their own entries highlighted |
| A6 | Early return by an operator | Not supported. Only expiry or a supervisor correction returns units. |
| A7 | How far back corrections go | No limit within the pool's history |
| A8 | Status history | Operators see their current status. Supervisors see status history, including before/after for each correction. |
| A9 | Time zone / "daily" | Single-site time zone. "Daily" only affects how the log is grouped. |
| A10 | Authentication | Seeded users plus a role switch. No passwords. |
| A11 | Validation | 1 ≤ `requested` ≤ last known remaining (client side). The server applies D2. |

---

## 8. Delivery roadmap

Each milestone ends in something usable. M1 is deliberately heavy: as well as its own deliverable, it sets up the stack and lays the shared foundation (data model, calculation contract, API contract, UI shell, test harness). M2, M3 and M4 can then be built **in parallel** without reworking it.

```
M1 Shared pool, live ──┬──> M2 Expiry & status          
                       ├──> M3 Supervisor corrections    
                       └──> M4 Field-ready UX & submission
```

M2 and M3 meet in one shared calculation function, split by ownership:

- **M3** owns the step that applies corrections to the entry list.
- **M2** owns the expiry and status steps that run on the corrected list.

So recalculation after a correction covers expiry and status automatically once both have merged.

### M1. Shared pool, live (size L, ~40% of budget) - IMPLEMNTED

**Usable deliverable:** an operator on a phone or desktop picks their name and requests up to the units left. They immediately see "granted", or "8 of 10 granted" if someone got in first. Pool remaining updates on every open screen. A whole-pool activity log is visible.

- Strict FCFS, never negative, request capped at remaining (D1, D2).
- Idempotent submits, plus a pending state while waiting for the server.
- Live refresh ≤ 2 s.

**Foundation for later milestones:**

- Docker Compose stack (Laravel app and Postgres) that starts with one command, plus seed data with pool capacity as an initial restock event.
- Full schema, including allocation `type`/`tier_at_log`, corrections and restocks, so M2/M3 add behaviour instead of migrations that change existing tables.
- A pure calculation service with an injectable clock. M1 implements grants and the sum. The expiry and status steps are no-ops, with pending tests waiting for M2.
- Rules config (tiers, windows, thresholds) loaded and validated.
- An API contract for every endpoint. The correction and restock endpoints return 501 for now.
- UI shell:
  - High-contrast design tokens.
  - A role switch with a placeholder supervisor tab.
  - A log renderer that already handles the `allocation`, `expiry`, `correction` and `restock` entry kinds.
- Test harness:
  - Unit and feature tests against Postgres.
  - A parallel-HTTP concurrency test against the running stack.
  - A README.

**Done when:**

- 50 parallel requests of 3 units against a pool of 100 produce: exactly 100 granted, exactly one partial grant (of 1), and the pool never below 0.
- A replayed idempotency key is not counted twice.
- Two browsers see each other's changes within 2 s.

### M2. Expiry and status (size M, ~20%) - IMPLEMENTED

**Usable deliverable:**

- Temporary allocations hand their units back on their own.
- Operators see their status level and "expires in 3 h" on their holdings.
- Expiry events appear in the log.

**Done when:**

- Tests with a fixed clock pass at exactly 1 ms before and after `expires_at`.
- Remaining goes up at expiry with no write.
- Status transitions are tested.
- An allocation logged as Silver keeps its window after the operator becomes Gold.

### M3. Supervisor corrections and restock (size M, ~25%)

**Usable deliverable:**

- A supervisor restocks the pool, and corrects or voids a past allocation with a reason.
- The pool and every affected status are recalculated.
- The audit view shows the original vs the corrected value, and the status history before vs after.

**Done when:** each §4 scenario passes:

- Lowering an entry frees its units.
- Raising an entry beyond free units is refused, with a clear message.
- Restocking and then raising succeeds.
- Correcting an expired entry changes history only.
- A correction racing an operator submit leaves a consistent pool.

### M4. Field-ready UX and submission (size S–M, ~15%)

**Usable deliverable:** the version you would hand to a warehouse operator.

- Audited sunlight theme.
- One-thumb flow with quick-amount buttons and steppers capped at remaining.
- Full-width result banners that use text, not colour alone.
- Clear pending, offline and retrying states.
- Final README: decisions, trade-offs, and a demo walkthrough script.

**Done when:**

- Key numbers measure ≥ 7:1 contrast and touch targets are ≥ 48 px.
- The full flow works at 360 px width.
- Toggling airplane mode shows the correct state and never creates a duplicate.
