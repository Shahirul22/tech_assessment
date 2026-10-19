# PRD: M1 — Shared pool, live

| Field | Value |
|---|---|
| Slice | M1 |
| Status | Approved (2026-10-17, by the user; PQ1–PQ4 resolved) |
| Source | requirement.md §0, §1, §2, §3 (D1, D2, D5, D8, D9, D10, D12), §4, §5, §7, §8 (M1) |
| Date | 2026-10-17 |
| Plan | [plan.md](plan.md) (Approved 2026-10-17) |

## 1. Summary
Field operators, mostly on phones, request units from one shared, limited pool and immediately see whether they got them, while every open screen shows the same live remaining amount and activity log. The server serves requests strictly first come, first served and the pool never goes negative, even when many operators submit at the same moment. M1 is also the foundation slice: it delivers the stack, full data model, calculation contract, API contract, UI shell and test harness so M2, M3 and M4 can be built in parallel without reworking it.

**Usable deliverable:** an operator on a phone or desktop picks their name, requests up to the units left, sees "granted" (or "8 of 10 granted" if someone got in first), and watches pool remaining and a whole-pool activity log update on every open screen.

## 2. Goals and non-goals
**Goals**
- An operator can request units and gets an honest, immediate answer (granted / partly granted / not granted).
- Pool remaining is always correct and never below 0, under 50 simultaneous requests.
- A retried or replayed submit never counts twice.
- Two browsers see each other's changes within 2 s.
- The foundation M2–M4 build on exists and is verified (§4 F1–F7).

**Non-goals (explicitly NOT in this slice)**
- Expiry behaviour, units returning to the pool, "expires in…" on holdings → M2 (FR5, D4). M1 only *records* `type` and the tier at log time.
- Status levels shown to operators (FR2 "own status", FR4) → M2. In M1 the status step is a no-op. D10 is not exercised.
- Restock action, setting capacity, corrections, voids, audit view, "overdrawn in hindsight" flag (FR6, FR7, FR8 behaviour, FR10 actions, D7, D11) → M3. In M1 those endpoints return 501.
- Sunlight theme audit, ≥ 7:1 contrast measurement, ≥ 48 px touch-target verification, quick-amount buttons and steppers, full-width result banners, airplane-mode walkthrough, final README → M4 (NFR4). M1 supplies only the design tokens and a first usable layout.
- Offline queue (D5: online-only, never queued). Waitlist for a rejected remainder (§4). Early return of units by an operator (A6). Backdating (§4).
- Real login or passwords (A10), deployment (D6).

## 3. Users and scenarios
| ID | Persona | Scenario (one sentence) | Priority |
|---|---|---|---|
| US1 | Operator on a phone | Picks their name, requests N units up to what is left, and immediately sees the outcome. | P1 |
| US2 | Operator on a phone | Gets a clear partial or no-grant result when someone else took units first, and can resubmit. | P1 |
| US3 | Operator on a phone or desktop | Watches the pool remaining and the whole-pool activity log update live, including other operators' actions. | P1 |
| US4 | Operator on a flaky network | Retries a submit without ever being counted twice, and sees a clear pending / offline state instead of a guessed total. | P1 |
| US5 | Many operators at once | Submit at the exact same moment and the pool stays correct and fair (first committed, first served). | P1 |
| US6 | Evaluator / supervisor | Switches role between operator and supervisor using seeded users and sees a placeholder supervisor tab. | P2 |
| US7 | Developer of M2–M4 | Starts the stack with one command and builds on the schema, calculation contract, API contract, UI shell and tests without changing M1 tables. | P2 |

