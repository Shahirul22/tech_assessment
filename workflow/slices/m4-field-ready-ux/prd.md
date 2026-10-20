# PRD: M4 — Field-ready UX and submission

| Field | Value |
|---|---|
| Slice | M4 |
| Status | Approved (2026-10-20, by the user; PQ1 resolved as hybrid retry, PQ2–PQ8 resolved by accepting the proposals) |
| Source | requirement.md §0 (esp. "feel instant", "bright sunlight", "effortless"), §1, §2 (NFR2–NFR5, FR1, FR2), §3 (D2, D5, D6, D12), §4 (Allocation), §5 ("Instant" vs "never wrong"), §7 (A10, A11), §8 (M4); M1 PRD (pending / offline states, AC-US4.1–4.4), M2 and M3 PRDs (hand-offs to M4) |
| Date | 2026-10-20 |
| Plan | [plan.md](plan.md) |

## 1. Summary
M4 makes the dashboard something a warehouse operator can use with one thumb, on a phone, in bright light, on a poor connection. Requests become a few taps (quick amounts and steppers capped at what is left), every result is a full-width text banner, the pending, offline and retrying states are unmistakable, and the screens are measured against a sunlight standard (≥ 7:1 contrast for key numbers, ≥ 48 px touch targets, working at 360 px). The slice ends with the final README: decisions, trade-offs and a demo walkthrough script.

**Usable deliverable:** the version you would hand to a warehouse operator. On a 360 px phone, Ana taps "5", sees "Pending", then a full-width "5 granted" banner; with airplane mode on she sees "Offline — nothing was sent" and, after a lost answer, the app resends by itself up to 3 times and then offers a Retry button, and none of this ever creates a second allocation.

## 2. Goals and non-goals
**Goals**
- A one-thumb request flow: quick-amount buttons and steppers that never go above the remaining last shown (D2, A11).
- Result banners that span the full width and say the outcome in words (granted, partly granted, none granted, refused, not confirmed), never by colour alone (NFR4).
- Clear pending, offline and retrying states with no guessed totals (D5, NFR5). A request whose answer is lost is resent automatically up to 3 times with the same idempotency key; if all fail, the operator gets a manual Retry (PQ1, resolved).
- A sunlight-readable look, audited with numbers: key numbers ≥ 7:1 contrast, touch targets ≥ 48 px.
- The whole flow works at 360 px width.
- A final README with decisions, trade-offs and a demo walkthrough script.

**Non-goals (explicitly NOT in this slice)**
- Any change to server rules or contracts: FCFS, the pool lock, `min(requested, remaining)`, expiry, status, corrections, restock, idempotency and the API (D1, D2, D4, D7, NFR1, NFR5 server side → M1–M3, already built). M4 changes only what the operator sees and taps.
- An offline queue (D5). A request that was never sent because the device was offline is not held for later and is not sent when the connection returns. Automatic resend applies only to a request that was already sent and whose answer was lost, and only while online (FR21.3).
- Real login (A10), deployment (D6), dark mode or a theme switch (see PQ4), a native app, a service worker.
- New business behaviour: no early return of units (A6), no new log entry kinds, no new roles.
- Redesigning the supervisor workflow. The supervisor screens get the same sunlight and touch-target standard (PQ5), not new features.

## 3. Users and scenarios
| ID | Persona | Scenario (one sentence) | Priority |
|---|---|---|---|
| US1 | Operator on a phone, one hand | Requests units with a quick-amount button or a stepper instead of typing. | P1 |
| US2 | Operator outdoors or in a bright warehouse | Reads remaining, own status, results and the log without squinting, and without relying on colour. | P1 |
| US3 | Operator on a phone | Gets a full-width banner that says in words what happened to the request. | P1 |
| US4 | Operator on a flaky network | Always knows whether a request is pending, offline, unconfirmed or being retried, and never sees a guessed total. | P1 |
| US5 | Operator toggling airplane mode | Goes offline and back online without ever creating a duplicate allocation. | P1 |
| US6 | Operator on a 360 px phone | Does the whole flow (pick name, request, read result, read log) without horizontal scroll or pinch-zoom. | P1 |
| US7 | Supervisor on a phone or desktop | Restocks and corrects with controls that meet the same contrast and touch-target standard. | P2 |
| US8 | Evaluator | Reads the final README, runs the demo walkthrough script and sees the decisions and trade-offs. | P1 |
| US9 | Evaluator | Re-runs the M1–M3 checks and finds nothing broken by the UX work. | P1 |

