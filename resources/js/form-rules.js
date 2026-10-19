// What the request form accepts (FR1.1, AC-US1.2, AC-US1.3).
//
// This is a convenience for the person typing: the server enforces the same rules (and `min(requested, remaining)`)
// whatever the screen says, so a stale number here can never over-grant.

/**
 * The most that can be requested: what is left, never below zero.
 *
 * @param {number} remaining
 * @returns {number}
 */
export function maxAmount(remaining) {
    return Number.isFinite(remaining) ? Math.max(0, remaining) : 0;
}

/**
 * @param {string|number|null|undefined} raw  what was typed
 * @param {number} remaining  the remaining last shown to this operator
 * @returns {{ok: boolean, value: number|null, message: string}}
 */
export function validateAmount(raw, remaining) {
    const max = maxAmount(remaining);

    if (max === 0) {
        return invalid('0 left');
    }

    const text = typeof raw === 'number' ? String(raw) : typeof raw === 'string' ? raw.trim() : '';

    if (text === '') {
        return invalid('Enter a number');
    }

    // Digits only: no sign, no decimal point, no exponent, no other scripts.
    if (!/^[0-9]+$/.test(text)) {
        return invalid('Enter a whole number');
    }

    const value = Number(text);

    if (value < 1) {
        return invalid('Enter 1 or more');
    }

    if (value > max) {
        return invalid(`Only ${max} left`);
    }

    return { ok: true, value, message: '' };
}

function invalid(message) {
    return { ok: false, value: null, message };
}
