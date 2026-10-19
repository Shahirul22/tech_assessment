import { test } from 'node:test';
import assert from 'node:assert/strict';

import { returnTimeText, serverOffsetMs, timeLeftText, windowText } from '../time-left.js';

const MINUTE = 60 * 1000;
const HOUR = 60 * MINUTE;

test('time left reads in hours from 2 hours, minutes from 1 minute, then "under 1 min" and "now"', () => {
    assert.equal(timeLeftText(3 * HOUR), '3 h');
    assert.equal(timeLeftText(2 * HOUR), '2 h');
    assert.equal(timeLeftText(2 * HOUR - 1), '119 min');
    assert.equal(timeLeftText(HOUR + 59 * MINUTE), '119 min');
    assert.equal(timeLeftText(45 * MINUTE), '45 min');
    assert.equal(timeLeftText(MINUTE), '1 min');
    assert.equal(timeLeftText(59 * 1000), 'under 1 min');
    assert.equal(timeLeftText(1), 'under 1 min');
    assert.equal(timeLeftText(0), 'now');
    assert.equal(timeLeftText(-5000), 'now');
});

test('a window reads in hours, minutes or seconds', () => {
    assert.equal(windowText(86400), '24 h');
    assert.equal(windowText(259200), '72 h');
    assert.equal(windowText(120), '2 min');
    assert.equal(windowText(240), '4 min');
    assert.equal(windowText(30), '30 s');
});

test('the return time says today, tomorrow or the date, in the given time zone', () => {
    const now = Date.parse('2026-10-19T08:00:00Z');

    assert.equal(returnTimeText('2026-10-19T10:00:00.000000Z', now, 'UTC'), '10:00 today');
    assert.equal(returnTimeText('2026-10-20T10:00:00.000000Z', now, 'UTC'), '10:00 tomorrow');
    assert.equal(returnTimeText('2026-10-22T09:30:00.123456Z', now, 'UTC'), '22 Oct 09:30');
});

test('the return time follows the time zone, including a date change', () => {
    const now = Date.parse('2026-10-19T08:00:00Z');

    // 23:30 UTC is already the next morning in Singapore (UTC+8).
    assert.equal(returnTimeText('2026-10-19T23:30:00Z', now, 'Asia/Singapore'), '07:30 tomorrow');
    assert.equal(returnTimeText('2026-10-19T23:30:00Z', now, 'UTC'), '23:30 today');
});

test('the return time counts today from the server clock it is given, not the device', () => {
    const serverNow = Date.parse('2026-10-19T23:00:00Z');

    assert.equal(returnTimeText('2026-10-20T01:00:00Z', serverNow, 'UTC'), '01:00 tomorrow');
});

test('a client clock one hour ahead gives the same time left once the offset is applied', () => {
    const serverTime = '2026-10-19T10:00:00.123456Z';
    const serverMs = Date.parse('2026-10-19T10:00:00.123Z');
    const expiresMs = Date.parse('2026-10-19T13:00:00.123Z');

    for (const skew of [0, HOUR, -HOUR]) {
        const clientAtSnapshot = serverMs + skew;
        const offset = serverOffsetMs(serverTime, clientAtSnapshot);
        // Ten minutes later on the device's own clock.
        const clientLater = clientAtSnapshot + 10 * MINUTE;

        assert.equal(timeLeftText(expiresMs - (clientLater + offset)), '2 h', `skew ${skew}`);
    }
});

test('the offset is the server time minus the device time', () => {
    assert.equal(serverOffsetMs('2026-10-19T10:00:00.000000Z', Date.parse('2026-10-19T11:00:00Z')), -HOUR);
    assert.equal(serverOffsetMs('2026-10-19T10:00:00.500000Z', Date.parse('2026-10-19T10:00:00Z')), 500);
});
