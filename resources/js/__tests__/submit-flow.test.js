import { afterEach, beforeEach, test, mock } from 'node:test';
import assert from 'node:assert/strict';

import { createSubmitFlow, doneText, fetchLog, fetchSnapshot, randomKey } from '../api.js';
import { GAP_MS, checkGap, initialState } from '../pool-state.js';

beforeEach(() => {
    mock.timers.enable({ apis: ['setTimeout'] });
});

afterEach(() => {
    mock.timers.reset();
});

const ana = { id: 1, name: 'Ana' };

function allocationResult(seq, requested, granted, remaining) {
    const outcome = granted === 0 ? 'none' : granted === requested ? 'full' : 'partial';

    return {
        allocation: {
            id: seq + 100, seq, operator: ana, type: 'consumed', requested, granted, outcome, tier_at_log: 'bronze',
            effective_at: '2026-10-17T10:00:00.000000Z', recorded_at: '2026-10-17T10:00:00.010000Z',
        },
        pool: { version: seq, remaining },
    };
}

const jsonResponse = (status, body) => ({ ok: status >= 200 && status < 300, status, json: async () => body });

/** A fetch whose responses the test releases by hand, and which honours AbortSignal. */
function controllableFetch() {
    const calls = [];

    const fetch = (url, options) => new Promise((resolve, reject) => {
        const call = { url, options, resolve, reject };
        calls.push(call);
        options.signal?.addEventListener('abort', () => reject(Object.assign(new Error('The operation was aborted'), { name: 'AbortError' })));
    });

    return { fetch, calls };
}

function harness({ online = true, remaining = 10, keys = ['key-1', 'key-2', 'key-3'], getType = undefined } = {}) {
    let state = initialState({ version: 10, remaining, log: [], log_has_more: false });
    const actions = [];
    const order = [];
    const { fetch, calls } = controllableFetch();
    let onlineNow = online;
    let nextKey = 0;

    const flow = createSubmitFlow({
        fetch: (...args) => { order.push('fetch'); return fetch(...args); },
        userId: 1,
        poolId: 1,
        getState: () => state,
        getType,
        dispatch: (action) => {
            actions.push(action);
            order.push(`dispatch:${action.type}:${action.status?.state ?? ''}`);
            if (action.type === 'state') {
                state = action.state;
            }
        },
        isOnline: () => onlineNow,
        newKey: () => keys[nextKey++],
        now: () => 0,
    });

    return {
        flow, calls, actions, order,
        state: () => state,
        statuses: () => actions.filter((a) => a.type === 'status').map((a) => a.status),
        lastStatus: () => actions.filter((a) => a.type === 'status').at(-1)?.status,
        setOnline: (value) => { onlineNow = value; },
    };
}

const flush = () => new Promise((resolve) => setImmediate(resolve));

test('shows pending before the request is sent, and leaves the totals alone until the server answers', async () => {
    const h = harness();

    const done = h.flow.submit('4');

    // Synchronously, before fetch has resolved: pending is on screen, the request is out, the number has not moved.
    assert.deepEqual(h.lastStatus(), { state: 'pending', amount: 4, key: 'key-1' });
    assert.equal(h.calls.length, 1);
    assert.ok(h.order.indexOf('dispatch:status:pending') < h.order.indexOf('fetch'), 'pending was set before fetch was called');
    assert.equal(h.state().remaining, 10);
    assert.equal(h.state().version, 10);

    h.calls[0].resolve(jsonResponse(201, allocationResult(11, 4, 4, 6)));
    await done;

    assert.equal(h.state().remaining, 6);
    assert.equal(h.state().version, 11);
    assert.equal(h.lastStatus().state, 'done');
});

test('sends one POST with the idempotency key, the demo user and the amount', async () => {
    const h = harness();
    const done = h.flow.submit(' 4 ');
    const [{ url, options }] = h.calls;

    assert.equal(url, '/api/pools/1/allocations');
    assert.equal(options.method, 'POST');
    assert.equal(options.headers['Idempotency-Key'], 'key-1');
    assert.equal(options.headers['X-Demo-User'], '1');
    assert.equal(options.headers['Content-Type'], 'application/json');
    assert.deepEqual(JSON.parse(options.body), { requested: 4, type: 'consumed' });
    assert.ok(options.signal, 'has an abort signal for the timeout');

    h.calls[0].resolve(jsonResponse(201, allocationResult(11, 4, 4, 6)));
    await done;
});

test('reports full, partial and none results with what was asked and granted', async () => {
    for (const [requested, granted, outcome] of [[3, 3, 'full'], [6, 4, 'partial'], [3, 0, 'none']]) {
        const h = harness();
        const done = h.flow.submit(String(requested));
        h.calls[0].resolve(jsonResponse(201, allocationResult(11, requested, granted, 10 - granted)));
        await done;

        assert.deepEqual(
            { ...h.lastStatus(), result: undefined },
            { state: 'done', outcome, requested, granted, type: 'consumed', expiresAt: null, replayed: false, result: undefined },
        );
    }
});

