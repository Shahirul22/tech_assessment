# PRD: M3 — Supervisor corrections and restock

| Field | Value |
|---|---|
| Slice | M3 |
| Status | Approved (2026-10-20, by the user; PQ1–PQ4 resolved by accepting the proposals) |
| Source | requirement.md §0, §1, §2 (FR2, FR3, FR6–FR10, NFR1–NFR3, NFR5, NFR6), §3 (D1, D2, D7, D8, D10, D11, D12), §4 (Pool, Corrections worked example), §5 (Retroactive corrections, Recalculating later statuses), §7 (A7, A8, A10), §8 (M3); M1 PRD and plan (hand-offs, §11 carry-ins), M2 PRD (status and expiry hand-offs) |
| Date | 2026-10-20 |
| Plan | [plan.md](plan.md) |

## 1. Summary
A supervisor can add units to the shared pool (restock) and correct or void a past allocation, with a reason. Every correction is stored as a new record next to the original, goes through the same one-at-a-time write path as operator requests, and recalculates the pool and the corrected operator's status. The supervisor gets an audit view showing original versus corrected value and the status before versus after. M3 turns the correction step that M1 left as an identity into real behaviour and replaces the placeholder supervisor tab.

**Usable deliverable:** Dana (supervisor) restocks +50, lowers Ana's 40 to 20 (the pool goes from 0 to 20), is refused when she tries to raise Ana's entry beyond what is free, succeeds after a restock, and opens an audit view that shows 40 → 20, her reason, and Ana's status before and after.

## 2. Goals and non-goals
**Goals**
- A supervisor restocks the pool and every open screen shows the new remaining (FR10, D8).
- A supervisor lowers, raises or voids a past allocation with a reason; the original is kept (FR6, FR8, D7).
- Lowering frees units at once; raising is refused when the pool cannot cover it right now; the pool never goes negative (D7, D2).
- The pool and the corrected operator's status are recalculated from the corrected history (FR7, D10).
- A past dip below zero caused by a correction is shown as "overdrawn in hindsight", not blocked (D11).
- A correction racing an operator submit leaves a consistent pool (NFR1).
- The supervisor audit view shows original versus corrected and status before versus after (§8 M3, A8).

**Non-goals (explicitly NOT in this slice)**
- Changing anything but the granted amount, or voiding (D7): no editing of operator, type, tier, time, or the idempotency key; no deleting; no backdating (§4).
- Correcting or deleting a restock, or lowering capacity. Restocks only add (see PQ3).
- Re-granting or taking back units from other operators when a correction frees or consumes units (D7: past grants are final).
- Extending or renewing an expiry window. A correction never moves `expires_at` (M2, D4).
- Early return by an operator (A6). A way to change an operator's tier. Real login (A10).
- Status restricting what an operator may do (D10: display-only). Status shown to other operators (A8).
- Sunlight audit, 7:1 contrast, 48 px targets, quick amounts, steppers, full-width banners, airplane-mode walkthrough, final README → M4 (NFR4). M3 builds its screens on the M1 design tokens only.
- Deployment (D6).

## 3. Users and scenarios
| ID | Persona | Scenario (one sentence) | Priority |
|---|---|---|---|
| US1 | Supervisor | Restocks the pool with a number and an optional note, and everyone sees the new remaining. | P1 |
| US2 | Supervisor | Lowers a past allocation with a reason and the freed units are available at once. | P1 |
| US3 | Supervisor | Tries to raise a past allocation, is refused with a clear message when the pool cannot cover it, restocks, and succeeds. | P1 |
| US4 | Supervisor | Voids a past allocation with a reason and all its units go back. | P1 |
| US5 | Supervisor | Corrects an entry that has already expired, and only history changes, not the current remaining. | P1 |
| US6 | Supervisor | Opens an audit view that shows original versus corrected value and status history before versus after. | P1 |
| US7 | Supervisor and operators | Act at the same moment (a correction racing a submit) and the pool stays consistent. | P1 |
| US8 | Operator on a phone | Sees the pool, own status and the log change within 2 s of a supervisor's restock or correction, with no reload. | P1 |
| US9 | Supervisor on a phone or desktop | Uses a real supervisor screen (restock, correct or void, audit) instead of the placeholder tab. | P1 |
| US10 | Developer of M4 | Builds the field-ready UX on a supervisor screen and a correction step that are real and tested. | P2 |

