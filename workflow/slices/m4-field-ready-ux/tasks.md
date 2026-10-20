# Tasks: M4 — Field-ready UX and submission

| Field | Value |
|---|---|
| Slice | M4 |
| PRD | [prd.md](prd.md) |
| Plan | [plan.md](plan.md) |
| Status | Approved |
| Date | 2026-10-20 |

## Conventions
- Read before every task: `workflow/rules/*` (`laravel-boost.md`), `CLAUDE.md`, the task's `Refs` in [plan.md](plan.md) and [prd.md](prd.md), and `.ai/rules/index.md` if that directory exists (it did not on 2026-10-20).
- **Laravel Boost is mandatory for every Blade, Vite, PHP and Pest task.** Before creating or editing such a file, search the Boost documentation (`search-docs`) for the installed versions, and name the Boost query in the task's completion note. Suggested queries are in plan §9 ("Boost queries"). Pure JS and CSS tasks that touch no Laravel API do not need a query, and say so in their note. If the Boost MCP server is unreachable, run `docker compose up -d --wait` and retry; never continue from memory (the M4 plan session found it down only because the stack was stopped).
- Everything runs in Docker, except the two browser scripts (plan DD14, approved). Shorthand (add `--user "$(id -u):$(id -g)"` when a command writes files):
  - `APP` = `docker compose run --rm app`
  - `NODE` = `docker compose --profile tools run --rm node`
  - Unit + feature tests: `APP php artisan test --compact` (add a file path or `--filter=`). Concurrency: `APP php artisan test --group=concurrency` against the running stack (`docker compose up -d --wait`). JS: `NODE npm test`. Everything: `bash scripts/verify.sh`.
  - After editing assets, run `NODE npm run build` if the UI does not show the change.
- After editing PHP, run `APP vendor/bin/pint --dirty --format agent`. The project is now a git repository.
- **Commit policy:** one commit per phase, after its checkpoint passes, as described in `.claude/skills/workflow-implement/SKILL.md` ("Phase commits"); stop at each checkpoint for human review and do not start the next phase until the user says so.
- **Screen changes only (FR26).** No file under `app/`, `database/`, `routes/` or `config/` changes. Never rename or remove an element id, a status `state` value or an exported function that M1–M3 code or tests use; M4 only adds. If something looks like it needs a server change, mark the task `[!]` and report it.
- **Approved test changes (plan §11 item 4):** exactly these existing tests change because the approved PRD changes the behaviour they pin: in `resources/js/__tests__/submit-flow.test.js` — the 8-second timeout test (now 3 s), the "lost response is unconfirmed" test (now unconfirmed only after 3 automatic resends), the "unexpected server error is unconfirmed" test for `503` (now resent automatically), and the `doneText` zero-grant assertion at line ~443 (now "None granted — 0 of 3, none left"); and the matching cases in `supervisor-flow.test.js`. Change them with the same test names where possible. No other test is deleted or weakened.
- Time in JS tests: the existing files use `node:test` `mock.timers` for `setTimeout` plus a `flush()` helper; new retry tests inject a fake `sleep` instead and do not rely on real time.
- Wording used by tests and screens (PRD §7): states are Pending, Offline, Retrying automatically (attempt n of 3), Not confirmed, Retrying, Confirmed. Resend waits 1 s, 2 s, 4 s; send timeout 3 s; quick amounts 1, 5, 10 and "All (N)".
- Where a plan file path is named, use it exactly. Files not listed in plan §4 are marked "(added)".

---

## Phase 0 — Baseline

### [x] T001 Confirm the M1–M3 baseline is green and Boost is reachable
- **Refs:** laravel-boost rules 3–4, FR26, AC-US9.1
- **Depends on:** none
- **Files:** none (check only)
- **Do:** Start the stack (`docker compose up -d --wait`). Call Boost `application-info` and note the Laravel, Pest and Vite versions in the task note. Run `bash scripts/verify.sh` once and record the five step results and the numbers it prints (PHP test count, JS pass count, p95 of the 50-request run, live-update result). Also run `git status` and note any uncommitted file that is not M4 work (`requirement.md` was modified before M4 and must stay out of every M4 commit).
- **Verify:** `bash scripts/verify.sh` → `verify: all 5 steps passed`.
- **Done when:** the baseline numbers are in the task note so a later regression is visible.
- **Out of scope:** any code change.
- **Note:** Boost `application-info`: Laravel 13.37.0, PHP 8.4, Pest 4.7.8, Vite 8.3.4, Alpine 3.17.4, Pint 1.32.1. `bash scripts/verify.sh` → all 5 steps passed: smoke OK (remaining 100); 400 PHP tests (1678 assertions); concurrency p95 0.657 s (restock 0.638 s, correction 0.369 s); JS 165 pass; live 10/10 within 2000 ms (median 37 ms, max 96 ms). `git status`: only `requirement.md` modified (pre-M4, stays out of commits) and the untracked M4 slice folder. No Boost docs query (no code touched).

### [x] T002 [P] Record the starting contrast and target-size numbers
- **Refs:** DD11, FR22, FR23, NFR4
- **Depends on:** none
- **Files:** none (the result goes in the task note)
- **Do:** Compute the contrast ratio of every colour pair declared in `resources/css/tokens.css` (text on `--color-bg`, `--color-surface`, `--color-own-bg`, `--color-warning-bg`, `--color-disabled-bg`, white on `--color-accent`, accent-strong on own-bg; border and focus ring on bg) with a throw-away script kept in the scratchpad, not in the repo. List every rule in `resources/css/app.css` that sets a control height or `min-height` below 3 rem (known: `.log__action` at 2 rem). Plan §7 DD11 already records the expected values (lowest text pair 7.17:1; border 6.24:1); confirm or correct them.
- **Verify:** the note contains one ratio per pair and a list of controls under 48 px; the lowest text-pair ratio is stated.
- **Done when:** a human can see what M4 has to fix, and T006's token test can be written against real numbers.
- **Out of scope:** changing any colour or size.
- **Note:** scratch script in the scratchpad. Real pairs: text on bg 19.57, surface 17.62, own-bg 16.64, warning-bg 17.68, disabled-bg 14.53; text-muted on bg 10.60, surface 9.54, own-bg 9.01, warning-bg 9.58, disabled-bg 7.87; accent-strong on bg 10.28, surface 9.26, own-bg 8.75; warning on bg 8.58, surface 7.73, own-bg 7.30, warning-bg 7.76; disabled-text on disabled-bg 7.87; on-accent on accent 7.17; border on bg 6.24; focus on bg 7.17. Lowest text pair 7.17 (matches plan DD11). Not every token/background combination is a real use (e.g. text on accent is 2.73), so T006's manifest lists only pairs the CSS uses. Controls below 48 px: `.log__action` (`min-height: 2rem`, app.css:458); `.indicator`, `.button`, `.input`, `.select`, `.choice__option`, `.holdings__row` use `--control-height` 3 rem (48 px at 16 px root, so they pass now). No Boost query (no code touched).

---

## Phase 1 — Pure modules (blocks everything after)

