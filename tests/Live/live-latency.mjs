// Measures how long a screen waits to hear about an allocation (NFR6, AC-US3.1).
//
// It connects to Reverb like a browser would, subscribes to the public channel `pool.1`, then submits an
// allocation of 1 unit as Ana through the web server and times how long `pool.updated` takes to arrive.
// It runs ten rounds on one connection; every round must arrive within 2000 ms with the same version and remaining
// as the HTTP response. Exit code 0 when all ten pass, 1 otherwise.
//
// Run it in the tools container, which can reach the stack by service name:
//   docker compose --profile tools run --rm node node tests/Live/live-latency.mjs
//
// Settings come from the environment (the `node` service loads .env.docker):
//   REVERB_HOST / REVERB_PORT / REVERB_APP_KEY   where Reverb is, and the app key
//   STACK_URL                                    the web server (default http://nginx; use http://localhost:8080 on the host)
//   POOL_ID, DEMO_USER_ID, ROUNDS, LIMIT_MS      defaults 1, 1, 10, 2000
//
// Each round consumes 1 unit from the pool.

import { randomUUID } from 'node:crypto';
import Pusher from 'pusher-js';

const env = process.env;
const config = {
    host: env.REVERB_HOST ?? 'localhost',
    port: Number(env.REVERB_PORT ?? 8081),
    key: env.REVERB_APP_KEY,
    stackUrl: (env.STACK_URL ?? 'http://nginx').replace(/\/$/, ''),
    poolId: Number(env.POOL_ID ?? 1),
    userId: env.DEMO_USER_ID ?? '1',
    rounds: Number(env.ROUNDS ?? 10),
    limitMs: Number(env.LIMIT_MS ?? 2000),
    scheme: env.REVERB_SCHEME ?? 'http',
};

function fail(message) {
    console.error(`FAIL: ${message}`);
    process.exit(1);
}

if (!config.key) {
    fail('REVERB_APP_KEY is not set. Run this through `docker compose --profile tools run --rm node …`, which loads .env.docker.');
}

function withTimeout(promise, ms, what) {
    let timer;
    const timeout = new Promise((_, reject) => {
        timer = setTimeout(() => reject(new Error(`${what} did not happen within ${ms} ms`)), ms);
    });

    return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
}

const pusher = new Pusher(config.key, {
    cluster: 'mt1',
    wsHost: config.host,
    wsPort: config.port,
    wssPort: config.port,
    forceTLS: config.scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
});

const channel = pusher.subscribe(`pool.${config.poolId}`);

// Events that arrived, by version, with the time they arrived.
const arrived = new Map();
const waiters = new Map();

channel.bind('pool.updated', (payload) => {
    const at = performance.now();
    arrived.set(payload.version, { payload, at });
    waiters.get(payload.version)?.({ payload, at });
});

function eventFor(version) {
    if (arrived.has(version)) {
        return Promise.resolve(arrived.get(version));
    }

    return new Promise((resolve) => waiters.set(version, resolve));
}

async function submit() {
    const startedAt = performance.now();
    const response = await fetch(`${config.stackUrl}/api/pools/${config.poolId}/allocations`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Demo-User': config.userId,
            'Idempotency-Key': randomUUID(),
        },
        body: JSON.stringify({ requested: 1 }),
    });

    if (response.status !== 201) {
        fail(`POST returned ${response.status}: ${await response.text()}`);
    }

    return { startedAt, body: await response.json() };
}

try {
    await withTimeout(
        new Promise((resolve) => channel.bind('pusher:subscription_succeeded', resolve)),
        5000,
        `subscribing to pool.${config.poolId} on ${config.host}:${config.port}`,
    );
    console.log(`Subscribed to pool.${config.poolId} on ${config.host}:${config.port}. Running ${config.rounds} rounds (limit ${config.limitMs} ms).`);

    const timings = [];

    for (let round = 1; round <= config.rounds; round++) {
        const { startedAt, body } = await submit();
        const { version, remaining } = body.pool;

        const { payload, at } = await withTimeout(eventFor(version), config.limitMs, `round ${round}: the pool.updated event for version ${version}`);
        const delay = Math.round(at - startedAt);

        if (payload.version !== version || payload.remaining !== remaining) {
            fail(`round ${round}: event says version ${payload.version} / remaining ${payload.remaining}, HTTP said ${version} / ${remaining}`);
        }

        if (payload.entry?.seq !== body.allocation.seq) {
            fail(`round ${round}: event entry seq ${payload.entry?.seq}, HTTP allocation seq ${body.allocation.seq}`);
        }

        if (delay > config.limitMs) {
            fail(`round ${round}: ${delay} ms is over the ${config.limitMs} ms limit`);
        }

        timings.push(delay);
        console.log(`round ${String(round).padStart(2)}: ${String(delay).padStart(4)} ms  (version ${version}, remaining ${remaining})`);
    }

    const sorted = [...timings].sort((a, b) => a - b);
    console.log(`${timings.length}/${config.rounds} within ${config.limitMs} ms. min ${sorted[0]} ms, median ${sorted[Math.floor(sorted.length / 2)]} ms, max ${sorted.at(-1)} ms.`);
    pusher.disconnect();
    process.exit(0);
} catch (error) {
    fail(error.message);
}
