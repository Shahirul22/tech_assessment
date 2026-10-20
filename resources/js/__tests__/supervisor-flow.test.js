import { test } from 'node:test';
import assert from 'node:assert/strict';

import { OFFLINE_MESSAGE } from '../api.js';
import { initialState } from '../pool-state.js';
import { createSupervisorFlow, refusalText } from '../supervisor.js';

const start = () => initialState({ version: 4, remaining: 0, log: [{ seq: 4, cursor: '4', kind: 'allocation', operator: { id: 3, name: 'Cara' }, requested: 10, granted: 10 }] });

function harness({ replies = [], online = true } = {}) {
    const actions = [];
    const calls = [];
    const waits = [];
    let state = start();
    let keyCount = 0;
    const queue = [...replies];
    const fetch = async (url, options) => {
        // The "pending" status must already be out before the request is made.
        calls.push({ url, options, actionsBefore: actions.length });
        const next = queue.shift();

        if (next instanceof Error) {
            throw next;
        }

        return { status: next.status, json: async () => next.body };
    };
    const flow = createSupervisorFlow({
        fetch,
        userId: 4,
        poolId: 1,
        getState: () => state,
        dispatch: (action) => {
            actions.push(action);

            if (action.type === 'state') {
                state = action.state;
            }
        },
        isOnline: () => online,
        newKey: () => `key-${++keyCount}`,
        now: () => 0,
        epochNow: () => 0,
        // A fake clock: every wait between resends ends at once and is recorded.
        sleep: (ms) => { waits.push(ms); return Promise.resolve(); },
    });

    return { flow, actions, calls, waits, state: () => state, statuses: (channel) => actions.filter((a) => a.type === 'status' && a.channel === channel).map((a) => a.status) };
}

const restockReply = { status: 201, body: { restock: { id: 5, seq: 5, actor: { id: 4, name: 'Dana' }, amount: 50, note: 'Delivery', recorded_at: '2026-10-17T13:00:00.000000Z' }, pool: { version: 5, remaining: 50 } } };
const correctionReply = (status = 201) => ({
    status,
    body: { correction: { id: 3, seq: 5, allocation_seq: 2, operator: { id: 1, name: 'Ana' }, kind: 'amend', from: 40, to: 20, reason: 'Counted wrong', supervisor: { id: 4, name: 'Dana' }, effective_at: '2026-10-17T14:00:00.000000Z' }, pool: { version: 5, remaining: 20 } },
});

test('a restock shows pending before the request is made, then applies the server answer', async () => {
    const { flow, actions, calls, state, statuses } = harness({ replies: [restockReply] });

    const result = await flow.restock('50', 'Delivery');

    assert.deepEqual(result, { started: true });
    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/api/pools/1/restocks');
    assert.equal(calls[0].options.headers['Idempotency-Key'], 'key-1');
    assert.deepEqual(JSON.parse(calls[0].options.body), { amount: 50, note: 'Delivery' });
    // The numbers have not moved when "pending" is shown, and the request is made after it.
    assert.equal(actions[0].type, 'status');
    assert.deepEqual(actions[0].status, { state: 'pending', key: 'key-1' });
    assert.equal(calls[0].actionsBefore, 1);
    assert.equal(state().remaining, 50);
    assert.equal(state().version, 5);
    assert.equal(actions.find((a) => a.type === 'state').effect, null);
    assert.equal(statuses('restock').at(-1).state, 'done');
});

test('an invalid restock sends nothing and says what is wrong', async () => {
    const { flow, calls, statuses } = harness();

    for (const amount of ['', '0', '-3', '1.5', 'abc']) {
        assert.deepEqual(await flow.restock(amount), { started: false, reason: 'invalid' });
    }

    assert.equal(calls.length, 0);
    assert.equal(statuses('restock').at(-1).message, 'Enter a whole number of units, 1 or more');
});