### [x] T003 [P] Add the retry runner `retry-policy.js` with its tests
- **Refs:** FR21.3, FR21.5, FR21.6, FR21.7, DD1, DD2, DD5, AC-US4.2a, AC-US4.3, AC-US5.2
- **Depends on:** T001
- **Files:** `resources/js/retry-policy.js` (create), `resources/js/__tests__/retry-policy.test.js` (create)
- **Do:** Export `createRetryRunner({ waitsMs = [1000, 2000, 4000], isOnline, sleep, onResending })` returning `{ run(sendOnce), abortWait(), dispose() }`, exactly as plan §6. `sendOnce()` resolves `{kind:'answered', value}` or `{kind:'no-answer'}` and never throws. `run` returns `{outcome:'answered', value}`, `{outcome:'gave-up'}`, `{outcome:'stopped-offline'}` or `{outcome:'disposed'}`. Order: send; then for each of the 3 resends: check online, call `onResending({attempt, of: 3, phase:'waiting'})`, `await sleep(waitsMs[i])` (cut short by `abortWait()`/`dispose()`), re-check online and disposed, `onResending({..., phase:'sending'})`, send. No DOM, no real timers (the default `sleep` is the only `setTimeout`). Follow the style of `resources/js/connection.js` (pure, options object, JSDoc).
- **Verify:** `NODE npm test` → the new file passes. Named tests: `sends once and stops when the first send is answered`; `makes exactly 4 sends in total when nothing is ever answered, then gives up`; `waits 1 s, 2 s, 4 s in that order`; `an answer on the second resend stops the loop`; `stops with stopped-offline when the device goes offline before a resend, sending nothing more`; `abortWait during a wait ends the round as stopped-offline when offline`; `dispose during a wait returns disposed and sends nothing more`; `onResending reports attempt 1 to 3 of 3, waiting then sending`.
- **Done when:** the runner proves AC-US4.2a ("exactly 4 sends, then no further send") with a fake sleep.
- **Out of scope:** wiring it into `api.js` or `supervisor.js` (T007, T008); idempotency keys (the caller owns them).
- **Note:** Pure JS, no Laravel API, so no Boost query. Beyond the task: after a send that gets no answer, the runner checks `disposed` before anything else, so a disposed round never reports gave-up. 8 named tests pass.

### [x] T004 [P] Add the banner model `banner-model.js`, with the new zero-grant wording
- **Refs:** FR20, FR20.1, FR20.2, FR21, FR22.2, DD7, DD8, AC-US3.1, AC-US3.3, AC-US4.5
- **Depends on:** T001
- **Files:** `resources/js/banner-model.js` (create), `resources/js/__tests__/banner-model.test.js` (create), `resources/js/api.js` (edit `doneText` only), `resources/js/__tests__/submit-flow.test.js` (edit the one `doneText` assertion near line 443)
- **Do:** Export `bannerFor(status, { serverNowMs, channel, doneText })` returning `null` or `{ kind, shape, text, note, action }`, per plan §4 table and §6. Kinds: `progress` (states `pending`, `resending`, `retrying`), `success`, `partial`, `none`, `refused` (`error` and the supervisor `refused`), `unconfirmed` (action `'retry'`), `offline`, `error` (unknown failure). Words: "Pending"; "Retrying automatically, attempt {attempt} of {of}"; "Retrying"; "{n} granted"; "{g} of {n} granted"; "None granted — 0 of {n}, none left"; "Refused — {message}"; "Not confirmed" (note carries the offline message when present); "Offline — nothing was sent". Shapes: `ring`, `cycle`, `check`, `triangle`, `cross`, `bar-square`, with `pending`→`ring`, `resending`/`retrying`→`cycle`, `none`/`partial`→`triangle`. `invalid` and `idle` return `null`. Restock and correction statuses (`pending`, `done`, `refused`, `error`, `unconfirmed`, `offline`) go through the same function with `channel`. In `api.js` change only the zero-grant string in `doneText` to `None granted — 0 of ${requested}, none left` and its doc comment, and update the one existing assertion.
- **Verify:** `NODE npm test` → passes. Named tests: `every state has words and a shape`; `no two different states share both the same words and the same shape`; `full, partial and zero grants read 4 granted, 4 of 6 granted and None granted`; `a temporary grant adds the return time`; `Retry is offered only when not confirmed`; `a refusal starts with Refused and carries the server's reason`; `pending, offline, resending, unconfirmed, retrying and done differ by words alone and by shape alone`; `restock and correction use the same kinds`.
- **Done when:** AC-US3.1 wording and AC-US4.5 are proved by a table-driven test.
- **Out of scope:** the Blade markup and CSS (T012, T013); the `dashboard.js` getter (T009).
- **Note:** Pure JS plus a one-string change in api.js; no Boost query. Deviation within the task's intent: the plan and PRD disagree on the shape of 'Retrying' (AC-US4.3 says same as Pending, AC-US4.5 says all six states differ by shape alone, and the task maps resending and retrying both to `cycle`). To satisfy AC-US4.5 `retrying` gets its own shape `dot-ring` (a ring with a filled centre), so T013 draws seven shapes, not six. Words differ too. The `doneText` assertion at submit-flow.test.js:443 now expects the new zero-grant text. The banner model returns only {kind, shape, text, note, action}; T009 maps tone/message for the old Blade until T012.

### [x] T005 [P] Add the amount helpers `amount-controls.js`
- **Refs:** FR19, FR19.1, FR19.2, FR19.3, DD9, DD10, AC-US1.2, AC-US1.3, AC-US1.5, AC-US1.6
- **Depends on:** T001
- **Files:** `resources/js/amount-controls.js` (create), `resources/js/__tests__/amount-controls.test.js` (create)
- **Do:** Export `quickAmounts(remaining, values = [1, 5, 10])` → `[{label, value, usable}]` and `stepAmount(currentText, delta, remaining)` → number or `null`, exactly as plan §6: fixed values are always listed and `usable` is `value <= remaining`; "All (N)" has `value = remaining`, is usable when `remaining >= 1`, and is left out when `remaining` equals a listed value (so `quickAmounts(5)` has 1 and 5 usable, 10 not usable, no "All"); remaining 0 → nothing usable; `stepAmount('3', 1, 100)` → 4; at 1 a decrease stays 1; at `remaining` an increase stays `remaining`; empty or unparsable text steps from 0 (so + gives 1 and − gives 1); remaining 0 → `null`. Reuse `maxAmount` from `resources/js/form-rules.js` for the cap; do not duplicate validation.
- **Verify:** `NODE npm test` → passes. Named tests: `quick amounts above the remaining are listed but not usable`; `All fills exactly the remaining`; `All is left out when it would repeat a fixed amount`; `nothing is usable at 0 left`; `the stepper never goes below 1 or above the remaining`; `typed garbage steps from zero`; `a quick amount sets the amount, it never adds to it`.
- **Done when:** AC-US1.2, AC-US1.3 and AC-US1.6 are proved by unit tests.
- **Out of scope:** markup, Alpine wiring and CSS (T010, T011).
- **Note:** Pure JS, no Boost query. At remaining 0 the row still lists 1, 5, 10 and an unusable 'All (0)'; nothing is usable. Stepper from a value above the new remaining comes down to the remaining.