## 4. Functional requirements
| ID | Requirement (MUST / SHOULD, one testable behaviour each) | Source | Story |
|---|---|---|---|
| FR19 | The request form MUST offer quick-amount buttons that fill the amount in one tap. Which values: PQ3. A button whose value is above the remaining last shown MUST NOT be usable. | §8 M4 [S], D2 | US1 |
| FR19.1 | The request form MUST offer a stepper (increase / decrease by 1) that cannot go below 1 or above the remaining last shown. At the limits the matching control MUST be visibly unavailable. | §8 M4 [S], D2, A11 | US1 |
| FR19.2 | Typing MUST still work and MUST keep the M1 validation and wording (whole number, 1 ≤ N ≤ remaining). A quick amount or stepper only fills the same field; it never bypasses validation. | M1 FR1.1, A11 | US1 |
| FR19.3 | When remaining is 0, quick amounts, steppers and submit MUST be unavailable and the screen MUST say "0 left". When remaining changes while the operator is on the form (live update), the cap and the available buttons MUST follow within the same 2 s as the number itself; an amount already above the new cap MUST be flagged, not silently changed. | D2, NFR6 [I] | US1 |
| FR19.4 | The request needs at most 2 taps after choosing the amount's kind: one quick amount (or stepper tap) and one submit. Typing is never required. | §0 "minimal typing", NFR4 [I] | US1 |
| FR20 | Every result of a request MUST be shown in a banner that spans the full width of the request panel and starts with the outcome in words: "N granted", "X of N granted", "none granted", "refused" (with the server's reason) or "not confirmed". The banner MUST also carry a shape or icon, so colour is never the only signal. | §8 M4 [S], NFR4, M1 FR1.4, M3 refusal messages | US3 |
| FR20.1 | A banner MUST stay visible until the operator's next action or a newer result replaces it, and MUST be announced to assistive technology as a status. | §8 M4 [I], NFR4 [I] | US3 |
| FR20.2 | The supervisor's restock and correction results (accepted, refused with the reason, not confirmed) MUST use the same full-width banner. | M3 hand-off to M4 | US7 |
| FR21 | The screen MUST show one clearly worded state for each of: **Pending** (sent, no answer yet), **Offline** (no connection, nothing sent), **Retrying automatically** (the answer was lost and the app is resending the same request; shows "attempt n of 3"), **Not confirmed** (all automatic resends failed; manual Retry offered), **Retrying** (Retry pressed, no answer yet), and **Confirmed**. Each state has a word and a shape; none is colour alone. | §8 M4 [S], D5, NFR4 | US4 |
| FR21.1 | While Pending or Retrying, the remaining number MUST NOT change because of the operator's own request until the server confirms (no guessed total), and the submit control MUST NOT allow a second submit of the same request. | §5 "Instant vs never wrong", M1 AC-US4.1 | US4 |
| FR21.2 | Pressing a quick amount, stepper or submit MUST show feedback (Pending state) within 100 ms. | NFR2 [S], M1 NFR2 | US4 |
| FR21.3 | When a sent request gets no answer (timeout or connection error) while the device is online, the app MUST resend it by itself, up to 3 automatic resends, each with the same idempotency key, with a short wait between attempts (PQ8). The screen MUST show "Retrying automatically, attempt n of 3". Automatic resend MUST stop at once if the device goes offline, and the request MUST then be shown as Not confirmed with the manual Retry. | PQ1 (resolved), D5, NFR5 | US4, US5 |
| FR21.5 | After the 3rd automatic resend fails, the screen MUST show "Not confirmed" with a manual Retry button. Retry MUST reuse the same key. Nothing else is ever sent by itself: a request never sent because the device was offline MUST NOT be held or sent when the connection returns, and a definite server answer (granted, refused, conflict, validation error) MUST NOT be resent. | PQ1 (resolved), D5, M1 AC-US4.4 | US4, US5 |
| FR21.6 | Manual Retry MUST start a fresh round: the same key, with up to 3 further automatic resends if it again gets no answer. | PQ1 [I] | US4 |
| FR21.7 | Supervisor restock and correction submits MUST follow the same automatic-then-manual retry rules as FR21.3, FR21.5 and FR21.6, with the same key. | PQ1 [I], M3 FR18 | US7 |
| FR21.4 | Going offline and coming back MUST update the "live / reconnecting / offline" indicator and, after reconnecting, the screen MUST refresh once to the server's remaining and log (M1 FR11 behaviour is kept). | D12, NFR6 | US5 |
| FR22 | The theme MUST be a high-contrast sunlight theme applied to the whole app. Which text and shapes are measured: PQ2. Whether it is the only theme: PQ4. | §8 M4 [S], NFR4 | US2 |
| FR22.1 | The key numbers (pool remaining, the operator's own status, units granted in a result, units and time left on holdings) MUST be at least 24 px high-weight type on phones and MUST be the largest text on their panel. | NFR4 "large type" [I] | US2 |
| FR22.2 | No state may be shown by colour alone: status, own-entry highlight, pending, offline, error, expiry and correction entries, disabled controls and focus MUST each also have words or a shape. | NFR4 [S] | US2 |
| FR22.3 | Keyboard and screen-reader use MUST keep working: every control has a visible focus indicator and a text name. | NFR4 [I] | US2 |
| FR23 | Every tappable control MUST have a touch target of at least 48 × 48 px with space between neighbours so a thumb does not hit the wrong one. Scope: PQ5. | NFR4 [S] | US1, US7 |
| FR24 | The full operator flow (pick name, see remaining and status, request with a quick amount, read the result, read holdings and the log, load older entries) MUST work at 360 px width with no horizontal page scroll, and the request controls MUST be reachable without scrolling past the log on a 360 × 640 phone. | NFR3 [S], §8 M4 [S], thumb reach [I] | US6 |
| FR24.1 | The same flow MUST still work at 1280 px width with no horizontal scroll. | NFR3 [S] | US6 |
| FR25 | The README MUST be final: how to run and test, the decisions and trade-offs (D1–D12 in plain words), accepted risks, the results of the M4 checks, and a demo walkthrough script (PQ7). | §8 M4 [S], D6 | US8 |
| FR26 | M4 MUST NOT change any M1–M3 server behaviour or API contract, and every M1–M3 acceptance criterion MUST still pass. Screen changes only. | §8 (parallel milestones), M1 F3, M2 F3 | US9 |

## 5. Non-functional requirements
| ID | Requirement with measurable threshold | Source |
|---|---|---|
| NFR4 | Key numbers have a contrast ratio of at least 7:1 against their background; touch targets are at least 48 px; state is never shown by colour alone; minimal typing. Measured, not judged by eye (PQ6). | requirement.md NFR4 [S], specifics [I] |
| NFR2 | The operator's action shows feedback within 100 ms, and the confirmed result arrives in about 1 s on the local stack (p95 ≤ 1 s, also with 100 ms added latency). M4 adds no delay: the 50-request test and the latency check still pass. | NFR2 [S], M1 PQ4 |
| NFR3 | The flow works at 360 px and 1280 px with no horizontal page scroll. | NFR3 [S] |
| NFR5 | Airplane-mode toggling, automatic resends and Retry never create a duplicate: after any sequence of offline, online, automatic resends and Retry, one submit equals at most one allocation. | NFR5 [I], §8 M4 done-when |
| NFR6 | Other screens still reflect a change within 2 s. M4 does not slow the live update path. | NFR6 [I] |
| NFR1 | N/A in M4 — server concurrency is unchanged and re-checked only as a regression (FR26). | NFR1 [S] |

## 6. Business rules and decisions that apply
- **D2** — the client caps the request at the remaining last shown; the server stays authoritative (`min(requested, remaining)`). Quick amounts and steppers are client convenience only.
- **D5** — online-only: no queue, nothing held for later, retries are idempotent, a visible pending/offline state instead of a guessed total. The user's PQ1 decision (2026-10-20) adds automatic resend of an already-sent, unanswered request; it holds nothing for later. It tightens M1 AC-US4.4 and the M1 README wording only for that case.
- **D12** — own submit is updated from the HTTP response and shown as pending until it arrives; the live / reconnecting / offline indicator stays.
- **D6** — time-boxed demo, no deployment, role switch instead of login.
- **A10** — seeded users and a role switch, no passwords.
- **A11** — client validation 1 ≤ `requested` ≤ last known remaining.
- **A5, A8** — log visibility and status visibility are unchanged.
- §5 "Instant vs never wrong" — the operator's action appears at once as pending; totals change only after the server confirms.

## 7. Acceptance criteria

### US1 — One-thumb request
**AC-US1.1** Given remaining 100 and Ana on a 360 px phone, when she taps the quick amount "5" and then submit, then the amount field shows 5, the request is sent once, and no keyboard was needed.
**AC-US1.2** Given remaining 8, then quick amounts above 8 are unavailable, the "All" style amount (if offered, PQ3) fills 8, and the stepper's increase control is unavailable at 8 and its decrease control is unavailable at 1.
**AC-US1.3** Given the amount field shows 3, when Ana taps the stepper increase twice, then it shows 5; when she taps decrease 10 times, then it stops at 1.
**AC-US1.4** Given Ana types "0", "-2", "2.5" or "abc", then the M1 messages ("Enter 1 or more", "Enter a whole number") still appear and submit is unavailable.
**AC-US1.5** Given Ana has 10 in the field and Ben takes 95 of 100 (live update to remaining 5), then within 2 s the cap shows "5 left", the quick amounts above 5 become unavailable, and the field flags "Only 5 left" without changing her value silently.
**AC-US1.6** Given remaining 0, then quick amounts, steppers and submit are unavailable and the screen says "0 left".
**AC-US1.7** Given a stale screen that still shows 10 left while the server has 4, when Ana submits 6, then the server grants 4 and the banner says "4 of 6 granted" (D2 server side unchanged).
**AC-US1.8** Given the operator screen at 360 px, then the name picker, quick amounts, stepper controls, kind choices, submit, Retry and "Show older" each measure at least 48 × 48 px with at least 8 px between neighbours (PQ5).

### US2 — Readable in sunlight
**AC-US2.1** Given every key number (remaining, own status, granted units in a banner, holdings units and time left), when its colour and background are measured, then each ratio is ≥ 7:1 (scope: PQ2).
**AC-US2.2** Given the status, own-entry highlight, pending, offline, error, expiry, correction and restock rows, then each has a word or shape in addition to any colour, shown by turning colour off (greyscale) and still telling every state apart.
**AC-US2.3** Given the remaining number on a 360 px phone, then it is the largest text on its panel and is at least 24 px (PQ2 for the exact size reading).
**AC-US2.4** Given keyboard Tab through the page, then every control shows a visible focus indicator and has a text name.

### US3 — Result banners
**AC-US3.1** Given requests that return a full grant (4 of 4), a partial grant (4 of 6), none granted (0 of 3) and a refusal, then each shows a banner across the full width of the request panel at 360 px and 1280 px, starting with "4 granted", "4 of 6 granted", "none granted" and "refused" plus the reason.
**AC-US3.2** Given a banner is showing, when Ana submits again, then the old banner is replaced by the pending state, and when the new result arrives it replaces that.
**AC-US3.3** Given each banner, then it has a shape or icon plus words, and a screen reader announces it as a status.
**AC-US3.4** Given a supervisor restock accepted (+50), a correction raised beyond free units (refused with the M3 message) and a lost answer, then each shows the same full-width banner kind with words and a shape.

### US4 — Pending, offline, retrying
**AC-US4.1** Given Ana taps submit, then within 100 ms she sees "Pending", the remaining number is unchanged, and submit cannot be pressed a second time for the same request.
**AC-US4.2** Given the server stores Ana's request of 3 but the answer is lost, then within the wait of PQ8 the screen shows "Retrying automatically, attempt 1 of 3", the remaining is still the last confirmed value, and when a resend gets an answer she sees the original "3 granted" and exactly one allocation exists.
**AC-US4.2a** Given the server is unreachable for the first attempt and all 3 automatic resends, then exactly 4 sends were made with the same key, then the screen shows "Not confirmed" with a Retry button, and no further send happens by itself.
**AC-US4.3** Given she then taps Retry, then the screen shows "Retrying" with the same shapes as Pending until the answer arrives, then the original result as a banner; if that also gets no answer, up to 3 more automatic resends follow (FR21.6) before "Not confirmed" returns.
**AC-US4.3a** Given the server answers with a definite result (e.g. refused, or a 409 conflict), then nothing is resent.
**AC-US4.4** Given the device is offline, then the indicator says "Offline", submit is unavailable (quick amounts and the stepper only fill the field and stay usable), the screen says "You are offline — nothing was sent", and no total is guessed.
**AC-US4.5** Given each of the six states (Pending, Offline, Retrying automatically, Not confirmed, Retrying, Confirmed) is shown in turn, then a person can tell which one it is from the words alone and from the shape alone.
**AC-US4.6** With 100 ms of added latency, the Pending state appears within 100 ms and the confirmed result arrives in about 1 s (M1 AC-US4.5 re-checked).

### US5 — Airplane mode and duplicates
**AC-US5.1** Given Ana toggles airplane mode on, taps 3, and presses submit, then nothing is sent; when she turns it off, then nothing is sent by itself and remaining is the server's value after one refresh.
**AC-US5.2** Given Ana submits 3, the server stores it, and airplane mode starts before the answer arrives, then automatic resending stops and the screen shows "Not confirmed" with Retry; when she goes online and taps Retry, she sees "3 granted" and exactly one allocation exists for that submit.
**AC-US5.3** Given Ana toggles airplane mode on and off 10 times in a row during one submit and taps Retry as often as she likes, then at most one allocation exists for that submit and remaining was reduced at most once.
**AC-US5.5** Given Ana's answer is lost while online and the server had stored the request, then an automatic resend is answered with the original result and the log shows one entry.
**AC-US5.4** Given the connection drops and returns, then the indicator goes "live → reconnecting or offline → live" and, after reconnecting, remaining and the log equal the server's after one refresh.

### US6 — 360 px flow
**AC-US6.1** Given a 360 × 640 viewport, then pick name, remaining, status, request controls and a result banner are all usable, and `scrollWidth <= innerWidth` holds on the pool view and on the supervisor view.
**AC-US6.2** Given a 360 × 640 viewport on first load, then the quick amounts, stepper and submit are reachable without scrolling past the activity log.
**AC-US6.3** Given a 1280 × 800 viewport, then the same flow works and `scrollWidth <= innerWidth` holds.

### US7 — Supervisor controls
**AC-US7.1** Given the supervisor screen, then every control (tabs, restock, correction form fields and radios, Correct buttons in the log) has a touch target ≥ 48 px and visible text labels.
**AC-US7.2** Given the supervisor's key numbers (remaining, audit values), then each measures ≥ 7:1.

### US8 — Final README
**AC-US8.1** Given the README, then it states how to run and test, the decisions D1–D12 and the trade-offs in plain words, the accepted risks, and the M4 check results with date and method.
**AC-US8.2** Given the demo walkthrough script, when a person follows it from `docker compose up` on a fresh clone, then each step has an expected result, and it covers: two browsers in sync, a partial grant, a Temporary allocation and its expiry, a supervisor restock and correction, and airplane mode (PQ7).

### US9 — Regression
**AC-US9.1** Given the full suites (unit and feature, concurrency, JS), then all pass; the 50 parallel requests of 3 against 100 still grant exactly 100 with one partial grant of 1, and a replayed idempotency key is not counted twice.
**AC-US9.2** Given two browsers open, then a change in one shows in the other within 2 s.

**Edge cases that must be covered**
- Remaining changes between tapping a quick amount and submitting: the server's `min(requested, remaining)` rules, the banner says "X of N granted".
- A quick amount tapped twice quickly: the field is set, not added; only one submit is sent.
- Submit double-tapped: one request, one allocation.
- Retry tapped while offline: it is refused with the offline message, nothing sent, the key is kept.
- The device goes offline during the wait between automatic resends: the countdown stops, no send happens, the screen shows "Not confirmed" with Retry.
- A late original answer arrives while an automatic resend is in flight: one result is shown, the other is ignored, and one allocation exists.
- The page is closed or reloaded during automatic resending: nothing continues in the background and nothing is stored for later (D5); the operator checks the log and resubmits if needed.
- A supervisor restock or correction whose answer is lost follows the same automatic then manual retry rules (FR21.7).
- A live update arrives while Pending: the remaining may change for other people's actions, but the operator's own request is shown only by the server's answer.
- Temporary chosen with a quick amount: the return-window text and the result text from M2 still show.
- Role switch mid-pending: the pending request stays with the user who sent it, and no result from it appears under the other user.
- Remaining reaches 0 during a pending submit: the banner shows the server's outcome, then the form shows "0 left".
- Long names, 5-digit amounts and 5-digit "left" numbers at 360 px do not overflow.
- The "Show older" and "Correct" controls meet the 48 px rule in a long log.

## 8. Open questions and assumptions
requirement.md §6 lists no open questions. The items below are ambiguities this PRD found in the M4 brief; none are decided here. "Proposed" is not "Resolved".

| ID | Question | Blocking? | Proposed answer / working assumption | Status |
|---|---|---|---|---|
| PQ1 | What does the "retrying" state mean? | Yes | Hybrid: up to 3 automatic same-key resends of a sent-but-unanswered request, while online; then a manual Retry. | **Resolved by user, 2026-10-20** (hybrid, up to 3 automatic resends, then manual Retry) |
| PQ2 | Which text counts as a "key number" for the ≥ 7:1 rule, and how is large type read? | No | All text and every number and state label on both screens, including text on highlighted, pending and banner backgrounds and on disabled controls, is ≥ 7:1; control borders and focus rings ≥ 3:1 [I]; "large type" means ≥ 24 px for the key numbers on phones. | Resolved by user, 2026-10-20 (proposal accepted) |
| PQ3 | Which quick-amount values? | No | 1, 5, 10 and "All" (everything left), showing only the ones that fit the remaining last shown. | Resolved by user, 2026-10-20 (proposal accepted) |
| PQ4 | Is the sunlight theme the only theme, or a switch next to the current one? | No | The only theme: the current tokens are revised in place, with no toggle and no dark mode. | Resolved by user, 2026-10-20 (proposal accepted) |
| PQ5 | Does the 48 px rule cover the supervisor screen and the role picker too? | No | Yes: every tappable control on both screens, including the role picker, tabs, radios, Retry, "Show older" and "Correct", at least 48 × 48 px with at least 8 px between neighbours [I]. | Resolved by user, 2026-10-20 (proposal accepted) |
| PQ6 | How are contrast, touch targets, 360 px and airplane mode proven? | No | Contrast and target sizes by repeatable measurement of the real page (results recorded in the README); 360 px and airplane mode by the existing manual checklist plus a scripted browser run, as for M1. No new dependency without your approval. | Resolved by user, 2026-10-20 (proposal accepted) |
| PQ8 | How long is the wait between automatic resends, and what counts as "no answer"? | No | Waits of 1 s, 2 s and 4 s after the first no-answer; "no answer" is a network error or a request timeout of 3 s [I]; the 3 resends take about 7 s of waiting plus up to 3 s per send, so at most about 19 s before the manual Retry appears. Only timeouts, connection errors and server 5xx errors count; definite answers (2xx, 4xx) are never resent. | Resolved by user, 2026-10-20 (proposal accepted; timeout changed from 5 s to 3 s by the user) |
| PQ7 | What shape and length for the demo walkthrough script? | No | A numbered section in the README, 10 minutes or less, one expected result per step, resetting the data first, using the seeded users and the demo expiry from M2. | Resolved by user, 2026-10-20 (proposal accepted) |

**Assumptions carried into the plan [I]:** the M4 work is screen-only (FR26); M1–M3 controls and element names stay so existing checks keep working; the 100 ms feedback rule and ≥ 8 px spacing are our readings of "feels instant" and "one thumb".

## 9. Dependencies and hand-offs
- **Needs from M1:** the dashboard shell, tokens, pending / offline / live indicator, idempotent submit and Retry, the live update path.
- **Needs from M2:** the Temporary selector, the return-window text, holdings, status and expiry log entries to restyle.
- **Needs from M3:** the supervisor tab, restock and correction forms, audit view and refusal messages to restyle.
- **Provides:** the final README and the demo walkthrough; nothing for other slices (M4 is last).
- **Parallel work:** M4 touches only the screens (§8 roadmap), so it cannot change any server contract.

## 10. Traceability
| Requirement ID | Covered by acceptance criteria |
|---|---|
| FR19 | AC-US1.1, AC-US1.2, AC-US1.6 |
| FR19.1 | AC-US1.2, AC-US1.3 |
| FR19.2 | AC-US1.4 |
| FR19.3 | AC-US1.5, AC-US1.6 |
| FR19.4 | AC-US1.1 |
| FR20 | AC-US3.1, AC-US3.3, AC-US1.7 |
| FR20.1 | AC-US3.2, AC-US3.3 |
| FR20.2 | AC-US3.4 |
| FR21 | AC-US4.1, AC-US4.2, AC-US4.3, AC-US4.4, AC-US4.5 |
| FR21.5 | AC-US4.2a, AC-US4.3a |
| FR21.6 | AC-US4.3 |
| FR21.7 | AC-US3.4, AC-US4.2a |
| FR21.1 | AC-US4.1, AC-US4.2 |
| FR21.2 | AC-US4.1, AC-US4.6 |
| FR21.3 | AC-US4.2, AC-US4.2a, AC-US5.1, AC-US5.2, AC-US5.3, AC-US5.5 |
| FR21.4 | AC-US4.4, AC-US5.4 |
| FR22 | AC-US2.1 |
| FR22.1 | AC-US2.1, AC-US2.3 |
| FR22.2 | AC-US2.2, AC-US4.5 |
| FR22.3 | AC-US2.4 |
| FR23 | AC-US1.8, AC-US7.1 |
| FR24 | AC-US6.1, AC-US6.2 |
| FR24.1 | AC-US6.3 |
| FR25 | AC-US8.1, AC-US8.2 |
| FR26 | AC-US9.1, AC-US9.2 |
| NFR4 | AC-US1.8, AC-US2.1, AC-US2.2, AC-US2.3, AC-US7.1, AC-US7.2 |
| NFR2 | AC-US4.1, AC-US4.6, AC-US9.1 |
| NFR3 | AC-US6.1, AC-US6.3 |
| NFR5 | AC-US5.2, AC-US5.3 |
| NFR6 | AC-US1.5, AC-US5.4, AC-US9.2 |
| NFR1 | N/A in M4 — regression only (AC-US9.1) |