## 4. Functional requirements
| ID | Requirement (MUST / SHOULD, one testable behaviour each) | Source | Story |
|---|---|---|---|
| FR10 | A supervisor MUST be able to restock the pool by a whole number of units ≥ 1, with an optional note. Remaining MUST rise by exactly that amount. | requirement.md FR10 [I], D8, Glossary "Restock" | US1 |
| FR10.1 | A restock MUST be recorded as a log entry (kind `restock`) naming the supervisor, the amount and the note, and MUST be usable by operators from the moment it is confirmed. | D8, FR3.1 (M1) | US1 |
| FR10.2 | Capacity is raised only by restock events; there is no "no daily reset" behaviour and no way to lower capacity. How the M1 `PUT /capacity` stub is handled is PQ3. | D8, PQ3 | US1 |
| FR6 | A supervisor MUST be able to correct a past allocation by changing its granted amount to a whole number ≥ 1, or by voiding it (amount becomes 0), and MUST give a reason (required, non-blank). | requirement.md FR6 [S], D7 | US2, US3, US4 |
| FR6.1 | **Lowering** an amount MUST free the difference immediately: remaining rises by (old − new) at once. Other operators' grants MUST NOT change. | D7, §4 example | US2 |
| FR6.2 | **Raising** an amount MUST be refused when (new − old) is more than the units free right now (current remaining, after expiry). The refusal MUST say how many units are needed and how many are free, and MUST record nothing. | D7, D11, D2 | US3 |
| FR6.3 | A raise that the pool can cover MUST be accepted and remaining MUST fall by (new − old). A raise needing exactly the free units MUST be accepted; one unit more MUST be refused. | D7 [boundary I] | US3 |
| FR6.4 | **Void** MUST free all units the entry currently holds. The original entry stays visible and is marked voided. | D7, FR8 | US4 |
| FR6.5 | A correction to an **already expired** Temporary entry MUST change history only: the current remaining MUST NOT change and no pool check applies to it. | D7, D11 | US5 |
| FR6.6 | A correction to an **active** Temporary entry MUST keep its `expires_at`; at expiry exactly the corrected units return, never the original ones. Consumed entries are corrected the same way. | D4, D7 [I] | US2, US3, US5 |
| FR6.7 | Corrections MUST be allowed on any entry in the pool's history, with no age limit. | A7 | US5 |
| FR6.8 | A correction MUST record the supervisor, the reason, the time, the entry it points at, and the from and to amounts. It MUST be append-only: nothing is edited or deleted. | D7, FR8 | US2 |
| FR6.9 | Only a supervisor MUST be able to restock or correct; an operator's attempt MUST be refused with the "forbidden role" error and record nothing. | FR9 [I], A-M1.3 | US1, US2 |
| FR6.10 | A correction or restock MUST go through the same one-at-a-time write path and the same ledger numbering as operator requests, so the order is the server's commit order. | D1, D7, §5 | US7 |
| FR6.11 | Restocks and corrections MUST be idempotent: a replay of the same key and payload returns the original reply and records nothing; the same key with a different payload is a conflict error and records nothing. | NFR5 [I], M1 PQ3 | US7 |
| FR6.12 | A correction that changes nothing (same amount as the entry currently has) MUST be refused as a validation error. Repeated corrections and corrections of voided entries: PQ4. | [I], PQ4 | US2 |
| FR7 | After a correction, the pool remaining and the corrected operator's status MUST be recalculated from the corrected history (corrections → expiry → sum and status), using M2's expiry step and status function. | requirement.md FR7 [S], D10 | US2, US6 |
| FR7.1 | Because past grants are final (D7), only the corrected operator's holdings change; every other operator's status MUST stay the same. | D7, D10 [I] | US6 |
| FR7.2 | "Downstream" status levels MUST be recalculated: the status the corrected operator had after each later entry in the history changes with the correction. What is shown is PQ2. | requirement.md §0 brief, D10, §5 | US6 |
| FR8 | The original entry MUST remain readable after any correction, and the correction MUST appear as its own entry. | requirement.md FR8 [I] | US6 |
| FR3.4 | The log MUST show `correction` entries (supervisor, original allocation, from → to or "voided", reason) and `restock` entries as already supported by the M1 renderer, with a text label (not colour alone). The viewer's own affected allocation entries stay highlighted. | FR3.1 (M1), NFR4 | US2, US8 |
| FR15 | When the recalculated history shows the pool below 0 at some earlier point because of a correction, the audit view MUST flag that stretch as "overdrawn in hindsight". The correction MUST still be accepted if the current balance covers it, and the current remaining MUST NOT be affected by the flag. | D11 | US3, US5 |
| FR16 | A supervisor-only audit view MUST list corrections with: original value, corrected value, supervisor, reason, time, and the corrected operator's status before versus after. | §8 M3 deliverable [S], A8 | US6 |
| FR17 | Every open screen MUST show a restock or correction within 2 s, without a reload: pool remaining, the log entry, and (for the corrected operator) own status and holdings. A gap or reconnect MUST recover with one snapshot. | NFR6, D12 | US8 |
| FR18 | The supervisor tab MUST replace the M1 placeholder with: a restock form, a correct-or-void form (pick an entry, new amount or void, reason), and the audit view. Operators MUST NOT see them. | FR9 [I], F6 (M1) | US9 |
| FR18.1 | A refusal or error MUST be shown to the supervisor as readable text on the screen, and while offline the UI MUST show an offline state, guess no total and queue nothing. | D5, NFR4 (colour not alone) | US9 |
| F3 | The correction step of the shared calculation MUST become real (M3 owns it); the pending M3 tests from M1 MUST become real passing tests; the four M1 stubs for M3 MUST become real endpoints (or be retired, PQ3), and the M1 and M2 API, log and calculation contracts MUST only grow. | §8 M1/M3 split, M1 PRD F3, F5 | US10 |