### [x] T006 [P] Add the contrast helper and the design-token test
- **Refs:** FR22, NFR4, DD11, DD12, AC-US2.1, AC-US7.2
- **Depends on:** T002
- **Files:** `resources/js/__tests__/helpers/contrast.js` (create), `resources/js/__tests__/design-tokens.test.js` (create)
- **Do:** The helper exports `contrastRatio(hexA, hexB)` using the WCAG relative-luminance formula. The test reads `resources/css/tokens.css`, parses the `--color-*` custom properties, and asserts a manifest of pairs: every text token (`--color-text`, `--color-text-muted`, `--color-accent-strong`, `--color-warning`, `--color-disabled-text`, `--color-on-accent`) against every background it is used on (`--color-bg`, `--color-surface`, `--color-own-bg`, `--color-warning-bg`, `--color-disabled-bg`, `--color-accent`) is **≥ 7:1**, and `--color-border` and `--color-focus` against `--color-bg` are **≥ 3:1**. A failure names the pair and the ratio. Keep helpers under `__tests__/helpers/` (the `npm test` glob only matches `*.test.js`, so the helper is not run as a test and is not bundled).
- **Verify:** `NODE npm test` → passes; temporarily change `--color-text-muted` to `#999999` in a scratch copy and confirm the test fails with the pair name (then restore; record that you did it).
- **Done when:** AC-US2.1 is proved for every declared colour and fails loudly when a token regresses.
- **Out of scope:** recolouring anything; a pair only appears in the manifest if the CSS really uses it (the browser audit in T016 catches the rest).
- **Note:** Pure JS test, no Boost query. Negative check done: with --color-text-muted set to #999999 the test failed naming 'text-muted (#999999) on bg (#ffffff) is 2.85:1, needs 7:1' (and four more pairs); tokens.css restored (git diff empty). The manifest lists only pairs the CSS uses (T002 note).

**Checkpoint 1:** `NODE npm test` runs the four new test files (retry-policy, banner-model, amount-controls, design-tokens) and all earlier tests green; nothing on screen has changed yet except the zero-grant wording in the result text.

---

## Phase 2 — Reliable sending (US4, US5, P1)

### [x] T007 [US4] Put the submit flow on the retry runner
- **Refs:** FR21, FR21.1, FR21.2, FR21.3, FR21.5, FR21.6, NFR2, NFR5, DD1–DD6, AC-US4.1–4.3a, AC-US5.1–5.3, AC-US5.5
- **Depends on:** T003, T004
- **Files:** `resources/js/api.js` (edit), `resources/js/__tests__/submit-flow.test.js` (edit)
- **Do:** In `createSubmitFlow`: set `SUBMIT_TIMEOUT_MS` to `3000` (also used by `postJson`); replace `send()`'s single attempt with `createRetryRunner` (injected `sleep` and `isOnline`; default waits 1 s, 2 s, 4 s). A send is "answered" for any 200/201 with a readable body and for any 4xx; it is "no-answer" for a network error, the abort, an unreadable 2xx body and **any 5xx including 503**. The status sequence (plan §6): `pending` → `resending {attempt, of: 3, phase}` → `unconfirmed` (after `gave-up` or `stopped-offline`, with the offline message in the second case). `retry()` starts a fresh round with the same `attempt.key`; its first send reports `retrying`, later automatic resends report `resending`. Add `dispose()` (runner `dispose`, then every later `dispatch` is dropped) and `goOffline()` (runner `abortWait`). The `dispatch({type:'state'...})` contract and the rule that pool numbers move only after 200/201 do not change. Update the four approved tests and add: `a lost answer is resent automatically with the same key up to 3 times`, `after three failed resends the screen says Not confirmed and Retry starts a fresh round with the same key`, `a 4xx, 409 or 422 is never resent`, `503 pool_busy is resent automatically`, `going offline during the wait stops resending and shows Not confirmed`, `dispose drops a late answer and stops resending`, `ten offline toggles and taps on Retry use one key`, `timeout is 3 seconds`, `pool numbers do not change while resending`.
- **Verify:** `NODE npm test` → all pass, including every pre-existing submit-flow test not listed as approved to change.
- **Done when:** AC-US4.2, AC-US4.2a, AC-US4.3, AC-US4.3a, AC-US5.2, AC-US5.3, AC-US5.5 hold at flow level with a fake clock.
- **Out of scope:** the supervisor flow (T008); screen words (T009); changing the server's behaviour for `Retry-After`.
- **Note:** Pure JS, no Boost query. Approved test changes made with their names kept where possible: lost response (now unconfirmed only after 4 sends), 8 s timeout (now 3 s, four timeouts), unexpected 5xx (now after the resends), zero-grant `doneText` (T004). The 503 case also left the "a refusal from the server is a definitive error" list because 503 is now resent (it has its own new test). Test helper change only: `harness()` injects a fake `sleep` and records `waits`; `loseSends`/`settleSends` answer the four sends so the tests that only needed "unconfirmed" (unreadable 2xx, new submit refused, Retry offline, retry keeps the type) reach it by the new route. No assertion weakened. All 9 new named tests added. `createSubmitFlow` also takes optional `sleep` and `waitsMs` (tests only).

### [x] T008 [US7] Put the supervisor flow on the retry runner
- **Refs:** FR21.7, DD1, DD3, DD6, AC-US3.4, AC-US4.2a
- **Depends on:** T003, T007
- **Files:** `resources/js/supervisor.js` (edit), `resources/js/__tests__/supervisor-flow.test.js` (edit)
- **Do:** In `createSupervisorFlow` replace the single attempt in `send(channel)` with the runner, per channel, using the same classification as T007 (`503` and other 5xx are "no answer"; 2xx and 4xx are answers, so `insufficient_free_units`, `validation_failed` and 409 are never resent). Same statuses, same key on every resend, and a manual `retry(channel)` starts a fresh round. Add `dispose()` and `goOffline()` for both channels. The existing `refused`, `error`, `done` statuses and the `refusalText` wording do not change. Update the matching existing cases (lost answer; unexpected server failure) and add: `a lost restock answer is resent automatically with the same key and then settles once`, `a correction gives up after three resends and offers Retry`, `a refused correction is never resent`, `the two channels resend independently`.
- **Verify:** `NODE npm test` → all pass.
- **Done when:** restock and correction follow the same automatic-then-manual rules as an operator request (FR21.7).
- **Out of scope:** the supervisor markup (T012) and the dashboard glue (T009).
- **Note:** Pure JS, no Boost query. Same classification as T007; one runner per channel. Existing cases changed as approved: lost answer, unexpected server failure, and the "other refusals" list lost its 503 entry (now resent, own test "503 pool_busy is resent automatically"). A 2xx with an unreadable body now counts as no answer (it used to crash `confirmed`). 4 new named tests plus 503, offline-stop and dispose tests.

