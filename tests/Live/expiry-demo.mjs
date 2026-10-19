// Watches one Temporary allocation expire against the running demo stack and measures how late the screen data is
// (NFR6, AC-US2.6 server half, AC-US8.1).
//
// Needs the short-window demo stack (bash scripts/demo-expiry.sh). It reads the snapshot as Ana, submits a Temporary
// request of 1 unit, then polls the snapshot every 200 ms and checks that:
//   - until expires_at the holding is listed, the unit is out of the pool and there is no expiry entry for it
//   - within 2 s after expires_at the holding is gone, the unit is back and there is exactly one expiry entry for it
// Exit code 0 when all of that holds, 1 otherwise. It prints the measured delay.
//
// Run it in the tools container, which can reach the stack by service name:
//   docker compose --profile tools run --rm node node tests/Live/expiry-demo.mjs
//
// Settings: STACK_URL (default http://nginx; use http://localhost:8080 on the host), POOL_ID, DEMO_USER_ID, LIMIT_MS
// (defaults 1, 1, 2000). It consumes 1 unit for the length of the window and gives it back.

import { randomUUID } from 'node:crypto';

const env = process.env;
const stackUrl = (env.STACK_URL ?? 'http://nginx').replace(/\/$/, '');
const poolId = Number(env.POOL_ID ?? 1);
const userId = env.DEMO_USER_ID ?? '1';
const limitMs = Number(env.LIMIT_MS ?? 2000);
const MAX_WINDOW_SECONDS = 600;

function fail(message) {
    console.error(`FAIL: ${message}`);
    process.exit(1);
}

async function snapshot() {
    const response = await fetch(`${stackUrl}/api/pools/${poolId}`, { headers: { Accept: 'application/json', 'X-Demo-User': userId } });

    if (!response.ok) {
        fail(`GET /api/pools/${poolId} answered ${response.status}`);
    }

    return response.json();
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const iso = (value) => Date.parse(value.replace(/(\.\d{3})\d+/, '$1'));

const first = await snapshot();

if (!first.viewer || first.viewer.window_seconds === null || first.viewer.window_seconds > MAX_WINDOW_SECONDS) {
    fail(`this viewer's window is ${first.viewer?.window_seconds ?? 'unknown'} s. Start the short-window stack first: bash scripts/demo-expiry.sh (windows of at most ${MAX_WINDOW_SECONDS} s).`);
}

const before = first.remaining;

const submit = await fetch(`${stackUrl}/api/pools/${poolId}/allocations`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Demo-User': userId, 'Idempotency-Key': randomUUID() },
    body: JSON.stringify({ requested: 1, type: 'temporary' }),
});

if (submit.status !== 201) {
    fail(`POST allocation answered ${submit.status}: ${await submit.text()}`);
}

const created = await submit.json();
const seq = created.allocation.seq;
const expiresAt = created.allocation.expires_at;

if (created.allocation.granted !== 1 || !expiresAt) {
    fail(`the pool granted ${created.allocation.granted} units (expires_at ${expiresAt}); it needs at least 1 free unit`);
}

console.log(`submitted Temporary 1 as entry #${seq}, expires at ${expiresAt} (window ${first.viewer.window_seconds} s)`);

const expiryEntriesFor = (log) => log.filter((entry) => entry.kind === 'expiry' && entry.allocation_seq === seq);
const deadline = iso(expiresAt) + limitMs + 3000;
let measured = null;

while (Date.now() < deadline) {
    const polledAt = Date.now();
    const body = await snapshot();
    // The server's clock decides what "before" and "after" the expiry mean, not this machine's.
    const serverNow = iso(body.server_time);
    const held = body.viewer.holdings.some((holding) => holding.allocation_seq === seq);
    const expiries = expiryEntriesFor(body.log).length;

    if (serverNow < iso(expiresAt)) {
        if (!held || body.remaining !== before - 1 || expiries !== 0) {
            fail(`before the expiry the snapshot showed held=${held}, remaining=${body.remaining} (expected ${before - 1}), expiry entries=${expiries}`);
        }
    } else if (!held && body.remaining === before && expiries === 1) {
        measured = serverNow - iso(expiresAt);
        console.log(`expired: holding gone, remaining back to ${body.remaining}, one expiry entry, ${measured} ms after expires_at`);
        break;
    } else if (expiries > 1) {
        fail(`${expiries} expiry entries for #${seq}`);
    }

    await sleep(Math.max(0, 200 - (Date.now() - polledAt)));
}

if (measured === null) {
    fail(`the expiry of #${seq} was not visible within ${limitMs} ms after expires_at`);
}

if (measured > limitMs) {
    fail(`the expiry took ${measured} ms to show, over the limit of ${limitMs} ms`);
}

// A second snapshot must not add a second expiry entry.
const again = await snapshot();

if (expiryEntriesFor(again.log).length !== 1) {
    fail(`the expiry entry for #${seq} appeared ${expiryEntriesFor(again.log).length} times on a later snapshot`);
}

console.log(`OK: measured delay ${measured} ms (limit ${limitMs} ms)`);
