import { test } from 'node:test';
import assert from 'node:assert/strict';

// The supervisor's calls in api.js (M3): restock, correction and audit.
import { OFFLINE_MESSAGE, fetchAudit, postCorrection, postRestock } from '../api.js';

function recordingFetch(status, body) {
    const calls = [];
    const fetch = async (url, options) => {
        calls.push({ url, options });

        return { status, json: async () => body };
    };

    return { fetch, calls };
}

test('postRestock sends the amount and note to the restocks path with the user and a key', async () => {
    const { fetch, calls } = recordingFetch(201, { restock: { seq: 5 }, pool: { version: 5, remaining: 50 } });

    const result = await postRestock({ fetch, userId: 4, poolId: 1, amount: 50, note: 'Delivery', key: 'k-1' });

    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/api/pools/1/restocks');
    assert.equal(calls[0].options.method, 'POST');
    assert.equal(calls[0].options.headers['X-Demo-User'], '4');
    assert.equal(calls[0].options.headers['Idempotency-Key'], 'k-1');
    assert.equal(calls[0].options.headers['Content-Type'], 'application/json');
    assert.deepEqual(JSON.parse(calls[0].options.body), { amount: 50, note: 'Delivery' });
    assert.deepEqual(result, { status: 201, body: { restock: { seq: 5 }, pool: { version: 5, remaining: 50 } }, key: 'k-1' });
});

test('postRestock leaves the note out when there is none, and makes a fresh key for each action', async () => {
    const { fetch, calls } = recordingFetch(201, {});

    await postRestock({ fetch, userId: 4, poolId: 1, amount: 5 });
    await postRestock({ fetch, userId: 4, poolId: 1, amount: 5, note: '' });

    assert.deepEqual(JSON.parse(calls[0].options.body), { amount: 5 });
    assert.deepEqual(JSON.parse(calls[1].options.body), { amount: 5 });
    assert.notEqual(calls[0].options.headers['Idempotency-Key'], calls[1].options.headers['Idempotency-Key']);
});

test('postCorrection sends an amend with its amount and a void without one', async () => {
    const { fetch, calls } = recordingFetch(201, {});

    await postCorrection({ fetch, userId: 4, poolId: 1, allocationSeq: 2, kind: 'amend', amount: 20, reason: 'Counted wrong', key: 'k-2' });
    await postCorrection({ fetch, userId: 4, poolId: 1, allocationSeq: 2, kind: 'void', amount: 9, reason: 'Duplicate', key: 'k-3' });

    assert.equal(calls[0].url, '/api/pools/1/allocations/2/corrections');
    assert.deepEqual(JSON.parse(calls[0].options.body), { kind: 'amend', amount: 20, reason: 'Counted wrong' });
    assert.deepEqual(JSON.parse(calls[1].options.body), { kind: 'void', reason: 'Duplicate' });
    assert.equal(calls[1].options.headers['Idempotency-Key'], 'k-3');
});

test('a refusal comes back with its status and details, not as a thrown error', async () => {
    const refusal = { error: { code: 'insufficient_free_units', message: 'Needs 20 more units, 0 are free. Restock first.', details: { needed: 20, free: 0, allocation_seq: 2 } } };
    const { fetch } = recordingFetch(422, refusal);

    const result = await postCorrection({ fetch, userId: 4, poolId: 1, allocationSeq: 2, kind: 'amend', amount: 60, reason: 'x', key: 'k' });

    assert.equal(result.status, 422);
    assert.deepEqual(result.body.error.details, { needed: 20, free: 0, allocation_seq: 2 });
});

test('a reply that is not JSON gives a status and no body', async () => {
    const fetch = async () => ({ status: 502, json: async () => { throw new Error('not json'); } });

    assert.deepEqual(await postRestock({ fetch, userId: 4, poolId: 1, amount: 5, key: 'k' }), { status: 502, body: null, key: 'k' });
});

test('offline, nothing is sent and the offline message comes back with the key', async () => {
    const { fetch, calls } = recordingFetch(201, {});

    const restock = await postRestock({ fetch, userId: 4, poolId: 1, amount: 5, key: 'k', isOnline: () => false });
    const correction = await postCorrection({ fetch, userId: 4, poolId: 1, allocationSeq: 2, kind: 'void', reason: 'x', key: 'k', isOnline: () => false });

    assert.equal(calls.length, 0);
    assert.deepEqual(restock, { offline: true, message: OFFLINE_MESSAGE, key: 'k' });
    assert.equal(correction.offline, true);
});

test('a lost connection rejects, so the caller can offer a retry with the same key', async () => {
    const fetch = async () => { throw new TypeError('network down'); };

    await assert.rejects(() => postRestock({ fetch, userId: 4, poolId: 1, amount: 5, key: 'k' }), /network down/);
});

test('a request with no answer is aborted at the timeout', async () => {
    let aborted = false;
    const fetch = (url, { signal }) => new Promise((resolve, reject) => {
        signal.addEventListener('abort', () => { aborted = true; reject(new Error('aborted')); });
    });

    await assert.rejects(() => postRestock({ fetch, userId: 4, poolId: 1, amount: 5, key: 'k', timeoutMs: 10 }), /aborted/);
    assert.equal(aborted, true);
});

test('fetchAudit reads the audit path with the user header and throws on a refusal', async () => {
    const { fetch, calls } = recordingFetch(200, { corrections: [], hindsight: [] });
    const ok = { ok: true, json: async () => ({ corrections: [], hindsight: [] }) };

    assert.deepEqual(await fetchAudit({ fetch: async (url, options) => { calls.push({ url, options }); return ok; }, userId: 4, poolId: 1 }), { corrections: [], hindsight: [] });
    assert.equal(calls[0].url, '/api/pools/1/audit');
    assert.equal(calls[0].options.headers['X-Demo-User'], '4');

    await assert.rejects(() => fetchAudit({ fetch: async () => ({ ok: false, status: 403, json: async () => ({}) }), userId: 1, poolId: 1 }), /403/);
});
