# PRD: M2 — Expiry and status

| Field | Value |
|---|---|
| Slice | M2 |
| Status | Approved (2026-10-19, by the user; PQ1–PQ3 resolved by accepting the proposals) |
| Source | requirement.md §0, §1, §2 (FR2, FR4, FR5, NFR1–NFR3, NFR6), §3 (D3, D4, D8, D10, D12), §4, §5 (Expiry), §7 (A2, A3, A4), §8 (M2); M1 PRD and plan (hand-offs, §11 carry-ins) |
| Date | 2026-10-19 |
| Plan | [plan.md](plan.md) |

## 1. Summary
Operators can mark a request as Temporary, and the units they were granted go back to the shared pool by themselves when the window for their tier ends, with no one doing anything and on every open screen. Each operator also sees their own status level and, for each temporary holding, how long is left ("expires in 3 h"). Expiry events show up in the whole-pool activity log. M2 turns the no-op expiry and status steps that M1 left in place into real behaviour.

**Usable deliverable:** a Bronze operator requests 4 Temporary units, sees "4 granted · returns <time>", sees "expires in 24 h" on that holding and their status level, and when the window ends the pool remaining goes up by 4 on every screen, the holding disappears, the status may drop, and an "expired" entry appears in the log.

## 2. Goals and non-goals
**Goals**
- Temporary allocations return their granted units to the pool exactly at the end of the tier window, calculated from the clock, with no write and no scheduled job (D4).
- The window uses the tier recorded when the allocation was logged (D3).
- Operators see their own status level and their temporary holdings with time left.
- Expiry events appear in the log and open screens update without a reload.
- The expiry and status steps are tested at exact boundaries with a fixed clock.

**Non-goals (explicitly NOT in this slice)**
- Supervisor restock, capacity changes, corrections, voids, audit view, "overdrawn in hindsight", status history and before/after views (FR6–FR8, FR10 actions, D7, D11, A8) → M3. The correction step stays as it is in M1. How a correction changes statuses is M3's job; M2 only provides the status function it will call.
- Early return of units by an operator (A6). Extending or renewing a window. Backdating (§4).
- A way to change an operator's tier from the UI. Tier is fixed per operator (§1 Glossary); tests change it directly in the data.
- Status restricting what an operator may do (D10: display-only). Status shown to other operators or a status log entry.
- Sunlight audit, 7:1 contrast, 48 px targets, quick-amount buttons, steppers, full-width banners, airplane-mode walkthrough, final README → M4 (NFR4). M2 builds the new UI parts on the M1 design tokens only.
- Real login (A10), deployment (D6).

## 3. Users and scenarios
| ID | Persona | Scenario (one sentence) | Priority |
|---|---|---|---|
| US1 | Operator on a phone | Chooses Temporary or Consumed when requesting, and is told when Temporary units will return. | P1 |
| US2 | Any operator | Sees units come back to the pool when a Temporary allocation's window ends, with no action by anyone. | P1 |
| US3 | Operator on a phone | Sees their own current temporary holdings, each with "expires in …". | P1 |
| US4 | Operator on a phone | Sees their own status level, and sees it change as their holdings change or expire. | P1 |
| US5 | Any operator | Sees "expired" events in the whole-pool activity log. | P1 |
| US6 | Operator whose tier changes later | Keeps the window they had when they logged the allocation. | P1 |
| US7 | Operator on a flaky network | Retries a submit after expiry has happened and still gets the original answer. | P2 |
| US8 | Evaluator | Watches expiry happen in a demo without waiting 24 hours. | P2 |
| US9 | Developer of M3 | Builds corrections on expiry and status steps that are real and tested. | P2 |

