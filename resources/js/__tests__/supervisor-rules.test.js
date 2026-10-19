import { test } from 'node:test';
import assert from 'node:assert/strict';

import { correctionProblem, restockProblem } from '../supervisor-rules.js';

const WHOLE = 'Enter a whole number of units, 1 or more';

test('a restock of a whole number of 1 or more has no problem', () => {
    for (const raw of ['1', '50', ' 7 ', '007', 3, '2147483647']) {
        assert.equal(restockProblem(raw), null, String(raw));
    }
});

test('a restock amount that is blank, 0, negative, fractional or not a number is a problem', () => {
    for (const raw of ['', '   ', '0', '000', '-5', '1.5', '1,5', 'abc', '3a', '1e3', '٣', '2147483648', null, undefined, 0, -1]) {
        assert.equal(restockProblem(raw), WHOLE, JSON.stringify(raw));
    }
});

const amend = (overrides) => ({ kind: 'amend', amountText: '20', reasonText: 'Counted wrong', currentUnits: 40, ...overrides });

test('a correction with a new amount and a reason has no problem', () => {
    assert.equal(correctionProblem(amend({})), null);
    assert.equal(correctionProblem(amend({ amountText: '60' })), null);
    assert.equal(correctionProblem(amend({ amountText: 20, reasonText: '  typo  ' })), null);
});

test('a blank reason is a problem', () => {
    for (const reasonText of ['', '   ', null, undefined]) {
        assert.equal(correctionProblem(amend({ reasonText })), 'Give a reason', JSON.stringify(reasonText));
    }
});

test('an amend amount of 0 says to use Void', () => {
    assert.equal(correctionProblem(amend({ amountText: '0' })), 'Use Void to remove all units');
    assert.equal(correctionProblem(amend({ amountText: '000' })), 'Use Void to remove all units');
});

test('an amend amount that is blank, negative, fractional or not a number is a problem', () => {
    for (const amountText of ['', '  ', '-2', '1.5', 'abc', '2e1', null, undefined]) {
        assert.equal(correctionProblem(amend({ amountText })), WHOLE, JSON.stringify(amountText));
    }
});

test('an amount equal to what the entry holds is "No change"', () => {
    assert.equal(correctionProblem(amend({ amountText: '40' })), 'No change');
    assert.equal(correctionProblem(amend({ amountText: ' 40 ' })), 'No change');
    assert.equal(correctionProblem(amend({ amountText: '0040' })), 'No change');
});

test('a void needs a reason and no amount', () => {
    const voided = (overrides) => ({ kind: 'void', amountText: '', reasonText: 'Duplicate', currentUnits: 40, ...overrides });

    assert.equal(correctionProblem(voided({})), null);
    assert.equal(correctionProblem(voided({ amountText: null })), null);
    assert.equal(correctionProblem(voided({ amountText: '5' })), 'A void takes no amount');
    assert.equal(correctionProblem(voided({ amountText: '0' })), 'A void takes no amount');
    assert.equal(correctionProblem(voided({ reasonText: ' ' })), 'Give a reason');
});

test('an entry that holds nothing cannot be voided', () => {
    assert.equal(correctionProblem({ kind: 'void', amountText: '', reasonText: 'x', currentUnits: 0 }), 'Nothing to void');
});

test('an entry that holds nothing can be raised to a whole number', () => {
    assert.equal(correctionProblem({ kind: 'amend', amountText: '3', reasonText: 'Was owed', currentUnits: 0 }), null);
});

test('the amount is judged before the reason', () => {
    assert.equal(correctionProblem({ kind: 'amend', amountText: '', reasonText: '', currentUnits: 40 }), WHOLE);
});
