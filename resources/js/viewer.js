// Who is looking at the screen (FR9, A10). The role switch stores the chosen demo user in localStorage.
// This is a convenience for the demo, not authentication.

export const STORAGE_KEY = 'fo.userId';

/**
 * The stored user id, or null when there is none or it is unusable. Storage can throw (a private window, blocked
 * site data), and that must never break the page.
 *
 * @param {Pick<Storage, 'getItem'>} storage
 * @returns {number|null}
 */
export function loadViewerId(storage) {
    try {
        const raw = storage.getItem(STORAGE_KEY);

        return raw !== null && /^[1-9][0-9]*$/.test(raw.trim()) ? Number(raw) : null;
    } catch {
        return null;
    }
}

/**
 * @param {Pick<Storage, 'setItem'>} storage
 * @returns {boolean} whether it was saved
 */
export function saveViewerId(storage, id) {
    try {
        storage.setItem(STORAGE_KEY, String(id));

        return true;
    } catch {
        return false;
    }
}

/**
 * The user to act as: the stored one if it still exists, otherwise the first operator, otherwise the first user.
 *
 * @param {Array<{id: number, role: string}>} users
 * @param {number|null} storedId
 */
export function chooseViewer(users, storedId) {
    return users.find((user) => user.id === storedId)
        ?? users.find((user) => user.role === 'operator')
        ?? users[0]
        ?? null;
}
