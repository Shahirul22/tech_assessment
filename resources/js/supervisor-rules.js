// What the supervisor's forms accept (FR18.1, AC-US1.3, AC-US2.4).
//
// A convenience for the person typing: the server enforces the same rules (and the pool check) whatever the screen
// says, so a stale number here can never record anything wrong.

const MAX_UNITS = 2147483647;
const WHOLE_NUMBER = 'Enter a whole number of units, 1 or more';

/**
 * @param {string|number|null|undefined} amountText  what was typed
 * @returns {string|null}  a short text for the first problem, or null when the restock can be sent
 */
export function restockProblem(amountText) {
    return wholeUnitsProblem(amountText);
}

/**
 * @param {{kind: string, amountText?: string|number|null, reasonText?: string|null, currentUnits: number}} form
 *   `kind` is 'amend' or 'void'; `currentUnits` is what the entry holds now.
 * @returns {string|null}  a short text for the first problem, or null when the correction can be sent
 */
export function correctionProblem({ kind, amountText, reasonText, currentUnits }) {
    const amount = text(amountText);

    if (kind === 'void') {
        if (amount !== '') {
            return 'A void takes no amount';
        }

        if (currentUnits === 0) {
            return 'Nothing to void';
        }
    } else {
        if (/^[0-9]+$/.test(amount) && Number(amount) === 0) {
            return 'Use Void to remove all units';
        }

        const problem = wholeUnitsProblem(amount);

        if (problem !== null) {
            return problem;
        }

        if (Number(amount) === currentUnits) {
            return 'No change';
        }
    }

    if (text(reasonText) === '') {
        return 'Give a reason';
    }

    return null;
}

function wholeUnitsProblem(raw) {
    const value = text(raw);

    // Digits only: no sign, no decimal point, no exponent, no other scripts.
    if (!/^[0-9]+$/.test(value) || Number(value) < 1 || Number(value) > MAX_UNITS) {
        return WHOLE_NUMBER;
    }

    return null;
}

function text(raw) {
    return typeof raw === 'number' ? String(raw) : typeof raw === 'string' ? raw.trim() : '';
}