## 4. Functional requirements
| ID | Requirement (MUST / SHOULD, one testable behaviour each) | Source | Story |
|---|---|---|---|
| FR5 | The system MUST return the units of a Temporary allocation to the pool after an expiry window that depends on the operator's tier. | requirement.md FR5 [S] | US2 |
| FR5.1 | `expires_at` MUST equal `effective_at` + the window of `tier_at_log`. Windows come from config; placeholders Bronze 24 h, Silver 48 h, Gold 72 h. | D4, D3, A2 | US2, US6 |
| FR5.2 | A Temporary allocation MUST still hold its units up to 1 ms before `expires_at`, and MUST have returned them at `expires_at` and after. | §4 Expiry ("from that moment"), §8 M2 done-when [boundary reading I] | US2 |
| FR5.3 | Consumed allocations MUST never expire, and their M1 behaviour MUST not change. | A3, D8 | US2 |
| FR5.4 | On expiry, the units **granted** (not the units requested) MUST return to the pool. The remaining MUST come from the clock at read time: no stored record is written or changed at expiry, and no scheduled job is needed. | D4, §4 Pool | US2 |
| FR5.5 | The window MUST use the tier recorded on the allocation at log time; a later change of the operator's tier MUST NOT change it. | D3, §8 M2 done-when | US6 |
| FR5.6 | The request form MUST let the operator choose Consumed or Temporary, defaulting to Consumed, and MUST show the return window that applies to the operator's tier when Temporary is chosen. | A3, M1 PRD PQ1 (selector arrives with M2), window text [I] | US1 |
| FR5.7 | The result of a Temporary request MUST say when the granted units return, e.g. "4 granted · returns 10:00 tomorrow"; a partial or zero grant uses the existing M1 wording and only states a return time when units were granted. | §8 M2 [I] | US1 |
| FR5.8 | A replay of an idempotency key MUST return the original result unchanged even after the allocation has expired. | M1 plan §11 carry-in (2026-10-19), NFR5 | US7 |
| FR5.9 | When a request is processed, units that expire at or before that request's own `effective_at` MUST count as returned, and units that expire later MUST NOT. | D1, D4 [I] | US2 |
| FR5.10 | Every open screen MUST show the new remaining, the new holdings and the new status within 2 s after an `expires_at`, without a manual reload and without depending on a pushed message. | NFR6, D12 (expiry: nothing broadcast) | US2, US3 |
| FR2 | The live running metric MUST now also show the operator's **own status level**, with pool remaining (this completes FR2; M1 delivered remaining only). | requirement.md FR2 [S] | US4 |
| FR4 | The status level MUST be calculated from the units the operator currently holds, compared with the thresholds in config (placeholders: standard from 0, elevated from 10, high from 30). What counts as "held" is PQ1. | requirement.md FR4 [S], D10, A4 | US4 |
| FR4.1 | A threshold is inclusive: holding exactly 10 gives elevated, exactly 30 gives high, and 9 and 29 give the level below. | A4 [I] | US4 |
| FR4.2 | The status MUST be recalculated from the current clock: it MUST fall when holdings expire and rise when holdings are granted. | D10, §4 Status | US4 |
| FR4.3 | The status MUST be shown to the operator as a text label (not colour alone) and MUST NOT restrict any action. It is shown only to its own operator. | D10, A8, NFR4 (colour not alone) | US4 |
| FR13 | The dashboard MUST list the operator's own active Temporary holdings, each with units and time left ("expires in 3 h"), soonest first, and an empty state when there are none. | §8 M2 deliverable [S] | US3 |
| FR3.2 | The log MUST show an `expiry` entry when a Temporary allocation falls due, naming whose allocation it was, how many units returned and the original allocation it belongs to. The viewer's own entries stay highlighted. | requirement.md FR3, §8 M2 deliverable [S], M1 FR3.1 | US5 |
| FR3.3 | An `expiry` entry MUST appear exactly once per expired allocation that held at least 1 unit, MUST NOT appear before `expires_at`, and MUST NOT exist for Consumed or zero-grant allocations. | D4 [I] | US5 |
| F3 | The expiry and status steps of the shared calculation MUST become real (M2 owns them), the pending M2 tests from M1 MUST become real passing tests, and M1's API, log and calculation contracts MUST only grow (new fields and entries), so every M1 acceptance criterion still passes. | §8 M1/M2 split, M1 PRD F3, F5 | US9 |
| FR14 | An evaluator MUST be able to see a full expiry (units back, holding gone, log entry) in a demo within a few minutes, without editing code or changing the clock used by tests. | §8 M2 usable deliverable [I]; see PQ2 | US8 |