## 5. Non-functional requirements
| ID | Requirement with measurable threshold | Source |
|---|---|---|
| NFR1 | With corrections and restocks in flight, 50 parallel requests of 3 units never drive the pool below 0, and each response's remaining equals the corrected ledger replayed up to that request. | requirement.md NFR1 [S] |
| NFR2 | A restock or correction is acknowledged (pending state) within 100 ms and confirmed within 1 s at p95 on the local stack, including during the 50-request parallel test. | NFR2 [S], target [I] |
| NFR3 | Supervisor screens (restock, correct or void, audit) and the new log entries fit at 360 px and 1280 px with no horizontal page scroll. | NFR3 [S] |
| NFR5 | Restock and correction replays stay exactly-once and identical, including after the entry has since been corrected again or has expired. | NFR5 [I] |
| NFR6 | A restock or correction is visible on another open screen within 2 s. | NFR6 [I] |
| NFR-M3.1 | The correction step is a pure function of the ledger: the same ledger always gives the same corrected list, and corrections are applied in ledger order. | §5 "Recalculating later statuses" |
| NFR4 | Not verified in M3 (→ M4). M3 only avoids colour-only meaning (FR3.4, FR18.1). | NFR4 [S] |

## 6. Business rules and decisions that apply
- **D1** FCFS by server commit order. Corrections and restocks share it. **D2** The pool never goes negative.
- **D7** Corrections: past grants final; amount change or void only; lowering frees at once; raising refused if more than free now; expired entry changes history only; append-only with supervisor and reason; same one-at-a-time write path.
- **D8** Capacity is set by a supervisor and topped up by logged restock events; Consumed use units permanently; Temporary hold them until expiry.
- **D10** Status is a per-operator level from the units held, display-only; a correction changes the corrected operator's status; "downstream" means later in time. With M2's PQ1 (accepted 2026-10-19), held = Consumed granted units + active Temporary units, after corrections.
- **D11** Never negative applies to the current balance only; a past dip caused by a correction is flagged "overdrawn in hindsight", not blocked.
- **D12** Live updates over `pool.{id}` with a version; client applies `n+1`, ignores older, fetches a snapshot on a gap; broadcast only after commit.
- **§4 Pool** `remaining = Σ restocks − Consumed units − units held by active Temporary allocations`, all from the corrected ledger. **§4 Worked example** (capacity 100: Ana 40, Ben 50, Cara 10) is used in section 7.
- **A7** No limit on how far back corrections go. **A8** Operators see their current status; supervisors see status history including before and after for each correction. **A9** Single site time zone. **A10** Seeded users plus a role switch. **A1** Whole units.
- **M1 carry-ins** (resolved by the user 2026-10-17 and 2026-10-19, for the plan): (1) `effective_at` can be a few ms out of `seq` order under contention, so M3's plan must choose the ordering key for "downstream" recalculation; (2) restock and correction writers must take their numbers from the one ledger numbering function inside the pool's transaction; (3) `corrections` columns were designed before M3, so a small migration is possible. These are design matters for the plan, not new requirements.
- **M2 hand-offs:** the expiry step and status function run on the corrected list; a correction to an expired entry changes history only.