## 4. Functional requirements
| ID | Requirement | Source | Story |
|---|---|---|---|
| FR1 | The system MUST let an operator submit an allocation request of N whole units (A1) against the pool. | requirement.md FR1 [S] | US1 |
| FR1.1 | The UI MUST cap the amount input to 1 ≤ N ≤ the remaining last shown to that operator, and show "N left" beside it. | D2 client, A11 | US1 |
| FR1.2 | The server MUST process each pool's requests strictly one at a time in commit order (never device clocks), and grant `min(requested, remaining)`. | D1, D2 server, §4 | US2, US5 |
| FR1.3 | The server MUST reject any request that would make the pool negative; the pool MUST never go below 0. | D2, NFR1 | US2, US5 |
| FR1.4 | The result MUST be shown to the requester as "N granted" when fully granted, "X of N granted" when partly granted, and a clear "none granted" message when nothing was granted. | M1 deliverable, D2 | US1, US2 |
| FR1.5 | A rejected remainder MUST NOT be waitlisted; the operator resubmits if they still need units. | §4 | US2 |
| FR1.8 | A request granted 0 units MUST still be recorded and shown in the activity log as "requested N, granted 0". | PQ2 | US2 |
| FR1.9 | The M1 API MUST accept `type` (default Consumed); the M1 form MUST NOT offer a type selector, so every M1 request from the UI is Consumed. | PQ1 | US1 |
| FR1.6 | Each allocation MUST retain `type` (Consumed / Temporary), `requested`, `granted`, `operator`, `tier_at_log`, `effective_at`, `recorded_at` and the idempotency key. | §4, D3 | US1, US7 |
| FR1.7 | `effective_at` MUST be the server's receipt time; operators MUST NOT be able to set or backdate it. | §4 | US5 |
| FR2 | The system MUST show each operator a live running metric: pool remaining. (Own status → M2.) | requirement.md FR2 [S] | US3 |
| FR3 | The system MUST show an activity log of the whole pool, in commit order, with the viewing operator's own entries highlighted. | requirement.md FR3 [S], A5 | US3 |
| FR3.1 | The log renderer MUST already render the four entry kinds `allocation`, `expiry`, `correction` and `restock`. M1 produces only `allocation` and `restock` entries. | §8 M1 foundation | US3, US7 |
| FR11 | Every open screen MUST reflect a pool change (remaining and new log entry) within 2 s, per the D12 behaviour: changes apply in commit order, older or duplicate updates are ignored, a gap or reconnect triggers one full refresh, and a fallback refresh runs about every 10 s while live updates are down. | NFR6, D12 [I as FR] | US3 |
| FR11.1 | The UI MUST show a visible "live / reconnecting / offline" indicator. | D12 | US3, US4 |
| FR12 | The requester's own submit MUST appear immediately as "pending", and the totals MUST change only after the server confirms. | D5, §5 "Instant vs never wrong" | US4 |
| FR12.1 | When offline, the UI MUST show an offline state and MUST NOT guess a total or queue the request for later. | D5 | US4 |
| FR9 | The system MUST provide two roles (operator, supervisor) switchable from seeded users with no password; the supervisor view is a placeholder tab. | requirement.md FR9 [I], A10 | US6 |
| FR10 | The pool's starting capacity MUST be seeded as an initial *restock* event, visible in the log. Restock and capacity-setting endpoints MUST exist in the contract and return 501. | FR10 [I], D8, §8 M1 | US6, US7 |
| F1 | A single command MUST start the full stack (app, database, live-update service) with seed data. | §8 M1, D9 | US7 |
| F2 | The data model MUST already include everything M2 and M3 need (allocation `type` and `tier_at_log`, corrections, restocks), so later slices add behaviour, not changes to existing tables. | §8 M1 | US7 |
| F3 | A pure calculation service with an injectable clock MUST compute pool remaining and grants from the ledger. Its expiry and status steps MUST be no-ops, with pending (not failing) tests waiting for M2. | §8 M1, §4 Pool | US7 |
| F4 | Rules config (tiers and expiry windows, status thresholds) MUST be loaded and validated; invalid config MUST fail clearly. | §8 M1, A2, A4 | US7 |
| F5 | An API contract MUST exist for every endpoint M1–M4 need; correction and restock endpoints MUST return 501. | §8 M1 | US7 |
| F6 | The UI shell MUST provide high-contrast design tokens, the role switch and a placeholder supervisor tab. | §8 M1 | US6, US7 |
| F7 | A test harness MUST provide unit and feature tests against the real database, a parallel-HTTP concurrency test against the running stack, and a README of assumptions and how to run. | §8 M1, §5 | US7 |