test('offline, nothing is sent, nothing is queued and the totals are left alone', async () => {
    const { flow, calls, actions, state } = harness({ online: false });

    assert.deepEqual(await flow.restock('50'), { started: false, reason: 'offline' });
    assert.deepEqual(await flow.correct({ allocationSeq: 2, kind: 'amend', amountText: '20', reasonText: 'x', currentUnits: 40 }), { started: false, reason: 'offline' });

    assert.equal(calls.length, 0);
    assert.deepEqual(actions.map((a) => a.status), [
        { state: 'offline', message: OFFLINE_MESSAGE },
        { state: 'offline', message: OFFLINE_MESSAGE },
    ]);
    assert.equal(state().remaining, 0);
});

const lost = () => new TypeError('network down');

test('a lost answer is unconfirmed after the automatic resends, and Retry sends the same key so the server counts it once', async () => {
    const { flow, calls, statuses, state } = harness({ replies: [lost(), lost(), lost(), lost(), { ...restockReply, status: 200 }] });

    await flow.restock('50', 'Delivery');

    assert.deepEqual(statuses('restock').at(-1), { state: 'unconfirmed', key: 'key-1' });
    assert.equal(calls.length, 4);
    assert.equal(state().remaining, 0);

    // A new submit would send a new key and could record twice: it is refused until Retry settles the first.
    assert.deepEqual(await flow.restock('50', 'Delivery'), { started: false, reason: 'unconfirmed' });

    assert.deepEqual(await flow.retry('restock'), { started: true });
    assert.equal(calls.length, 5);
    assert.equal(calls[4].options.headers['Idempotency-Key'], 'key-1');
    assert.equal(statuses('restock').at(-1).state, 'done');
    assert.equal(statuses('restock').at(-1).replayed, true);
    assert.equal(state().remaining, 50);
});

test('Retry while offline stays unconfirmed with the offline message and sends nothing', async () => {
    let online = true;
    let sent = 0;
    const statuses = [];
    const flow = createSupervisorFlow({
        fetch: async () => { sent += 1; throw new TypeError('down'); },
        sleep: async () => {},
        userId: 4,
        poolId: 1,
        getState: () => start(),
        dispatch: (action) => statuses.push(action.status),
        isOnline: () => online,
        newKey: () => 'k',
    });

    await flow.restock('5');
    online = false;

    assert.deepEqual(await flow.retry('restock'), { started: false, reason: 'offline' });
    assert.equal(sent, 4);
    assert.deepEqual(statuses.at(-1), { state: 'unconfirmed', key: 'k', message: OFFLINE_MESSAGE });
});

test('a correction that is accepted moves the version and asks for one snapshot', async () => {
    const { flow, actions, calls, state, statuses } = harness({ replies: [correctionReply()] });

    const result = await flow.correct({ allocationSeq: 2, kind: 'amend', amountText: '20', reasonText: ' Counted wrong ', currentUnits: 40 });

    assert.deepEqual(result, { started: true });
    assert.equal(calls[0].url, '/api/pools/1/allocations/2/corrections');
    assert.deepEqual(JSON.parse(calls[0].options.body), { kind: 'amend', amount: 20, reason: 'Counted wrong' });
    assert.equal(actions.find((a) => a.type === 'state').effect, 'snapshot');
    assert.equal(state().version, 5);
    // Remaining waits for the snapshot; the totals are not guessed.
    assert.equal(state().remaining, 0);
    assert.equal(statuses('correction').at(-1).state, 'done');
});

test('a void is sent without an amount', async () => {
    const { flow, calls } = harness({ replies: [correctionReply()] });

    await flow.correct({ allocationSeq: 2, kind: 'void', amountText: '', reasonText: 'Duplicate', currentUnits: 40 });

    assert.deepEqual(JSON.parse(calls[0].options.body), { kind: 'void', reason: 'Duplicate' });
});