## 7. Acceptance criteria
Setup for the examples unless stated: pool capacity 100 (seeded restock); Ana is Bronze (24 h), Ben Silver, Cara Gold; Dana is the supervisor. Ledger: 09:00 Ana Consumed 40 (remaining 60), 10:00 Ben Consumed 50 (10), 11:00 Cara Consumed 10 (0). Status thresholds standard from 0, elevated from 10, high from 30, so Ana is high, Ben is high, Cara is elevated. T is a fixed instant.

### US1 — Restock
**AC-US1.1** Given remaining is 0 and Dana is selected, when she restocks 50 with the note "Delivery", then her screen shows remaining 50, the log has a restock entry by Dana for +50 with "Delivery", and Ana's and Ben's open screens show remaining 50 within 2 s.
**AC-US1.2** Given remaining is 50 after that restock, when Ben requests 50, then he is granted 50 ("50 granted") and remaining is 0.
**AC-US1.3** Given any client, when a restock amount is 0, negative, fractional or not a number, then the server rejects it with a validation error and records nothing; given no note, it is accepted.
**AC-US1.4** Given an operator (Ana) calls restock, then she gets the forbidden-role error and nothing is recorded.
**AC-US1.5** Given a restock with key `K` is replayed 5 times with the same payload, then remaining rises once, one log entry exists, and every reply is the original; given `K` with a different amount, then a conflict error is returned and nothing is recorded.

### US2 — Lowering
**AC-US2.1** Given the setup (remaining 0), when Dana lowers Ana's 40 to 20 with the reason "Counted wrong", then remaining is 20, Ben's 50 and Cara's 10 are unchanged, the log has a correction entry "Dana changed Ana's allocation 40 → 20 · Counted wrong", and Ana's original allocation entry is still in the log showing the original 40.
**AC-US2.2** Given that correction, when Cara requests 20, then she is granted 20 ("20 granted") and remaining is 0.
**AC-US2.3** Given that correction, then Ana's status changes from high to elevated (holds 20), Ben stays high and Cara stays elevated, and Ana's open screen shows "Status: elevated" within 2 s without a reload.
**AC-US2.4** Given a correction with a missing or blank reason, a new amount that is 0 (that is a void), negative, fractional or not a number, or an amount equal to what the entry currently has, then it is rejected with a validation error and nothing is recorded.
**AC-US2.5** Given an unknown allocation, or one from another pool, then the server returns a not-found error and nothing is recorded.
**AC-US2.6** Given Dana is selected on a phone, when she submits a correction, then she sees it pending within 100 ms and confirmed within about 1 s, and the totals change only after the server confirms (D5).