test('treats a replayed 200 as done too', async () => {
    const h = harness();
    const done = h.flow.submit('3');
    h.calls[0].resolve(jsonResponse(200, allocationResult(11, 3, 3, 7)));
    await done;

    assert.equal(h.lastStatus().state, 'done');
    assert.equal(h.lastStatus().replayed, true);
    assert.equal(h.state().remaining, 7);
});

test('a refusal from the server is a definitive error, with the totals untouched', async () => {
    for (const [status, code] of [[409, 'idempotency_key_conflict'], [422, 'validation_failed'], [403, 'forbidden_role'], [503, 'pool_busy'], [401, 'unknown_user'], [404, 'not_found']]) {
        const h = harness();
        const done = h.flow.submit('4');
        h.calls[0].resolve(jsonResponse(status, { error: { code, message: `m-${code}`, details: {} } }));
        await done;

        assert.deepEqual(h.lastStatus(), { state: 'error', status, code, message: `m-${code}` }, String(status));
        assert.equal(h.state().remaining, 10, String(status));
        assert.equal(h.actions.some((a) => a.type === 'state'), false, `${status} changed no totals`);
    }
});

test('a definitive error frees the form: the next submit is a new attempt with a new key', async () => {
    const h = harness();
    const first = h.flow.submit('4');
    h.calls[0].resolve(jsonResponse(422, { error: { code: 'validation_failed', message: 'bad', details: {} } }));
    await first;

    const second = h.flow.submit('4');
    assert.equal(h.calls[1].options.headers['Idempotency-Key'], 'key-2');
    h.calls[1].resolve(jsonResponse(201, allocationResult(11, 4, 4, 6)));
    await second;
});

test('a lost response is "unconfirmed": totals stay, the key is kept, and Retry sends the same key and shows the original result', async () => {
    const h = harness();
    const first = h.flow.submit('4');
    h.calls[0].reject(new TypeError('fetch failed'));
    await first;

    assert.deepEqual(h.lastStatus(), { state: 'unconfirmed', amount: 4, key: 'key-1' });
    assert.equal(h.state().remaining, 10, 'no total is guessed');

    const retry = h.flow.retry();
    assert.deepEqual(h.lastStatus(), { state: 'pending', amount: 4, key: 'key-1' });
    assert.equal(h.calls.length, 2);
    assert.equal(h.calls[1].options.headers['Idempotency-Key'], 'key-1', 'the same key');
    assert.deepEqual(JSON.parse(h.calls[1].options.body), { requested: 4, type: 'consumed' });

    // The server had it already and answers with the stored result.
    h.calls[1].resolve(jsonResponse(200, allocationResult(11, 4, 4, 6)));
    await retry;

    assert.equal(h.lastStatus().state, 'done');
    assert.equal(h.lastStatus().replayed, true);
    assert.equal(h.state().remaining, 6);
});

test('a request that takes longer than 8 seconds becomes unconfirmed', async () => {
    const h = harness();
    const done = h.flow.submit('4');

    mock.timers.tick(7_999);
    await flush();
    assert.equal(h.lastStatus().state, 'pending');

    mock.timers.tick(1);
    await done;

    assert.deepEqual(h.lastStatus(), { state: 'unconfirmed', amount: 4, key: 'key-1' });
    assert.equal(h.state().remaining, 10);
});

test('an unexpected server error is unconfirmed too, since the entry may have been stored', async () => {
    for (const status of [500, 502, 504]) {
        const h = harness();
        const done = h.flow.submit('4');
        h.calls[0].resolve(jsonResponse(status, {}));
        await done;

        assert.equal(h.lastStatus().state, 'unconfirmed', String(status));
        assert.equal(h.state().remaining, 10);
    }
});

test('a 2xx whose body cannot be read is unconfirmed, not a crash', async () => {
    const h = harness();
    const done = h.flow.submit('4');
    h.calls[0].resolve({ ok: true, status: 201, json: async () => { throw new SyntaxError('bad json'); } });
    await done;

    assert.equal(h.lastStatus().state, 'unconfirmed');
});

test('offline: nothing is sent, nothing is stored, and nothing is sent when the connection returns', async () => {
    const h = harness({ online: false });

    await h.flow.submit('4');

    assert.deepEqual(h.lastStatus(), { state: 'offline', message: 'You are offline — nothing was sent' });
    assert.equal(h.calls.length, 0);
    assert.equal(h.state().remaining, 10);

    // The connection comes back and time passes: still nothing sent by itself.
    h.setOnline(true);
    mock.timers.tick(60_000);
    await flush();
    assert.equal(h.calls.length, 0);

    // A new, deliberate submit is a fresh attempt with the first key (none was spent offline).
    const done = h.flow.submit('4');
    assert.equal(h.calls[0].options.headers['Idempotency-Key'], 'key-1');
    h.calls[0].resolve(jsonResponse(201, allocationResult(11, 4, 4, 6)));
    await done;
});

