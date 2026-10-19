import { test } from 'node:test';
import assert from 'node:assert/strict';

import { auditRows, auditView, hindsightText, EMPTY_AUDIT_TEXT } from '../audit-view.js';

const TZ = 'Europe/London';
const dana = { id: 4, name: 'Dana' };
const ana = { id: 1, name: 'Ana' };

const amend = {
    seq: 15, allocation_seq: 1, operator: ana, kind: 'amend', from: 40, to: 20, reason: 'Counted wrong', supervisor: dana,
    at: '2026-10-17T14:00:00.000000Z', original: { requested: 40, granted: 40 },
    status: {
        before: 'high',
        after: 'elevated',
        points: [
            { label: 'when corrected', seq: 15, at: '2026-10-17T14:00:00.000000Z', before: 'high', after: 'elevated' },
            { label: "after Ana's request #12", seq: 12, at: '2026-10-17T10:30:00.000000Z', before: 'high', after: 'elevated' },
        ],
    },
};

const audit = (corrections, hindsight = []) => ({ version: 15, server_time: '2026-10-17T15:00:00.000000Z', corrections, hindsight });

test('a correction reads as who, which entry, from to, reason and the status before and after', () => {
    const [row] = auditRows(audit([amend]), TZ);

    assert.equal(row.seq, 15);
    assert.equal(row.text, 'Dana · #1 Ana · 40 → 20 · Counted wrong · Ana: high → elevated');
});

test('a void reads as voided with the units it held', () => {
    const [row] = auditRows(audit([{ ...amend, kind: 'void', to: 0, reason: 'Duplicate' }]), TZ);

    assert.equal(row.text, 'Dana · #1 Ana · voided (40 → 0) · Duplicate · Ana: high → elevated');
});

test('each status point reads as its label, the time and before to after', () => {
    const [row] = auditRows(audit([amend]), TZ);

    assert.deepEqual(row.points, [
        'when corrected (15:00 today): high → elevated',
        "after Ana's request #12 (11:30 today): high → elevated",
    ]);
});

test('the time of a point is shown in the viewer\'s own time zone (Tokyo is already on the 18th), and as tomorrow or a date when it is not today', () => {
    const later = { ...amend, status: { ...amend.status, points: [{ label: 'now', seq: null, at: '2026-10-19T09:00:00.000000Z', before: 'high', after: 'elevated' }, { label: 'x', seq: null, at: '2026-10-25T09:00:00.000000Z', before: 'high', after: 'standard' }] } };

    assert.deepEqual(auditRows(audit([later]), 'Asia/Tokyo').map((row) => row.points)[0], [
        'now (18:00 tomorrow): high → elevated',
        'x (25 Oct 18:00): high → standard',
    ]);
});

test('an unchanged status says so and does not claim a change', () => {
    const [row] = auditRows(audit([{ ...amend, status: { before: 'elevated', after: 'elevated', points: [] } }]), TZ);

    assert.equal(row.text, 'Dana · #1 Ana · 40 → 20 · Counted wrong · Ana: elevated (unchanged)');
    assert.deepEqual(row.points, []);
});

test('several corrections keep the order the server sent', () => {
    const rows = auditRows(audit([amend, { ...amend, seq: 16 }, { ...amend, seq: 17 }]), TZ);

    assert.deepEqual(rows.map((row) => row.seq), [15, 16, 17]);
});

test('a hindsight window reads as the lowest level between two times', () => {
    const window = { from: { seq: 3, at: '2026-10-17T09:00:00.000000Z' }, to: { seq: 5, at: '2026-10-17T12:00:00.000000Z' }, lowest: -20, corrections: [6] };

    assert.equal(hindsightText(window, TZ), 'Overdrawn in hindsight: lowest -20 between 10:00 and 13:00');
});

test('a window that has not ended reads "and now", and one across midnight names the days', () => {
    const open = { from: { seq: 3, at: '2026-10-17T09:00:00.000000Z' }, to: null, lowest: -5, corrections: [4] };
    const overnight = { from: { seq: 3, at: '2026-10-17T22:00:00.000000Z' }, to: { seq: null, at: '2026-10-18T08:00:00.000000Z' }, lowest: -5, corrections: [4] };

    assert.equal(hindsightText(open, TZ), 'Overdrawn in hindsight: lowest -5 between 10:00 and now');
    assert.equal(hindsightText(overnight, TZ), 'Overdrawn in hindsight: lowest -5 between 17 Oct 23:00 and 18 Oct 09:00');
});

test('an empty audit gives an empty-state text, not an error', () => {
    assert.equal(EMPTY_AUDIT_TEXT, 'No corrections yet');
    assert.deepEqual(auditView(audit([]), TZ), { empty: true, emptyText: 'No corrections yet', rows: [], hindsight: [] });
    assert.deepEqual(auditRows(null, TZ), []);
    assert.equal(auditView(undefined, TZ).empty, true);
});

test('the view bundles the rows and the hindsight lines', () => {
    const window = { from: { seq: 3, at: '2026-10-17T09:00:00.000000Z' }, to: { seq: 5, at: '2026-10-17T12:00:00.000000Z' }, lowest: -20, corrections: [6] };
    const view = auditView(audit([amend], [window]), TZ);

    assert.equal(view.empty, false);
    assert.equal(view.rows.length, 1);
    assert.deepEqual(view.hindsight, ['Overdrawn in hindsight: lowest -20 between 10:00 and 13:00']);
});