### US3 — Raising
**AC-US3.1** Given the setup (remaining 0), when Dana raises Ana's 40 to 60 with a reason, then it is refused with a clear message ("needs 20 more units, 0 are free"), remaining is still 0, the pool version does not change, and no log entry is added.
**AC-US3.2** Given Dana restocks +50 (remaining 50) and then repeats the same correction, then it is accepted, remaining is 30, and the log shows the restock and then the correction (40 → 60).
**AC-US3.3** Given remaining is 20, when Dana raises an entry by exactly 20, then it is accepted and remaining is 0; given she raises an entry by 21 with remaining 20, then it is refused.
**AC-US3.4** Given the setup, a restock of +50 at 13:00 (remaining 50), and the raise of Ana 40 → 60 at 14:00 (accepted, remaining 30), then the audit view flags "overdrawn in hindsight" for the stretch from 11:00 to 13:00 where the recalculated pool was −20; the correction was not blocked, and the current remaining is 30 (D11).
**AC-US3.5** Given a raise, then Ana's status is recalculated from her new holding (60 → high) and shown to her within 2 s.

### US4 — Void
**AC-US4.1** Given the setup, when Dana voids Ana's 40 with a reason, then remaining is 40, Ana's status is standard, the log has a correction entry "voided (40 → 0)" and Ana's original allocation entry stays in the log marked as voided.
**AC-US4.2** Given Ana holds an active Temporary 4 (expires at T + 24 h) and Dana voids it at T + 1 h, then remaining rises by 4 at once; at T + 24 h, remaining does not change and no expiry entry exists for it.

### US5 — Expired and active Temporary entries
**AC-US5.1** Given Ana was granted Temporary 4 at T (expired at T + 24 h, so its 4 units are already back) and Dana lowers it to 2 at T + 30 h, then the current remaining does not change, the status does not change, the correction appears in the log and the audit view, and the history (past pool levels and status history) is recalculated.
**AC-US5.2** Given the same expired entry, when Dana raises it from 4 to 9 while 0 units are free, then it is accepted (history only, no pool check), the current remaining does not change, and the audit view flags any recalculated stretch below 0 as "overdrawn in hindsight".
**AC-US5.3** Given an active Temporary 4 expiring at T + 24 h and Dana lowers it to 2 at T + 1 h, then remaining rises by 2 at once and at exactly T + 24 h rises by exactly 2 more (not 4); `expires_at` is unchanged.
**AC-US5.4** Given an active Temporary 4 and Dana raises it to 6 at T + 1 h with 2 free, then it is accepted, remaining falls by 2, `expires_at` is unchanged, and at T + 24 h exactly 6 units return.

### US6 — Audit and downstream status
**AC-US6.1** Given Ana logs Consumed 28 at 09:00 (elevated) and Consumed 5 at 10:30 (holds 33, high), when Dana lowers the first to 20 at 14:00, then the audit view shows Ana's status at 10:30 as high before and elevated after (holds 25), and her current status as elevated (25).
**AC-US6.2** Given any correction, then Ben's and Cara's statuses are unchanged (FR7.1), and Ben's screen shows nothing about Ana's status (A8).
**AC-US6.3** Given the correction in AC-US2.1, then the audit view lists it with: original 40, corrected 20, Dana, "Counted wrong", the time, and Ana's status high before and elevated after; the original allocation (Ana, 40, 09:00) is still retrievable.
**AC-US6.4** Given an operator (Ana) calls the audit endpoint or opens the supervisor tab, then access is refused and nothing is shown.
**AC-US6.5** Given an entry has had no correction, then it does not appear in the audit view; given several corrections, they are listed in commit order.

