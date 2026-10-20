// The quick amounts and the stepper of the request form (FR19, FR19.1, FR19.3, DD9, DD10).
//
// Pure helpers for the person tapping: they only say what to put in the amount field. The field stays the one source
// of truth and form-rules.js still validates it; the server enforces `min(requested, remaining)` whatever is tapped.

import { maxAmount } from './form-rules.js';

/**
 * The quick-amount buttons for the remaining last shown. The fixed values are always listed, so the row does not jump
 * when the number changes; one above the remaining is listed but not usable. "All (N)" fills what is left, and is left
 * out when N is already one of the fixed values.
 *
 * @param {number} remaining
 * @param {number[]} [values]
 * @returns {{label: string, value: number, usable: boolean}[]}
 */
export function quickAmounts(remaining, values = [1, 5, 10]) {
    const max = maxAmount(remaining);
    const buttons = values.map((value) => ({ label: String(value), value, usable: value <= max }));

    if (!values.includes(max)) {
        buttons.push({ label: `All (${max})`, value: max, usable: max >= 1 });
    }

    return buttons;
}

/**
 * The amount after a tap on + or −, kept between 1 and the remaining. Empty or unreadable text counts as 0.
 *
 * @param {string|number|null|undefined} currentText  what the field holds
 * @param {number} delta  +1 or −1
 * @param {number} remaining
 * @returns {number|null}  null when nothing can be requested (nothing left)
 */
export function stepAmount(currentText, delta, remaining) {
    const max = maxAmount(remaining);

    if (max === 0) {
        return null;
    }

    const text = typeof currentText === 'number' ? String(currentText) : typeof currentText === 'string' ? currentText.trim() : '';
    const current = /^[0-9]+$/.test(text) ? Number(text) : 0;

    return Math.min(max, Math.max(1, current + delta));
}