## 5. Non-functional requirements
| ID | Requirement with measurable threshold | Source |
|---|---|---|
| NFR1 | With Temporary units expiring while requests are in flight, 50 parallel requests never drive the pool below 0, and each response's remaining equals the ledger replayed at that request's `effective_at`. | requirement.md NFR1 [S] |
| NFR2 | Adding expiry and status does not slow submit: p95 submit-to-confirmed stays ≤ 1 s in the 50-request parallel test (M1 AC-US4.5 still passes). | NFR2 [S] |
| NFR3 | The M2 additions (type choice, holdings list, status, expiry log entries) fit at 360 px and 1280 px with no horizontal page scroll. | NFR3 [S] |
| NFR5 | Replays stay exactly-once and identical after expiry (FR5.8). | NFR5 [I] |
| NFR6 | An expiry is visible on another open screen within 2 s of `expires_at`. | NFR6 [I] |
| NFR-M2.1 | Expiry and status are pure functions of the ledger, the rules and an injectable clock, so tests with a fixed clock can pin a result at exact instants (1 ms before, at, and 1 ms after `expires_at`). | §5 "Expiry", §8 M2 done-when |
| NFR4 | Not verified in M2 (→ M4). M2 only avoids colour-only meaning (FR4.3, FR3.2). | NFR4 [S] |

## 6. Business rules and decisions that apply
- **D3** Tier is separate from status; the tier is recorded on each allocation at log time and is what sets the window.
- **D4** The window starts at `effective_at` and uses the tier recorded on the allocation. On expiry the granted units return. Expiry is worked out from the clock at read time, with no scheduled job.
- **D8** Consumed allocations use units permanently; Temporary allocations hold units until they expire.
- **D10** Status is a per-operator level from the units the operator currently holds, mapped to thresholds in config. Display-only.
- **D12** Expiry broadcasts nothing; each client schedules a refresh at the next `expires_at` it can see; the ~10 s fallback refresh still applies.
- **D1** FCFS by server commit order. **D2** The pool never goes negative.
- **§4 Pool** `remaining = Σ restocks − Consumed units − units held by active Temporary allocations`.
- **§4 Expiry** Only Temporary allocations expire. `expires_at = effective_at + window(tier_at_log)`. From that moment the granted units count as available.
- **A2** Tier names and windows are config placeholders (24 h / 48 h / 72 h). **A3** The operator picks Temporary or Consumed at submit. **A4** Three status levels with placeholder thresholds. **A6** No early return by the operator. **A8** Operators see only their current status.
- **M1 carry-ins** (accepted 2026-10-19): a replay must stay identical to the first reply (M1 plan §11, FR5.8 here); M3 will choose the ordering key for downstream recalculation, M2 does not.

## 7. Acceptance criteria
Setup for the examples: pool of 100 unless stated; Ana is Bronze (24 h), Ben Silver (48 h), Cara Gold (72 h); T is a fixed instant; thresholds standard 0 / elevated 10 / high 30.