### [x] T009 [US4] Show the six states on the dashboard and stop stale flows
- **Refs:** FR20.1, FR21, FR21.1, FR21.2, FR21.4, DD6, DD7, DD13, AC-US4.1, AC-US4.4, AC-US5.4
- **Depends on:** T007, T008
- **Files:** `resources/js/dashboard.js` (edit), `resources/js/supervisor.js` (edit the `supervisorPart` getters only)
- **Do:** Make the `banner` getter (and the restock and correction banners in `supervisorPart`) return `bannerFor(...)` from `banner-model.js`. Pending and automatic-resend states now show in the banner as well as in the log row (label "Pending", "Retrying…", "Not confirmed"). `submitDisabled` stays true while `pending`, `resending`, `retrying` or `unconfirmed`. In `newSubmitFlow()` and `newSupervisorFlow()` call `dispose()` on the flow being replaced. In `startLive()`'s `offline` listener also call `submitFlow?.goOffline()` and the supervisor flow's `goOffline()`. When a banner appears or changes, call `scrollIntoView({ block: 'nearest' })` on `#result-banner` (guard for a missing element). Do not change the Blade markup in this task: the existing three banner blocks bind to the same getters, so the screen already shows the new words (shapes and layout come in T012).
- **Verify:** `NODE npm run build`, then in a browser at http://localhost:8080 as Ana: in DevTools set Network to Offline and submit 3 → "Offline — nothing was sent", nothing sent. Set Network to "Block request URL" for `/api/pools/1/allocations` while online, submit 3 → "Pending", then "Retrying automatically, attempt 1 of 3", 2, 3, then "Not confirmed" with Retry; unblock, press Retry → "3 granted" and exactly one new row in the log.
- **Done when:** the operator can watch the hybrid retry on the real page (checkpoint 2).
- **Out of scope:** markup, shapes and CSS; quick amounts; any change to `connection.js`.
- **Note:** Pure JS glue, no Boost query; Blade untouched. `banner-model.js` gained `withTone()` (adds `tone`/`message` for the old markup; removed in T012). Finding: the stack serves assets baked into its images, so UI changes show only after `docker compose up -d --build --wait` (`NODE npm run build` alone is not enough; it must run without `--user` because `public/build/assets` is root-owned). Browser check at 360 px as Ana (headless Chromium via a scratch playwright-core install; needs libasound, fetched into the scratchpad): offline shows "You are offline — nothing was sent" with submit disabled; with `/api/pools/1/allocations` aborted the banner read Retrying automatically 1 of 3 at 57 ms, 2 of 3 at 1.04 s, 3 of 3 at 3.04 s, Not confirmed at 7.02 s, 4 requests, Retry shown, remaining unchanged (97); unblocked and Retry gave "3 granted", remaining 94. Viewer switch during retries is covered by the dispose unit tests, not by a browser run.

**Checkpoint 2:** `NODE npm test` green; on the running stack, blocking `/api/pools/1/allocations` shows Pending → Retrying automatically (1, 2, 3 of 3) → Not confirmed with Retry (about 19 s worst case with a hanging network, about 7 s when the request fails at once); unblock and press Retry → "N granted" with one log entry; switching user in the picker during the retries leaves the new user's screen untouched.

---

## Phase 3 — One-thumb request (US1, P1)

### [x] T010 [US1] Add the quick-amount row and the stepper to the request form
- **Refs:** FR19, FR19.1, FR19.2, FR19.3, FR19.4, DD9, DD10, AC-US1.1–1.7
- **Depends on:** T005, T009
- **Files:** `resources/views/dashboard.blade.php` (edit), `resources/js/dashboard.js` (edit)
- **Do:** In the request form, around the existing amount input, add: a `−` button and a `+` button (visible text names "Decrease" and "Increase" in `aria-label`, symbols as content) calling `step(-1)`/`step(1)`; and a row `id="quick-amounts"` of buttons built with `x-for` over a `quickAmountList` getter (`quickAmounts(remaining)`), each `type="button"`, `x-bind:disabled="!item.usable"`, label from `item.label`, click → `pickAmount(item.value)`. `pickAmount` and `step` set `this.amount` (as text) and clear an old result with the existing `amountChanged()`; they never call `submit()`. The stepper buttons are disabled when `stepAmount` would not change the value or `maxAmount === 0`. Keep `id="requested"`, `id="request-submit"`, the "N left" hint and the validation messages unchanged. Run Boost `search-docs` for `blade anonymous components props` only if you add a component (not needed here); note that none was needed.
- **Verify:** `NODE npm run build`, then in a browser as Ana: tap 5 → field shows 5; tap + twice → 7; tap − ten times → 1; "All (N)" fills N; type 2.5 → "Enter a whole number" and submit disabled; with remaining 0 (use Ben to take the rest) all controls are disabled and the form says "0 left"; Ben takes units while Ana's field shows 10 → within 2 s quick amounts above the new remaining are disabled and the field flags "Only N left" without changing her number.
- **Done when:** AC-US1.1–1.6 behave as written (the unit part is in T005).
- **Out of scope:** touch-target sizing and look (T011, T013); one-tap submit (rejected in DD10).
- **Note:** Boost `search-docs` queries: "blade anonymous components props", "blade components accessibility", "blade attributes merge class" (no component added here; the docs confirm plain markup needs nothing). Added `quickAmountList`, `amountValue`, `canDecrease`, `canIncrease`, `pickAmount`, `step` to dashboard.js; markup ids `amount-decrease`, `amount-increase`, `quick-amounts`. Browser check at 360 px as Ana (headless Chromium): tap 5 → 5; + + → 7; − ×10 → 1 with − disabled; All (94) → 94; "2.5" → "Enter a whole number" and submit disabled; with 10 typed and Ben taking units live, remaining 5 appeared within the 2.5 s wait, hint "5 left", message "Only 5 left", field still 10, quick amounts 1 and 5 usable and 10 disabled; at 0 the hint says "0 left", the submit label reads "0 left" and every control is disabled. The quick-amount row sits directly above the offline message and submit, after the Kind choice.

### [x] T011 [US1] Style the quick amounts and stepper for a thumb
- **Refs:** FR19, FR19.1, FR23, DD11, AC-US1.8
- **Depends on:** T010
- **Files:** `resources/css/tokens.css` (edit), `resources/css/app.css` (edit)
- **Do:** In `tokens.css` add `--touch-min: 48px` and set `--control-height: max(var(--touch-min), 3rem)`. In `app.css` style `#quick-amounts` as a wrapping row with `gap: var(--space-2)` (8 px or more), buttons at least `var(--touch-min)` square with `min-width` for "All (100)"; stepper buttons square at `--touch-min`; an unusable control keeps its text and gets the existing disabled look plus a strike-through or dashed border so it is not shown by colour alone; add an `:active` pressed style (inset border change) so a tap is acknowledged at once. Quick amounts and the stepper sit in one thumb-reachable block directly above the submit button. No fixed widths wider than 360 px.
- **Verify:** `NODE npm run build`; at 360 × 640 in the browser, the amount controls and submit button are visible without scrolling past the log, and in DevTools each quick-amount and stepper button measures ≥ 48 × 48 px with ≥ 8 px between neighbours.
- **Done when:** AC-US1.8 holds for the amount controls (the whole-page check is T016).
- **Out of scope:** recolouring, banners, supervisor controls.
- **Note:** Pure CSS, no Boost query. `--touch-min: 48px`, `--control-height: max(var(--touch-min), 3rem)`; pressed `:active` style; unusable controls get a dashed border and strike-through. Measured at 360 × 640: stepper buttons 48 × 48, quick amounts 51 × 51 (All (94) 127 × 51), submit 48 high; neighbour gaps are 8–12 px (flex gap `--space-2`/`--space-3`); controls and submit end at y = 460 of 640 and the submit is above the log; `scrollWidth` 360 = `innerWidth`. Checkpoint 3: after a reseed, tap 5 then Request sent one POST and showed "5 granted" (remaining 100 → 95). The demo data was reseeded (`migrate:fresh --seed --force`) after these checks.

