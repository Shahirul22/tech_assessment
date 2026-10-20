import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

import { contrastRatio } from './helpers/contrast.js';

// The sunlight standard (NFR4, PQ2): text is at least 7:1 on every background it is drawn on; borders and the focus
// ring are at least 3:1 on the page. Only pairs the CSS really uses are listed; the browser audit finds the rest.
const TEXT_PAIRS = {
    text: ['bg', 'surface', 'own-bg', 'warning-bg', 'disabled-bg'],
    'text-muted': ['bg', 'surface', 'own-bg', 'warning-bg', 'disabled-bg'],
    'accent-strong': ['bg', 'surface', 'own-bg'],
    warning: ['bg', 'surface', 'own-bg', 'warning-bg'],
    'disabled-text': ['disabled-bg'],
    'on-accent': ['accent'],
};
const LINE_PAIRS = { border: ['bg'], focus: ['bg'] };

const tokens = Object.fromEntries(
    [...readFileSync(new URL('../../css/tokens.css', import.meta.url), 'utf8').matchAll(/--color-([\w-]+):\s*(#[0-9a-fA-F]{6})\s*;/g)].map((match) => [match[1], match[2]]),
);

function failures(pairs, minimum) {
    return Object.entries(pairs).flatMap(([foreground, backgrounds]) => backgrounds.flatMap((background) => {
        assert.ok(tokens[foreground], `--color-${foreground} is declared in tokens.css`);
        assert.ok(tokens[background], `--color-${background} is declared in tokens.css`);

        const ratio = contrastRatio(tokens[foreground], tokens[background]);

        return ratio >= minimum ? [] : [`${foreground} (${tokens[foreground]}) on ${background} (${tokens[background]}) is ${ratio.toFixed(2)}:1, needs ${minimum}:1`];
    }));
}

test('every text colour is at least 7:1 on every background it is used on', () => {
    assert.deepEqual(failures(TEXT_PAIRS, 7), []);
});

test('borders and the focus ring are at least 3:1 on the page background', () => {
    assert.deepEqual(failures(LINE_PAIRS, 3), []);
});

test('the contrast helper matches known WCAG values', () => {
    assert.equal(contrastRatio('#000000', '#ffffff').toFixed(2), '21.00');
    assert.equal(contrastRatio('#777777', '#ffffff').toFixed(2), '4.48');
    assert.equal(contrastRatio('#ffffff', '#ffffff').toFixed(2), '1.00');
});
