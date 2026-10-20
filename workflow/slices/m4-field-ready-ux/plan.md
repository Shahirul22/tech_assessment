# Plan: M4 — Field-ready UX and submission

| Field | Value |
|---|---|
| Slice | M4 |
| PRD | [prd.md](prd.md) (Approved 2026-10-20) |
| Status | Approved (2026-10-20, by the user; §11 items 1–5 accepted, resend timeout set to 3 s) |
| Date | 2026-10-20 |

## 1. Approach summary
M4 changes only what the operator sees and taps; no server code, table, endpoint or contract changes (FR26). The single most important idea: **every send goes through one small pure "retry runner" (`retry-policy.js`) and every message the operator reads comes from one pure "banner model" (`banner-model.js`).** Today the submit flow (`api.js`) and the supervisor flow (`supervisor.js`) each hand-roll their own send, "no answer" and Retry logic, and the three banners in the Blade view are copy-pasted. If the hybrid retry (up to 3 automatic same-key resends, then manual Retry) were added to each by hand, the two flows would drift. The runner decides *when to resend and when to stop*; the banner model decides *what words and shape to show for each of the six states*. Both are plain functions with injected clocks, so every acceptance criterion about retries and wording is a fast unit test. The rest is presentation: pure helpers for quick amounts and the stepper, one anonymous Blade component for the banner, a revised token file with a measured sunlight standard, 48 px targets, and two browser audit scripts (the second part needs your approval, §11 item 1). Because the server already makes a repeated idempotency key return the original result, resending can never create a second allocation; that is what makes automatic resend safe.

## 2. Rules check
Read before designing: `workflow/rules/laravel-boost.md` (the only rule file; `workflow/constitution.md` and `workflow/boundaries.md` do not exist), `CLAUDE.md` project rules and the Boost guidelines in it, the M1–M3 plans and PRDs, and the current client code (`resources/js/*`, `resources/css/*`, `resources/views/dashboard.blade.php`). `.ai/rules` does not exist (checked 2026-10-20). Boost was reached this session after the stack was started (the earlier "connection closed" came from the stack being down); `application-info` and three `search-docs` queries were run (below).

| Rule (cite file / ID) | Complies? | How |
|---|---|---|
| laravel-boost rule 1 (`search-docs` before Laravel code) | Yes, by task design | Queries run for this plan: `vite build assets blade`, `blade components accessibility`, `blade @vite directive`. They confirmed: Blade anonymous components live in `resources/views/components`, `@vite` loads the built assets, and processed static assets need Vite's `assets` option (M4 adds none). Each Blade/Vite task in §10 names its own `search-docs` query. |
| laravel-boost rule 2 (real schema via `database-schema`; tinker; `last-error`) | N/A | No schema, query or model work. `last-error` and `browser-logs` are used if a task debugs. |
| laravel-boost rule 3 (`application-info` at session start) | Yes | Called this session (Laravel 13.37.0, PHP 8.4, Alpine 3.17.4, Vite 8.3.4, Pest 4.7.8). T001 calls it again. |
| laravel-boost rule 4 (never continue without Boost) | Yes | Boost was unreachable at the start of this session; the stack was started with `docker compose up -d --wait`, Boost answered, and the plan continued only after that. |
| laravel-boost rule 5 (each task note names the documentation query) | Yes, by task design | Part of every Blade/Vite/Pest task's Verify line. |
| laravel-boost rule 6 (Boost never overrides `requirement.md` §0, the PRD or the plan) | Yes | No conflict found. Items for you are in §11. |
| `CLAUDE.md`: existing structure; no new base folders | Yes | New files go in existing folders (`resources/js`, `resources/js/__tests__`, `resources/views/components`, `tests/Live`, `tests/Feature`). `resources/views/components/` is a Laravel-standard sub-folder of `resources/views`. |
| `CLAUDE.md`: "Do not change the application's dependencies without approval" | Needs approval | Everything except the two browser audit scripts needs no new package. Those two need `playwright-core` (dev only): §11 item 1. Nothing is added until you approve. |
| `CLAUDE.md`: "only create documentation files if explicitly requested" | Yes | Only `README.md` (which exists and which FR25 requires to be final) is edited. `docs/api-contract.md` is not touched (server unchanged). |
| `CLAUDE.md`: do not delete tests | Yes, with approval items in §11 | No test is deleted. Four existing tests change their expected values because the approved PRD changes the behaviour they pin (§11 item 4). |
| `CLAUDE.md`: Pint after PHP changes; run narrow tests | Yes | M4 adds one small PHP test file; Pint runs on it. |
| `CLAUDE.md`: Docker for everything (no host PHP/Composer/Node) | Deviation, flagged | The JS unit tests and the build run in the `node` container. The browser audit needs a real Chromium, which the Alpine `node` image does not have. §11 item 2. |
| `requirement.md` §0, D2, D5, D6, D12, NFR2–NFR6 | Yes | §7 DD1–DD12. D5 is respected: automatic resend holds nothing for later (DD2). |
| Approved PRD M4 non-goals | Yes | No server change, no offline queue, no theme switch, no new business behaviour. |

**Re-run at the end:** see §11 "Rules re-check" (filled in by the last task, T024).