### US1 — Choose Temporary or Consumed
**AC-US1.1** Given remaining is 10 and Ana is selected, when she picks Temporary and requests 4 at T, then she sees "4 granted" with the return time T + 24 h, remaining shows 6, and the log has an allocation entry by Ana marked Temporary for 4.
**AC-US1.2** Given the form is opened, then Consumed is selected by default; when Ana picks Temporary, then the text "returns after 24 h" (her tier's window) is shown before she submits.
**AC-US1.3** Given Ana requests Temporary 6 when only 4 are left, then she sees "4 of 6 granted" and a return time for those 4 units; given 0 are granted, then no return time is shown.
**AC-US1.4** Given an API call with no `type`, then it is stored as Consumed (M1 AC-US4.7 still holds); given an unknown `type`, then it is rejected with a validation error and nothing is recorded.

### US2 — Units return by themselves
**AC-US2.1** Given Ana is granted Temporary 4 at T (remaining 6), when the clock is T + 24 h − 1 ms, then remaining is 6; at exactly T + 24 h, remaining is 10; at T + 24 h + 1 ms, remaining is 10.
**AC-US2.2** Given the same ledger, when the clock moves from before to after `expires_at`, then no row is written or changed in any table, and the pool version does not change.
**AC-US2.3** Given Ana is granted Consumed 4 at T, then remaining is 6 at T + 24 h + 1 ms and at T + 1000 h.
**AC-US2.4** Given Ana asked for Temporary 6 and was granted 4, then 4 units (not 6) return at expiry and remaining rises by exactly 4.
**AC-US2.5** Given remaining is 0 because Ana holds Temporary 4 and others hold the rest, when Ben requests 3 at T + 24 h − 1 ms, then he is granted 0 ("0 of 3 granted"); when he requests 3 at T + 24 h + 1 ms, then he is granted 3 (FR5.9).
**AC-US2.6** Given Ana's and Ben's screens are open and Ana's holding expires at E, then both screens show the higher remaining within 2 s after E, with no reload and no manual action (FR5.10, NFR6).
**AC-US2.7** Given a Temporary request granted 0 units, then it holds nothing, nothing returns at its `expires_at`, and no expiry entry is created.
**AC-US2.8** Given Ana's Temporary 40 expires at E and 50 parallel requests of 3 arrive around E, then the pool is never below 0 at any point, and each response's remaining equals the ledger replayed at that request's `effective_at` (NFR1).

### US3 — Holdings with time left
**AC-US3.1** Given Ana holds Temporary 4 expiring in 23 h and Temporary 3 expiring in 2 h, then her holdings list shows two rows, the 3-unit row first, each with units and "expires in …" text.
**AC-US3.2** Given a holding reaches `expires_at`, then its row disappears from the list within 2 s without a reload, and the list shows an empty state ("no temporary holdings") if it was the last.
**AC-US3.3** Given Ben is viewing, then he does not see Ana's holdings, and Consumed allocations never appear in the holdings list.
**AC-US3.4** Given a device clock set 1 hour ahead of the server, then the time left shown and the moment the holding disappears match the server's time within 2 s, not the device's.

### US4 — Status level
**AC-US4.1** Given Ana holds Temporary units only, when she holds 9 then her status is standard; at 10, elevated; at 29, elevated; at 30, high (FR4.1).
**AC-US4.2** Given Ana holds Temporary 12 (elevated) that expires at E, then her status is elevated at E − 1 ms and standard at E.
**AC-US4.3** Given Ana is elevated, then Ben's screen shows Ben's own status and nothing about Ana's.
**AC-US4.4** Given status shown, then it is a text label ("Status: elevated"), and no request is blocked or limited by it.
**AC-US4.5** Given an operator with no allocations, then the status is standard and the holdings list is empty, with no error.
**AC-US4.6** Given Ana holds Consumed 12 and no Temporary units, then her status is the level given by PQ1's answer: **elevated** if Consumed counts as held, **standard** if only Temporary counts. *(PQ1 resolved: Consumed counts as held, so the answer is elevated.)*

### US5 — Expiry in the log
**AC-US5.1** Given Ana's Temporary 4 expires at E, then at E − 1 ms the log has no expiry entry, and from E the log has one entry "Expired: Ana's 4 units returned" linked to Ana's original allocation entry.
**AC-US5.2** Given the same, then Ana sees the entry highlighted as hers and Ben sees it unhighlighted; both see it without a reload (FR5.10).
**AC-US5.3** Given several reloads and a later snapshot, then the expiry entry appears exactly once.
**AC-US5.4** Given Consumed allocations and zero-grant Temporary allocations, then no expiry entry is created for them (AC-US2.7).
**AC-US5.5** Given a Temporary allocation that expires at E, then the log is readable on a narrow phone at 360 px and on a desktop at 1280 px with no horizontal page scroll (NFR3), and the entry has a text label, not colour alone.

### US6 — Window follows the tier at log time
**AC-US6.1** Given Ben is Silver when he logs Temporary 5 at T and his tier is changed to Gold afterwards, then that allocation still expires at T + 48 h, not T + 72 h.
**AC-US6.2** Given Ben (now Gold) logs another Temporary at T2, then it expires at T2 + 72 h.

### US7 — Replays after expiry
**AC-US7.1** Given Ana's Temporary request with key K1 was answered "4 granted, remaining 6" at T, when the same key and payload are replayed at T + 25 h, then the reply is identical to the first one (same granted, same outcome, same remaining 6), with one allocation and one expiry entry in the ledger.
**AC-US7.2** Given K1 with a different amount, operator or type, then the server returns a conflict error and records nothing (M1 AC-US4.6 still holds).

### US8 — Demonstrable expiry
**AC-US8.1** Given the documented demo way (PQ2), when an evaluator requests Temporary units, then within a few minutes they see the units return, the holding vanish and the log entry appear on two open screens, without editing code.

### US9 — Foundation for M3
**AC-US9.1** Given the M1 pending expiry and status tests, then they are now real tests and pass; the pending tests for corrections remain pending.
**AC-US9.2** Given the whole M1 acceptance suite (unit, feature, concurrency, JS), then it still passes unchanged except for tests that M1 marked as waiting for M2.
**AC-US9.3** Given the M1 API responses, then every M1 field is still present with the same meaning; the status, the holdings and the expiry entries are additions only.

**Edge cases that must be covered**
- Request with a 1 ms gap around a window end: AC-US2.5.
- Two allocations of the same operator expiring at the same instant: both holdings go, two expiry entries appear, remaining rises by the sum.
- Expiry while the operator's screen is closed: when they open it, remaining, holdings, status and log match the server (AC-US5.3).
- Page reload or server restart after expiry: no stale value; no duplicate entry.
- Expiry of a holding that had been partly granted (AC-US2.4) and of a zero grant (AC-US2.7).
- Live-update service down at expiry time: the ~10 s fallback refresh and the scheduled refresh at `expires_at` still correct the screen.
- Supervisor viewing: sees expiry entries in the log; has no status or holdings (supervisors do not submit, M1 A-M1.3).
- An operator who holds exactly a threshold amount when a part of it expires (e.g. 30 → 29): the status drops from high to elevated.
- Config with a status threshold or window changed: M1 config validation (AC-US7.4) still fails startup on a bad value.

## 8. Open questions and assumptions
`requirement.md` §6 lists no open questions. The rows below carry in the decisions M2 depends on and record gaps found while writing this PRD (PQ#). PQ1–PQ3 were resolved by the user on 2026-10-19 (all proposals accepted); `requirement.md` was not edited.

| ID | Question | Blocking? | Proposed answer / working assumption | Status |
|---|---|---|---|---|
| OQ4 | What status means (units held, thresholds, display-only) | — | Recorded as D10 (reading a) | Resolved (requirement.md §6 → D10) |
| OQ3 | Where capacity and the hold/return rule come from | — | Recorded as D8 | Resolved (requirement.md §6 → D8) |
| PQ1 | Which units count as "held" for status: only active Temporary units, or also Consumed units? D10 says "the units the operator currently holds"; §4 uses "held" only for Temporary, but the brief says supervisor adjustments must change "downstream status levels", and that only has an effect on old Consumed entries if Consumed counts. | Yes | **Held = Consumed units granted (never expire) + active Temporary units, after corrections. Status rises with use and falls only when Temporary units expire.** | Resolved by user, 2026-10-19 (proposal accepted) |
| PQ2 | How does an evaluator see expiry without waiting 24 h? Windows are 24/48/72 h placeholders (A2). | No | **Keep 24/48/72 h as the defaults, and allow a demo setting that shortens the windows to a few minutes without code changes. Tests keep using a fixed clock, not the demo setting.** | Resolved by user, 2026-10-19 (proposal accepted) |
| PQ3 | Where does an expiry entry sit in the log, which is ordered by commit number (`seq`) and is newest first? An expiry has no commit. | No | **Expiry entries are placed by their expiry time among the other entries, appear from the moment they fall due, and never move afterwards.** | Resolved by user, 2026-10-19 (proposal accepted) |
| A-M2.1 | Boundary reading [I] | No | At exactly `expires_at` the units are already back (matches "from that moment", §4); 1 ms earlier they are still held. | Working assumption [I] |
| A-M2.2 | Which time decides expiry for a submit [I] | No | The request's own `effective_at` (server receipt time), so FCFS and expiry use one clock reading. | Working assumption [I] |
| A-M2.3 | Zero-grant Temporary [I] | No | Holds nothing, never expires, no expiry entry. | Working assumption [I] |
| A-M2.4 | Time-left text [I] | No | Coarse and counting down on the screen (e.g. "expires in 3 h", "in 12 min"), based on server time. | Working assumption [I] |
| A-M2.5 | Holdings list scope [I] | No | Own active Temporary allocations only; Consumed allocations are not "holdings" because they never return. | Working assumption [I] |
| A-M2.6 | Tier changes [I] | No | No UI for changing tier; the tier-change case is tested by changing the data directly. | Working assumption [I] |
| A1, A2, A3, A4, A6, A8 | As in requirement.md §7 | No | Carried unchanged | Carried |

## 9. Dependencies and hand-offs
- **Needs from M1 (done):** the allocation `type`, `tier_at_log` and `effective_at` data; the tier windows and thresholds in config; the calculation steps with the clock injected; the pending M2 tests; the snapshot's empty `viewer.status` / `viewer.holdings`; the `expiry` log entry kind in the log renderer and contract; the pool lock and idempotency path.
- **Carry-ins from M1 plan §11 (accepted 2026-10-19):** replays must stay identical after expiry (FR5.8; for the plan to design).
- **Provides to M3:** a real, tested expiry step and status function that run on the corrected list, so a correction recalculates expiry and status for free; the rule that a correction to an already-expired entry changes history only (D7); the status function M3 uses for before/after status history.
- **Provides to M4:** the type selector, holdings list, status display and expiry log entries on the M1 tokens, ready for the sunlight and one-thumb work.
- **Parallel work:** M3 and M4 can proceed alongside M2 as the roadmap says; M2 touches only the expiry and status steps and the additive UI parts.

## 10. Traceability
| Requirement ID | Covered by acceptance criteria |
|---|---|
| FR5 | AC-US2.1, AC-US2.5 |
| FR5.1 | AC-US1.1, AC-US2.1, AC-US6.1, AC-US6.2 |
| FR5.2 | AC-US2.1, AC-US4.2, AC-US5.1 |
| FR5.3 | AC-US2.3, AC-US5.4 |
| FR5.4 | AC-US2.2, AC-US2.4 |
| FR5.5 | AC-US6.1, AC-US6.2 |
| FR5.6 | AC-US1.2, AC-US1.4 |
| FR5.7 | AC-US1.1, AC-US1.3 |
| FR5.8 | AC-US7.1, AC-US7.2 |
| FR5.9 | AC-US2.5, AC-US2.8 |
| FR5.10 | AC-US2.6, AC-US3.2, AC-US5.2 |
| FR2 | AC-US4.1, AC-US4.3 |
| FR4 | AC-US4.1, AC-US4.6 |
| FR4.1 | AC-US4.1 |
| FR4.2 | AC-US4.2 |
| FR4.3 | AC-US4.3, AC-US4.4 |
| FR13 | AC-US3.1, AC-US3.2, AC-US3.3, AC-US3.4 |
| FR3.2 | AC-US5.1, AC-US5.2 |
| FR3.3 | AC-US5.1, AC-US5.3, AC-US5.4, AC-US2.7 |
| F3 | AC-US9.1, AC-US9.2, AC-US9.3 |
| FR14 | AC-US8.1 |
| NFR1 | AC-US2.8 |
| NFR2 | AC-US9.2 (M1 AC-US4.5 re-run with Temporary requests included) |
| NFR3 | AC-US5.5 |
| NFR5 | AC-US7.1, AC-US7.2 |
| NFR6 | AC-US2.6, AC-US3.2 |
| NFR-M2.1 | AC-US2.1, AC-US4.2, AC-US5.1 |
| NFR4 | N/A in M2 — verified in M4 |