test('rejects what the form rules reject, without sending anything', async () => {
    const h = harness({ remaining: 10 });

    for (const [raw, message] of [['11', 'Only 10 left'], ['0', 'Enter 1 or more'], ['', 'Enter a number'], ['1.5', 'Enter a whole number']]) {
        await h.flow.submit(raw);
        assert.deepEqual(h.lastStatus(), { state: 'invalid', message }, JSON.stringify(raw));
    }

    assert.equal(h.calls.length, 0);
});

test('with nothing left, a submit is invalid and sends nothing', async () => {
    const h = harness({ remaining: 0 });

    await h.flow.submit('1');

    assert.deepEqual(h.lastStatus(), { state: 'invalid', message: '0 left' });
    assert.equal(h.calls.length, 0);
});

test('a second tap while a request is pending is ignored', async () => {
    const h = harness();
    const first = h.flow.submit('4');

    const second = await h.flow.submit('4');

    assert.equal(h.calls.length, 1);
    assert.deepEqual(second, { started: false, reason: 'pending' });

    h.calls[0].resolve(jsonResponse(201, allocationResult(11, 4, 4, 6)));
    await first;
});

test('while a result is unconfirmed, a new submit is refused until Retry settles it', async () => {
    const h = harness();
    const first = h.flow.submit('4');
    h.calls[0].reject(new TypeError('fetch failed'));
    await first;

    const other = await h.flow.submit('2');

    assert.deepEqual(other, { started: false, reason: 'unconfirmed' });
    assert.equal(h.calls.length, 1, 'no second key was spent on a possibly stored request');
});

test('Retry while offline sends nothing and keeps the attempt for later', async () => {
    const h = harness();
    const first = h.flow.submit('4');
    h.calls[0].reject(new TypeError('fetch failed'));
    await first;

    h.setOnline(false);
    await h.flow.retry();

    assert.deepEqual(h.lastStatus(), { state: 'unconfirmed', amount: 4, key: 'key-1', message: 'You are offline — nothing was sent' });
    assert.equal(h.calls.length, 1);

    h.setOnline(true);
    const retry = h.flow.retry();
    assert.equal(h.calls[1].options.headers['Idempotency-Key'], 'key-1');
    h.calls[1].resolve(jsonResponse(200, allocationResult(11, 4, 4, 6)));
    await retry;
    assert.equal(h.lastStatus().state, 'done');
});

test('Retry with nothing to retry does nothing', async () => {
    const h = harness();

    const result = await h.flow.retry();

    assert.deepEqual(result, { started: false, reason: 'nothing to retry' });
    assert.equal(h.calls.length, 0);
});

test('a confirmed result clears the attempt, so the next submit gets a new key', async () => {
    const h = harness();
    const first = h.flow.submit('4');
    h.calls[0].resolve(jsonResponse(201, allocationResult(11, 4, 4, 6)));
    await first;

    const second = h.flow.submit('2');
    assert.equal(h.calls[1].options.headers['Idempotency-Key'], 'key-2');
    h.calls[1].resolve(jsonResponse(201, allocationResult(12, 2, 2, 4)));
    await second;

    assert.equal(h.state().remaining, 4);
    assert.equal(h.state().version, 12);
});

test('opens a gap on the same clock the dashboard checks it with (performance.now), so it can time out', async () => {
    // No `now` is injected: the flow must use performance.now() by default. Date.now() would be ~1.7e12 and a gap
    // opened with it would never look 500 ms old to checkGap(state, performance.now()).
    let state = initialState({ version: 10, remaining: 10, log: [], log_has_more: false });
    const { fetch, calls } = controllableFetch();
    const flow = createSubmitFlow({
        fetch, userId: 1, poolId: 1, getState: () => state, isOnline: () => true, newKey: () => 'k',
        dispatch: (action) => { if (action.type === 'state') { state = action.state; } },
    });

    const done = flow.submit('4');
    // The own result is version 13 while the screen is at 10: a gap.
    calls[0].resolve(jsonResponse(201, allocationResult(13, 4, 4, 0)));
    await done;

    assert.equal(state.version, 10);
    assert.equal(state.buffered.length, 1);
    assert.ok(state.gapSince < 1e9, `gapSince ${state.gapSince} is not on the performance.now() clock`);
    // Just past the boundary: gapSince is a fractional performance.now() value, so adding exactly GAP_MS can land a
    // hair under 500 in floating point.
    assert.equal(checkGap(state, state.gapSince + GAP_MS + 1).effect, 'snapshot');
});