## 3. Technical context
Unchanged from M1–M3 (PHP 8.4, Laravel 13.37, PostgreSQL 16, Blade + Alpine 3.17.4 + Vite 8, Reverb + Echo, Pest 4, `node --test`). Only differences:

| Item | Choice |
|---|---|
| Language / runtime | Browser JS (ES modules), CSS custom properties, Blade. No PHP behaviour changes. |
| New dependencies | None for the app. `playwright-core` (dev only) for the two browser scripts, **only if you approve** (§11 item 1). |
| Storage | None. Nothing is persisted on the device: an automatic resend lives only while the page is open (D5). |
| Testing | `node --test` for the new pure modules and the revised flows; one Pest feature test for the page markup; two browser scripts under `tests/Live/`; the existing `scripts/verify.sh` as the regression gate. |
| Target platforms | Phones from 360 px wide, desktop to 1280 px, Chromium-class browsers (the demo). |
| Constraints | Confirmed result ≈ 1 s p95 (NFR2); feedback ≤ 100 ms; ≥ 7:1 contrast; ≥ 48 px targets; no horizontal scroll at 360 px. |

## 4. Architecture
All of M4 is the browser side. The server contract (docs/api-contract.md) is the fixed edge.

```
 dashboard.blade.php ── <x-result-banner> ×3 (request, restock, correction)
        │ Alpine (dashboard.js, supervisor.js)
        │   getters call ▼                       sends via ▼
 banner-model.js  bannerFor(status)        api.js createSubmitFlow ─┐
 amount-controls.js quickAmounts / stepAmount  supervisor.js createSupervisorFlow ─┤  both call
 form-rules.js (unchanged)                                            ▼
                                              retry-policy.js  createRetryRunner(...)
                                                 │ sleeps (injected), isOnline (injected)
                                                 ▼
                                   POST … + Idempotency-Key  (server unchanged: same key → same answer)
```

**Where the hard rules are enforced**
- *Never a duplicate:* every resend, automatic or manual, sends the **same** idempotency key; the server returns the stored result (M1 NFR5, tests exist). The client keeps `attempt` (with its key) until a definite answer arrives, so a second submit with a new key is impossible while one is outstanding.
- *No queue (D5):* the runner only resends a request that was **already sent** and got no answer, only while `isOnline()` is true. A request refused locally for being offline is dropped (never stored), exactly as today. Automatic resending stops on the browser's `offline` event, on viewer switch (`dispose()`), and when the page is closed (timers die with it).
- *Never a guessed total:* the dispatch contract is unchanged. Pool numbers change only on a `state` action after a 200/201.
- *Operator-visible state:* one function (`bannerFor`) turns any status into `{kind, shape, text, note, action}`.

### Retry runner (new, pure)
States and what the screen says (FR21):

| Status `state` | Meaning | Screen words | Shape |
|---|---|---|---|
| `pending` | first send in flight | "Pending" | hollow circle |
| `resending` | automatic resend `attempt` of 3 (waiting or in flight) | "Retrying automatically, attempt 2 of 3" | circle with arrows (↻) |
| `unconfirmed` | all automatic resends failed, or the device went offline | "Not confirmed" + Retry button (+ "You are offline — nothing was sent" when offline) | triangle (!) |
| `retrying` | manual Retry pressed, first send of the new round in flight | "Retrying" | circle with arrows (↻), no counter |
| `offline` | refused locally, nothing sent | "Offline — nothing was sent" | square with bar (same as the indicator) |
| `done` | server answered 200/201 | "4 granted", "4 of 6 granted", "None granted — 0 of 3, none left" | check mark |
| `error` | definite refusal (4xx, 409, 422) | "Refused — {server message}" | cross |

The only definite non-resendable answers are 2xx and 4xx. "No answer" is: network error, abort at the 3 s timeout, a 2xx whose body cannot be read, any 5xx **including 503 `pool_busy`** (see DD3).

### Project structure (files this slice creates or changes)
```
resources/js/
├── retry-policy.js            # NEW  createRetryRunner(): pure resend rules, injected sleep/online (FR21.3, 21.5, 21.6)
├── banner-model.js            # NEW  bannerFor(status, ctx): words, shape, action for every state (FR20, FR21, FR22.2)
├── amount-controls.js         # NEW  quickAmounts(), stepAmount(): pure helpers (FR19, FR19.1, FR19.3)
├── api.js                     # CHANGE createSubmitFlow uses the runner; 3 s timeout; dispose(); doneText wording for 0 grants
├── supervisor.js              # CHANGE createSupervisorFlow uses the runner (FR21.7); dispose()
├── dashboard.js               # CHANGE amount controls glue, banner getter via bannerFor, offline event → flow, scroll banner into view
├── form-rules.js              # unchanged
├── __tests__/
│   ├── retry-policy.test.js   # NEW
│   ├── banner-model.test.js   # NEW
│   ├── amount-controls.test.js# NEW
│   ├── design-tokens.test.js  # NEW  parses tokens.css, asserts ≥ 7:1 / ≥ 3:1 pairs (needs no browser)
│   ├── helpers/contrast.js    # NEW  hex → relative luminance → ratio (test helper only; not bundled)
│   ├── submit-flow.test.js    # CHANGE (§11 item 4)
│   └── supervisor-flow.test.js# CHANGE
resources/css/
├── tokens.css                 # CHANGE sunlight tokens, --touch-min, --font-size-key-text
└── app.css                    # CHANGE controls ≥ 48 px, banner shapes, quick amounts, stepper, key-number sizes
resources/views/
├── dashboard.blade.php        # CHANGE amount controls markup; three banners become <x-result-banner>
└── components/result-banner.blade.php   # NEW anonymous component (one banner, three uses)
tests/
├── Feature/FieldReadyMarkupTest.php     # NEW  page contains the hooks (ids, roles, labels)
└── Live/
    ├── ui-audit.mjs           # NEW  (needs approval) measures contrast, target size, 360/1280 overflow in a real browser
    └── offline-flow.mjs       # NEW  (needs approval) airplane mode, lost answer, duplicates; counts allocations through the API
README.md                      # CHANGE final README (FR25)
scripts/verify.sh              # CHANGE adds the token test (already inside `npm test`); optional ui step if approved
```