## 5. Non-functional requirements
| ID | Requirement with measurable threshold | Source |
|---|---|---|
| NFR1 | 50 parallel requests of 3 units against a pool of 100 never drive the pool below 0 and grant exactly 100 units in total. | requirement.md NFR1 [S] |
| NFR2 | The requester's action is acknowledged (pending state shown) within 100 ms [I]; the confirmed result arrives within about 1 s on a normal network (measure defined in PQ4). | NFR2 [S], target [I] |
| NFR3 | The full M1 flow (pick name, submit, see remaining, read log) works at 360 px (phone) and 1280 px (desktop) widths with no horizontal page scroll. | NFR3 [S], widths [I] |
| NFR5 | A replayed idempotency key is counted once, whatever the number of replays. The same key with a different payload is refused with a conflict error and records nothing (PQ3). | NFR5 [I], PQ3 |
| NFR6 | A change made on one screen is visible on another open screen within 2 s. | NFR6 [I] |
| NFR4 | Not verified in M1 (→ M4). M1 delivers only the design tokens (F6). | NFR4 [S] |

## 6. Business rules and decisions that apply
- **D1** One shared pool, strict FCFS; order is server commit order.
- **D2** Pool never negative. Client caps at last-shown remaining; server grants `min(requested, remaining)` and rejects the rest.
- **D3** Tier is separate from status; the operator's tier is recorded on each allocation at log time.
- **D5** Online-only. No offline queue. Retries are idempotent. Visible pending/offline state instead of a guessed total.
- **D8** Capacity comes from restock events; the initial capacity is one. Consumed allocations use units permanently. (Temporary hold-and-return is M2.)
- **D9** Stack is fixed by the brief owner: Laravel, PostgreSQL, Docker Compose, all set up in M1.
- **D12** UI and live-update behaviour (see FR11, FR11.1, FR12). Choices of library and channel are for the plan.
- **§4 Pool** `remaining = Σ restocks − Consumed units − units held by active Temporary allocations`. In M1 nothing expires, so a Temporary allocation holds its units for the whole slice.
- **§4 Allocation** one request at a time per pool; no waitlist; no backdating.
- **A1** whole units, one unit type per pool · **A2** tier names and windows are config placeholders (Bronze 24 h / Silver 48 h / Gold 72 h) · **A4** 3 status levels, placeholder thresholds · **A5** operators see the whole pool's log, own entries highlighted · **A10** seeded users plus role switch · **A11** client-side validation 1 ≤ requested ≤ last known remaining.

## 7. Acceptance criteria

### US1 — Operator submits and sees the outcome
**AC-US1.1** Given remaining is 10 and Ana is selected, when she requests 4, then she sees "4 granted", remaining shows 6, and the log has a new allocation entry by Ana for 4.
**AC-US1.2** Given remaining shown is 10, when Ana tries to enter 11, then the input does not accept more than 10 (or submit is disabled), and "10 left" is shown next to it.
**AC-US1.3** Given remaining shown is 0, then the submit control is disabled and "0 left" is shown.
**AC-US1.4** Given any client, when a request has an amount that is 0, negative, fractional or not a number, then the server rejects it with a validation error and no allocation is recorded.
**AC-US1.5** Given a stored allocation, then it retains `type`, `requested`, `granted`, `operator`, `tier_at_log`, `effective_at`, `recorded_at` and the idempotency key, and `tier_at_log` equals the operator's tier at the time of the request.
**AC-US1.6** Given a client whose device clock is set 1 hour ahead, when it submits, then `effective_at` equals the server's receipt time (within 1 s of server time), not the device time.