test('a refused raise shows the units needed and free, changes nothing and frees the key for a new try', async () => {
    const refusal = { status: 422, body: { error: { code: 'insufficient_free_units', message: 'Needs 20 more units, 0 are free. Restock first.', details: { needed: 20, free: 0, allocation_seq: 2 } } } };
    const { flow, calls, state, statuses } = harness({ replies: [refusal, correctionReply()] });
    const raise = { allocationSeq: 2, kind: 'amend', amountText: '60', reasonText: 'Miscounted', currentUnits: 40 };

    await flow.correct(raise);

    assert.deepEqual(statuses('correction').at(-1), { state: 'refused', code: 'insufficient_free_units', message: 'Needs 20 more units, 0 are free. Restock first.' });
    assert.equal(refusalText({ needed: 20, free: 0 }), 'Needs 20 more units, 0 are free. Restock first.');
    assert.equal(state().version, 4);
    assert.equal(state().remaining, 0);

    // After a restock the supervisor tries again: a new action with a new key.
    await flow.correct(raise);

    assert.equal(calls[0].options.headers['Idempotency-Key'], 'key-1');
    assert.equal(calls[1].options.headers['Idempotency-Key'], 'key-2');
});

test('other refusals are shown in words', async () => {
    const noChange = { status: 422, body: { error: { code: 'validation_failed', message: 'The given data was invalid.', details: { fields: { amount: ['no_change'] } } } } };
    const voided = { status: 422, body: { error: { code: 'allocation_voided', message: 'Entry #2 was voided and cannot be corrected again.', details: { allocation_seq: 2 } } } };
    const { flow, statuses } = harness({ replies: [noChange, voided] });
    const form = { allocationSeq: 2, kind: 'amend', amountText: '20', reasonText: 'x', currentUnits: 40 };

    await flow.correct(form);
    await flow.correct(form);

    assert.deepEqual(statuses('correction').filter((s) => s.state === 'error').map((s) => [s.code, s.message]), [
        ['no_change', 'No change'],
        ['allocation_voided', 'Entry #2 was voided and cannot be corrected again.'],
    ]);
});

test('an unexpected server failure is unconfirmed, since the entry may have been stored', async () => {
    const failed = { status: 500, body: { error: { code: 'server_error' } } };
    const { flow, calls, statuses } = harness({ replies: [failed, failed, failed, failed] });

    await flow.restock('5');

    assert.equal(calls.length, 4, 'resent with the same key before giving up');
    assert.deepEqual(statuses('restock').at(-1), { state: 'unconfirmed', key: 'key-1' });
});

test('an invalid correction (blank reason, no change, void of nothing) sends nothing', async () => {
    const { flow, calls, statuses } = harness();

    await flow.correct({ allocationSeq: 2, kind: 'amend', amountText: '20', reasonText: ' ', currentUnits: 40 });
    await flow.correct({ allocationSeq: 2, kind: 'amend', amountText: '40', reasonText: 'x', currentUnits: 40 });
    await flow.correct({ allocationSeq: 2, kind: 'void', amountText: '', reasonText: 'x', currentUnits: 0 });

    assert.equal(calls.length, 0);
    assert.deepEqual(statuses('correction').map((s) => s.message), ['Give a reason', 'No change', 'Nothing to void']);
});

test('the two channels do not block each other', async () => {
    const { flow, calls } = harness({ replies: [restockReply, correctionReply()] });

    await Promise.all([
        flow.restock('50'),
        flow.correct({ allocationSeq: 2, kind: 'amend', amountText: '20', reasonText: 'x', currentUnits: 40 }),
    ]);

    assert.equal(calls.length, 2);
});

// M4: the same automatic-then-manual rules as an operator request (FR21.7).

test('a lost restock answer is resent automatically with the same key and then settles once', async () => {
    const { flow, calls, waits, statuses, state } = harness({ replies: [lost(), lost(), { ...restockReply, status: 200 }] });

    await flow.restock('50', 'Delivery');

    assert.equal(calls.length, 3);
    assert.deepEqual(waits, [1000, 2000]);
    assert.deepEqual([...new Set(calls.map((call) => call.options.headers['Idempotency-Key']))], ['key-1']);
    assert.deepEqual(statuses('restock').map((status) => status.state), ['pending', 'resending', 'resending', 'resending', 'resending', 'done']);
    assert.deepEqual(statuses('restock')[1], { state: 'resending', key: 'key-1', attempt: 1, of: 3, phase: 'waiting' });
    assert.equal(state().remaining, 50);
});

