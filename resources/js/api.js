// Talking to the API: submitting a request safely, and fetching snapshots and log pages (FR12, FR12.1, NFR5, DD8).
//
// The submit flow never guesses a total. It shows "pending" at once, leaves the numbers alone until the server has
// answered, and when the answer is lost it keeps the same idempotency key so a retry cannot be counted twice.
// `fetch`, the clock and the key generator are passed in, so the flow is tested without a browser.

import { validateAmount } from './form-rules.js';
import { applyOwnResult } from './pool-state.js';
import { returnTimeText } from './time-left.js';

/** A request that has not been answered after this long is "unconfirmed" (8 s). */
export const SUBMIT_TIMEOUT_MS = 8000;

export const OFFLINE_MESSAGE = 'You are offline — nothing was sent';

/**
 * A fresh idempotency key. crypto.randomUUID() only exists on secure pages (https or localhost), and the demo may be
 * opened from a phone over plain http, so fall back to building a v4 UUID from getRandomValues, which works everywhere.
 *
 * @param {{randomUUID?: () => string, getRandomValues: (bytes: Uint8Array) => Uint8Array}} [cryptoLike]
 */
export function randomKey(cryptoLike = globalThis.crypto) {
    if (typeof cryptoLike.randomUUID === 'function') {
        return cryptoLike.randomUUID();
    }

    const bytes = cryptoLike.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/**
 * The submit flow for one operator on one pool.
 *
 * `dispatch` receives two kinds of action:
 *   {type: 'status', status}        what to show: idle-form feedback, pending, done, error, unconfirmed, offline, invalid
 *   {type: 'state', state, effect}  the new pool state after the server confirmed, and what to do next (see pool-state.js)
 *
 * @param {object} options
 * @param {typeof fetch} options.fetch
 * @param {number|string} options.userId  sent as X-Demo-User
 * @param {number} options.poolId
 * @param {() => object} options.getState  the current pool state (for the remaining used by validation)
 * @param {() => string} [options.getType]  the chosen type, 'consumed' or 'temporary'; consumed when omitted
 * @param {(action: object) => void} options.dispatch
 * @param {() => boolean} options.isOnline
 * @param {() => string} [options.newKey]
 * @param {() => number} [options.now]  must be the same clock the caller uses for checkGap (performance.now)
 * @param {() => number} [options.epochNow]  the epoch clock (Date.now), for comparing with server times
 * @param {number} [options.timeoutMs]
 */
export function createSubmitFlow({ fetch, userId, poolId, getState, getType = () => 'consumed', dispatch, isOnline, newKey = randomKey, now = () => performance.now(), epochNow = () => Date.now(), timeoutMs = SUBMIT_TIMEOUT_MS }) {
    // The request being sent or waiting to be retried: {amount, type, key}. Null when nothing is outstanding.
    let attempt = null;
    let inFlight = false;

    const status = (value) => dispatch({ type: 'status', status: value });

    function unconfirmed(message) {
        inFlight = false;
        status({ state: 'unconfirmed', amount: attempt.amount, key: attempt.key, ...(message ? { message } : {}) });
    }

    async function send() {
        inFlight = true;
        // Synchronously, before the request is made: the screen shows it, and the numbers have not moved.
        status({ state: 'pending', amount: attempt.amount, key: attempt.key });

        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeoutMs);
        let response;

        try {
            response = await fetch(`/api/pools/${poolId}/allocations`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Demo-User': String(userId),
                    'Idempotency-Key': attempt.key,
                },
                body: JSON.stringify({ requested: attempt.amount, type: attempt.type }),
                signal: controller.signal,
            });
        } catch {
            // No answer: the request may or may not have arrived. Keep the key so Retry is safe.
            return unconfirmed();
        } finally {
            clearTimeout(timer);
        }

        if (response.status === 200 || response.status === 201) {
            let result;

            try {
                result = await response.json();
            } catch {
                return unconfirmed();
            }

            return confirmed(result, response.status === 200);
        }

        // An unexpected server failure does not say whether the entry was stored. Retrying with the same key is safe.
        if (response.status >= 500 && response.status !== 503) {
            return unconfirmed();
        }

        let body = null;

        try {
            body = await response.json();
        } catch {
            // Not JSON (for example a gateway page): fall through to the generic message.
        }

        attempt = null;
        inFlight = false;
        status({
            state: 'error',
            status: response.status,
            code: body?.error?.code ?? 'error',
            message: body?.error?.message ?? 'Something went wrong.',
        });
    }

    function confirmed(result, replayed) {
        const { amount } = attempt;
        const { state, effect } = applyOwnResult(getState(), result, now(), epochNow());

        attempt = null;
        inFlight = false;
        dispatch({ type: 'state', state, effect });
        status({
            state: 'done',
            outcome: result.allocation.outcome,
            requested: amount,
            granted: result.allocation.granted,
            type: result.allocation.type,
            expiresAt: result.allocation.expires_at ?? null,
            replayed,
            result,
        });
    }

    return {
        /**
         * @param {string|number} raw  what was typed in the form
         * @returns {Promise<{started: boolean, reason?: string}>}
         */
        async submit(raw) {
            if (inFlight) {
                return { started: false, reason: 'pending' };
            }

            // A request that may have been stored must be settled with Retry, not duplicated with a new key.
            if (attempt !== null) {
                return { started: false, reason: 'unconfirmed' };
            }

            const check = validateAmount(raw, getState().remaining);

            if (!check.ok) {
                status({ state: 'invalid', message: check.message });

                return { started: false, reason: 'invalid' };
            }

            // Offline: send nothing, keep nothing, queue nothing. Going back online sends nothing by itself.
            if (!isOnline()) {
                status({ state: 'offline', message: OFFLINE_MESSAGE });

                return { started: false, reason: 'offline' };
            }

            attempt = { amount: check.value, type: getType() === 'temporary' ? 'temporary' : 'consumed', key: newKey() };
            await send();

            return { started: true };
        },

        /**
         * Send the unconfirmed request again with the SAME key, so the server counts it once and returns the original result.
         *
         * @returns {Promise<{started: boolean, reason?: string}>}
         */
        async retry() {
            if (inFlight) {
                return { started: false, reason: 'pending' };
            }

            if (attempt === null) {
                return { started: false, reason: 'nothing to retry' };
            }

            if (!isOnline()) {
                unconfirmed(OFFLINE_MESSAGE);

                return { started: false, reason: 'offline' };
            }

            await send();

            return { started: true };
        },
    };
}