### US2 — Partial and no grant
**AC-US2.1** Given remaining is 10 and Ana and Ben each request 6 at the same moment, then the request committed first gets "6 granted", the other gets "4 of 6 granted", remaining is 0, and total granted is 10.
**AC-US2.2** Given the server's remaining is 0 but Ben's screen still shows 3 left, when Ben requests 3, then he sees a clear "none granted" message ("0 of 3 granted"), remaining stays 0, no units are held for him, his screen updates to "0 left", and the log shows "Ben requested 3, granted 0" (FR1.8).
**AC-US2.3** Given Ben received "4 of 6 granted", then no waitlist entry exists, and a later request is only served by a new submit from Ben.
**AC-US2.4** Given a client calls the API directly asking for 11 when remaining is 10, then the server grants 10 and reports "10 of 11 granted"; remaining is 0.

### US3 — Live remaining and log
**AC-US3.1** Given Ana and Ben each have the dashboard open in separate browsers, when Ana is granted 5 units, then Ben's remaining and log show the change within 2 s, without a manual reload.
**AC-US3.2** Given the log, then it lists the whole pool's entries in commit order, Ana's own entries are visibly highlighted for Ana (and not for Ben), and the seeded initial capacity appears as a *restock* entry.
**AC-US3.3** Given the log renderer receives sample entries of kind `allocation`, `expiry`, `correction` and `restock`, then each renders without error, with a distinct label (text, not colour alone).
**AC-US3.4** Given Ben's live connection drops, then the indicator changes from "live" to "reconnecting" or "offline", Ben's screen still refreshes about every 10 s, and after reconnect one full refresh leaves remaining and log equal to the server's.
**AC-US3.5** Given two updates arrive out of order (the later one first), or an update is duplicated, then the screen never shows a stale or double-counted remaining, and after a gap it refreshes once and matches the server.
**AC-US3.6** Given a narrow phone (360 px) and a desktop (1280 px), then the screen shows remaining, the request form and the log with no horizontal page scroll.

### US4 — Safe retries, pending and offline
**AC-US4.1** Given Ana presses submit, then within 100 ms she sees her request as "pending", and the pool remaining number has not changed until the server confirms.
**AC-US4.2** Given the server received Ana's request but the response was lost, when Ana retries, then she sees the original result, and exactly one allocation exists for that submit.
**AC-US4.3** Given a request is replayed 10 times with the same idempotency key and payload, then remaining is reduced once, one log entry exists, and every reply carries the original result.
**AC-US4.4** Given the device has no connection, when Ana tries to submit, then an offline state is shown, no total is guessed, nothing is queued, and nothing is sent automatically when the connection returns.
**AC-US4.5** Given the 50-request parallel test on the local Docker stack, then the 95th percentile of submit-to-confirmed time is ≤ 1 s, and a manual check with 100 ms added latency still confirms within about 1 s (NFR2, PQ4).
**AC-US4.6** Given key `K1` was used for a request of 3 units, when `K1` arrives with a different amount or operator, then the server returns a conflict error, nothing is recorded, and remaining is unchanged (PQ3).
**AC-US4.7** Given the M1 request form, then it shows no Consumed/Temporary selector, and a request sent from it is stored with type Consumed; given an API call with no `type`, then it is stored as Consumed (PQ1).

### US5 — Concurrency and fairness
**AC-US5.1** Given a pool of 100, when 50 requests of 3 units are sent in parallel, then total granted is exactly 100, exactly one request is partly granted (1 of 3), 33 are fully granted, 16 are granted nothing, and remaining is exactly 0.
**AC-US5.2** Given the same run, then remaining as recorded after every commit is ≥ 0, and the log order equals commit order.
**AC-US5.3** Given any two requests, then the one committed first is served first regardless of the clocks of the devices that sent them.

### US6 — Roles and placeholders
**AC-US6.1** Given the app is open, when the user picks a seeded operator (e.g. Ana), then they land in the operator view with that name; when they pick a seeded supervisor, they see a supervisor tab labelled as a placeholder. No password is asked.
**AC-US6.2** Given the restock and correction endpoints, when called, then each returns 501 with the contract's documented error body and changes nothing.

