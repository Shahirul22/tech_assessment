// Drives the real page through offline, lost answers and Retry, and counts what the server stored (M4: FR21.3, FR21.5, FR21.6, NFR5,
// AC-US4.2, AC-US4.2a, AC-US4.4, AC-US5.1-5.5).
//
// As Ana, in a real Chromium against the running stack, five cases. After each one the allocations Ana has are counted through
// GET /api/pools/{id}/log, so "at most one allocation per submit" is read from the server, not from the screen:
//   1. offline: submit sends nothing; nothing is sent by itself when the connection returns
//   2. the answer is lost after the server stored the request: the page resends the same key and shows the original result once
//   3. every send fails: exactly 4 sends with one key, then "Not confirmed" with Retry and nothing more is sent; Retry settles it
//   4. ten offline/online toggles during one submit, then Retry taps: at most one allocation, one key
//   5. a 422 and a 409 answer are never resent
// One PASS or FAIL line per case; exit code 0 when all pass, 1 otherwise.
//
// Run it on the host, against the running stack (the one browser step that does not run in Docker, plan DD14):
//   node tests/Live/offline-flow.mjs
// Settings come from the environment:
//   STACK_URL       the web server (default http://localhost:8080)
//   CHROMIUM_PATH   the Chromium to drive (default: the newest ~/.cache/ms-playwright/chromium-*/chrome-linux64/chrome)
//   POOL_ID, USER_ID   default 1 and 1 (Ana)
// Chromium needs its system libraries (for example libasound2); playwright-core is a dev dependency and downloads no browser.
//
// It consumes about 4 units from the pool. Run it on a fresh seed, or reseed afterwards:
//   docker compose run --rm app php artisan migrate:fresh --seed --force