/**
 * The words under a finished request: "4 granted", "4 of 6 granted", "0 of 3 granted — none left", and for units that
 * come back, " · returns 10:00 tomorrow" (FR5.6, FR5.7).
 *
 * @param {{requested: number, granted: number, expiresAt?: string|null}} status  the `done` status
 * @param {number} serverNowMs  the server's time now, so "today" and "tomorrow" do not depend on the device clock
 * @param {string} [timeZone]  the viewer's time zone; the device's when omitted
 */
export function doneText({ requested, granted, expiresAt = null }, serverNowMs, timeZone) {
    if (granted === 0) {
        return `0 of ${requested} granted — none left`;
    }

    const base = granted === requested ? `${granted} granted` : `${granted} of ${requested} granted`;

    return expiresAt ? `${base} · returns ${returnTimeText(expiresAt, serverNowMs, timeZone)}` : base;
}

/**
 * GET /api/pools/{pool}: the snapshot. Throws when the server says no, so the caller keeps what it has.
 */
export async function fetchSnapshot({ fetch, userId, poolId, signal }) {
    return getJson(fetch, `/api/pools/${poolId}`, userId, signal);
}

/**
 * GET /api/pools/{pool}/log: older entries for "Show older".
 */
export async function fetchLog({ fetch, userId, poolId, before, limit, signal }) {
    const query = new URLSearchParams();

    if (before !== undefined && before !== null) {
        query.set('before', String(before));
    }

    if (limit !== undefined && limit !== null) {
        query.set('limit', String(limit));
    }

    const suffix = query.size > 0 ? `?${query}` : '';

    return getJson(fetch, `/api/pools/${poolId}/log${suffix}`, userId, signal);
}

async function getJson(fetch, url, userId, signal) {
    const response = await fetch(url, {
        headers: { Accept: 'application/json', 'X-Demo-User': String(userId) },
        signal,
    });

    if (!response.ok) {
        throw new Error(`${url} answered ${response.status}`);
    }

    return response.json();
}
