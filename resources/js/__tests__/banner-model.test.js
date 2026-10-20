import assert from 'node:assert/strict';
import { test } from 'node:test';

import { bannerFor } from '../banner-model.js';

const SERVER_NOW = Date.parse('2026-10-20T08:00:00Z');
const EXPIRES = '2026-10-21T10:00:00.000000Z';

const done = (requested, granted, expiresAt = null) => ({ state: 'done', requested, granted, expiresAt });

/** One status for each of the states the screen can show on the request banner. */
const STATES = {
    pending: { state: 'pending', amount: 3, key: 'k' },
    resending: { state: 'resending', amount: 3, key: 'k', attempt: 2, of: 3, phase: 'waiting' },
    retrying: { state: 'retrying', amount: 3, key: 'k' },
    unconfirmed: { state: 'unconfirmed', amount: 3, key: 'k' },
    offline: { state: 'offline', message: 'You are offline — nothing was sent' },
    done: done(3, 3),
};

const context = { serverNowMs: SERVER_NOW, channel: 'request' };

test('every state has words and a shape', () => {
    const everything = [
        ...Object.values(STATES),
        done(6, 4),
        done(3, 0),
        { state: 'error', status: 422, code: 'validation_failed', message: 'bad' },
        { state: 'error', status: 500, code: 'error', message: 'Something went wrong.' },
        { state: 'refused', code: 'insufficient_free_units', message: 'Needs 5 more units, 2 are free. Restock first.' },
    ];

    for (const status of everything) {
        const result = bannerFor(status, context);

        assert.ok(result.text.length > 0, `${status.state} has words`);
        assert.ok(['ring', 'cycle', 'dot-ring', 'check', 'triangle', 'cross', 'bar-square'].includes(result.shape), `${status.state} has a shape`);
        assert.ok(result.kind, `${status.state} has a kind`);
    }
});

test('no two different states share both the same words and the same shape', () => {
    const seen = new Map();

    for (const [name, status] of Object.entries({ ...STATES, partial: done(6, 4), none: done(3, 0), refused: { state: 'error', code: 'x', message: 'no' } })) {
        const { text, shape } = bannerFor(status, context);
        const signature = `${text}|${shape}`;

        assert.equal(seen.has(signature), false, `${name} repeats ${seen.get(signature)}`);
        seen.set(signature, name);
    }
});

test('full, partial and zero grants read 4 granted, 4 of 6 granted and None granted', () => {
    assert.equal(bannerFor(done(4, 4), context).text, '4 granted');
    assert.equal(bannerFor(done(6, 4), context).text, '4 of 6 granted');
    assert.equal(bannerFor(done(3, 0), context).text, 'None granted — 0 of 3, none left');

    assert.deepEqual(['success', 'partial', 'none'], [done(4, 4), done(6, 4), done(3, 0)].map((status) => bannerFor(status, context).kind));
});

test('a temporary grant adds the return time', () => {
    assert.equal(bannerFor({ ...done(4, 4, EXPIRES) }, { ...context, serverNowMs: SERVER_NOW }).text.startsWith('4 granted · returns '), true);
    assert.equal(bannerFor(done(6, 4, EXPIRES), context).text.startsWith('4 of 6 granted · returns '), true);
});

test('Retry is offered only when not confirmed', () => {
    for (const [name, status] of Object.entries(STATES)) {
        assert.equal(bannerFor(status, context).action, name === 'unconfirmed' ? 'retry' : null, name);
    }

    assert.equal(bannerFor({ state: 'error', code: 'x', message: 'no' }, context).action, null);
});

test('the offline note rides on Not confirmed', () => {
    const result = bannerFor({ state: 'unconfirmed', message: 'You are offline — nothing was sent' }, context);

    assert.equal(result.text, 'Not confirmed');
    assert.equal(result.note, 'You are offline — nothing was sent');
});

test("a refusal starts with Refused and carries the server's reason", () => {
    const result = bannerFor({ state: 'error', status: 409, code: 'idempotency_key_conflict', message: 'Key was used before' }, context);

    assert.equal(result.kind, 'refused');
    assert.equal(result.text, 'Refused — Key was used before');
    assert.equal(bannerFor({ state: 'refused', message: 'Needs 5 more units, 2 are free. Restock first.' }, context).text, 'Refused — Needs 5 more units, 2 are free. Restock first.');
});

test('pending, offline, resending, unconfirmed, retrying and done differ by words alone and by shape alone', () => {
    const banners = ['pending', 'offline', 'resending', 'unconfirmed', 'retrying', 'done'].map((name) => bannerFor(STATES[name], context));

    assert.equal(new Set(banners.map((banner) => banner.text)).size, 6, 'six different words');
    assert.equal(new Set(banners.map((banner) => banner.shape)).size, 6, 'six different shapes');
});

test('Pending and Retrying are told apart, and the automatic resend shows its attempt', () => {
    assert.equal(bannerFor(STATES.pending, context).text, 'Pending');
    assert.equal(bannerFor(STATES.retrying, context).text, 'Retrying');
    assert.equal(bannerFor(STATES.resending, context).text, 'Retrying automatically, attempt 2 of 3');
});

test('idle, invalid and unknown states show no banner', () => {
    for (const state of ['idle', 'invalid', 'nonsense']) {
        assert.equal(bannerFor({ state, message: 'm' }, context), null, state);
    }

    assert.equal(bannerFor(undefined, context), null);
});

test('restock and correction use the same kinds', () => {
    const restock = { channel: 'restock', doneText: (status) => `Added ${status.result.restock.amount} units`, serverNowMs: SERVER_NOW };
    const ok = bannerFor({ state: 'done', result: { restock: { amount: 50 } } }, restock);

    assert.deepEqual(ok, { kind: 'success', shape: 'check', text: 'Added 50 units', note: '', action: null });

    for (const channel of ['restock', 'correction']) {
        assert.equal(bannerFor(STATES.pending, { channel }).kind, 'progress');
        assert.equal(bannerFor(STATES.resending, { channel }).kind, 'progress');
        assert.equal(bannerFor(STATES.unconfirmed, { channel }).kind, 'unconfirmed');
        assert.equal(bannerFor(STATES.offline, { channel }).kind, 'offline');
        assert.equal(bannerFor({ state: 'refused', message: 'Needs 1 more units, 0 are free. Restock first.' }, { channel }).kind, 'refused');
    }
});
