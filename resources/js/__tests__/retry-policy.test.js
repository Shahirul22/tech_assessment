import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createRetryRunner } from '../retry-policy.js';

const answered = (value = 'ok') => ({ kind: 'answered', value });
const noAnswer = { kind: 'no-answer' };

/** A runner with a fake clock: every sleep resolves at once and is recorded. */
function setup({ online = () => true, onResending } = {}) {
    const waits = [];
    const events = [];
    const runner = createRetryRunner({
        isOnline: online,
        sleep: async (ms) => {
            waits.push(ms);
        },
        onResending: (info) => {
            events.push(info);
            onResending?.(info);
        },
    });

    return { runner, waits, events };
}

/** A send that replays the given results in order, then keeps giving no answer. */
function sender(...results) {
    const calls = { count: 0 };
    const sendOnce = async () => results[calls.count++] ?? noAnswer;

    return { calls, sendOnce };
}

test('sends once and stops when the first send is answered', async () => {
    const { runner, waits } = setup();
    const { calls, sendOnce } = sender(answered('first'));

    assert.deepEqual(await runner.run(sendOnce), { outcome: 'answered', value: 'first' });
    assert.equal(calls.count, 1);
    assert.deepEqual(waits, []);
});

test('makes exactly 4 sends in total when nothing is ever answered, then gives up', async () => {
    const { runner } = setup();
    const { calls, sendOnce } = sender();

    assert.deepEqual(await runner.run(sendOnce), { outcome: 'gave-up' });
    assert.equal(calls.count, 4);
});

test('waits 1 s, 2 s, 4 s in that order', async () => {
    const { runner, waits } = setup();

    await runner.run(sender().sendOnce);

    assert.deepEqual(waits, [1000, 2000, 4000]);
});

test('an answer on the second resend stops the loop', async () => {
    const { runner, waits } = setup();
    const { calls, sendOnce } = sender(noAnswer, noAnswer, answered('late'));

    assert.deepEqual(await runner.run(sendOnce), { outcome: 'answered', value: 'late' });
    assert.equal(calls.count, 3);
    assert.deepEqual(waits, [1000, 2000]);
});

test('stops with stopped-offline when the device goes offline before a resend, sending nothing more', async () => {
    let online = true;
    const { runner, waits } = setup({ online: () => online });
    const { calls, sendOnce } = sender();
    const first = async () => {
        const result = await sendOnce();
        online = false;

        return result;
    };

    assert.deepEqual(await runner.run(first), { outcome: 'stopped-offline' });
    assert.equal(calls.count, 1);
    assert.deepEqual(waits, []);
});

test('abortWait during a wait ends the round as stopped-offline when offline', async () => {
    let online = true;
    const { calls, sendOnce } = sender();
    // A sleep that never finishes by itself.
    const runner = createRetryRunner({ isOnline: () => online, sleep: () => new Promise(() => {}) });
    const round = runner.run(sendOnce);

    await new Promise((resolve) => setImmediate(resolve));
    online = false;
    runner.abortWait();

    assert.deepEqual(await round, { outcome: 'stopped-offline' });
    assert.equal(calls.count, 1);
});

test('dispose during a wait returns disposed and sends nothing more', async () => {
    const { calls, sendOnce } = sender();
    const runner = createRetryRunner({ isOnline: () => true, sleep: () => new Promise(() => {}) });
    const round = runner.run(sendOnce);

    await new Promise((resolve) => setImmediate(resolve));
    runner.dispose();

    assert.deepEqual(await round, { outcome: 'disposed' });
    assert.equal(calls.count, 1);
});

test('onResending reports attempt 1 to 3 of 3, waiting then sending', async () => {
    const { runner, events } = setup();

    await runner.run(sender().sendOnce);

    assert.deepEqual(events, [
        { attempt: 1, of: 3, phase: 'waiting' },
        { attempt: 1, of: 3, phase: 'sending' },
        { attempt: 2, of: 3, phase: 'waiting' },
        { attempt: 2, of: 3, phase: 'sending' },
        { attempt: 3, of: 3, phase: 'waiting' },
        { attempt: 3, of: 3, phase: 'sending' },
    ]);
});
