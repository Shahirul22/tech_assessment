import { afterEach, beforeEach, test, mock } from 'node:test';
import assert from 'node:assert/strict';

import { createConnection } from '../connection.js';

beforeEach(() => {
    mock.timers.enable({ apis: ['setInterval', 'setTimeout'] });
});

afterEach(() => {
    mock.timers.reset();
});

function setup(options = {}) {
    const states = [];
    const snapshots = { count: 0 };
    const connection = createConnection({
        onState: (state) => states.push(state),
        onSnapshotNeeded: () => { snapshots.count += 1; },
        isOnline: true,
        ...options,
    });

    return { connection, states, snapshots };
}

test('starts as reconnecting until the socket connects, and says so once', () => {
    const { states } = setup();

    assert.deepEqual(states, ['reconnecting']);
});

test('is live when the socket is connected and the browser is online, with one snapshot on arrival', () => {
    const { connection, states, snapshots } = setup();

    connection.socketState('connecting');
    connection.socketState('connected');

    assert.deepEqual(states, ['reconnecting', 'live']);
    assert.equal(snapshots.count, 1);
});

test('does not poll while live', () => {
    const { connection, snapshots } = setup();
    connection.socketState('connected');
    const afterArrival = snapshots.count;

    mock.timers.tick(60_000);

    assert.equal(snapshots.count, afterArrival);
});

test('live, then reconnecting, then live: polls only while down and takes one snapshot on return', () => {
    const { connection, states, snapshots } = setup();
    connection.socketState('connected');
    assert.equal(snapshots.count, 1);

    connection.socketState('unavailable');
    assert.equal(states.at(-1), 'reconnecting');

    mock.timers.tick(9_999);
    assert.equal(snapshots.count, 1, 'no tick before 10 s');

    mock.timers.tick(1);
    assert.equal(snapshots.count, 2, 'first tick at 10 s');

    mock.timers.tick(20_000);
    assert.equal(snapshots.count, 4, 'three ticks in 30 s');

    connection.socketState('connected');
    assert.equal(states.at(-1), 'live');
    assert.equal(snapshots.count, 5, 'exactly one snapshot on the way back to live');

    mock.timers.tick(60_000);
    assert.equal(snapshots.count, 5, 'polling stopped');
});

test('any socket state other than connected is not live', () => {
    for (const bad of ['initialized', 'connecting', 'unavailable', 'failed', 'disconnected']) {
        const { connection, states } = setup();
        connection.socketState('connected');
        connection.socketState(bad);

        assert.equal(states.at(-1), 'reconnecting', bad);
    }
});

test('a browser that is offline overrides everything else', () => {
    const { connection, states } = setup();
    connection.socketState('connected');

    connection.browserOnline(false);
    assert.equal(states.at(-1), 'offline', 'offline even though the socket still says connected');

    connection.socketState('disconnected');
    connection.socketState('connecting');
    assert.equal(states.at(-1), 'offline', 'socket changes do not lift it');
});

test('coming back online with the socket still down is reconnecting, not live, and takes no snapshot yet', () => {
    const { connection, states, snapshots } = setup({ isOnline: false });
    connection.socketState('disconnected');
    assert.equal(states.at(-1), 'offline');
    const before = snapshots.count;

    connection.browserOnline(true);

    assert.equal(states.at(-1), 'reconnecting');
    assert.equal(snapshots.count, before);

    connection.socketState('connected');
    assert.equal(states.at(-1), 'live');
    assert.equal(snapshots.count, before + 1);
});

test('coming back online with a connected socket is live, with one snapshot', () => {
    const { connection, states, snapshots } = setup();
    connection.socketState('connected');
    connection.browserOnline(false);
    const before = snapshots.count;

    connection.browserOnline(true);

    assert.equal(states.at(-1), 'live');
    assert.equal(snapshots.count, before + 1);
});

test('only reports a state when it changes', () => {
    const { connection, states } = setup();

    connection.socketState('connecting');
    connection.socketState('unavailable');
    connection.socketState('connected');
    connection.socketState('connected');
    connection.browserOnline(true);

    assert.deepEqual(states, ['reconnecting', 'live']);
});

test('the poll clock starts again each time the connection drops', () => {
    const { connection, snapshots } = setup();
    connection.socketState('connected');
    mock.timers.tick(50_000);
    const base = snapshots.count;

    connection.socketState('disconnected');
    mock.timers.tick(9_999);
    assert.equal(snapshots.count, base, 'ten full seconds after the drop, not after the page loaded');

    mock.timers.tick(1);
    assert.equal(snapshots.count, base + 1);
});

test('uses the poll interval it is given', () => {
    const { snapshots } = setup({ pollMs: 2_000 });

    mock.timers.tick(6_000);

    assert.equal(snapshots.count, 3);
});

test('polls while the browser is offline too, since "not live" is the rule', () => {
    const { snapshots } = setup({ isOnline: false });

    mock.timers.tick(30_000);

    assert.equal(snapshots.count, 3);
});

test('dispose stops polling and ignores later input', () => {
    const { connection, states, snapshots } = setup();

    connection.dispose();
    mock.timers.tick(60_000);
    connection.socketState('connected');
    connection.browserOnline(false);

    assert.equal(snapshots.count, 0);
    assert.deepEqual(states, ['reconnecting']);
});