### US7 — Foundation
**AC-US7.1** Given a clean checkout with Docker, when one command is run, then the app, database and live-update service start, seed data loads, and the dashboard shows remaining 100 with a seeded restock of 100 in the log.
**AC-US7.2** Given the schema, then it has fields for allocation `type` and `tier_at_log`, for corrections (pointing at the original entry, with supervisor and reason) and for restocks, so that M2 and M3 need no change to existing tables (confirmed by a review checklist in the plan).
**AC-US7.3** Given a fixed clock and a ledger of one restock of 100 and allocations granting 3, 3 and 4, when the calculation runs, then remaining is 90; and for the same ledger the expiry and status steps return the input unchanged. The pending M2 tests exist and are marked pending, not failing.
**AC-US7.4** Given valid config (3 tiers with windows, 3 status thresholds), then it loads. Given config with a missing tier window, a non-positive window, or thresholds that are not in ascending order, then startup fails with an error naming the bad key.
**AC-US7.5** Given the API contract, then every endpoint M1–M4 need is listed with request, response and error shapes, and the unbuilt ones are marked 501.
**AC-US7.6** Given the test suites, then unit and feature tests run against the real database and pass, the parallel-HTTP concurrency test runs against the running stack and passes AC-US5.1, and the README states the assumptions (§8) and how to run everything.

**Edge cases that must be covered**
- Request exactly equal to remaining (10 of 10): fully granted, remaining 0.
- Request of 1 when remaining is 1, with two operators at once: one gets "1 granted", the other "0 of 1 granted".
- Request of 0 units remaining on the server with a stale screen: AC-US2.2.
- Replay of a request whose result was "none granted" returns the same "none granted" result, not a new attempt.
- Same idempotency key with a different payload: conflict error, nothing recorded (AC-US4.6).
- Live-update service down at submit time: the HTTP response still updates the requester, and other screens catch up on the ~10 s refresh.
- Server restart or page reload mid-session: the screen shows the server's current remaining and log, not a stale value.
- Operator with no allocations yet: the log still shows the whole pool; nothing of theirs is highlighted; no error.
- Empty log apart from the seeded restock: shown correctly.

