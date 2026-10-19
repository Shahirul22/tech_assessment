import { test } from 'node:test';
import assert from 'node:assert/strict';

import { GAP_MS, applyEvent, applyOlderPage, applyOwnResult, applySnapshot, checkGap, initialState, nextExpiryAt, oldestCursor } from '../pool-state.js';

const entry = (seq, extra = {}) => ({ seq, cursor: String(seq), kind: 'allocation', operator: { id: 1, name: 'Ana' }, requested: 3, granted: 3, outcome: 'full', ...extra });
const event = (version, remaining, extra = {}) => ({ version, remaining, entry: entry(version, extra) });
const snapshot = (version, remaining, seqs = []) => ({ version, remaining, log: seqs.map((seq) => entry(seq)), log_has_more: false });

const start = () => initialState(snapshot(10, 90, [10, 9, 8]));
const seqs = (state) => state.log.map((e) => e.seq);

test('applies an event that is exactly the next version', () => {
    const { state, effect } = applyEvent(start(), event(11, 87), 0);

    assert.equal(state.version, 11);
    assert.equal(state.remaining, 87);
    assert.deepEqual(seqs(state), [11, 10, 9, 8]);
    assert.equal(effect, null);
});

test('ignores a duplicate and an older event', () => {
    const before = start();

    const duplicate = applyEvent(before, event(10, 5), 0);
    const older = applyEvent(before, event(7, 5), 0);

    for (const { state, effect } of [duplicate, older]) {
        assert.equal(state.version, 10);
        assert.equal(state.remaining, 90);
        assert.deepEqual(seqs(state), [10, 9, 8]);
        assert.equal(effect, null);
    }
});

test('resolves an out-of-order pair without a snapshot', () => {
    const first = applyEvent(start(), event(12, 84), 0);

    // 12 arrives before 11: held back, and the number on screen does not change yet.
    assert.equal(first.effect, 'wait');
    assert.equal(first.state.version, 10);
    assert.equal(first.state.remaining, 90);
    assert.equal(first.state.buffered.length, 1);
    assert.equal(first.state.gapSince, 0);

    const second = applyEvent(first.state, event(11, 87), 50);

    assert.equal(second.effect, null);
    assert.equal(second.state.version, 12);
    assert.equal(second.state.remaining, 84);
    assert.deepEqual(seqs(second.state), [12, 11, 10, 9, 8]);
    assert.deepEqual(second.state.buffered, []);
    assert.equal(second.state.gapSince, null);
});

test('drains a longer run of buffered events once the missing one arrives', () => {
    let state = start();
    for (const [version, remaining, at] of [[14, 78, 0], [13, 81, 10], [12, 84, 20]]) {
        state = applyEvent(state, event(version, remaining), at).state;
    }
    assert.equal(state.version, 10);

    const { state: done } = applyEvent(state, event(11, 87), 30);

    assert.equal(done.version, 14);
    assert.equal(done.remaining, 78);
    assert.deepEqual(seqs(done), [14, 13, 12, 11, 10, 9, 8]);
    assert.deepEqual(done.buffered, []);
});

test('asks for exactly one snapshot when a gap is not filled within 500 ms', () => {
    const waiting = applyEvent(start(), event(12, 84), 1000);
    assert.equal(waiting.effect, 'wait');

    assert.equal(checkGap(waiting.state, 1000 + GAP_MS - 1).effect, 'wait');

    const asked = checkGap(waiting.state, 1000 + GAP_MS);
    assert.equal(asked.effect, 'snapshot');
    assert.equal(asked.state.snapshotPending, true);

    // Coalesced: while the snapshot is in flight, neither the timer nor another gap asks again.
    assert.equal(checkGap(asked.state, 1700).effect, null);
    const another = applyEvent(asked.state, event(14, 78), 1800);
    assert.equal(another.effect, null);
    assert.equal(another.state.buffered.length, 2);
});

test('asks for the snapshot from applyEvent too when a later event arrives after the wait', () => {
    const waiting = applyEvent(start(), event(12, 84), 0);

    const late = applyEvent(waiting.state, event(13, 81), GAP_MS);

    assert.equal(late.effect, 'snapshot');
    assert.equal(late.state.snapshotPending, true);
});

test('a snapshot replaces the state, drops what it covers and keeps going with what is next', () => {
    const waiting = applyEvent(applyEvent(start(), event(12, 84), 0).state, event(15, 72), 10).state;
    const pending = checkGap(waiting, 600).state;

    // The snapshot is at version 13: the buffered 12 is covered, the buffered 15 still has a gap before it.
    const { state, effect } = applySnapshot(pending, snapshot(13, 81, [13, 12, 11]), 700);

    // A gap remains (version 14 is missing), so the caller keeps its gap timer running.
    assert.equal(effect, 'wait');
    assert.equal(state.version, 13);
    assert.equal(state.remaining, 81);
    assert.deepEqual(seqs(state), [13, 12, 11]);
    assert.equal(state.snapshotPending, false);
    assert.deepEqual(state.buffered.map((e) => e.version), [15]);
    assert.equal(state.gapSince, 700);
});

