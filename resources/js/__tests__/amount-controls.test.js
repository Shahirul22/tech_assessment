import assert from 'node:assert/strict';
import { test } from 'node:test';

import { quickAmounts, stepAmount } from '../amount-controls.js';

const summary = (remaining) => quickAmounts(remaining).map(({ label, value, usable }) => `${label}:${value}:${usable ? 'usable' : 'unusable'}`);

test('quick amounts above the remaining are listed but not usable', () => {
    assert.deepEqual(summary(100), ['1:1:usable', '5:5:usable', '10:10:usable', 'All (100):100:usable']);
    assert.deepEqual(summary(8), ['1:1:usable', '5:5:usable', '10:10:unusable', 'All (8):8:usable']);
    assert.deepEqual(summary(1), ['1:1:usable', '5:5:unusable', '10:10:unusable']);
});

test('All fills exactly the remaining', () => {
    const all = quickAmounts(37).at(-1);

    assert.deepEqual(all, { label: 'All (37)', value: 37, usable: true });
});

test('All is left out when it would repeat a fixed amount', () => {
    assert.deepEqual(summary(5), ['1:1:usable', '5:5:usable', '10:10:unusable']);
    assert.equal(quickAmounts(10).some(({ label }) => label.startsWith('All')), false);
});

test('nothing is usable at 0 left', () => {
    assert.equal(quickAmounts(0).every(({ usable }) => !usable), true);
    assert.equal(stepAmount('3', 1, 0), null);
    assert.equal(stepAmount('', -1, 0), null);
});

test('the stepper never goes below 1 or above the remaining', () => {
    assert.equal(stepAmount('3', 1, 100), 4);
    assert.equal(stepAmount('3', -1, 100), 2);
    assert.equal(stepAmount('1', -1, 100), 1);
    assert.equal(stepAmount('100', 1, 100), 100);
    assert.equal(stepAmount('8', 1, 8), 8);

    // Ten taps down from 3 stop at 1.
    let amount = '3';

    for (let tap = 0; tap < 10; tap++) {
        amount = String(stepAmount(amount, -1, 100));
    }

    assert.equal(amount, '1');
});

test('typed garbage steps from zero', () => {
    for (const text of ['', 'abc', '2.5', '-2', '  ', null, undefined]) {
        assert.equal(stepAmount(text, 1, 100), 1, `+ from ${JSON.stringify(text)}`);
        assert.equal(stepAmount(text, -1, 100), 1, `− from ${JSON.stringify(text)}`);
    }
});

test('an amount above the new remaining comes down to it on the next tap', () => {
    assert.equal(stepAmount('10', -1, 5), 5);
    assert.equal(stepAmount('10', 1, 5), 5);
});

test('a quick amount sets the amount, it never adds to it', () => {
    // The caller puts `value` in the field; tapping the same button twice gives the same field.
    const five = quickAmounts(100)[1];

    assert.equal(five.value, 5);
    assert.equal(String(five.value), String(five.value));
    assert.equal(stepAmount(String(five.value), 0, 100), 5, 'the field holds 5, not 10');
});
