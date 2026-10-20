// Measures the real page against the sunlight standard (M4: FR22, FR23, FR24, NFR3, NFR4, AC-US1.8, AC-US2.1, AC-US6.1-6.3, AC-US7.1-7.2).
//
// It opens the dashboard in a real Chromium at 360 x 640 and 1280 x 800, as an operator (Ana) and as a supervisor
// (Dana, both tabs), shows every banner state, and checks:
//   tap targets     every visible tappable element is at least 48 x 48 CSS px, with at least 8 px to the nearest other one
//   contrast        every visible text element's colour on its effective background is at least 7:1 (text over an image or a
//                   gradient is skipped and counted)
//   key numbers     remaining, status, banner words, holdings and audit values are at least 24 px at 360 px
//   overflow        scrollWidth <= innerWidth
//   order           at 360 x 640 the submit button is above the activity log
// One PASS or FAIL line per check, then the offenders; exit code 0 when all pass, 1 otherwise.
//
// Run it on the host, against the running stack (the one browser step that does not run in Docker, plan DD14):
//   node tests/Live/ui-audit.mjs
// Settings come from the environment:
//   STACK_URL       the web server (default http://localhost:8080)
//   CHROMIUM_PATH   the Chromium to drive (default: the newest ~/.cache/ms-playwright/chromium-*/chrome-linux64/chrome)
//   POOL_ID         default 1
// Chromium needs its system libraries (for example libasound2); playwright-core is a dev dependency and downloads no browser.
//
// It records a few entries (a grant, a Temporary holding and one correction, about 3 units) so holdings and the audit have
// rows to measure. Run it on a fresh seed if the numbers matter afterwards. Banner states other than the real grant are
// shown by setting the component's status (what the flows would report), so layout and contrast are measured for each.