## 8. Open questions and assumptions
`requirement.md` §6 lists no open questions. The rows below carry in the resolved ones that M1 depends on, and record gaps found while writing this PRD (PQ#). PQ1–PQ4 were resolved by the user on 2026-10-17 (all four proposals accepted). `requirement.md` was not edited; the answers live in this PRD.

| ID | Question | Blocking? | Proposed answer / working assumption | Status |
|---|---|---|---|---|
| OQ2 | How the pool stays non-negative | — | Recorded as D2 | Resolved (requirement.md §6 → D2) |
| OQ3 | Where capacity comes from | — | Recorded as D8 | Resolved (requirement.md §6 → D8) |
| OQ5 | Stack | — | Recorded as D9 | Resolved (requirement.md §6 → D9) |
| OQ7 | Frontend and live transport | — | Recorded as D12 | Resolved (requirement.md §6 → D12) |
| PQ1 | Does the M1 request form let the operator choose Consumed vs Temporary? A3 says the operator picks at submit, but the M1 deliverable does not mention it and no slice lists adding the selector. In M1 nothing expires, so a Temporary pick would hold units for good. | No | **M1 API accepts `type` (default Consumed); the M1 form has no selector, so every M1 request is Consumed. The selector is added with M2, when Temporary becomes meaningful.** | Resolved by user, 2026-10-17 (proposal accepted) |
| PQ2 | When a request is granted 0 units, is it recorded and shown in the log? | No | **Record it and show it in the log as "requested 3, granted 0". This keeps replay answers stable and lets an operator see why they got nothing.** | Resolved by user, 2026-10-17 (proposal accepted) |
| PQ3 | What happens when the same idempotency key arrives with a different payload (different amount or operator)? | No | **Refuse it with a conflict error and record nothing; return the original result only when the payload matches.** | Resolved by user, 2026-10-17 (proposal accepted) |
| PQ4 | What counts as a "normal network" for the "≤ ~1 s" confirmed-result target (NFR2)? | No | **Measured on the local Docker stack: 95th percentile of submit-to-confirmed ≤ 1 s during the 50-request parallel test, and a manual check at 100 ms added latency.** | Resolved by user, 2026-10-17 (proposal accepted) |
| A1 | Unit | No | Whole numbers; one unit type per pool | Carried (requirement.md A1) |
| A2 | Tier names and windows | No | Bronze 24 h / Silver 48 h / Gold 72 h as config placeholders | Carried |
| A4 | Status thresholds | No | 3 levels, placeholder values in config | Carried |
| A5 | Log visibility | No | Whole pool, own entries highlighted | Carried |
| A10 | Authentication | No | Seeded users plus role switch | Carried |
| A11 | Validation | No | 1 ≤ requested ≤ last known remaining (client side) | Carried |
| A-M1.1 | Seeded users [I] | No | At least 3 operators, one per tier (Ana, Ben, Cara, as in the §4 example) and 1 supervisor. Names and tiers are placeholders. | Working assumption [I] |
| A-M1.2 | Initial capacity [I] | No | 100 units, matching the §8 done-when numbers | Working assumption [I] |
| A-M1.3 | Supervisors and submit [I] | No | Only operators submit allocations; a supervisor's submit attempt is refused with a "forbidden role" error. In M1 the supervisor sees only the placeholder tab. | Resolved by user, 2026-10-17 (plan §11 proposal accepted) |

## 9. Dependencies and hand-offs
- **Needs from other slices:** none. M1 is the root of the roadmap.
- **Provides to other slices:**
  - M2: the allocation `type` and `tier_at_log` data, the config for tiers, windows and thresholds, and the calculation service with no-op expiry and status steps plus pending tests.
  - M3: the corrections and restocks data, the one-at-a-time write path and pool version that corrections must share with submits, and the 501 correction and restock endpoints in the API contract.
  - M2 and M3: the single shared calculation function (M3 owns the correction step, M2 owns the expiry and status steps).
  - M4: the design tokens, the responsive layout and the pending/offline/live indicators to refine.
  - All: the Docker stack, seed data, test harness and concurrency test.

## 10. Traceability
| Requirement ID | Covered by acceptance criteria |
|---|---|
| FR1 | AC-US1.1, AC-US1.4 |
| FR1.1 | AC-US1.2, AC-US1.3 |
| FR1.2 | AC-US2.1, AC-US5.1, AC-US5.3 |
| FR1.3 | AC-US2.2, AC-US2.4, AC-US5.1, AC-US5.2 |
| FR1.4 | AC-US1.1, AC-US2.1, AC-US2.2, AC-US2.4 |
| FR1.5 | AC-US2.3 |
| FR1.8 | AC-US2.2 |
| FR1.9 | AC-US4.7 |
| FR1.6 | AC-US1.5 |
| FR1.7 | AC-US1.6, AC-US5.3 |
| FR2 | AC-US1.1, AC-US3.1 |
| FR3 | AC-US3.1, AC-US3.2 |
| FR3.1 | AC-US3.3 |
| FR11 | AC-US3.1, AC-US3.4, AC-US3.5 |
| FR11.1 | AC-US3.4 |
| FR12 | AC-US4.1 |
| FR12.1 | AC-US4.4 |
| FR9 | AC-US6.1 |
| FR10 | AC-US3.2, AC-US6.2 |
| F1 | AC-US7.1 |
| F2 | AC-US7.2 |
| F3 | AC-US7.3 |
| F4 | AC-US7.4 |
| F5 | AC-US6.2, AC-US7.5 |
| F6 | AC-US6.1 |
| F7 | AC-US7.6 |
| NFR1 | AC-US5.1, AC-US5.2 |
| NFR2 | AC-US4.1, AC-US4.5 |
| NFR3 | AC-US3.6 |
| NFR5 | AC-US4.2, AC-US4.3, AC-US4.6 |
| NFR6 | AC-US3.1, AC-US3.4 |
| NFR4 | N/A in M1 — verified in M4 |