test('fetchSnapshot and fetchLog ask the API as the demo user', async () => {
    const seen = [];
    const fetch = async (url, options) => { seen.push([url, options.headers['X-Demo-User']]); return jsonResponse(200, { ok: url }); };

    assert.deepEqual(await fetchSnapshot({ fetch, userId: 2, poolId: 1 }), { ok: '/api/pools/1' });
    assert.deepEqual(await fetchLog({ fetch, userId: 2, poolId: 1, before: 22 }), { ok: '/api/pools/1/log?before=22' });
    assert.deepEqual(await fetchLog({ fetch, userId: 2, poolId: 1, before: 22, limit: 50 }), { ok: '/api/pools/1/log?before=22&limit=50' });
    assert.deepEqual(seen.map(([, user]) => user), ['2', '2', '2']);
});

test('fetchSnapshot throws when the server says no, so the caller can keep what it has', async () => {
    const fetch = async () => jsonResponse(500, {});

    await assert.rejects(() => fetchSnapshot({ fetch, userId: 1, poolId: 1 }), /500/);
});

test('makes a v4 UUID key even where crypto.randomUUID does not exist (an http page on a phone)', () => {
    const noRandomUuid = { getRandomValues: (bytes) => { bytes.fill(0xab); return bytes; } };

    const key = randomKey(noRandomUuid);

    assert.match(key, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    assert.match(randomKey({ randomUUID: () => 'from-native' }), /from-native/);
    assert.match(randomKey(), /^[0-9a-f-]{36}$/);
});

// M2: the chosen type and the return time.

function temporaryResult(seq, requested, granted, remaining, expiresAt) {
    const result = allocationResult(seq, requested, granted, remaining);

    return { ...result, allocation: { ...result.allocation, type: 'temporary', expires_at: granted > 0 ? expiresAt : null } };
}

test('sends the chosen type with the amount', async () => {
    const h = harness({ getType: () => 'temporary' });
    const done = h.flow.submit('4');

    assert.deepEqual(JSON.parse(h.calls[0].options.body), { requested: 4, type: 'temporary' });

    h.calls[0].resolve(jsonResponse(201, temporaryResult(11, 4, 4, 6, '2026-10-20T10:00:00.000000Z')));
    await done;
});

test('a retry resends the same type and the same key, even if the choice changed meanwhile', async () => {
    let type = 'temporary';
    const h = harness({ getType: () => type });

    const first = h.flow.submit('4');
    h.calls[0].reject(new Error('network'));
    await first;

    type = 'consumed';
    const retried = h.flow.retry();

    assert.equal(h.calls[1].options.headers['Idempotency-Key'], 'key-1');
    assert.deepEqual(JSON.parse(h.calls[1].options.body), { requested: 4, type: 'temporary' });

    h.calls[1].resolve(jsonResponse(200, temporaryResult(11, 4, 4, 6, '2026-10-20T10:00:00.000000Z')));
    await retried;
});

test('the done status carries the type and the expiry of a temporary grant, and the viewer reaches the state', async () => {
    const h = harness({ getType: () => 'temporary' });
    const done = h.flow.submit('4');
    const viewer = { operator_id: 1, status: 'standard', tier: 'bronze', window_seconds: 86400, holdings: [] };

    h.calls[0].resolve(jsonResponse(201, { ...temporaryResult(11, 4, 4, 6, '2026-10-20T10:00:00.000000Z'), viewer }));
    await done;

    assert.equal(h.lastStatus().type, 'temporary');
    assert.equal(h.lastStatus().expiresAt, '2026-10-20T10:00:00.000000Z');
    assert.deepEqual(h.state().viewer, viewer);
});

test('the banner says when temporary units return, in full, partial, zero and consumed cases', () => {
    const serverNow = Date.parse('2026-10-19T08:00:00Z');
    const expiresAt = '2026-10-20T10:00:00.000000Z';

    assert.equal(doneText({ requested: 4, granted: 4, expiresAt }, serverNow, 'UTC'), '4 granted · returns 10:00 tomorrow');
    assert.equal(doneText({ requested: 6, granted: 4, expiresAt }, serverNow, 'UTC'), '4 of 6 granted · returns 10:00 tomorrow');
    assert.equal(doneText({ requested: 3, granted: 0, expiresAt: null }, serverNow, 'UTC'), '0 of 3 granted — none left');
    assert.equal(doneText({ requested: 4, granted: 4, expiresAt: null }, serverNow, 'UTC'), '4 granted');
    assert.equal(doneText({ requested: 6, granted: 4 }, serverNow, 'UTC'), '4 of 6 granted');
});