### US7 — Concurrency and idempotency
**AC-US7.1** Given remaining is 20, when Dana raises Ana 40 → 60 (needs 20) and Ben requests 20 at the same moment, then exactly one outcome happens: either the correction is accepted and Ben is granted 0, or Ben is granted 20 and the correction is refused; never both; remaining is never below 0 and equals the ledger replayed in commit order.
**AC-US7.2** Given 50 parallel requests of 3 units and, among them, a lowering that frees 20 and a restock of +10, then remaining is ≥ 0 after every commit, total granted never exceeds capacity plus what was freed or restocked, ledger numbers are unique and gapless across restocks, allocations and corrections, and the log order equals commit order.
**AC-US7.3** Given two supervisors lower the same entry from 40 to 20 at the same moment with different keys, then they are applied one at a time; the second sees the entry already at 20 and is refused as "no change" (AC-US2.4); remaining rises by 20 once.
**AC-US7.4** Given a correction with key `K` answered at T, when `K` is replayed with the same payload after the entry has since been corrected again, then the reply is identical to the first one and nothing new is recorded; given `K` with a different amount or entry, then a conflict error is returned and nothing is recorded.
**AC-US7.5** Given the 50-request parallel test with corrections and restocks included, then p95 submit-to-confirmed is ≤ 1 s for operator requests, restocks and corrections (NFR2, M1 AC-US4.5 still passes).

### US8 — Live updates
**AC-US8.1** Given Ana's and Ben's screens are open, when Dana restocks or corrects, then both screens show the new remaining and the new log entry within 2 s with no reload; Ana's screen also shows her new status and holdings if her holdings changed.
**AC-US8.2** Given two updates arrive out of order or duplicated, or a gap occurs, then the screen never shows a stale or double-counted remaining and one snapshot recovers it (M1 AC-US3.5 includes correction and restock events).
**AC-US8.3** Given the live-update service is down, then the ~10 s fallback refresh still shows the restock or correction, and the supervisor who made it is updated from the HTTP response.

### US9 — Supervisor screen
**AC-US9.1** Given Dana is selected, then the placeholder tab is replaced by a restock form, a correct-or-void form (choose an entry from the log, a new amount or "void", a required reason) and the audit view; given an operator is selected, none of these are visible.
**AC-US9.2** Given a phone at 360 px and a desktop at 1280 px, then all supervisor screens and the new log entries show with no horizontal page scroll (NFR3).
**AC-US9.3** Given a refused correction (AC-US3.1), then the screen shows the refusal as readable text with the units needed and free, not colour alone.
**AC-US9.4** Given the device has no connection, when Dana tries to restock or correct, then an offline state is shown, no total is guessed, nothing is queued and nothing is sent when the connection returns (D5).

### US10 — Foundation
**AC-US10.1** Given the M1 pending correction tests, then they are now real tests and pass; there are no pending M3 tests left.
**AC-US10.2** Given the whole M1 and M2 acceptance suites (unit, feature, concurrency, JS), then they still pass unchanged, except tests that M1 marked as waiting for M3 (the 501 stub checks for the endpoints M3 builds).
**AC-US10.3** Given the M1 and M2 API responses and log entries, then every existing field is still present with the same meaning; correction fields, audit data and the hindsight flag are additions only.