## 5. Data model
N/A — M4 changes no table, column or stored value (FR26). Nothing is persisted in the browser either (D5). The idempotency key already lives on the server; the client holds it in memory for the lifetime of one outstanding request.

## 6. Interface contracts
HTTP contract: **unchanged** (docs/api-contract.md). M4 only adds client-side contracts.

### `createRetryRunner(options) → { run(sendOnce) , abortWait(), dispose() }` (retry-policy.js)
- Purpose / PRD refs: FR21.3, FR21.5, FR21.6, FR21.7, NFR5.
- Options: `{ waitsMs = [1000, 2000, 4000], isOnline, sleep = (ms) => new Promise(r => setTimeout(r, ms)), onResending({attempt, of, phase}) }`.
- `sendOnce()` resolves `{ kind: 'answered', value }` for a definite answer (2xx handled by the caller too) or `{ kind: 'no-answer' }`. It never throws.
- `run(sendOnce)` returns `{ outcome: 'answered', value } | { outcome: 'gave-up' } | { outcome: 'stopped-offline' } | { outcome: 'disposed' }`:
  1. `sendOnce()`; if answered → return.
  2. For `i = 0..2`: if `!isOnline()` → `stopped-offline`; `onResending({attempt:i+1, of:3, phase:'waiting'})`; `await sleep(waitsMs[i])` (cut short by `abortWait()` or `dispose()`); re-check online/disposed; `onResending({attempt:i+1, of:3, phase:'sending'})`; `sendOnce()`; if answered → return.
  3. After the 3rd resend with no answer → `gave-up`.
- Same key on every call is the caller's job (it passes the same `attempt.key` closure); the runner never sees keys.
- Errors: none thrown.

### Submit flow status shapes (api.js, additive)
```js
{ state: 'pending',   amount, key }
{ state: 'resending', amount, key, attempt: 1|2|3, of: 3, phase: 'waiting'|'sending' }
{ state: 'unconfirmed', amount, key, message? }   // after give-up or offline stop
{ state: 'retrying',  amount, key }                // manual Retry, first send of the new round
{ state: 'offline', message } | { state: 'invalid', message }
{ state: 'done', ... unchanged } | { state: 'error', status, code, message }
```
New flow methods: `dispose()` (stop resending, drop any later dispatch), `goOffline()` (cut a waiting resend short; the dashboard calls it from the window `offline` event). `submit()`, `retry()` keep their signatures and return values.

### `bannerFor(status, context) → null | { kind, shape, text, note, action }` (banner-model.js)
- `context = { serverNowMs, channel: 'request'|'restock'|'correction', doneText }`.
- `kind ∈ progress | success | partial | none | refused | unconfirmed | offline | error`; `shape ∈ ring | cycle | check | triangle | cross | bar-square`; `action` is `'retry'` only for `unconfirmed` (so Retry is shown exactly then).
- Word rules (PRD §7): `done` full → "{n} granted"; partial → "{g} of {n} granted"; zero → "None granted — 0 of {n}, none left"; Temporary adds " · returns …" (existing `returnTimeText`); `error`/`refused` → "Refused — {message}"; `invalid` → null (the field message shows it); `idle` → null.

### `quickAmounts(remaining, values = [1, 5, 10]) → [{ label, value, usable }]` and `stepAmount(currentText, delta, remaining) → number | null` (amount-controls.js)
- `quickAmounts(100)` → 1, 5, 10, "All (100)" all usable; `quickAmounts(8)` → 1, 5 usable, 10 not usable, "All (8)" usable (value 8). `quickAmounts(0)` → none usable. "All" is hidden when it would duplicate a fixed value (e.g. remaining 5 → 1, 5, 10 [not usable], and "All" omitted).
- `stepAmount('3', +1, 100)` → 4; `stepAmount('3', -1, 100)` → 2; at 1 a decrease returns 1; at remaining an increase returns remaining; unparsable or empty text steps from 0 (so + gives 1, − gives 1); remaining 0 returns null.