**Checkpoint 3:** at 360 px, tap "5" then submit (two taps, no keyboard) → request sent once; "All (N)" fills the remaining; at 0 left everything is unusable and says "0 left"; the buttons measure ≥ 48 px.

---

## Phase 4 — Sunlight, banners and layout (US2, US3, US6, US7)

### [x] T012 [US3] Build the `x-result-banner` component and use it three times
- **Refs:** FR20, FR20.1, FR20.2, FR22.2, DD7, DD8, AC-US3.1–3.4
- **Depends on:** T004, T009
- **Files:** `resources/views/components/result-banner.blade.php` (create), `resources/views/dashboard.blade.php` (edit)
- **Do:** First run Boost `search-docs` queries `blade anonymous components props` and `blade components accessibility`; name them in the note. Create an anonymous component with `@props(['id', 'bind', 'retry'])` that renders `<div id="{{ $id }}-banner" role="status">` (keep today's ids: `result-banner` for the request, `restock-result` and `correction-result` for the supervisor forms — read the current markup first and keep every existing id: `result-text`, `retry`, `restock-result-text`, `restock-retry`, `correction-result-text`, `correction-retry`), a `<span class="banner__shape banner__shape--…" aria-hidden="true">` bound to `bind.shape`, the words from `bind.text`, the optional note, and a Retry `<button>` shown only when `bind.action === 'retry'`. Use Alpine bindings (`x-show`, `x-bind:class`, `x-text`) as the current markup does. Replace the three copy-pasted banner blocks in `dashboard.blade.php` with the component. No text may live only in a CSS pseudo-element.
- **Verify:** `NODE npm run build`; `APP php artisan view:clear`; then in the browser each of the three banners still appears with its words (as Ana: grant, partial, none; as Dana: restock +50, a refused raise), and Retry still works in each. `APP php artisan test --compact tests/Feature/DashboardViewTest.php` → passes.
- **Done when:** the three banners are one component and every state from the banner model shows words and a shape slot (the shapes are drawn in T013).
- **Out of scope:** the CSS for the shapes and sizes (T013).
- **Note:** Boost `search-docs` queries: "blade anonymous components props", "blade components accessibility", "blade attributes merge class" (anonymous component in resources/views/components, `@props`, plain `{{ }}` for the prop values). Small deviation within the task: besides `id`, `bind`, `retry` the component takes `text-id` and `retry-id`, because the three banners keep three different ids for the words and the Retry button (`result-text`/`retry`, `restock-result-text`/`restock-retry`, `correction-result-text`/`correction-retry`). The temporary `withTone()` aliases were removed; the three getters return `bannerFor()` directly and `app.css` colours by `banner--{kind}`. `DashboardViewTest` passes. Browser at 360 px: Ana "4 granted", "96 granted", stale request "None granted — 0 of 3, none left" (class `banner--none`); Dana restock "Added 50 units. Remaining: 50", restock answer lost → "Not confirmed" + Retry → "Added 7 units. Remaining: 57". The correction banner uses the same component (refusal wording is covered by the supervisor-flow tests).

### [x] T013 [US2] Apply the sunlight standard: key-number sizes, shapes, 48 px controls
- **Refs:** FR20, FR22, FR22.1, FR22.2, FR22.3, FR23, DD8, DD11, AC-US1.8, AC-US2.1–2.4, AC-US3.1, AC-US7.1
- **Depends on:** T006, T011, T012
- **Files:** `resources/css/tokens.css` (edit), `resources/css/app.css` (edit)
- **Do:** Add `--font-size-key-text: 1.5rem` and use it for `.status`, the banner text, `.holdings__row` (units and time left) and the audit's key values, so every key number on a panel is ≥ 24 px and the remaining number stays the largest. Draw the banner shapes in CSS for `ring`, `cycle`, `check`, `triangle`, `cross`, `bar-square` (borders, `clip-path`, plain symbols as `content` with a fixed size of 1.5 rem), each distinct in outline. Make the banner span the full width of the request panel at 360 px and 1280 px, with the Retry button full width under the words on narrow screens. Raise `.log__action` (the Correct button) and any other control below 48 px to `min-height: var(--control-height)` with at least 8 px between neighbours (use the list from T002). Keep `:focus-visible` outlines. If T006's token test or the browser audit shows a failing pair, fix the token, not the test.
- **Verify:** `NODE npm test` → token test passes; `NODE npm run build`; in the browser (operator and supervisor): every tappable control ≥ 48 × 48 px; the banner spans the panel width at 360 px; in DevTools "Emulate vision deficiency: achromatopsia" the six states (Pending, Retrying automatically, Not confirmed, Retrying, Offline, Confirmed) can still be told apart by words and by shape.
- **Done when:** AC-US2.2 (greyscale), AC-US2.3, AC-US3.1 (full width) and AC-US7.1 hold by hand; the automated measurement is T016.
- **Out of scope:** a theme switch or dark mode (PQ4); changing the layout breakpoint.
- **Note:** Pure CSS, no Boost query (the Vite build was run; the earlier `vite build assets blade` query of the plan applies). `--font-size-key-text: 1.5rem` on `.status`, banner text, `.holdings__row` and `.audit__text`; `.remaining__number` keeps the largest size (min 3.75 rem). Seven shapes drawn in CSS (the plan said six; `dot-ring` was added in T004): ring, dot-ring, cycle (↻), check (✓), triangle (!), cross (✕), bar-square. `.log__action` now `min-height: var(--control-height)`. Banner is a full-width flex row; Retry takes the full width under the words below 600 px. Greyscale (CSS `filter: grayscale(1)`, same effect as achromatopsia emulation): Pending ring, Retrying automatically ↻, Retrying ring with dot, Not confirmed triangle, Offline square with bar, Confirmed tick are all told apart by words and shape. Preliminary measurement at 360 px for Ana and for Dana (both tabs): no visible button, select, text input or choice label under 48 × 48; the automated measurement is T016. Token test passes (no token changed).

### [x] T014 [P] [US6] Fix the 360 px layout of both screens
- **Refs:** FR24, FR24.1, NFR3, AC-US6.1, AC-US6.2, AC-US6.3
- **Depends on:** T013
- **Files:** `resources/css/app.css` (edit)
- **Do:** At a 360 × 640 viewport go through the operator screen (picker, indicator, remaining, status, request controls, banner, holdings, log, "Show older") and the supervisor screen (tabs, restock, correction, audit); fix every horizontal overflow (long names, five-digit amounts, wide inputs, audit rows) with wrapping or `min-width: 0`, not with fixed widths. Keep the DOM order: pool panel, request panel, then the log, so the submit button is above the log. Check 1280 × 800 still uses the two-column layout.
- **Verify:** in the browser console at 360 px and at 1280 px as Ana and as Dana, on both tabs: `document.documentElement.scrollWidth <= window.innerWidth` → `true`; and `document.querySelector('#request-submit').getBoundingClientRect().bottom < document.querySelector('#log').getBoundingClientRect().top` → `true` at 360 × 640.
- **Done when:** AC-US6.1–6.3 hold by hand.
- **Out of scope:** new features; the automated overflow check (T016).
- **Note:** Pure CSS, no Boost query. No change was needed: at 360 × 640 and 1280 × 800, as Ana and as Dana (pool and supervisor tabs), `scrollWidth` equals `innerWidth`, no element extends past the viewport (including a stress run with remaining 99999, a 59-character operator name and an unbroken 63-character message), and at 360 × 640 the submit button is above the log. At 1280 the log is in the right column, as designed.

### [x] T015 [US1] Add the page markup feature test
- **Refs:** FR19, FR20.2, FR22.3, FR23, AC-US1.1, AC-US3.3, AC-US7.1
- **Depends on:** T010, T012
- **Files:** `tests/Feature/FieldReadyMarkupTest.php` (create)
- **Do:** Run Boost `search-docs` query `pest feature test view assertions`; name it in the note. Create it with `php artisan make:test --pest FieldReadyMarkupTest` (feature test). Seed with `DatabaseSeeder` like `tests/Feature/DashboardViewTest.php`, request `/`, and assert the HTML contains: `id="quick-amounts"`; the stepper buttons with `aria-label` "Decrease" and "Increase"; three elements with `role="status"` and the ids `result-banner`, `restock-result`, `correction-result`; the Retry button ids `retry`, `restock-retry`, `correction-retry`; the Correct button carrying an `aria-label` that starts with "Correct entry". Do not assert styles.
- **Verify:** `APP php artisan test --compact tests/Feature/FieldReadyMarkupTest.php` → pass; `APP vendor/bin/pint --dirty --format agent` → clean.
- **Done when:** the markup hooks the audit and the older checks rely on cannot be removed unnoticed.
- **Out of scope:** testing JS behaviour in PHP.
- **Note:** Boost `search-docs` query: "pest feature test view assertions" (also "assertSee assertSeeHtml response content"). Created with `php artisan make:test --pest FieldReadyMarkupTest`; 5 tests, all pass with `DashboardViewTest` (16 tests); Pint passed. It asserts markup strings (ids, role, aria-label, three aria-hidden shapes), no styles.

**Checkpoint 4:** the operator and supervisor screens at 360 px and 1280 px look final; each banner shows words and a shape; greyscale emulation still reads; `NODE npm test` and the touched feature tests green.

---

## Phase 5 — Browser audits (approved dependency: `playwright-core`, dev only)

### [x] T016 [US2] Add the browser audit `tests/Live/ui-audit.mjs`
- **Refs:** FR22, FR23, FR24, FR24.1, NFR3, NFR4, DD12, DD14, AC-US1.8, AC-US2.1, AC-US6.1–6.3, AC-US7.1, AC-US7.2
- **Depends on:** T013, T014
- **Files:** `package.json` (edit), `package-lock.json` (edit), `tests/Live/ui-audit.mjs` (create)
- **Do:** Install the approved dev dependency with `NODE` using the host user (`docker compose --profile tools run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp node npm install --save-dev playwright-core`); pick a version compatible with the Chromium in `~/.cache/ms-playwright/chromium-1243/` (run the script against it with `executablePath`; if the API refuses it, record the version tried in the task note). The script (run on the host: `node tests/Live/ui-audit.mjs`; env `STACK_URL` default `http://localhost:8080`, `CHROMIUM_PATH`) opens the page at 360 × 640 and 1280 × 800 as an operator (Ana) and as a supervisor (Dana, both tabs), forces each banner state it can (request granted, partial, none, offline, not confirmed; restock accepted; correction refused), and checks: every visible tappable element ≥ 48 × 48 CSS px with ≥ 8 px to its nearest tappable neighbour; every visible text element's computed foreground against its effective background (walk up the ancestors; skip elements over images or gradients and say so) ≥ 7:1; key numbers (`#remaining`, `.status`, banner text, holdings rows) ≥ 24 px at 360 px; `scrollWidth <= innerWidth`; the submit button above the log. Print one line per check and exit 1 with the list of offenders. Follow the style of `tests/Live/live-latency.mjs` (header comment with how to run, exit codes). Add an `npm` script only if it needs no other change to `package.json`.
- **Verify:** `node tests/Live/ui-audit.mjs` → every check prints PASS and the exit code is 0; break one thing on purpose (set `.log__action { min-height: 2rem }` in a scratch build) and confirm the script fails naming that control, then restore.
- **Done when:** the numbers behind AC-US1.8, AC-US2.1, AC-US6.1–6.3, AC-US7.1 and AC-US7.2 are measured, not judged by eye.
- **Out of scope:** greyscale judgement (manual), other dependencies, running the script inside Docker.
- **Note:** No Boost query (plain Node script and a dev dependency; no Laravel API). Installed `playwright-core` ^1.64.1 (dev only, approved) with `docker compose --profile tools run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp node npm install --save-dev playwright-core`; it drives the Chromium in `~/.cache/ms-playwright/chromium-1243/` with no API problem. The Chromium needs libasound2, which was missing on this machine; it was unpacked (without root) into the scratchpad and used through `LD_LIBRARY_PATH` (the script header says Chromium needs its system libraries). No `npm` script added. Result: `node tests/Live/ui-audit.mjs` → six PASS lines, exit 0: 449 tap targets (smallest 48.0 px, all gaps ≥ 8 px), 2748 text elements (lowest 7.17:1, 0 skipped over images or gradients), 92 key-number measurements ≥ 24 px at 360 px, 36 overflow checks, 11 submit-above-log checks. Negative check: with `.log__action { min-height: 2rem }` the script failed with 21 offenders naming "Correct entry #15 … is 102.8 x 32.0" and exit 1; app.css restored (git diff empty) and the stack rebuilt. Banner states other than a real grant are shown by setting the component status; the script says so in its header.

### [x] T017 [P] [US5] Add the offline and duplicate browser flow `tests/Live/offline-flow.mjs`
- **Refs:** FR21.3, FR21.5, FR21.6, NFR5, DD2, DD5, AC-US4.2, AC-US4.2a, AC-US4.4, AC-US5.1–5.5
- **Depends on:** T009, T016
- **Files:** `tests/Live/offline-flow.mjs` (create)
- **Do:** With `playwright-core` (from T016) drive the real page as Ana against the running stack, counting her allocations through `GET /api/pools/1` (or `/log`) before and after each case: (1) context offline → submit → "Offline — nothing was sent", zero requests, none created after going online; (2) abort the response after the server committed (route fulfil-then-abort or `route.abort()` after `route.fetch()`) → "Retrying automatically, attempt 1 of 3", then the original "N granted" and exactly one new allocation; (3) block the endpoint for all four sends → exactly 4 requests with the same `Idempotency-Key`, then "Not confirmed" and a Retry button, no further request; unblock, press Retry → "N granted", one allocation; (4) ten offline/online toggles during one pending submit plus up to three Retry taps → at most one allocation; (5) a 422/409 reply is not resent (one request). Print PASS/FAIL per case; exit 1 on any failure. It consumes a few units: restore the data afterwards the way `scripts/verify.sh` does (`migrate:fresh --seed`) or document that it must be run on a fresh seed.
- **Verify:** `node tests/Live/offline-flow.mjs` → all five cases PASS, exit 0.
- **Done when:** AC-US4.2a, AC-US4.4 and AC-US5.1–5.5 are proved against the real page and server.
- **Out of scope:** the wording and the shapes (T004, T016).
- **Note:** No Boost query. `node tests/Live/offline-flow.mjs` → 5 of 5 cases PASS, exit 0: (1) offline: 0 requests, 0 allocations after going online; (2) response aborted after `route.fetch()` committed it: "Retrying automatically, attempt 1 of 3" then "3 granted", 2 sends with 1 key, +1 allocation; (3) all sends aborted: exactly 4 sends with 1 key, "Not confirmed" + Retry, still 4 sends after 2.5 s, Retry → "3 granted", +1 allocation; (4) 10 offline/online toggles during one submit and Retry taps: 1 key, ≤ 1 allocation; (5) 422 and 409: one request each, "Refused — …". Allocations are counted from `GET /api/pools/1/log?limit=50` (50 is the largest page the API accepts). It consumed about 4 units; the demo data is reseeded at the end of the run.

### [x] T018 Fix what the audits found and re-run them
- **Refs:** FR22, FR23, FR24, NFR4, NFR5, AC-US1.8, AC-US2.1, AC-US5.3, AC-US7.1
- **Depends on:** T016, T017
- **Files:** `resources/css/app.css` (edit), `resources/css/tokens.css` (edit), and whichever of `resources/js/*.js` or `resources/views/dashboard.blade.php` an audit failure points to (list them in the note)
- **Do:** Take each failure from T016 and T017, fix the cause (never the check), rebuild, and re-run both scripts and `NODE npm test`. If nothing failed, say so in the note and make no change.
- **Verify:** `node tests/Live/ui-audit.mjs` and `node tests/Live/offline-flow.mjs` → exit 0; `NODE npm test` → pass.
- **Done when:** both scripts are green with the measured numbers recorded in the note (lowest contrast ratio found, smallest target found).
- **Out of scope:** new features or design changes the audits did not ask for.
- **Note:** No audit failed on the first run of either script, so no cause was fixed and no file changed. Measured numbers: lowest contrast 7.17:1 (white on accent), smallest target 48.0 px. Both scripts and `npm test` (210 pass) re-run green after the negative check was reverted.

**Checkpoint 5:** `node tests/Live/ui-audit.mjs` and `node tests/Live/offline-flow.mjs` print PASS with their measured numbers; `NODE npm test` green.

---

## Phase 6 — Final README and regression (US8, US9)

### [ ] T019 [US8] README: decisions, trade-offs and accepted risks in plain words
- **Refs:** FR25, AC-US8.1
- **Depends on:** T018
- **Files:** `README.md` (edit)
- **Do:** Rewrite "Assumptions and accepted trade-offs" (and trim the M1/M2/M3 sections it now repeats) into one section "Decisions and trade-offs": D1–D12 each in one or two plain sentences with the reason, then the accepted risks (derived remaining, no persistence across reloads, public websocket channel, host-run browser audits, up to ~19 s before the manual Retry, `effective_at` versus commit order). Keep the "Run it", "Test it" and "Versions" sections accurate (mention the new test files and the two audit scripts, including that `playwright-core` is a dev dependency and that they run on the host). Do not state any result that has not been produced yet.
- **Verify:** every D1–D12 id appears once with a reason: `grep -c "^- \*\*D[0-9]" README.md` (or the format you use) returns 12; every command in the edited text has been run or is copied from a script.
- **Done when:** AC-US8.1 (decisions, trade-offs, accepted risks) holds; the check results are added in T021.
- **Out of scope:** the walkthrough (T020), results (T021).

### [ ] T020 [US8] README: the demo walkthrough script
- **Refs:** FR25, AC-US8.2
- **Depends on:** T019
- **Files:** `README.md` (edit)
- **Do:** Add a numbered section "Demo walkthrough (10 minutes)". Start with the reset (`docker compose down -v`, `docker compose up -d --wait`, `docker compose run --rm app php artisan migrate:fresh --seed --force`), then steps with an expected result each: two browsers in sync (Ana and Ben, 4 units, both screens within 2 s); a partial grant (Ben requests more than is left → "X of N granted"); one-thumb request at 360 px with a quick amount; a Temporary request and its expiry using `scripts/demo-expiry.sh` (read the script first and copy its real command and wait times); a supervisor restock and a correction (lower, then a refused raise, then restock and raise); lost answer and automatic resend (DevTools "Block request URL"), then manual Retry; airplane mode. Use only the seeded users and the commands that exist.
- **Verify:** every command in the section exists (`ls scripts/`; `docker compose config` accepts the compose files named); the section has at most 10 minutes of waiting by the script's own timings.
- **Done when:** a person can follow it from a clean checkout (proved in T022).
- **Out of scope:** adding new scripts or seed data.

### [ ] T021 [US8] README: manual checks and the results section for M4
- **Refs:** FR25, NFR2, NFR3, NFR4, AC-US2.2, AC-US2.4, AC-US4.6, AC-US8.1
- **Depends on:** T020
- **Files:** `README.md` (edit)
- **Do:** Update the "Manual checks" table: add checks for the hybrid retry (Pending → Retrying automatically → Not confirmed → Retry), greyscale emulation (achromatopsia), a Tab walk (visible focus, text names), and 48 px targets; update check 5 ("Lost answer, safe retry") and check 4 (airplane mode) to the new behaviour. Add a "Results of the M4 run" section with the date and method, filled from T022–T023 (leave a clearly marked placeholder until then and replace it there; do not invent numbers).
- **Verify:** the table rows each have a "How" and an "Expected" cell; no placeholder text remains after T023.
- **Done when:** the README lists every manual check M4 depends on (AC-US2.2, 2.4, 4.6).
- **Out of scope:** the results themselves (T022, T023).

### [ ] T022 [US8] Follow the demo script on a clean checkout and fix it
- **Refs:** AC-US8.2, AC-US2.2, AC-US2.4, AC-US4.6
- **Depends on:** T021
- **Files:** `README.md` (edit)
- **Do:** From `docker compose down -v`, follow the README walkthrough step by step exactly as written, in a real browser (two windows, then a 360 px window). Also do the manual checks: greyscale emulation across the six states, a Tab walk through the operator and supervisor screens, and the 100 ms added-latency check (DevTools throttling: the Pending state appears within 100 ms and the result in about 1 s). Fix any step that is wrong or unclear in the README. Record the findings (time taken, what failed, what was fixed) in the task note and fill the results rows prepared in T021.
- **Verify:** the whole script is followed to the end with every expected result seen, in at most 10 minutes of active time.
- **Done when:** AC-US8.2, AC-US2.2, AC-US2.4 and AC-US4.6 have recorded results.
- **Out of scope:** new features found while demoing (report them instead).

### [ ] T023 [US9] Run the full regression and record the numbers
- **Refs:** FR26, NFR1, NFR2, NFR6, AC-US9.1, AC-US9.2, AC-US4.6
- **Depends on:** T022
- **Files:** `README.md` (edit the results section only)
- **Do:** Run `bash scripts/verify.sh` (this erases and reseeds the demo data) and `node tests/Live/ui-audit.mjs` and `node tests/Live/offline-flow.mjs` once more. Copy the real output summary into the README results: tests passed, JS tests, p95 of the 50 parallel requests (must stay ≤ 1 s), the 50-request result (exactly 100 granted, one partial grant of 1), the live-update step (within 2 s), the lowest contrast ratio and smallest touch target measured. Confirm `git diff --stat` shows nothing under `app/`, `database/`, `routes/` or `config/`. Run Boost `search-docs` query `vite build assets blade` before the final `NODE npm run build` and name it in the note.
- **Verify:** `bash scripts/verify.sh` → `verify: all 5 steps passed`; the git diff of M4 contains no server file.
- **Done when:** AC-US9.1 and AC-US9.2 are shown to pass with recorded numbers, and FR26 is shown by the diff.
- **Out of scope:** fixing non-M4 issues.

### [ ] T024 Re-run the rules check, tidy up and close the plan notes
- **Refs:** plan §2 and §11 "Rules re-check", laravel-boost rules 1–6
- **Depends on:** T023
- **Files:** `workflow/slices/m4-field-ready-ux/plan.md` (edit §11 "Rules re-check" and §12 only), `workflow/slices/m4-field-ready-ux/tasks.md` (edit status markers)
- **Do:** Re-read `workflow/rules/laravel-boost.md` and `CLAUDE.md`. Fill plan §11 "Rules re-check" with one line per rule and the Boost calls actually made (`application-info`; the `search-docs` queries from the tasks that ran them). Run `APP vendor/bin/pint --dirty --format agent` once more. Add a dated line to the plan's change log for any deviation found while implementing (or "none"). Confirm no `.env*` file, `node_modules/`, or build output is staged.
- **Verify:** the Rules re-check section no longer says "Not yet run"; `git status` shows no unintended file.
- **Done when:** the plan's rules table is current and the slice is ready for review.
- **Out of scope:** marking the tasks file or PRD Done (the user decides).

**Checkpoint 6:** the README is final (decisions, trade-offs, demo walkthrough, checks with M4 results); `bash scripts/verify.sh` passes; both audit scripts pass; every PRD acceptance criterion has a recorded result.

---

## Coverage
| PRD ID | Task(s) |
|---|---|
| FR19 | T005, T010, T011, T015 |
| FR19.1 | T005, T010, T011 |
| FR19.2 | T005, T010 |
| FR19.3 | T005, T010 |
| FR19.4 | T010 |
| FR20 | T004, T009, T012, T013 |
| FR20.1 | T004, T009, T012 |
| FR20.2 | T004, T008, T012, T015 |
| FR21 | T004, T007, T009 |
| FR21.1 | T007, T009 |
| FR21.2 | T007, T009 |
| FR21.3 | T003, T007, T017 |
| FR21.4 | T009 |
| FR21.5 | T003, T007, T017 |
| FR21.6 | T003, T007, T017 |
| FR21.7 | T003, T008 |
| FR22 | T006, T013, T016 |
| FR22.1 | T013, T016 |
| FR22.2 | T004, T012, T013 |
| FR22.3 | T013, T015, T022 |
| FR23 | T011, T013, T015, T016 |
| FR24 | T014, T016 |
| FR24.1 | T014, T016 |
| FR25 | T019, T020, T021, T022 |
| FR26 | T001, T023 |
| NFR1 | T023 (regression only) |
| NFR2 | T007, T022, T023 |
| NFR3 | T014, T016 |
| NFR4 | T006, T013, T016, T022 |
| NFR5 | T007, T017 |
| NFR6 | T023 |
| AC-US1.1 | T010, T015 |
| AC-US1.2 | T005, T010 |
| AC-US1.3 | T005, T010 |
| AC-US1.4 | T010 (existing validation kept) |
| AC-US1.5 | T005, T010 |
| AC-US1.6 | T005, T010 |
| AC-US1.7 | T010 (server `min()` rule unchanged; checked in T023 regression) |
| AC-US1.8 | T011, T013, T016 |
| AC-US2.1 | T006, T013, T016 |
| AC-US2.2 | T013, T022 |
| AC-US2.3 | T013, T016 |
| AC-US2.4 | T013, T022 |
| AC-US3.1 | T004, T012, T013, T016 |
| AC-US3.2 | T009, T012 |
| AC-US3.3 | T004, T012, T015 |
| AC-US3.4 | T008, T012 |
| AC-US4.1 | T007, T009 |
| AC-US4.2 | T007, T017 |
| AC-US4.2a | T003, T007, T008, T017 |
| AC-US4.3 | T003, T007, T017 |
| AC-US4.3a | T007, T017 |
| AC-US4.4 | T009, T017 |
| AC-US4.5 | T004, T013 |
| AC-US4.6 | T022 |
| AC-US5.1 | T007, T017 |
| AC-US5.2 | T003, T007, T017 |
| AC-US5.3 | T007, T017 |
| AC-US5.4 | T009, T017 |
| AC-US5.5 | T007, T017 |
| AC-US6.1 | T014, T016 |
| AC-US6.2 | T011, T014, T016 |
| AC-US6.3 | T014, T016 |
| AC-US7.1 | T013, T015, T016 |
| AC-US7.2 | T006, T016 |
| AC-US8.1 | T019, T021 |
| AC-US8.2 | T020, T022 |
| AC-US9.1 | T001, T023 |
| AC-US9.2 | T023 |

Plan decisions: DD1 → T003, T007, T008 · DD2 → T003, T007, T017 · DD3 → T007, T008 · DD4 → T007 · DD5 → T003, T007, T017 · DD6 → T007, T009 · DD7 → T004, T012 · DD8 → T004, T012, T013 · DD9 → T005, T010 · DD10 → T005, T010 · DD11 → T002, T006, T011, T013 · DD12 → T006, T016 · DD13 → T009 · DD14 → T016, T017.

## Blocked / deferred
| Task | Reason | Needs |
|---|---|---|
| — | none yet | — |
