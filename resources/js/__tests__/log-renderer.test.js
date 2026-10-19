import { test } from 'node:test';
import assert from 'node:assert/strict';

import { renderEntry } from '../log-renderer.js';

const ana = { id: 1, name: 'Ana' };
const ben = { id: 2, name: 'Ben' };
const dana = { id: 4, name: 'Dana' };

const allocation = (operator, requested, granted, outcome) => ({
    seq: 12, kind: 'allocation', at: '2026-10-17T10:00:00.123456Z', operator, type: 'consumed', requested, granted, outcome,
});

test('renders a full allocation', () => {
    const view = renderEntry(allocation(ana, 3, 3, 'full'), 99);

    assert.deepEqual(view, { label: 'Allocation', text: 'Ana: 3 granted', own: false });
});

test('renders a partial allocation', () => {
    assert.equal(renderEntry(allocation(ben, 6, 4, 'partial'), 99).text, 'Ben: 4 of 6 granted');
});

test('renders an allocation that got nothing, as requested N, granted 0', () => {
    assert.equal(renderEntry(allocation(ben, 3, 0, 'none'), 99).text, 'Ben requested 3, granted 0');
});

test('renders a restock, with or without a note', () => {
    const seed = { seq: 1, kind: 'restock', at: 'x', actor: null, amount: 100, note: 'Initial stock' };
    const added = { seq: 9, kind: 'restock', at: 'x', actor: dana, amount: 25, note: null };

    assert.deepEqual(renderEntry(seed, 1), { label: 'Restock', text: '+100 units · Initial stock', own: false });
    assert.equal(renderEntry(added, 1).text, 'Dana added 25 units');
    assert.equal(renderEntry({ ...added, amount: 1, note: 'Delivery' }, 1).text, 'Dana added 1 unit · Delivery');
});

test('renders a correction, amend and void', () => {
    const amend = { seq: 20, kind: 'correction', at: 'x', supervisor: dana, operator: ana, allocation_seq: 12, correction_kind: 'amend', change: { from: 40, to: 20 }, reason: 'Counted wrong' };
    const voided = { ...amend, correction_kind: 'void', change: { from: 40, to: null }, reason: 'Duplicate' };

    assert.deepEqual(renderEntry(amend, 99), { label: 'Correction', text: "Dana changed Ana's request #12: 40 → 20 · Counted wrong", own: false });
    assert.equal(renderEntry(voided, 99).text, "Dana voided Ana's request #12 (40 → 0) · Duplicate");
});

test('renders an expiry with its operator and the allocation it belongs to', () => {
    const expiry = { seq: null, kind: 'expiry', at: 'x', operator: ana, allocation_seq: 12, units: 3 };

    assert.deepEqual(renderEntry(expiry, 99), { label: 'Expiry', text: 'Ana: 3 units returned (#12)', own: false });
    assert.equal(renderEntry({ ...expiry, units: 1 }, 99).text, 'Ana: 1 unit returned (#12)');
});

test('marks an expiry as own only for the operator whose units came back', () => {
    const expiry = { seq: null, kind: 'expiry', at: 'x', operator: ana, allocation_seq: 12, units: 3 };

    assert.equal(renderEntry(expiry, 1).own, true, 'Ana');
    assert.equal(renderEntry(expiry, 2).own, false, 'Ben');
    assert.equal(renderEntry(expiry, null).own, false, 'nobody picked yet');
});

test('a temporary allocation says so, a consumed one reads as before', () => {
    const temporary = { ...allocation(ana, 4, 4, 'full'), type: 'temporary' };

    assert.equal(renderEntry(temporary, 99).text, 'Ana: 4 granted · temporary');
    assert.equal(renderEntry({ ...allocation(ben, 6, 4, 'partial'), type: 'temporary' }, 99).text, 'Ben: 4 of 6 granted · temporary');
    assert.equal(renderEntry({ ...allocation(ben, 3, 0, 'none'), type: 'temporary' }, 99).text, 'Ben requested 3, granted 0 · temporary');
    assert.equal(renderEntry(allocation(ana, 4, 4, 'full'), 99).text, 'Ana: 4 granted');
});