### Blade: `<x-result-banner id="result" bind="banner" retry="retry()" />`
Props: `id` (keeps today's ids: `result`, `restock-result`, `correction-result`, so the existing checks and tests keep working), `bind` (the Alpine property holding `bannerFor(...)`), `retry` (Alpine expression for the button). Renders the `role="status"` wrapper, the shape (`aria-hidden`), the words, the Retry button (when `action === 'retry'`), and the optional note.

### Browser scripts (need approval)
- `tests/Live/ui-audit.mjs` — `node tests/Live/ui-audit.mjs` with `STACK_URL` and `CHROMIUM_PATH`; at 360 × 640 and 1280 × 800, as an operator and as a supervisor, in every banner/state it can force: for every visible tappable element it checks width ≥ 48 and height ≥ 48 and ≥ 8 px to its neighbours; for every visible text node it computes foreground against the effective background and requires ≥ 7:1; it asserts `scrollWidth <= innerWidth` and that the submit button sits above the log. Exit 1 with a list of offenders.
- `tests/Live/offline-flow.mjs` — drives the real page through: offline submit (nothing sent), lost answer with the request stored (response aborted after the server committed), automatic resend then success, three failed resends then manual Retry, ten airplane toggles. After each it counts allocations through `GET /api/pools/{id}` and asserts at most one per submit.

## 7. Key design decisions
| ID | Decision | Alternatives considered | Why this one | PRD refs |
|---|---|---|---|---|
| DD1 | One pure retry runner shared by the submit flow and the supervisor flow. | Add loops to `api.js` and `supervisor.js` separately; a generic third-party retry library. | The two flows already duplicate send/no-answer/Retry logic; a third copy of resend rules would drift. A 40-line module with an injected `sleep` is testable without timers. A library is a new dependency for a small rule. | FR21.3, FR21.5–21.7 |
| DD2 | Automatic resend only for a request that was **sent** and unanswered, only while online; nothing is stored, nothing survives a reload. | Resend after reconnect (an offline queue); persist the key in `localStorage` to resume after reload. | D5 forbids holding things for later, and the approved PQ1 hybrid says exactly this. After a reload the operator reads the log and resubmits (PRD edge case). | FR21.3, FR21.5, D5 |
| DD3 | `503 pool_busy` and every 5xx count as "no answer" and are resent automatically. | Show 503 as a definite error (today's behaviour in `createSubmitFlow`). | PQ8 says "server 5xx". `503` means the lock wait timed out so nothing was stored; resending with the same key is safe and is the best help to the operator. Today it becomes a plain error, which forced a manual retype. Tests for 503 change (§11 item 4). | FR21.3, PQ8 |
| DD4 | Timeout 3 s per send (was 8 s) for the first send and every resend; waits 1 s, 2 s, 4 s. | Keep 8 s; 5 s; a longer first send and shorter resends. | The user chose 3 s (2026-10-20). The p95 confirmed time is 0.79 s and the slow-3G check confirmed in 0.5 s, so 3 s does not cut a normal request, and a cut one is safe because the resend reuses the key. Worst case to the manual Retry is ~19 s (4 × 3 s + 7 s waits) when every send times out, ~7 s when the network fails instantly. The "attempt n of 3" counter keeps the screen from looking frozen. | FR21.3, PQ8, NFR2 |
| DD5 | Same key on every send, held by the flow's `attempt` object until a definite answer. | New key per automatic resend. | Same key is what makes the server count it once. A new key would create duplicates (NFR5). | NFR5, FR21.3 |
| DD6 | `dispose()` on the old flow when the viewer changes; every dispatch after `dispose()` is dropped. | Let the old flow finish and ignore by user id. | Today `newSubmitFlow()` replaces the flow but an in-flight call still dispatches into the new viewer's `status`. With automatic resends that window grows to ~27 s, so it must be closed. | PRD edge case (role switch mid-pending) |
| DD7 | `bannerFor()` as one pure function, banner as one Blade component used three times. | Keep three hand-written banners; make the component in Alpine only. | The wording and shape rules are acceptance criteria (AC-US3.x, AC-US4.5); they should be tested once, and the three copies in the view have already drifted (`Retry` markup differs by id). | FR20, FR20.2, FR22.2 |
| DD8 | Shapes drawn in CSS (borders, `clip-path`, `content` with plain symbols), each with `aria-hidden`; the words carry the meaning. | SVG icons; an icon font; colour only. | No asset pipeline work (no `assets` option, no fonts download), works offline, one place to tune sizes. The words are the accessible name; the shape is for sighted users who cannot rely on colour. | FR20, FR22.2, NFR4 |
| DD9 | Quick amounts 1, 5, 10 and "All (N)", shown always, **unusable** (disabled with the text kept) when above remaining. | Hide unusable ones; configurable values. | Hiding makes the row jump when remaining changes live, which is bad for a thumb. Fixed values are what PQ3 approved. | FR19, FR19.3, PQ3 |
| DD10 | Quick amounts and stepper only fill the same field and then rely on `validateAmount`; they never submit. | One-tap submit on a quick amount. | One tap = one accidental request on a shared pool; PRD AC-US1.1 explicitly has "tap amount, then submit". The field stays the single source of truth for validation. | FR19.2, FR19.4 |
| DD11 | One sunlight theme by editing `tokens.css` in place; add `--touch-min: 48px` and `--font-size-key-text: 1.5rem`; use `max(48px, 3rem)` for control heights. | A second theme and a toggle; fixed px everywhere. | PQ4. `max(48px, 3rem)` keeps ≥ 48 px even if the browser root font is below 16 px, and grows with the user's text size. Measured baseline: every text pair in `tokens.css` is already ≥ 7:1 (lowest: white on accent 7.17, disabled text 7.87, warning on warning-bg 7.76); the border colour is 6.24 and the focus ring 7.17 (both clear 3:1). So M4's work is *proving* it and fixing sizes, not recolouring. | FR22, PQ2, PQ4 |
| DD12 | Contrast proven in two layers: a token test with no browser (all declared pairs), and a browser audit of real rendered text (catches highlight and banner backgrounds). | One layer only. | The token test runs in `npm test` and in the verify script with no new dependency; the rendered audit finds a pair nobody listed (for example text over `--color-own-bg`). The second layer needs `playwright-core` (approval). If you decline, the README gets a console snippet and the token test is the automated part. | NFR4, PQ6 |
| DD13 | After a result arrives, scroll the banner into view with `block: 'nearest'`. | Pin the banner to the top of the screen; leave as is. | At 360 × 640 the banner sits below the submit button and can be off screen; a thumb user should not have to look for it. `nearest` does nothing when it is already visible. | FR20, FR20.1, FR24 |
| DD14 | Browser audit scripts live in `tests/Live/` beside the existing live scripts, run on the host against the stack. | A Docker image with Chromium. | Host already has Chromium from earlier checks; a Chromium image is a large, slow addition for a demo. §11 item 2. | PQ6 |

## 8. Requirement-to-design traceability
| PRD ID | Where it is handled |
|---|---|
| FR19, FR19.1, FR19.2, FR19.4 | `amount-controls.js`; quick-amount and stepper markup in `dashboard.blade.php`; `dashboard.js` `pickAmount()` / `step()`; DD9, DD10 |
| FR19.3 | `quickAmounts` recomputed from live `remaining`; `amountMessage` already flags a too-high typed amount |
| FR20, FR20.1 | `banner-model.js`, `<x-result-banner>`, `role="status"`; `doneText` zero-grant wording (DD7, §11 item 4); DD13 |
| FR20.2 | Same banner for restock and correction; `supervisor.js` statuses go through `bannerFor` with `channel` |
| FR21 | State table in §4; `banner-model.js`; statuses in §6 |
| FR21.1 | Unchanged `dispatch` contract (state only after 200/201); submit disabled while `pending`/`resending`/`retrying`/`unconfirmed` |
| FR21.2 | Status set synchronously before `fetch` (kept); `:active` pressed style; tested with a fake clock |
| FR21.3, FR21.5, FR21.6 | `retry-policy.js`; `api.js` `send()`; DD1–DD5 |
| FR21.4 | `connection.js` unchanged; `window.offline` → `flow.goOffline()`; live refresh on reconnect unchanged |
| FR21.7 | `supervisor.js` uses the same runner (DD1) |
| FR22, FR22.1 | `tokens.css`, `app.css` (key numbers ≥ 24 px, largest on their panel); DD11 |
| FR22.2 | Shape + words for every state: banner shapes (DD8), existing indicator, log labels; verified by AC-US2.2 greyscale check |
| FR22.3 | Existing `:focus-visible` kept and checked in the audit; buttons keep text names (`aria-label` on Correct) |
| FR23 | `--touch-min`, `.log__action` raised from 2 rem to 48 px, stepper and quick-amount sizes; `ui-audit.mjs` |
| FR24, FR24.1 | CSS review at 360 and 1280; DOM order unchanged (form before log); `ui-audit.mjs` overflow and order assertions |
| FR25 | README rewrite (T019–T021) |
| FR26 | No PHP change; regression gate `scripts/verify.sh` (T023) |
| NFR2 | 100 ms feedback unit test; 50-parallel run and 100 ms latency check re-run in T023 |
| NFR3 | `ui-audit.mjs`; manual check #2 |
| NFR4 | Token test, `ui-audit.mjs`, greyscale check, README results |
| NFR5 | Same key on every send (DD5); `offline-flow.mjs`; unit tests below |
| NFR6 | Live path untouched; AC-US9.2 re-run through `tests/Live/live-latency.mjs` |
| NFR1 | N/A — regression only |
| AC-US1.1–1.8 | `amount-controls.test.js`, `FieldReadyMarkupTest`, `ui-audit.mjs` (sizes), manual check |
| AC-US2.1–2.4 | `design-tokens.test.js`, `ui-audit.mjs`, manual greyscale and Tab walk |
| AC-US3.1–3.4 | `banner-model.test.js`, `FieldReadyMarkupTest`, `ui-audit.mjs` (banner width) |
| AC-US4.1–4.6, 4.2a, 4.3a | `retry-policy.test.js`, `submit-flow.test.js`, `banner-model.test.js`, `offline-flow.mjs`, latency check |
| AC-US5.1–5.5 | `submit-flow.test.js`, `offline-flow.mjs` |
| AC-US6.1–6.3 | `ui-audit.mjs`, manual check #2 |
| AC-US7.1–7.2 | `ui-audit.mjs` (supervisor pass), `design-tokens.test.js` |
| AC-US8.1–8.2 | README review (T019–T022); the demo script is followed once on a fresh clone |
| AC-US9.1–9.2 | `scripts/verify.sh` (T023) |

## 9. Test strategy
| Level | What it proves | Maps to AC | Notes |
|---|---|---|---|
| Unit — `retry-policy.test.js` | Exactly 4 sends then give up (AC-US4.2a); stops on offline mid-wait with no further send; `abortWait()` cuts a wait; `dispose()` stops everything; waits are 1 s, 2 s, 4 s in order; an answer on resend 2 stops the loop; never throws | AC-US4.2, 4.2a, 5.2, 5.3 | Fake `sleep` records calls; no real timers |
| Unit — `submit-flow.test.js` (changed) | **Named hard-rule tests:** `a lost answer is resent automatically with the same key up to 3 times`; `after three failed resends the screen says Not confirmed and Retry starts a fresh round with the same key`; `a 4xx, 409 or 422 is never resent`; `503 pool_busy is resent automatically`; `going offline during the wait stops resending`; `dispose drops a late answer`; `ten offline toggles and taps on Retry send one key` ; `timeout is 3 s` | AC-US4.2–4.3a, US5.1–5.5 | Existing tests updated, not removed (§11 item 4) |
| Unit — `supervisor-flow.test.js` (changed) | Restock and correction follow the same automatic-then-manual rules with one key | AC-US3.4, AC-US4.2a | Same fake sleep |
| Unit — `banner-model.test.js` | Each of the 8 kinds yields words + a distinct shape; full/partial/zero wording; "Refused — …" with the reason; Retry action only for `unconfirmed`; five states distinguishable by words alone and by shape alone | AC-US3.1, 3.3, 4.5 | Table-driven |
| Unit — `amount-controls.test.js` | Quick-amount usability for remaining 100, 8, 5, 1, 0; stepper limits at 1 and remaining; typed garbage steps from 0; quick amount sets, not adds | AC-US1.2, 1.3, 1.5, 1.6 | Pure |
| Unit — `design-tokens.test.js` | Every text/background pair declared in the test manifest ≥ 7:1; borders, focus ring ≥ 3:1; fails with the pair name and ratio | AC-US2.1, US7.2 | The manifest lists pairs by token name; adding a token without a pair is allowed, using it with a new background is caught by the browser audit |
| Feature — `FieldReadyMarkupTest.php` | The dashboard HTML contains: the quick-amount row, stepper buttons with text labels, three `role="status"` banners with the old ids, "Correct" buttons with `aria-label` | AC-US1.1, 3.3, 7.1 | Pest, real DB; no JS |
| Integration — existing suites | Unchanged server behaviour | AC-US9.1 | `APP php artisan test`, `--group=concurrency`, `NODE npm test` |
| Browser audit (needs approval) | Contrast of rendered text ≥ 7:1; every tappable ≥ 48 × 48 with ≥ 8 px gaps; `scrollWidth <= innerWidth` at 360 and 1280; submit above the log | AC-US2.1, 1.8, 6.1–6.3, 7.1–7.2 | Operator and supervisor passes |
| Browser flow (needs approval) | Offline, lost answer, automatic resend, manual Retry, ten airplane toggles produce at most one allocation per submit | AC-US5.1–5.5, US4.4 | Counts allocations via the API |
| Manual | Greyscale (browser "achromatopsia" emulation) shows every state still readable; Tab walk; two browsers in sync; 100 ms latency; demo script followed on a clean clone | AC-US2.2, 2.4, US8.2, US9.2 | Results go in the README |

**Hard rules from the PRD with their own named tests:** "at most one allocation per submit through any retry sequence" (`submit-flow.test.js` and `offline-flow.mjs`), "exactly 4 sends then manual Retry" (`retry-policy.test.js`), "never resend a definite answer" (`submit-flow.test.js`), "key numbers ≥ 7:1" (`design-tokens.test.js`).

**Boost queries (rule 5)** used by tasks: `blade anonymous components props` (T012), `vite build assets blade` (T013, T023), `pest feature test view assertions` (T015). Already run this session: `vite build assets blade`, `blade components accessibility`, `blade @vite directive`.

## 10. Task outline
Order is by dependency; **[P]** = can run in parallel with its siblings. One commit per phase (per `workflow-implement`).

- **Phase 0 — Baseline:**
  - T001 Call Boost `application-info`; run `scripts/verify.sh` on the current tree and record the result (the regression baseline).
  - T002 [P] Record the baseline numbers: the contrast table of the current tokens and a list of every control shorter than 48 px (known: `.log__action`), by hand or with the audit once it exists. Stored in the task note, not a new file.
- **Phase 1 — Pure modules (blocks everything after) [P inside]:**
  - T003 [P] `retry-policy.js` + `retry-policy.test.js`.
  - T004 [P] `banner-model.js` + `banner-model.test.js` (including the new zero-grant wording in `doneText`).
  - T005 [P] `amount-controls.js` + `amount-controls.test.js`.
  - T006 [P] `helpers/contrast.js` + `design-tokens.test.js` (reads `tokens.css`).
- **Phase 2 — Reliable sending (US4, US5, P1):**
  - T007 `api.js` `createSubmitFlow` on the runner: 3 s timeout, 503 resendable, `dispose()`, `goOffline()`, new statuses; update `submit-flow.test.js`.
  - T008 `supervisor.js` `createSupervisorFlow` on the runner (FR21.7); update `supervisor-flow.test.js`.
  - T009 `dashboard.js`: `banner` and the pending rows from `bannerFor`; `window.offline` → `goOffline()`; `dispose()` in `newSubmitFlow()`/`newSupervisorFlow()`; submit-disabled states; scroll banner into view.
  - **Checkpoint 2:** with the stack up, throttle or block the network in the browser: "Pending" → "Retrying automatically, attempt 1 of 3" → "Not confirmed" with Retry → Retry → "N granted", one log entry.
- **Phase 3 — One-thumb request (US1, P1):**
  - T010 Blade: quick-amount row and stepper around the amount field; `dashboard.js` `pickAmount()` / `step()`.
  - T011 CSS for quick amounts and stepper at 48 px, pressed state, unusable state with text kept.
  - **Checkpoint 3:** on a 360 px window tap 5, then submit; "All (N)" fills N; at remaining 0 everything is unusable and says "0 left".
- **Phase 4 — Sunlight, banners and layout (US2, US3, US6, US7):**
  - T012 `<x-result-banner>` component with shapes; replace the three banners in `dashboard.blade.php`.
  - T013 [P] `tokens.css` and `app.css`: `--touch-min`, key-number sizes (status, banner, holdings ≥ 24 px), `.log__action` and all controls ≥ 48 px with ≥ 8 px gaps, banner width and shapes.
  - T014 [P] 360 px pass: header, picker, indicator, request panel, supervisor forms; fix any overflow.
  - T015 `FieldReadyMarkupTest.php`.
  - **Checkpoint 4:** the operator and supervisor screens at 360 px and 1280 px look final; greyscale still reads; `npm test` green including the token test.
- **Phase 5 — Browser audits (needs the §11 item 1 approval; skip the two audit tasks if declined):**
  - T016 `tests/Live/ui-audit.mjs`.
  - T017 [P] `tests/Live/offline-flow.mjs`.
  - T018 Fix whatever the audits find; re-run.
  - **Checkpoint 5:** both scripts print PASS with the measured numbers.
- **Phase 6 — Final README and regression (US8, US9):**
  - T019 README: decisions D1–D12 in plain words, trade-offs and accepted risks (rewrite of "Assumptions and accepted trade-offs").
  - T020 README: demo walkthrough script (10 minutes or less, reset data first, two browsers, partial grant, Temporary and expiry demo, restock and correction, airplane mode).
  - T021 README: updated manual checks table (new retry behaviour, greyscale, 48 px) and "Results of the last full run" for M4.
  - T022 Follow the demo script once on a clean checkout (`docker compose down -v` first) and fix the script where it is wrong.
  - T023 Run `scripts/verify.sh`, the 100 ms latency check and the live check; paste the numbers into the README results.
  - T024 Rules re-check (fill §11), Pint on the one PHP file, and the plan's change log.
  - **Checkpoint 6:** README is final; every PRD AC has a recorded result.

*(T001–T024 are the outline's working numbers; `workflow-tasks` assigns the final ids and may split tasks that are too large. References to T-numbers elsewhere in this plan use this list.)*

**Checkpoints:** after Phase 2 a human can break the network and watch the hybrid retry; after Phase 3 one-thumb requests work; after Phase 4 the screens are final; after Phase 5 the measurements exist; after Phase 6 the whole thing is handed over.

## 11. Risks, deviations and open items
| # | Item | Type | Mitigation or justification | Owner / status |
|---|---|---|---|---|
| 1 | **Browser audit scripts need `playwright-core` as a dev dependency.** The repo rule says no dependency changes without approval, and PRD PQ6 says the same. | Open — **needs your approval** | Proposal: **approve `playwright-core` as a devDependency** (no browser download; it drives the Chromium already on this machine). If declined: Phase 5 is skipped; the token test (no dependency) still proves contrast of every declared colour; touch targets, overflow and offline flow are checked by the manual table plus a console snippet in the README. The slice is still deliverable, but "measured" for 48 px and for rendered contrast becomes manual. | Approved by the user 2026-10-20 |
| 2 | The audit needs a real Chromium and Node on the host, while `CLAUDE.md` says everything runs in Docker. | Deviation | The scripts only drive a browser against the Docker stack; nothing else runs on the host. The unit tests, build and verify script stay in Docker. Alternative (a Chromium container) is heavy for this demo; rejected in DD14. Accepting item 1 covers this. | Approved by the user 2026-10-20 |
| 3 | PRD wording: AC-US4.4 said quick amounts "send". | Resolved | PRD AC-US4.4 corrected 2026-10-20: submit is unavailable offline; quick amounts and the stepper only fill the field and stay usable. | Done |
| 4 | Four existing JS tests and one wording change pin behaviour the approved PRD changes: (a) 503 shows an error (now resent); (b) timeout test says 8 s (now 3 s); (c) "unconfirmed" after one failure with Retry (now after 3 automatic resends); (d) zero grant reads "0 of 3 granted — none left" (PRD AC-US3.1 says banners start with "none granted"; new text "None granted — 0 of 3, none left"). | Deviation | Tests are updated, not deleted, with the same names where possible; each change is called out in the task note. `CLAUDE.md` says tests are part of the application, so this needs your sign-off. | Approved by the user 2026-10-20 |
| 5 | Worst case before the manual Retry. | Resolved | Accepted by the user 2026-10-20 with the resend timeout shortened to 3 s: ~19 s worst case (4 × 3 s + 7 s of waits), ~7 s when the network fails instantly. PRD PQ8 reworded to match. | Done |
| 6 | After a page reload, a request that was sent but unanswered is forgotten (no persistence, D5). The server may have stored it. | Accepted risk | PRD edge case: the operator reads the log and resubmits if needed. Persisting the key was rejected in DD2. | Accepted by PRD |
| 7 | While resending, the viewer may switch role. The old flow is disposed; its late answer is dropped, though the server may have stored the request. | Accepted risk | Visible in the log (the entry arrives live). Closing this fully would need a per-user flow registry; not worth it for the demo. | Accepted by PRD |
| 8 | Greyscale/colour-blind readability and keyboard order are judged by eye. | Risk | Browser emulation of "achromatopsia" plus the README checklist; recorded results. | Implementer |
| 9 | `max(48px, 3rem)` and 8 px gaps are our reading of "≥ 48 px" (PRD FR23). On a user with a larger root font, targets grow, so 360 px layouts may wrap sooner. | Risk | The 360 px audit runs at the default 16 px root; wrapping is allowed everywhere (no fixed widths), overflow is checked. | Implementer |
| 10 | No "Rules re-check" yet. | Open | Filled by T024. | Implementer |

### Rules re-check
Re-run on 2026-10-20 by T024, after reading `workflow/rules/laravel-boost.md` and `CLAUDE.md` again.

| Rule | Result | Evidence |
|---|---|---|
| laravel-boost 1 (`search-docs` before Laravel code) | Complied | `search-docs` queries made: "blade anonymous components props", "blade components accessibility", "blade attributes merge class" (T010, reused by T012; Blade, `x-result-banner`); "pest feature test view assertions" and "assertSee assertSeeHtml response content" (T015); "vite build assets blade" (T023, before the final build). The plan session also ran "vite build assets blade", "blade components accessibility" and "blade @vite directive". Pure JS and CSS tasks touched no Laravel API and ran none, and said so in their notes. |
| laravel-boost 2 (real schema, tinker, `last-error`) | Not needed | No schema, query or model work. Behaviour was checked by tests and by browser scripts, not tinker; `last-error` and `browser-logs` were not needed. |
| laravel-boost 3 (`application-info` at session start) | Complied | Called at the start of the implementation session (Laravel 13.37.0, PHP 8.4, Pest 4.7.8, Vite 8.3.4, Alpine 3.17.4); noted in T001. |
| laravel-boost 4 (never continue without Boost) | Complied | Boost answered every call; the stack was started first (`docker compose up -d --wait`). |
| laravel-boost 5 (each note names the query) | Complied | Every Blade, Vite and Pest task note names its query; JS and CSS notes say none was needed. |
| laravel-boost 6 (Boost never overrides the PRD or plan) | Complied | No conflict with Boost guidance. Deviations from the plan itself are in the change log below. |
| `CLAUDE.md`: structure and dependencies | Complied | New files only in existing folders; the one new dependency is the approved `playwright-core` (dev). No file under `app/`, `database/`, `routes/` or `config/` changed (`git diff --stat 3399441 -- app database routes config` is empty). |
| `CLAUDE.md`: no unrequested docs; do not delete tests | Complied | Only `README.md` (required by FR25) and the workflow documents changed; no test was deleted. The approved test changes (§11 item 4) were made with the same names; the two further test edits listed in the change log are called out. |
| `CLAUDE.md`: Pint | Complied | `vendor/bin/pint --dirty --format agent` → passed (one new PHP file). |
| Docker for everything | Deviation, approved | Only the two browser audit scripts run on the host (§11 items 1–2). |

## 12. Change log
| Date | Change | Reason | Approved by |
|---|---|---|---|
| 2026-10-20 | Plan drafted from the approved PRD (including the hybrid retry decision PQ1). | `/workflow-plan` | — |
| 2026-10-20 | Send timeout 3 s instead of 5 s; PRD AC-US4.4 and PQ8 reworded; §11 items 1–5 approved; `playwright-core` dev dependency approved. | User review of the plan | User |
| 2026-10-20 | Implementation deviations, recorded for review (none changes server behaviour): (1) **seven banner shapes, not six:** `retrying` has its own shape `dot-ring` (§4 table, §6 `bannerFor`), because the PRD says Retrying looks like Pending (AC-US4.3) and also that all six states differ by shape alone (AC-US4.5); (2) `<x-result-banner>` takes `text-id` and `retry-id` props besides `id`, `bind` and `retry`, to keep the three banners' existing ids; (3) `createSubmitFlow` and `createSupervisorFlow` accept optional `sleep` and `waitsMs` options (tests inject a fake clock); (4) in the supervisor flow a 2xx with an unreadable body counts as no answer (it used to crash); (5) two further existing tests changed because 503 is now resent: the 503 entries of "a refusal from the server is a definitive error" (submit flow) and "other refusals are shown in words" (supervisor flow), and the unrelated tests that only needed an "unconfirmed" status now reach it through four failed sends (helper change, no assertion weakened); (6) the running stack serves assets baked into its images, so UI changes need `docker compose up -d --build --wait`. | Found while implementing T004, T007–T009, T012. | Pending the user's review (deviation 1 changes a shape set the plan named) |