test('a snapshot followed by the next buffered event applies it', () => {
    const buffered = applyEvent(start(), event(12, 84), 0).state;

    const { state } = applySnapshot(buffered, snapshot(11, 87, [11, 10, 9]), 5);

    assert.equal(state.version, 12);
    assert.equal(state.remaining, 84);
    assert.deepEqual(seqs(state), [12, 11, 10, 9]);
    assert.deepEqual(state.buffered, []);
    assert.equal(state.gapSince, null);
});

test('never goes back: a stale snapshot is ignored', () => {
    const current = applyEvent(start(), event(11, 87), 0).state;

    const { state } = applySnapshot({ ...current, snapshotPending: true }, snapshot(10, 90, [10, 9, 8]), 5);

    assert.equal(state.version, 11);
    assert.equal(state.remaining, 87);
    assert.deepEqual(seqs(state), [11, 10, 9, 8]);
    assert.equal(state.snapshotPending, false);
});

test('an own result applies like an event, and the same broadcast afterwards adds nothing', () => {
    const own = {
        allocation: { ...entry(11, { granted: 3 }), kind: undefined, effective_at: '2026-10-17T10:00:00.000000Z' },
        pool: { version: 11, remaining: 87 },
    };

    const afterHttp = applyOwnResult(start(), own, 0);
    assert.equal(afterHttp.state.version, 11);
    assert.equal(afterHttp.state.remaining, 87);
    assert.deepEqual(seqs(afterHttp.state), [11, 10, 9, 8]);

    // The broadcast of the same entry arrives later: ignored, one row only, same numbers.
    const afterBroadcast = applyEvent(afterHttp.state, event(11, 87), 20);
    assert.deepEqual(seqs(afterBroadcast.state), [11, 10, 9, 8]);
    assert.equal(afterBroadcast.state.remaining, 87);
    assert.equal(afterBroadcast.state.version, 11);
});

test('the broadcast first and the own result second also leaves one row', () => {
    const afterBroadcast = applyEvent(start(), event(11, 87), 0).state;
    const own = { allocation: { ...entry(11), effective_at: 'x' }, pool: { version: 11, remaining: 87 } };

    const { state } = applyOwnResult(afterBroadcast, own, 10);

    assert.deepEqual(seqs(state), [11, 10, 9, 8]);
    assert.equal(state.remaining, 87);
    assert.equal(state.log.filter((e) => e.seq === 11).length, 1);
});

test('an own result that is ahead of the screen waits for what is missing', () => {
    const own = { allocation: { ...entry(13), effective_at: 'x' }, pool: { version: 13, remaining: 81 } };

    const { state, effect } = applyOwnResult(start(), own, 0);

    assert.equal(effect, 'wait');
    assert.equal(state.version, 10);
    assert.equal(state.remaining, 90);
});

test('an own result is turned into the allocation log entry shape', () => {
    const own = {
        allocation: {
            id: 57, seq: 11, operator: { id: 1, name: 'Ana' }, type: 'consumed', requested: 4, granted: 3, outcome: 'partial',
            tier_at_log: 'bronze', effective_at: '2026-10-17T10:00:00.123456Z', recorded_at: '2026-10-17T10:00:00.130000Z',
        },
        pool: { version: 11, remaining: 87 },
    };

    const { state } = applyOwnResult(start(), own, 0);

    assert.deepEqual(state.log[0], {
        seq: 11, cursor: '11', kind: 'allocation', at: '2026-10-17T10:00:00.123456Z', operator: { id: 1, name: 'Ana' },
        type: 'consumed', requested: 4, granted: 3, outcome: 'partial', expires_at: null,
    });
});

test('does not change the state it was given', () => {
    const before = start();
    const frozen = structuredClone(before);

    applyEvent(before, event(11, 87), 0);
    applyEvent(before, event(13, 81), 0);
    applySnapshot(before, snapshot(12, 84, [12, 11]), 0);
    checkGap(before, 0);

    assert.deepEqual(before, frozen);
});

test('upserts a log entry by seq instead of adding a second row', () => {
    const state = applyEvent(start(), event(11, 87, { granted: 3 }), 0).state;

    // The same seq arrives again with other content, as when a snapshot overlaps a broadcast.
    const { state: after } = applySnapshot(state, { ...snapshot(11, 87, [11, 10]), log: [entry(11, { granted: 2 }), entry(10)] }, 0);

    assert.equal(after.log.filter((e) => e.seq === 11).length, 1);
    assert.equal(after.log[0].granted, 2);
});