import { existsSync, readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { chromium } from 'playwright-core';

const env = process.env;
const stackUrl = (env.STACK_URL ?? 'http://localhost:8080').replace(/\/$/, '');
const poolId = Number(env.POOL_ID ?? 1);
const userId = Number(env.USER_ID ?? 1);
const endpoint = `**/api/pools/${poolId}/allocations`;

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

/** How many allocations the user has, read from the server's own log. */
async function allocationCount() {
    let count = 0;
    let before = null;

    for (;;) {
        const response = await fetch(`${stackUrl}/api/pools/${poolId}/log?limit=50${before ? `&before=${before}` : ''}`, { headers: { 'X-Demo-User': String(userId), Accept: 'application/json' } });
        const page = await response.json();

        count += page.entries.filter((entry) => entry.kind === 'allocation' && entry.operator?.id === userId).length;

        if (!page.has_more) {
            return count;
        }

        before = page.entries.at(-1).cursor;
    }
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** A fresh page as the user, with every POST to the allocations endpoint recorded. */
async function openPage(browser) {
    const context = await browser.newContext({ viewport: { width: 360, height: 640 } });
    const page = await context.newPage();
    const sent = [];

    page.on('request', (request) => {
        if (request.method() === 'POST' && request.url().includes(`/api/pools/${poolId}/allocations`)) {
            sent.push(request.headers()['idempotency-key']);
        }
    });

    await page.goto(stackUrl);
    await page.waitForFunction(() => document.querySelectorAll('#user-picker option').length > 1);
    await page.selectOption('#user-picker', String(userId));
    await page.waitForTimeout(600);

    return { context, page, sent };
}

const bannerText = (page) => page.locator('#result-text').textContent().then((text) => text.trim());
const submit = (page, amount) => page.evaluate((value) => {
    const data = window.Alpine.$data(document.querySelector('[x-data]'));

    data.amount = String(value);

    return data.submit();
}, amount);
const waitForBanner = (page, pattern, timeout = 15000) => page.waitForFunction((source) => new RegExp(source).test(document.getElementById('result-text').textContent), pattern.source, { timeout });
const distinct = (keys) => [...new Set(keys)];

function expectEqual(actual, expected, what) {
    if (JSON.stringify(actual) !== JSON.stringify(expected)) {
        throw new Error(`${what}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
    }
}

const cases = [
    ['offline: nothing is sent, and nothing when the connection returns', async (browser) => {
        const { context, page, sent } = await openPage(browser);
        const before = await allocationCount();

        await context.setOffline(true);
        await page.waitForTimeout(300);
        await submit(page, 3);
        expectEqual(await bannerText(page), 'Offline — nothing was sent', 'banner while offline');

        await context.setOffline(false);
        await page.waitForTimeout(3500);
        expectEqual(sent.length, 0, 'requests sent');
        expectEqual((await allocationCount()) - before, 0, 'allocations created');
        await context.close();
    }],

    ['answer lost after the server stored it: the same key is resent and the original result shows once', async (browser) => {
        const { context, page, sent } = await openPage(browser);
        const before = await allocationCount();
        let first = true;

        await page.route(endpoint, async (route) => {
            if (first) {
                first = false;
                await route.fetch();
                await route.abort();

                return;
            }

            await route.continue();
        });

        const started = submit(page, 3);

        await page.waitForFunction(() => /Retrying automatically, attempt 1 of 3/.test(document.getElementById('result-text').textContent), null, { timeout: 5000 });
        await waitForBanner(page, /^3 granted/);
        await started;

        expectEqual(sent.length, 2, 'sends');
        expectEqual(distinct(sent).length, 1, 'distinct keys');
        expectEqual((await allocationCount()) - before, 1, 'allocations created');
        await context.close();
    }],

    ['every send fails: 4 sends with one key, then Not confirmed with Retry, nothing more by itself; Retry settles it once', async (browser) => {
        const { context, page, sent } = await openPage(browser);
        const before = await allocationCount();

        await page.route(endpoint, (route) => route.abort());
        await submit(page, 3);
        await waitForBanner(page, /^Not confirmed/);

        expectEqual(sent.length, 4, 'sends');
        expectEqual(distinct(sent).length, 1, 'distinct keys');
        // Alpine reveals an x-show element in a timeout, a few milliseconds after the text changes.
        await page.locator('#retry').waitFor({ state: 'visible', timeout: 2000 }).catch(() => {});
        expectEqual(await page.locator('#retry').isVisible(), true, 'Retry visible');

        await page.waitForTimeout(2500);
        expectEqual(sent.length, 4, 'sends after waiting');

        await page.unroute(endpoint);
        await page.click('#retry');
        await waitForBanner(page, /^3 granted/);

        expectEqual(distinct(sent).length, 1, 'distinct keys after Retry');
        expectEqual((await allocationCount()) - before, 1, 'allocations created');
        await context.close();
    }],

    ['ten offline/online toggles during one submit and Retry taps: at most one allocation, one key', async (browser) => {
        const { context, page, sent } = await openPage(browser);
        const before = await allocationCount();

        // The first request waits two seconds, so the toggling happens while it is outstanding.
        await page.route(endpoint, async (route) => {
            await sleep(2000);
            await route.continue().catch(() => {});
        });

        const started = submit(page, 3);

        for (let toggle = 0; toggle < 10; toggle++) {
            await context.setOffline(toggle % 2 === 0);
            await sleep(120);
        }

        await context.setOffline(false);
        await page.waitForTimeout(500);

        // Settle it the way a person would: wait, then press Retry (up to three times) while it shows Not confirmed.
        for (let tap = 0; tap < 3; tap++) {
            const settled = await page.waitForFunction(() => /granted|Not confirmed/.test(document.getElementById('result-text').textContent), null, { timeout: 20000 }).then(() => true, () => false);

            if (!settled || /granted/.test(await bannerText(page))) {
                break;
            }

            await page.click('#retry');
        }

        await started.catch(() => {});
        await waitForBanner(page, /granted/, 20000);

        expectEqual(distinct(sent).length, 1, 'distinct keys');
        expectEqual((await allocationCount()) - before <= 1, true, 'at most one allocation');
        await context.close();
    }],

    ['a 422 and a 409 answer are never resent', async (browser) => {
        const { context, page, sent } = await openPage(browser);
        const before = await allocationCount();

        for (const [status, code] of [[422, 'validation_failed'], [409, 'idempotency_key_conflict']]) {
            await page.route(endpoint, (route) => route.fulfill({
                status,
                contentType: 'application/json',
                body: JSON.stringify({ error: { code, message: `no-${status}`, details: {} } }),
            }));

            const count = sent.length;

            await submit(page, 3);
            await waitForBanner(page, new RegExp(`^Refused — no-${status}`), 5000);
            await page.waitForTimeout(1500);
            expectEqual(sent.length - count, 1, `sends for ${status}`);
            await page.unroute(endpoint);
        }

        expectEqual((await allocationCount()) - before, 0, 'allocations created');
        await context.close();
    }],
];

let failed = 0;
const browser = await chromium.launch({ executablePath: findChromium() });

for (const [index, [name, run]] of cases.entries()) {
    try {
        await run(browser);
        console.log(`PASS  case ${index + 1}  ${name}`);
    } catch (error) {
        failed += 1;
        console.log(`FAIL  case ${index + 1}  ${name}\n      ${error.message}`);
    }
}

await browser.close();
console.log(`${cases.length - failed} of ${cases.length} cases passed`);
process.exit(failed === 0 ? 0 : 1);
