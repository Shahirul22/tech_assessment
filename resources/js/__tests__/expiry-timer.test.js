import { test } from 'node:test';
import assert from 'node:assert/strict';

import { createExpiryTimer } from '../expiry-timer.js';

/** A timer that only moves when told to. */
function fakeClock(start = 1_000_000) {
    let now = start;
    let nextId = 1;
    const pending = new Map();

    return {
        nowMs: () => now,
        setTimer: (fn, delay) => {
            const id = nextId++;
            pending.set(id, { fn, at: now + delay, delay });

            return id;
        },
        clearTimer: (id) => pending.delete(id),
        pending,
        advance(ms) {
            now += ms;

            for (const [id, timer] of [...pending]) {
                if (timer.at <= now) {
                    pending.delete(id);
                    timer.fn();
                }
            }
        },
    };
}

function timerWith(clock, calls) {
    return createExpiryTimer({ onDue: () => calls.push(clock.nowMs()), ...clock });
}

test('fires once, 250 ms after the due instant', () => {
    const clock = fakeClock();
    const calls = [];
    const timer = timerWith(clock, calls);

    timer.schedule(clock.nowMs() + 5000);

    clock.advance(5249);
    assert.equal(calls.length, 0);

    clock.advance(1);
    assert.equal(calls.length, 1);

    clock.advance(60_000);
    assert.equal(calls.length, 1);
});

test('a new schedule replaces the old one', () => {
    const clock = fakeClock();
    const calls = [];
    const timer = timerWith(clock, calls);

    timer.schedule(clock.nowMs() + 5000);
    timer.schedule(clock.nowMs() + 1000);

    assert.equal(clock.pending.size, 1);

    clock.advance(1250);
    assert.equal(calls.length, 1);

    clock.advance(10_000);
    assert.equal(calls.length, 1);
});

test('a due instant in the past fires at once', () => {
    const clock = fakeClock();
    const calls = [];
    const timer = timerWith(clock, calls);

    timer.schedule(clock.nowMs() - 60_000);

    assert.equal([...clock.pending.values()][0].delay, 0);

    clock.advance(0);
    assert.equal(calls.length, 1);
});

test('null arms nothing, and cancels what was armed', () => {
    const clock = fakeClock();
    const calls = [];
    const timer = timerWith(clock, calls);

    timer.schedule(null);
    assert.equal(clock.pending.size, 0);

    timer.schedule(clock.nowMs() + 1000);
    timer.schedule(null);
    assert.equal(clock.pending.size, 0);

    clock.advance(10_000);
    assert.equal(calls.length, 0);
});

test('a delay beyond the timer limit is cut and re-armed until it is due', () => {
    const clock = fakeClock();
    const calls = [];
    const timer = timerWith(clock, calls);
    const thirtyDays = 30 * 24 * 3600 * 1000;

    timer.schedule(clock.nowMs() + thirtyDays);

    assert.equal([...clock.pending.values()][0].delay, 2_147_000_000);

    clock.advance(2_147_000_000);
    assert.equal(calls.length, 0);
    assert.equal(clock.pending.size, 1, 're-armed');

    clock.advance(thirtyDays - 2_147_000_000 + 250);
    assert.equal(calls.length, 1);
});

test('dispose cancels a pending timer', () => {
    const clock = fakeClock();
    const calls = [];
    const timer = timerWith(clock, calls);

    timer.schedule(clock.nowMs() + 1000);
    timer.dispose();

    assert.equal(clock.pending.size, 0);

    clock.advance(10_000);
    assert.equal(calls.length, 0);
});