test('a correction gives up after three resends and offers Retry', async () => {
    const { flow, calls, waits, statuses, state } = harness({ replies: [lost(), lost(), lost(), lost(), correctionReply(200)] });
    const form = { allocationSeq: 2, kind: 'amend', amountText: '20', reasonText: 'Counted wrong', currentUnits: 40 };

    await flow.correct(form);

    assert.equal(calls.length, 4);
    assert.deepEqual(waits, [1000, 2000, 4000]);
    assert.deepEqual(statuses('correction').at(-1), { state: 'unconfirmed', key: 'key-1' });
    assert.equal(state().version, 4, 'no total moved');

    assert.deepEqual(await flow.retry('correction'), { started: true });
    assert.equal(statuses('correction').find((status) => status.state === 'retrying').key, 'key-1');
    assert.equal(calls[4].options.headers['Idempotency-Key'], 'key-1');
    assert.equal(statuses('correction').at(-1).state, 'done');
});

test('a refused correction is never resent', async () => {
    const refusal = { status: 422, body: { error: { code: 'insufficient_free_units', message: 'x', details: { needed: 20, free: 0, allocation_seq: 2 } } } };
    const { flow, calls, waits, statuses } = harness({ replies: [refusal] });

    await flow.correct({ allocationSeq: 2, kind: 'amend', amountText: '60', reasonText: 'Miscounted', currentUnits: 40 });

    assert.equal(calls.length, 1);
    assert.deepEqual(waits, []);
    assert.equal(statuses('correction').at(-1).state, 'refused');
});

test('503 pool_busy is resent automatically', async () => {
    const busy = { status: 503, body: { error: { code: 'pool_busy', message: 'The pool is busy. Try again.', details: {} } } };
    const { flow, calls, statuses } = harness({ replies: [busy, restockReply] });

    await flow.restock('50');

    assert.equal(calls.length, 2);
    assert.equal(calls[1].options.headers['Idempotency-Key'], 'key-1');
    assert.equal(statuses('restock').at(-1).state, 'done');
});

test('the two channels resend independently', async () => {
    // One channel waits for its next resend (the clock never ends) while the other is answered at once.
    const answers = [];
    const calls = [];
    const statuses = [];
    const flow = createSupervisorFlow({
        fetch: async (url, options) => {
            calls.push({ url, key: options.headers['Idempotency-Key'] });

            if (url.endsWith('/restocks')) {
                throw new TypeError('down');
            }

            return { status: 201, json: async () => correctionReply().body };
        },
        sleep: () => new Promise(() => {}),
        userId: 4,
        poolId: 1,
        getState: () => start(),
        dispatch: (action) => { answers.push(action); if (action.type === 'status') { statuses.push(action); } },
        isOnline: () => true,
        newKey: () => `key-${calls.length + 1}`,
    });

    const restock = flow.restock('5');
    const correction = flow.correct({ allocationSeq: 2, kind: 'amend', amountText: '20', reasonText: 'x', currentUnits: 40 });
    await correction;

    assert.equal(statuses.filter((a) => a.channel === 'correction').at(-1).status.state, 'done');
    assert.equal(statuses.filter((a) => a.channel === 'restock').at(-1).status.state, 'resending', 'the restock still waits to resend');

    flow.dispose();
    await restock;
    assert.equal(calls.filter((call) => call.url.endsWith('/restocks')).length, 1, 'dispose stopped the waiting resend');
});

test('going offline during the wait stops a restock as not confirmed', async () => {
    let online = true;
    const statuses = [];
    const flow = createSupervisorFlow({
        fetch: async () => { throw new TypeError('down'); },
        sleep: () => new Promise(() => {}),
        userId: 4,
        poolId: 1,
        getState: () => start(),
        dispatch: (action) => statuses.push(action.status),
        isOnline: () => online,
        newKey: () => 'k',
    });

    const restock = flow.restock('5');
    await new Promise((resolve) => setImmediate(resolve));
    online = false;
    flow.goOffline();
    await restock;

    assert.deepEqual(statuses.at(-1), { state: 'unconfirmed', key: 'k', message: OFFLINE_MESSAGE });
});