test('appends an older page below what is shown, without touching the numbers', () => {
    const state = initialState({ ...snapshot(10, 90, [10, 9, 8]), log_has_more: true });

    const next = applyOlderPage(state, { entries: [entry(7), entry(6)], has_more: true });

    assert.deepEqual(seqs(next), [10, 9, 8, 7, 6]);
    assert.equal(next.logHasMore, true);
    assert.equal(next.version, 10);
    assert.equal(next.remaining, 90);
});

test('an older page that overlaps what is shown adds no duplicate, and the last page hides the button', () => {
    const state = initialState({ ...snapshot(10, 90, [10, 9, 8]), log_has_more: true });

    const next = applyOlderPage(state, { entries: [entry(8), entry(7), entry(6)], has_more: false });

    assert.deepEqual(seqs(next), [10, 9, 8, 7, 6]);
    assert.equal(next.log.filter((e) => e.seq === 8).length, 1);
    assert.equal(next.logHasMore, false);
});

test('a live event that arrives while older pages are open keeps them', () => {
    let state = applyOlderPage(initialState({ ...snapshot(10, 90, [10, 9, 8]), log_has_more: true }), { entries: [entry(7)], has_more: false });

    state = applyEvent(state, event(11, 87), 0).state;

    assert.deepEqual(seqs(state), [11, 10, 9, 8, 7]);
    assert.equal(state.logHasMore, false);
});

test('an older page does not mutate the state it was given', () => {
    const before = initialState({ ...snapshot(10, 90, [10, 9, 8]), log_has_more: true });
    const frozen = structuredClone(before);

    applyOlderPage(before, { entries: [entry(7)], has_more: false });

    assert.deepEqual(before, frozen);
});

// M2: cursors, viewer, server offset and the next expiry.

const NOW = Date.parse('2026-10-19T10:00:00Z');
const expiryEntry = (anchor, allocationSeq) => ({
    seq: null, cursor: `${anchor}x${allocationSeq}`, kind: 'expiry', at: 'x', operator: { id: 1, name: 'Ana' }, allocation_seq: allocationSeq, units: 3,
});
const viewerBlock = (status, holdings = []) => ({ operator_id: 1, status, tier: 'bronze', window_seconds: 86400, holdings });
const fullSnapshot = (version, extra = {}) => ({
    ...snapshot(version, 90, [version, version - 1]),
    server_time: '2026-10-19T10:00:00.000000Z',
    next_expiry_at: null,
    viewer: viewerBlock('standard'),
    ...extra,
});

test('keeps the viewer, the server offset and the next expiry from a snapshot', () => {
    const state = initialState(fullSnapshot(10, { next_expiry_at: '2026-10-20T10:00:00.000000Z' }), NOW - 5000);

    assert.deepEqual(state.viewer, viewerBlock('standard'));
    assert.equal(state.serverOffsetMs, 5000);
    assert.equal(nextExpiryAt(state), Date.parse('2026-10-20T10:00:00Z'));
    assert.equal(nextExpiryAt(initialState(fullSnapshot(10), NOW)), null);
});

test('an expiry entry that arrives in two snapshots is one row, ordered by its cursor', () => {
    const withExpiry = (version) => fullSnapshot(version, { log: [entry(11), expiryEntry(10, 4), entry(10), entry(9)] });

    const first = applySnapshot(initialState(fullSnapshot(10), NOW), withExpiry(11), NOW).state;
    const second = applySnapshot(first, withExpiry(11), NOW).state;

    assert.deepEqual(second.log.map((e) => e.cursor), ['11', '10x4', '10', '9']);
});

test('an expiry derived on a poll at the same version is added, and the viewer and next expiry are refreshed', () => {
    const state = initialState(fullSnapshot(10, { next_expiry_at: '2026-10-19T12:00:00.000000Z', viewer: viewerBlock('elevated', [{ allocation_seq: 4, units: 12, expires_at: '2026-10-19T12:00:00.000000Z' }]) }), NOW);

    const polled = fullSnapshot(10, { log: [expiryEntry(10, 4), entry(10), entry(9)], viewer: viewerBlock('standard'), next_expiry_at: null });
    const { state: after } = applySnapshot(state, polled, NOW + 7_200_000);

    assert.deepEqual(after.log.map((e) => e.cursor), ['10x4', '10', '9']);
    assert.equal(after.viewer.status, 'standard');
    assert.equal(nextExpiryAt(after), null);
});

test('a newer snapshot replaces the viewer', () => {
    const state = initialState(fullSnapshot(10), NOW);

    const { state: after } = applySnapshot(state, fullSnapshot(12, { viewer: viewerBlock('high') }), NOW);

    assert.equal(after.viewer.status, 'high');
});

