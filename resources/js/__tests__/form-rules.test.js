import { test } from 'node:test';
import assert from 'node:assert/strict';

import { maxAmount, validateAmount } from '../form-rules.js';

test('the most that can be requested is what is left, never below zero', () => {
    assert.equal(maxAmount(10), 10);
    assert.equal(maxAmount(1), 1);
    assert.equal(maxAmount(0), 0);
    assert.equal(maxAmount(-4), 0);
    assert.equal(maxAmount(Number.NaN), 0);
});

test('accepts a whole number from 1 up to what is left', () => {
    for (const [raw, value] of [['1', 1], ['4', 4], ['10', 10], [' 3 ', 3], ['007', 7]]) {
        assert.deepEqual(validateAmount(raw, 10), { ok: true, value, message: '' }, raw);
    }
});

test('rejects a value above what is left and says how many are left', () => {
    assert.deepEqual(validateAmount('11', 10), { ok: false, value: null, message: 'Only 10 left' });
    assert.deepEqual(validateAmount('100', 1), { ok: false, value: null, message: 'Only 1 left' });
    assert.equal(validateAmount('99999999999999999999', 10).message, 'Only 10 left');
});

test('rejects anything that is not a whole number of 1 or more', () => {
    const cases = [
        ['', 'Enter a number'],
        ['   ', 'Enter a number'],
        ['0', 'Enter 1 or more'],
        ['000', 'Enter 1 or more'],
        ['-2', 'Enter a whole number'],
        ['1.5', 'Enter a whole number'],
        ['1,5', 'Enter a whole number'],
        ['abc', 'Enter a whole number'],
        ['3a', 'Enter a whole number'],
        ['1e3', 'Enter a whole number'],
        ['٣', 'Enter a whole number'],
    ];

    for (const [raw, message] of cases) {
        assert.deepEqual(validateAmount(raw, 10), { ok: false, value: null, message }, JSON.stringify(raw));
    }
});

test('with nothing left, nothing is valid and the message is "0 left"', () => {
    for (const raw of ['', '0', '1', '5', 'abc']) {
        assert.deepEqual(validateAmount(raw, 0), { ok: false, value: null, message: '0 left' }, JSON.stringify(raw));
    }
});

test('treats a negative or unknown remaining like zero left', () => {
    assert.equal(validateAmount('1', -3).message, '0 left');
    assert.equal(validateAmount('1', Number.NaN).message, '0 left');
});

test('a non-string input is rejected instead of throwing', () => {
    assert.equal(validateAmount(undefined, 10).ok, false);
    assert.equal(validateAmount(null, 10).ok, false);
    assert.equal(validateAmount(3, 10).ok, true);
});