test('marks an allocation as own only for the operator who made it', () => {
    const entry = allocation(ana, 3, 3, 'full');

    assert.equal(renderEntry(entry, 1).own, true, 'owner');
    assert.equal(renderEntry(entry, 2).own, false, 'someone else');
    assert.equal(renderEntry(entry, null).own, false, 'nobody picked yet');
    assert.equal(renderEntry(entry, '1').own, true, 'an id read back from storage as text');
});

test('a restock and a correction are never own', () => {
    const restock = { seq: 1, kind: 'restock', at: 'x', actor: ana, amount: 5, note: null };
    const correction = { seq: 2, kind: 'correction', at: 'x', supervisor: ana, allocation_seq: 1, change: { from: 1, to: 0 }, reason: 'r' };

    assert.equal(renderEntry(restock, 1).own, false);
    assert.equal(renderEntry(correction, 1).own, false);
});

test('labels are words, not symbols', () => {
    const labels = [
        renderEntry(allocation(ana, 1, 1, 'full'), 1).label,
        renderEntry({ seq: 1, kind: 'restock', actor: null, amount: 1, note: null }, 1).label,
        renderEntry({ seq: 2, kind: 'correction', supervisor: dana, allocation_seq: 1, change: { from: 1, to: 0 }, reason: 'r' }, 1).label,
        renderEntry({ seq: null, kind: 'expiry', operator: ana, allocation_seq: 1, units: 1 }, 1).label,
    ];

    assert.deepEqual(labels, ['Allocation', 'Restock', 'Correction', 'Expiry']);
});

test('an unknown kind is shown plainly instead of throwing', () => {
    assert.deepEqual(renderEntry({ seq: 5, kind: 'mystery' }, 1), { label: 'Entry', text: 'Unknown entry #5', own: false });
});

test('highlights a correction only on the screen of the operator it corrected', () => {
    const amend = { seq: 20, kind: 'correction', at: 'x', supervisor: dana, operator: ana, allocation_seq: 12, correction_kind: 'amend', change: { from: 40, to: 20 }, reason: 'Counted wrong' };

    assert.equal(renderEntry(amend, 1).own, true, 'Ana');
    assert.equal(renderEntry(amend, 2).own, false, 'Ben');
    assert.equal(renderEntry(amend, null).own, false, 'nobody picked yet');
    assert.equal(renderEntry(amend, 4).own, false, 'the supervisor');
});

test('reads a correction without an operator, as an older server sent it', () => {
    const old = { seq: 20, kind: 'correction', at: 'x', supervisor: dana, allocation_seq: 12, change: { from: 3, to: 2 }, reason: 'miscounted' };

    assert.deepEqual(renderEntry(old, 1), { label: 'Correction', text: 'Dana changed request #12: 3 → 2 · miscounted', own: false });
});

test('a corrected allocation still shows the original request and grant, and says it was corrected', () => {
    const corrected = { ...allocation(ana, 40, 40, 'full'), corrected_to: 20, voided: false };

    assert.deepEqual(renderEntry(corrected, 1), { label: 'Allocation', text: 'Ana: 40 granted · corrected to 20', own: true });
    assert.equal(renderEntry({ ...allocation(ben, 6, 4, 'partial'), corrected_to: 2, voided: false }, 1).text, 'Ben: 4 of 6 granted · corrected to 2');
    assert.equal(renderEntry({ ...allocation(ben, 3, 0, 'none'), corrected_to: 3, voided: false }, 1).text, 'Ben requested 3, granted 0 · corrected to 3');
});

test('a voided allocation says "voided" in words and still shows the original', () => {
    const voided = { ...allocation(ana, 40, 40, 'full'), corrected_to: 0, voided: true };

    assert.equal(renderEntry(voided, 1).text, 'Ana: 40 granted · voided');
    assert.equal(renderEntry({ ...voided, type: 'temporary' }, 1).text, 'Ana: 40 granted · temporary · voided');
});

test('an uncorrected allocation reads as before, with or without the new keys', () => {
    assert.equal(renderEntry({ ...allocation(ana, 3, 3, 'full'), corrected_to: null, voided: false }, 1).text, 'Ana: 3 granted');
    assert.equal(renderEntry(allocation(ana, 3, 3, 'full'), 1).text, 'Ana: 3 granted');
});