**Edge cases that must be covered**
- Correction of a zero-grant entry: PQ1.
- Raise and lower on the same entry in sequence: each is judged against the entry's current corrected amount (PQ4).
- Correction of a voided entry: PQ4.
- Void or lowering of an expired entry: history only; a derived `expiry` entry follows the corrected units (A-M3.4).
- Two allocations by the same operator, one corrected: only that entry's units change; the other and its expiry are untouched.
- Correction that makes a threshold cross exactly: holding 30 → 29 drops high to elevated; 10 → 9 drops elevated to standard (M2 FR4.1).
- Raise needing exactly the free units, and one unit more (AC-US3.3).
- Restock of 1 unit and a request of 1 at the same moment: restock then request is granted; request then restock gives "0 of 1 granted" then a +1 — never negative.
- Replay of a refused correction is evaluated afresh (a refusal records nothing, AC-US3.1): it gives the same refusal while the pool is unchanged and succeeds if a restock arrived in between; recorded corrections are replayed exactly (AC-US7.4). *(Wording changed 2026-10-20 on the user's approval of plan §11 item 1.)*
- Live-update service down at commit time (AC-US8.3). Server restart or page reload after a correction: the screen shows the server's current remaining, status and log.
- Supervisor viewing: has no status or holdings (supervisors do not submit, A-M1.3); a supervisor's submit attempt is still refused.
- Empty audit view (no corrections yet) shows an empty state, not an error.
- Config with a bad window or threshold: M1 config validation still fails startup.

## 8. Open questions and assumptions
`requirement.md` §6 lists no open questions. The rows below carry in the resolved decisions M3 depends on, and record gaps found while writing this PRD (PQ#). PQ1–PQ4 were resolved by the user on 2026-10-20 (all proposals accepted); `requirement.md` was not edited.

| ID | Question | Blocking? | Proposed answer / working assumption | Status |
|---|---|---|---|---|
| OQ1 | How corrections to past entries work | — | Recorded as D7 | Resolved (requirement.md §6 → D7) |
| OQ3 | Where capacity comes from | — | Recorded as D8 | Resolved (requirement.md §6 → D8) |
| OQ4 | What status means | — | Recorded as D10 | Resolved (requirement.md §6 → D10) |
| OQ6 | Whether a correction can make the pool negative | — | Recorded as D11 | Resolved (requirement.md §6 → D11) |
| PQ1 | Is there a ceiling on a raised amount, and can a zero-grant entry be raised? D7 allows raising but gives no ceiling; the §4 example raises Ana from 40 to 60 although she only asked for 40, so "no more than requested" cannot be the rule. A zero-grant entry raised to N looks like a new grant, which D7 says does not happen. | Yes | **Any whole number ≥ 1 is allowed as the new amount, on any entry including a zero-grant one, as long as the pool check (FR6.2) passes; there is no cap tied to `requested`.** | Resolved by user, 2026-10-20 (proposal accepted) |
| PQ2 | What does "status history before vs after" show? The brief says corrections "recalculate downstream status levels" and A8 says supervisors see status history, but neither says at which points in time the history is shown. | Yes | **Per correction, the audit view shows the corrected operator's status now (before and after) and the status they had after each of their own later entries (allocations and expiries) in the history, before versus after, listing only the points where it differs. Only the corrected operator has a history to show (FR7.1).** | Resolved by user, 2026-10-20 (proposal accepted) |
| PQ3 | Capacity setting: M1 declared both `POST /restocks` and `PUT /capacity` as stubs, but D8 says capacity is set by the seed and topped up by restocks, and there is no rule for lowering it. What happens to `PUT /capacity`? | No | **Restock is the only way to add capacity; `PUT /capacity` is retired from the contract (M1 plan §11 already lets M3 merge them), and there is no way to lower capacity.** | Resolved by user, 2026-10-20 (proposal accepted) |
| PQ4 | Repeated corrections: can the same entry be corrected twice, and can a voided entry be corrected again? | No | **Each correction is judged against the entry's current corrected amount, so an entry can be corrected many times (every one kept); a voided entry is final and further corrections are refused.** | Resolved by user, 2026-10-20 (proposal accepted) |
| A-M3.1 | Whole units, positive restock amount, no maximum [I] | No | Restock amount is a whole number ≥ 1; no upper limit in the demo. | Working assumption [I] |
| A-M3.2 | Who sees the reason [I] | No | The correction's reason is part of the log entry shown to everyone (M1 log entry shape already includes `reason`); the audit view and the hindsight flag are supervisor-only. | Working assumption [I] |
| A-M3.3 | Which supervisor [I] | No | Any seeded supervisor may restock or correct; the acting supervisor is recorded. | Working assumption [I] |
| A-M3.4 | Expiry entries after a correction [I] | No | An `expiry` entry is derived (M2), so it follows the corrected units: a void leaves no expiry entry, a lowered entry shows the lowered units. | Working assumption [I] |
| A-M3.5 | Ordering key [I] | No | What users see is commit order (`seq`). The key used for "downstream" recalculation is chosen by the plan (carry-in, resolved 2026-10-17). | Carried to the plan |
| A1, A7, A8, A9, A10 | As in requirement.md §7 | No | Carried unchanged | Carried |

## 9. Dependencies and hand-offs
- **Needs from M1 (done):** the pool lock and one-at-a-time write path; the ledger numbering function; the `corrections` and `restocks` tables, and `idempotency_key` on corrections; the correction and restock log entry kinds in the renderer and contract; the four 501 stubs; the roles and the placeholder supervisor tab; the identity `ApplyCorrections` step; the pending M3 tests; the live-update channel and version rule.
- **Needs from M2 (implemented):** the expiry step and status function that run on the corrected list; the derived `expiry` log entries; the viewer status and holdings in the snapshot.
- **Carry-ins for the plan:** ordering key for downstream recalculation (A-M3.5); restock and correction writers must use the ledger numbering function; possible small `corrections` migration; M1 plan §11 allows merging `PUT /capacity` into restocks (PQ3).
- **Provides to M4:** the supervisor screens (restock, correct or void, audit) and the correction and restock log entries on the M1 tokens, ready for the sunlight and one-thumb work; the refusal messages for the result banners.
- **Parallel work:** M4 can proceed alongside M3 as the roadmap says; M3 touches only the correction step, the restock and correction endpoints, the audit view and the supervisor tab.

## 10. Traceability
| Requirement ID | Covered by acceptance criteria |
|---|---|
| FR10 | AC-US1.1, AC-US1.2 |
| FR10.1 | AC-US1.1, AC-US1.2 |
| FR10.2 | AC-US1.3 |
| FR6 | AC-US2.1, AC-US2.4, AC-US3.2, AC-US4.1 |
| FR6.1 | AC-US2.1, AC-US2.2 |
| FR6.2 | AC-US3.1, AC-US3.3 |
| FR6.3 | AC-US3.2, AC-US3.3 |
| FR6.4 | AC-US4.1 |
| FR6.5 | AC-US5.1, AC-US5.2 |
| FR6.6 | AC-US4.2, AC-US5.3, AC-US5.4 |
| FR6.7 | AC-US5.1 |
| FR6.8 | AC-US2.1, AC-US6.3 |
| FR6.9 | AC-US1.4, AC-US6.4 |
| FR6.10 | AC-US7.1, AC-US7.2 |
| FR6.11 | AC-US1.5, AC-US7.3, AC-US7.4 |
| FR6.12 | AC-US2.4, AC-US2.5, AC-US7.3 |
| FR7 | AC-US2.3, AC-US3.5, AC-US4.1 |
| FR7.1 | AC-US6.2 |
| FR7.2 | AC-US6.1, AC-US6.3 |
| FR8 | AC-US2.1, AC-US4.1, AC-US6.3 |
| FR3.4 | AC-US2.1, AC-US4.1, AC-US1.1 |
| FR15 | AC-US3.4, AC-US5.2 |
| FR16 | AC-US6.1, AC-US6.3, AC-US6.5 |
| FR17 | AC-US8.1, AC-US8.2, AC-US8.3 |
| FR18 | AC-US9.1 |
| FR18.1 | AC-US9.3, AC-US9.4 |
| F3 | AC-US10.1, AC-US10.2, AC-US10.3 |
| NFR1 | AC-US7.1, AC-US7.2 |
| NFR2 | AC-US2.6, AC-US7.5 |
| NFR3 | AC-US9.2 |
| NFR5 | AC-US1.5, AC-US7.4 |
| NFR6 | AC-US1.1, AC-US8.1 |
| NFR-M3.1 | AC-US7.2, AC-US7.3 |
| NFR4 | N/A in M3 — verified in M4 |
