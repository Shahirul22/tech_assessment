import { test } from 'node:test';
import assert from 'node:assert/strict';

import { STORAGE_KEY, chooseViewer, loadViewerId, saveViewerId } from '../viewer.js';

const users = [
    { id: 1, name: 'Ana', role: 'operator', tier: 'bronze' },
    { id: 2, name: 'Ben', role: 'operator', tier: 'silver' },
    { id: 4, name: 'Dana', role: 'supervisor', tier: null },
];

const memoryStorage = (initial = {}) => {
    const data = { ...initial };

    return { getItem: (key) => data[key] ?? null, setItem: (key, value) => { data[key] = String(value); }, data };
};

const brokenStorage = {
    getItem() { throw new Error('storage blocked'); },
    setItem() { throw new Error('storage blocked'); },
};

test('the storage key is fo.userId', () => {
    assert.equal(STORAGE_KEY, 'fo.userId');
});

test('reads the stored user id as a number', () => {
    assert.equal(loadViewerId(memoryStorage({ 'fo.userId': '2' })), 2);
});

test('nothing stored, or something that is not an id, reads as null', () => {
    for (const raw of [undefined, '', '  ', 'abc', '1.5', '-1', '0']) {
        assert.equal(loadViewerId(memoryStorage(raw === undefined ? {} : { 'fo.userId': raw })), null, JSON.stringify(raw));
    }
});

test('storage that throws (a private window, blocked site data) reads as null and saves nothing', () => {
    assert.equal(loadViewerId(brokenStorage), null);
    assert.equal(saveViewerId(brokenStorage, 2), false);
});

test('saves the id under the key', () => {
    const storage = memoryStorage();

    assert.equal(saveViewerId(storage, 2), true);
    assert.equal(storage.data['fo.userId'], '2');
});

test('picks the stored user when that user exists', () => {
    assert.deepEqual(chooseViewer(users, 2), users[1]);
    assert.deepEqual(chooseViewer(users, 4), users[2]);
});

test('falls back to the first operator when nothing usable is stored', () => {
    assert.deepEqual(chooseViewer(users, null), users[0]);
    assert.deepEqual(chooseViewer(users, 999), users[0], 'a stored id that no longer exists');
});

test('falls back to the first user when there is no operator, and to null when there are no users', () => {
    assert.deepEqual(chooseViewer([users[2]], null), users[2]);
    assert.equal(chooseViewer([], 1), null);
});