test('a stale own result does not overwrite a newer viewer, and a fresh one does', () => {
    const own = (version, status) => ({
        allocation: { id: 1, seq: version, operator: { id: 1, name: 'Ana' }, type: 'temporary', requested: 1, granted: 1, outcome: 'full', effective_at: 'x', expires_at: null },
        pool: { version, remaining: 80 },
        viewer: viewerBlock(status),
    });
    const state = initialState(fullSnapshot(12, { viewer: viewerBlock('high') }), NOW);

    const stale = applyOwnResult(state, own(11, 'standard'), NOW).state;
    const fresh = applyOwnResult(state, own(13, 'elevated'), NOW).state;

    assert.equal(stale.viewer.status, 'high');
    assert.equal(fresh.viewer.status, 'elevated');
    assert.equal(fresh.viewerVersion, 13);
});

test('an event with an earlier expiry lowers the next expiry; a later one, a duplicate and an old event do not', () => {
    const state = initialState(fullSnapshot(10, { next_expiry_at: '2026-10-19T12:00:00.000000Z' }), NOW);
    const at = (iso) => Date.parse(iso);
    const withExpiry = (version, iso) => event(version, 80, { type: 'temporary', expires_at: iso });

    const later = applyEvent(state, withExpiry(11, '2026-10-19T15:00:00.000000Z'), NOW).state;
    assert.equal(nextExpiryAt(later), at('2026-10-19T12:00:00Z'));

    const earlier = applyEvent(later, withExpiry(12, '2026-10-19T11:00:00.000000Z'), NOW).state;
    assert.equal(nextExpiryAt(earlier), at('2026-10-19T11:00:00Z'));

    const duplicate = applyEvent(earlier, withExpiry(12, '2026-10-19T10:30:00.000000Z'), NOW).state;
    const old = applyEvent(earlier, withExpiry(5, '2026-10-19T10:30:00.000000Z'), NOW).state;
    assert.equal(nextExpiryAt(duplicate), at('2026-10-19T11:00:00Z'));
    assert.equal(nextExpiryAt(old), at('2026-10-19T11:00:00Z'));
});

test('an event lowers the next expiry when none was held, and ignores an expiry already in the past', () => {
    const state = initialState(fullSnapshot(10), NOW);
    const withExpiry = (version, iso) => event(version, 80, { type: 'temporary', expires_at: iso });

    const lowered = applyEvent(state, withExpiry(11, '2026-10-20T10:00:00.000000Z'), NOW).state;
    assert.equal(nextExpiryAt(lowered), Date.parse('2026-10-20T10:00:00Z'));

    const past = applyEvent(state, withExpiry(11, '2026-10-19T09:00:00.000000Z'), NOW).state;
    assert.equal(nextExpiryAt(past), null);
});

test('the next expiry is measured on the server clock, using the offset', () => {
    // The device is one hour behind the server.
    const state = initialState(fullSnapshot(10), NOW - 3_600_000);
    const event11 = event(11, 80, { type: 'temporary', expires_at: '2026-10-19T10:30:00.000000Z' });

    // Server time is 10:00 at snapshot, so 10:30 is ahead and counts.
    assert.equal(nextExpiryAt(applyEvent(state, event11, NOW - 3_600_000).state), Date.parse('2026-10-19T10:30:00Z'));
});

test('pages by cursor: the oldest cursor is the last row, whatever its kind', () => {
    const state = { ...initialState(fullSnapshot(10), NOW), log: [entry(11), expiryEntry(10, 4), entry(10)] };
    const expiryLast = { ...state, log: [entry(11), entry(10), expiryEntry(9, 4)] };

    assert.equal(oldestCursor(state), '10');
    assert.equal(oldestCursor(expiryLast), '9x4');
    assert.equal(oldestCursor({ ...state, log: [] }), null);
});

test('an older page merges expiry entries by cursor too', () => {
    const state = { ...initialState(fullSnapshot(10), NOW), log: [entry(10), entry(9)] };

    const { log } = applyOlderPage(state, { entries: [expiryEntry(8, 3), entry(8), entry(7)], has_more: false });

    assert.deepEqual(log.map((e) => e.cursor), ['10', '9', '8x3', '8', '7']);
});

test('compares an event\'s expiry with the epoch clock, not the gap clock', () => {
    const state = initialState(fullSnapshot(10), NOW);
    const withExpiry = event(11, 80, { type: 'temporary', expires_at: '2026-10-19T10:30:00.000000Z' });

    // The gap clock reads 5 s since page load; the epoch clock is the real time.
    assert.equal(nextExpiryAt(applyEvent(state, withExpiry, 5000, NOW).state), Date.parse('2026-10-19T10:30:00Z'));
});