import { randomUUID } from 'node:crypto';
import { existsSync, readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { chromium } from 'playwright-core';

const env = process.env;
const stackUrl = (env.STACK_URL ?? 'http://localhost:8080').replace(/\/$/, '');
const poolId = Number(env.POOL_ID ?? 1);

const MIN_TARGET = 48;
const MIN_GAP = 8;
const MIN_CONTRAST = 7;
const MIN_KEY_PX = 24;
const VIEWPORTS = [{ width: 360, height: 640 }, { width: 1280, height: 800 }];

function fail(message) {
    console.error(`FAIL: ${message}`);
    process.exit(1);
}

function findChromium() {
    if (env.CHROMIUM_PATH) {
        return env.CHROMIUM_PATH;
    }

    const root = join(homedir(), '.cache', 'ms-playwright');
    const candidates = existsSync(root) ? readdirSync(root).filter((name) => name.startsWith('chromium-')).sort().reverse() : [];
    const found = candidates.map((name) => join(root, name, 'chrome-linux64', 'chrome')).find((path) => existsSync(path));

    return found ?? fail('No Chromium found. Set CHROMIUM_PATH to a Chromium or Chrome executable.');
}

async function api(path, userId, body) {
    const response = await fetch(`${stackUrl}/api/pools/${poolId}${path}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Demo-User': String(userId), 'Idempotency-Key': randomUUID() },
        body: JSON.stringify(body),
    });

    if (response.status !== 201) {
        fail(`POST ${path} as user ${userId} returned ${response.status}: ${await response.text()}`);
    }

    return response.json();
}

// Rows for holdings and the audit to measure: a Temporary holding for Ana, and one correction by Dana.
async function seedRows() {
    await api('/allocations', 1, { requested: 1, type: 'temporary' });
    const consumed = await api('/allocations', 1, { requested: 2, type: 'consumed' });

    await api(`/allocations/${consumed.allocation.seq}/corrections`, 4, { kind: 'amend', amount: 1, reason: 'ui audit' });
}

/** What the request banner can say. Statuses are what the submit flow reports (api.js). */
const REQUEST_STATES = {
    pending: { state: 'pending', amount: 3, key: 'k' },
    resending: { state: 'resending', amount: 3, key: 'k', attempt: 2, of: 3, phase: 'waiting' },
    retrying: { state: 'retrying', amount: 3, key: 'k' },
    'not confirmed': { state: 'unconfirmed', amount: 3, key: 'k', message: 'You are offline — nothing was sent' },
    offline: { state: 'offline', message: 'You are offline — nothing was sent' },
    granted: { state: 'done', requested: 4, granted: 4, outcome: 'full', type: 'consumed', expiresAt: null, result: {} },
    partial: { state: 'done', requested: 6, granted: 4, outcome: 'partial', type: 'consumed', expiresAt: null, result: {} },
    none: { state: 'done', requested: 3, granted: 0, outcome: 'none', type: 'consumed', expiresAt: null, result: {} },
    refused: { state: 'error', status: 422, code: 'validation_failed', message: 'The amount is more than is left' },
};

/** What the supervisor banners can say: [property, status]. */
const SUPERVISOR_STATES = {
    'restock accepted': ['restockStatus', { state: 'done', replayed: false, result: { restock: { amount: 50 }, pool: { remaining: 150 } } }],
    'restock not confirmed': ['restockStatus', { state: 'unconfirmed', key: 'k' }],
    'correction refused': ['correctionStatus', { state: 'refused', code: 'insufficient_free_units', message: 'Needs 20 more units, 0 are free. Restock first.' }],
    'correction accepted': ['correctionStatus', { state: 'done', replayed: false, result: { correction: { kind: 'amend', allocation_seq: 2, from: 40, to: 20 }, pool: { remaining: 20 } } }],
    'correction pending': ['correctionStatus', { state: 'pending', key: 'k' }],
};

const results = new Map();
const checks = ['tap target size', 'tap target spacing', 'contrast', 'key number size', 'no horizontal scroll', 'submit above the log'];
checks.forEach((name) => results.set(name, { offenders: [], measured: 0, detail: '' }));

const record = (name, offenders, measured, detail = '') => {
    const entry = results.get(name);

    entry.offenders.push(...offenders);
    entry.measured += measured;
    entry.detail = detail || entry.detail;
};

/** Runs inside the page: everything that is measured in one pass over what is on screen. */
function measurePage({ minTarget, minGap, minContrast, minKeyPx, isNarrow }) {
    const visible = (element) => element.checkVisibility({ checkOpacity: false, checkVisibilityCSS: true }) && element.getClientRects().length > 0;
    const label = (element) => `${element.tagName.toLowerCase()}${element.id ? `#${element.id}` : ''}${element.className && typeof element.className === 'string' ? `.${element.className.trim().split(/\s+/)[0]}` : ''} "${(element.getAttribute('aria-label') || element.innerText || element.value || '').trim().slice(0, 24)}"`;

    // Tap targets. A radio is tapped through its label, so the label stands for it.
    const tappable = [...document.querySelectorAll('button, select, a[href], input:not([type=hidden]):not([type=radio]), label.choice__option')].filter(visible);
    const rects = tappable.map((element) => ({ element, rect: element.getBoundingClientRect() }));
    const small = rects.filter(({ rect }) => rect.width < minTarget - 0.5 || rect.height < minTarget - 0.5)
        .map(({ element, rect }) => `${label(element)} is ${rect.width.toFixed(1)} x ${rect.height.toFixed(1)}`);
    const gap = (a, b) => Math.hypot(Math.max(0, a.left - b.right, b.left - a.right), Math.max(0, a.top - b.bottom, b.top - a.bottom));
    const crowded = [];

    rects.forEach((first, index) => {
        rects.slice(index + 1).forEach((second) => {
            if (first.element.contains(second.element) || second.element.contains(first.element)) {
                return;
            }

            const distance = gap(first.rect, second.rect);

            if (distance < minGap - 0.5) {
                crowded.push(`${label(first.element)} and ${label(second.element)} are ${distance.toFixed(1)} px apart`);
            }
        });
    });

    const smallest = rects.reduce((least, { rect }) => Math.min(least, rect.width, rect.height), Infinity);

    // Contrast of every element that draws text of its own.
    const parse = (value) => {
        const match = value.match(/^rgba?\(([^)]+)\)$/);

        if (!match) {
            return null;
        }

        const [red, green, blue, alpha = 1] = match[1].split(/[ ,/]+/).filter(Boolean).map(Number);

        return { red, green, blue, alpha };
    };
    const luminance = ({ red, green, blue }) => {
        const [r, g, b] = [red, green, blue].map((channel) => {
            const value = channel / 255;

            return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
        });

        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };
    const over = (top, below) => ({
        red: top.red * top.alpha + below.red * (1 - top.alpha),
        green: top.green * top.alpha + below.green * (1 - top.alpha),
        blue: top.blue * top.alpha + below.blue * (1 - top.alpha),
        alpha: 1,
    });
    // The colour behind an element: its ancestors' backgrounds laid over white. Null when an image or gradient is in the way.
    const backgroundOf = (element) => {
        const layers = [];

        for (let node = element; node; node = node.parentElement) {
            const style = getComputedStyle(node);

            if (style.backgroundImage !== 'none') {
                return null;
            }

            const colour = parse(style.backgroundColor);

            if (colour && colour.alpha > 0) {
                layers.push(colour);
            }
        }

        return layers.reduceRight((below, layer) => over(layer, below), { red: 255, green: 255, blue: 255, alpha: 1 });
    };
    const ratios = [];
    const lowContrast = [];
    let skipped = 0;
    const textElements = [...document.querySelectorAll('body *')].filter((element) => {
        if (['SCRIPT', 'STYLE', 'OPTION', 'TEMPLATE'].includes(element.tagName) || !visible(element)) {
            return false;
        }

        if (element.matches('input[type=radio]')) {
            return false;
        }

        return element.matches('input, select') || [...element.childNodes].some((node) => node.nodeType === Node.TEXT_NODE && node.textContent.trim() !== '');
    });

    textElements.forEach((element) => {
        const background = backgroundOf(element);
        const colour = parse(getComputedStyle(element).color);

        if (!background || !colour) {
            skipped += 1;

            return;
        }

        const foreground = over(colour, background);
        const [lighter, darker] = [luminance(foreground), luminance(background)].sort((a, b) => b - a);
        const ratio = (lighter + 0.05) / (darker + 0.05);

        ratios.push(ratio);

        if (ratio < minContrast) {
            lowContrast.push(`${label(element)} is ${ratio.toFixed(2)}:1`);
        }
    });

    // Key numbers: 24 px at 360 px.
    const keyElements = [...document.querySelectorAll('#remaining, #status, .banner__text, .holdings__row, .audit__text')].filter(visible);
    const smallKeys = isNarrow
        ? keyElements.filter((element) => parseFloat(getComputedStyle(element).fontSize) < minKeyPx - 0.01).map((element) => `${label(element)} is ${getComputedStyle(element).fontSize}`)
        : [];

    const submit = document.querySelector('#request-submit');
    const log = document.querySelector('#log');

    return {
        tapCount: tappable.length,
        smallest,
        small,
        crowded,
        textCount: ratios.length,
        skipped,
        lowest: ratios.length ? Math.min(...ratios) : null,
        lowContrast,
        keyCount: keyElements.length,
        smallKeys,
        scrollWidth: document.documentElement.scrollWidth,
        innerWidth: window.innerWidth,
        submitBottom: submit && visible(submit) ? submit.getBoundingClientRect().bottom : null,
        logTop: log && visible(log) ? log.getBoundingClientRect().top : null,
    };
}

let lowestRatio = Infinity;
let smallestTarget = Infinity;
let skippedTotal = 0;

async function measure(page, viewport, where) {
    const narrow = viewport.width <= 400;
    const found = await page.evaluate(measurePage, { minTarget: MIN_TARGET, minGap: MIN_GAP, minContrast: MIN_CONTRAST, minKeyPx: MIN_KEY_PX, isNarrow: narrow });
    const at = `${viewport.width}x${viewport.height} ${where}`;

    smallestTarget = Math.min(smallestTarget, found.smallest);
    skippedTotal += found.skipped;

    if (found.lowest !== null) {
        lowestRatio = Math.min(lowestRatio, found.lowest);
    }

    record('tap target size', found.small.map((line) => `${at}: ${line}`), found.tapCount);
    record('tap target spacing', found.crowded.map((line) => `${at}: ${line}`), found.tapCount);
    record('contrast', found.lowContrast.map((line) => `${at}: ${line}`), found.textCount);
    record('key number size', found.smallKeys.map((line) => `${at}: ${line}`), narrow ? found.keyCount : 0);
    record('no horizontal scroll', found.scrollWidth <= found.innerWidth ? [] : [`${at}: scrollWidth ${found.scrollWidth} > innerWidth ${found.innerWidth}`], 1);

    if (narrow && found.submitBottom !== null && found.logTop !== null) {
        record('submit above the log', found.submitBottom < found.logTop ? [] : [`${at}: submit ends at ${found.submitBottom.toFixed(0)}, log starts at ${found.logTop.toFixed(0)}`], 1);
    }
}

const setData = (page, values) => page.evaluate((entries) => {
    const data = window.Alpine.$data(document.querySelector('[x-data]'));

    Object.entries(entries).forEach(([key, value]) => { data[key] = value; });
}, values);

async function openAs(browser, viewport, userId) {
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();

    await page.goto(stackUrl);
    await page.waitForFunction(() => document.querySelectorAll('#user-picker option').length > 1);
    await page.selectOption('#user-picker', String(userId));
    await page.waitForTimeout(600);

    return { context, page };
}

try {
    await seedRows();

    const browser = await chromium.launch({ executablePath: findChromium() });

    for (const viewport of VIEWPORTS) {
        // Operator.
        let { context, page } = await openAs(browser, viewport, 1);

        await measure(page, viewport, 'Ana, idle');

        // A real grant through the page: a quick amount, then submit.
        await page.click('#quick-amounts button:has-text("1")');
        await page.click('#request-submit');
        await page.waitForFunction(() => /granted/.test(document.getElementById('result-text').textContent), null, { timeout: 8000 });
        await measure(page, viewport, 'Ana, granted for real');

        for (const [name, status] of Object.entries(REQUEST_STATES)) {
            await setData(page, { status });
            await page.waitForTimeout(80);
            await measure(page, viewport, `Ana, ${name}`);
        }

        await context.close();

        // Supervisor: both tabs.
        ({ context, page } = await openAs(browser, viewport, 4));
        await measure(page, viewport, 'Dana, pool tab');
        await page.click('#tab-supervisor');
        await page.waitForTimeout(500);
        await measure(page, viewport, 'Dana, supervisor tab');

        for (const [name, [property, status]] of Object.entries(SUPERVISOR_STATES)) {
            await setData(page, { [property]: status });
            await page.waitForTimeout(80);
            await measure(page, viewport, `Dana, ${name}`);
        }

        await context.close();
    }

    await browser.close();
} catch (error) {
    fail(error.message);
}

let failed = false;

for (const [name, { offenders, measured }] of results) {
    const ok = offenders.length === 0;

    failed = failed || !ok;
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name} (${measured} measured${offenders.length ? `, ${offenders.length} offenders` : ''})`);
}

console.log(`smallest tap target ${smallestTarget.toFixed(1)} px; lowest text contrast ${lowestRatio.toFixed(2)}:1 (${skippedTotal} text elements over an image or gradient skipped)`);

if (failed) {
    console.log('\nOffenders:');

    for (const [name, { offenders }] of results) {
        [...new Set(offenders)].forEach((line) => console.log(`  [${name}] ${line}`));
    }
}

process.exit(failed ? 1 : 0);
