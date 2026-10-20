# Trade-offs: Field Operator Allocation Module

This document lists what the brief left open, what I assumed, and why the database and UI look the way they do. The full decision record is in [requirement.md](../requirement.md) §3 and §7 (decisions D1 to D12, assumptions A1 to A11), and the [README](../README.md) has a "Decisions and trade-offs" section.

## 1. Ambiguities in the brief and how I settled them

Before any code was written I asked the AI to find the ambiguities and open questions in the brief, and I answered each one in `requirement.md`. The workflow repeats this for every milestone: the PRD skill carries open questions forward, marks the blocking ones, and does not answer them itself. Items marked ◆ would change the design most if the client answers differently.

| # | The brief says | Open question | What I assumed | Why |
|---|---|---|---|---|
| 1 ◆ | "a running unit pool" | One pool for everyone, or one per operator? | One shared pool, first come first served, in the order the server commits requests (D1) | "Multiple operators logging at the same moment" only makes sense against shared stock. Phone clocks disagree, so order cannot come from the device. |
| 2 ◆ | "never show wrong totals" and "feel instant" | Show the new total at once, or wait for the server? | The operator's own request shows at once as Pending, and totals change only after the server confirms (D2, D12) | A guessed total can be wrong, while a pending row cannot. The form caps the request at what was last seen, and the server grants `min(requested, remaining)`. A stale screen gets "4 of 6 granted" and never an overdraw. |
| 3 ◆ | "certain allocations have variable expiration windows based on operator tiers" | Which allocations expire? Do expired units return? | The operator picks Temporary (expires) or Consumed (permanent). On expiry the units return to the pool (D4, A3) | The brief does not say who decides "certain", so an explicit choice is the least surprising. |
| 4 ◆ | "operator tiers" and "status levels" | Are they the same thing? | They are separate. Tier is fixed per operator and sets the expiry window. Status is derived from the units held now, against thresholds in config (D3, D10) | Treating them as one would make a status change rewrite old expiry windows. The tier is copied onto each allocation when it is logged, so a later promotion leaves old windows alone. |
| 5 ◆ | "adjustments can retroactively alter past entries" | What can a supervisor change? Are past grants re-run? | A correction changes the amount or voids an entry, and nothing else. Past grants are final, so nothing is re-granted or taken back. Lowering frees units at once. Raising is refused unless the free units cover it (D7) | Re-running history would hand units to different people after the fact. "Never negative" then needs no exception: the supervisor restocks first, then corrects. |
| 6 | "recalculate downstream status levels" | Does "never negative" apply to history too? | It applies to the balance now. A correction that would have put an earlier moment below zero is accepted, and the audit flags it as "overdrawn in hindsight" (D11) | Blocking on hypothetical past states would refuse corrections the pool can cover today, and the flag keeps the history honest. |
| 7 | "status levels" | How many, and what thresholds? | Three levels at 0, 10 and 30 units, in `config/pool_rules.php`, validated at boot (A4) | These are placeholders. Changing them is a config edit, and a bad file stops start-up. |
| 8 | "a warehouse", "bright sunlight" | Is offline support needed? | Online only, no queue (D5). One narrow exception: a request that was sent and got no answer is resent up to 3 times with the same idempotency key, and then a manual Retry appears | A queued request could be granted hours after the person walked away. A visible "Offline" or "Not confirmed" state beats a guessed total. |
| 9 | "supervisor adjustments" | Is a real login needed? | Seeded users and a role switch (A10), labelled in the UI and docs as a demo switch | Authentication is not what is being assessed. The rules are. |
| 10 | "feel instant" | What number counts as instant? | The operator's own action is acknowledged at once and confirmed in about 1 s (p95) on the local stack. Other screens update within 2 s | This turns an adjective into a test. Measured: p95 0.709 s for 50 parallel requests, and the live update check passed 10 of 10. |

I also settled these smaller points. Units are whole numbers (A1). Operators cannot backdate, because `effective_at` is the server's receipt time (§4). The log shows the whole pool, with the viewer's own entries marked (A5). An operator cannot return units early (A6).

## 2. Why the database looks like this

- Ledger instead of counters. `restocks`, `allocations` and `corrections` are append-only. `remaining`, expiry and every status are never stored. One pure function, `PoolCalculator`, calculates them from the ledger at a given instant. That makes a retroactive correction cheap to get right, because no stored total can fall out of step and the original rows stay readable for the audit.
- One write path and one lock. Every write takes `SELECT … FOR UPDATE` on the pool row inside a transaction, reloads the ledger, calculates, and stores one entry numbered from the pool's `version`. Commit order is service order, which is what "first come, first served" means. A 5 s lock timeout answers 503 instead of hanging.
- Rules in the database as well. `CHECK` constraints (`granted <= requested`, correction kind against amount) and unique `(pool_id, idempotency_key)` and `(pool_id, seq)` keys mean an application bug cannot store an impossible row or count a retry twice.
- Expiry at read time. There is no scheduled job that can run late or be forgotten. The cost is that every read recomputes, and each screen schedules its own refetch at the next expiry it can see.
- PostgreSQL, tested for real. `timestamptz(6)` and row locks are the point, so tests run on Postgres. A concurrency test fires 50 parallel HTTP requests of 3 units at a pool of 100 and expects exactly 100 granted and one partial grant.

## 3. Why the UI looks like this

- A typical request takes two taps. Quick-amount buttons and a stepper are capped at the remaining units, and every control is at least 48 px with 8 px gaps.
- For sunlight there is one high-contrast theme and no toggle. Text is at least 7:1 (lowest measured 7.17:1) and key numbers are at least 24 px. Every state has words and a shape as well as colour: a hollow ring for Pending, a triangle for Not confirmed, a tick for Confirmed. The README's manual checks include a greyscale pass.
- The connection state is always visible: a Live, Reconnecting or Offline indicator, and a full-width result banner such as "None granted — 0 of 3, none left". Retry cannot create a duplicate, because it reuses the idempotency key.
- I chose Blade, Alpine and Reverb over an SPA to carry less code and stay fast on a weak phone. The logic that matters (ordering live updates, retry, time text) sits in small modules with no DOM, and they have tests. Updates apply in `version` order, a gap triggers a snapshot fetch, and a 10 s poll covers the socket being down.

## 4. Known limits, accepted on purpose

- Replay cost grows with history. Every write reloads the pool's whole ledger under the lock. That is exact and simple at demo scale. At real scale I would add periodic snapshots.
- There is no real security. `X-Demo-User` is a role switch and the websocket channel is public, so the app should not be deployed as is.
- Nothing survives a reload. An unanswered request is forgotten if the page closes, although the server may have stored it. The log shows what actually happened.
- Tier names, windows and thresholds are placeholders, not the client's numbers.

## 5. What I would ask the client first

1. Is the pool really shared, or is it per site or per team? (Item 1)
2. Which allocations expire, and who decides? (Item 3)
3. Are tier and status really separate, and what are the real tier windows and thresholds? (Items 4 and 7)
4. May a supervisor ever give units that are not there, or is "never negative" absolute? (Items 5 and 6)
5. Is the warehouse Wi-Fi bad enough to need real offline queuing, and if so, who owns a request that is granted late? (Item 8)
