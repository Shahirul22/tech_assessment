// Talking to the API: submitting a request safely, and fetching snapshots and log pages (FR12, FR12.1, NFR5, DD8).
//
// The submit flow never guesses a total. It shows "pending" at once, leaves the numbers alone until the server has
// answered, and when the answer is lost it resends with the same idempotency key, so a resend cannot be counted twice.
// `fetch`, the clock and the key generator are passed in, so the flow is tested without a browser.

import { validateAmount } from './form-rules.js';
import { applyOwnResult } from './pool-state.js';
import { createRetryRunner } from './retry-policy.js';
import { returnTimeText } from './time-left.js';

/** A send that has not been answered after this long counts as "no answer" (3 s); it is then resent with the same key. */
export const SUBMIT_TIMEOUT_MS = 3000;

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
 * A send that gets no answer (a network error, the timeout, an unreadable 2xx, any 5xx) is sent again by itself up to
 * three times with the SAME idempotency key, only while the browser is online (retry-policy.js). If all fail, or the
 * device goes offline, the request is "unconfirmed" and Retry starts a fresh round with the same key. A definite
 * answer (2xx, 4xx) is never resent.
 *
 * `dispatch` receives two kinds of action:
 *   {type: 'status', status}        what to show: pending, resending, retrying, done, error, unconfirmed, offline, invalid
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
 * @param {(ms: number) => Promise<void>} [options.sleep]  the wait between resends; a real timer when omitted
 * @param {number[]} [options.waitsMs]  the waits before each resend (1 s, 2 s, 4 s when omitted)
 */
export function createSubmitFlow({ fetch, userId, poolId, getState, getType = () => 'consumed', dispatch, isOnline, newKey = randomKey, now = () => performance.now(), epochNow = () => Date.now(), timeoutMs = SUBMIT_TIMEOUT_MS, sleep, waitsMs }) {
    // The request being sent or waiting to be retried: {amount, type, key}. Null when nothing is outstanding.
    let attempt = null;
    let inFlight = false;
    // After dispose() nothing more is dispatched: the viewer changed, and a late answer must not reach the new screen.
    let disposed = false;

    const status = (value) => {
        if (!disposed) {
            dispatch({ type: 'status', status: value });
        }
    };

    const runner = createRetryRunner({
        waitsMs,
        isOnline,
        sleep,
        onResending: ({ attempt: number, of, phase }) => status({ state: 'resending', amount: attempt.amount, key: attempt.key, attempt: number, of, phase }),
    });

    function unconfirmed(message) {
        inFlight = false;
        status({ state: 'unconfirmed', amount: attempt.amount, key: attempt.key, ...(message ? { message } : {}) });
    }

    /**
     * One POST with the attempt's key. Resolves, never rejects: a definite answer, or no answer.
     *
     * @returns {Promise<{kind: 'answered', value: object} | {kind: 'no-answer'}>}
     */
    async function sendOnce() {
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
            // No answer: the request may or may not have arrived. The same key makes a resend safe.
            return { kind: 'no-answer' };
        } finally {
            clearTimeout(timer);
        }

        if (response.status === 200 || response.status === 201) {
            try {
                return { kind: 'answered', value: { confirmed: true, result: await response.json(), replayed: response.status === 200 } };
            } catch {
                return { kind: 'no-answer' };
            }
        }

        // A server failure (including 503 pool_busy) does not say whether the entry was stored: resend with the same key.
        if (response.status >= 500) {
            return { kind: 'no-answer' };
        }

        let body = null;

        try {
            body = await response.json();
        } catch {
            // Not JSON (for example a gateway page): fall through to the generic message.
        }

        return { kind: 'answered', value: { confirmed: false, status: response.status, body } };
    }

    /**
     * @param {'pending'|'retrying'} firstState  what the screen says while the first send of this round is out
     */
    async function send(firstState) {
        inFlight = true;
        // Synchronously, before the request is made: the screen shows it, and the numbers have not moved.
        status({ state: firstState, amount: attempt.amount, key: attempt.key });

        const round = await runner.run(sendOnce);

        if (disposed) {
            inFlight = false;

            return;
        }

        if (round.outcome === 'gave-up') {
            return unconfirmed();
        }

        if (round.outcome === 'stopped-offline') {
            return unconfirmed(OFFLINE_MESSAGE);
        }

        const { value } = round;

        if (value.confirmed) {
            return confirmed(value.result, value.replayed);
        }

        attempt = null;
        inFlight = false;
        status({
            state: 'error',
            status: value.status,
            code: value.body?.error?.code ?? 'error',
            message: value.body?.error?.message ?? 'Something went wrong.',
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
            await send('pending');

            return { started: true };
        },

        /**
         * Send the unconfirmed request again with the SAME key, so the server counts it once and returns the original
         * result. It starts a fresh round: if it again gets no answer, up to three more automatic resends follow.
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

            await send('retrying');

            return { started: true };
        },

        /** The browser went offline: cut a waiting resend short; the round then stops as "not confirmed". */
        goOffline() {
            runner.abortWait();
        },

        /** The viewer changed: stop resending and drop anything this flow would still dispatch. */
        dispose() {
            disposed = true;
            runner.dispose();
        },
    };
}

/**
 * The words under a finished request: "4 granted", "4 of 6 granted", "None granted — 0 of 3, none left", and for units that
 * come back, " · returns 10:00 tomorrow" (FR5.6, FR5.7).
 *
 * @param {{requested: number, granted: number, expiresAt?: string|null}} status  the `done` status
 * @param {number} serverNowMs  the server's time now, so "today" and "tomorrow" do not depend on the device clock
 * @param {string} [timeZone]  the viewer's time zone; the device's when omitted
 */
export function doneText({ requested, granted, expiresAt = null }, serverNowMs, timeZone) {
    if (granted === 0) {
        return `None granted — 0 of ${requested}, none left`;
    }

    const base = granted === requested ? `${granted} granted` : `${granted} of ${requested} granted`;

    return expiresAt ? `${base} · returns ${returnTimeText(expiresAt, serverNowMs, timeZone)}` : base;
}

/**
 * POST /api/pools/{pool}/restocks (supervisor). One user action is one call with its own fresh idempotency key; a retry
 * of the same action passes the same key. Offline it sends nothing and queues nothing.
 *
 * Resolves to {status, body, key} for any answer the server gave, including a refusal (the caller reads `body.error`).
 * Resolves to {offline: true, message} without sending when the device is offline. Rejects when no answer came back
 * (a lost connection or the timeout): the request may have been stored, so the caller offers a retry with the same key.
 *
 * @param {object} options
 * @param {typeof fetch} options.fetch
 * @param {number|string} options.userId
 * @param {number} options.poolId
 * @param {number} options.amount
 * @param {string|null} [options.note]
 * @param {string} [options.key]  the idempotency key; a new one when omitted
 * @param {() => boolean} [options.isOnline]
 * @param {number} [options.timeoutMs]
 */
export function postRestock({ fetch, userId, poolId, amount, note = null, key = randomKey(), isOnline = () => true, timeoutMs = SUBMIT_TIMEOUT_MS }) {
    return postJson({
        fetch, userId, key, isOnline, timeoutMs,
        url: `/api/pools/${poolId}/restocks`,
        body: note === null || note === '' ? { amount } : { amount, note },
    });
}

/**
 * POST /api/pools/{pool}/allocations/{seq}/corrections (supervisor). Same contract as postRestock.
 *
 * @param {object} options
 * @param {number} options.allocationSeq  the entry number the log shows
 * @param {'amend'|'void'} options.kind
 * @param {number|null} [options.amount]  the new units for an amend; left out for a void
 * @param {string} options.reason
 */
export function postCorrection({ fetch, userId, poolId, allocationSeq, kind, amount = null, reason, key = randomKey(), isOnline = () => true, timeoutMs = SUBMIT_TIMEOUT_MS }) {
    return postJson({
        fetch, userId, key, isOnline, timeoutMs,
        url: `/api/pools/${poolId}/allocations/${allocationSeq}/corrections`,
        body: kind === 'void' ? { kind, reason } : { kind, amount, reason },
    });
}

/**
 * GET /api/pools/{pool}/audit (supervisor): the audit view. Throws when the server says no.
 */
export async function fetchAudit({ fetch, userId, poolId, signal }) {
    return getJson(fetch, `/api/pools/${poolId}/audit`, userId, signal);
}

async function postJson({ fetch, userId, url, body, key, isOnline, timeoutMs }) {
    if (!isOnline()) {
        return { offline: true, message: OFFLINE_MESSAGE, key };
    }

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Demo-User': String(userId),
                'Idempotency-Key': key,
            },
            body: JSON.stringify(body),
            signal: controller.signal,
        });
        let parsed = null;

        try {
            parsed = await response.json();
        } catch {
            // Not JSON (for example a gateway page): the caller sees a status and no body.
        }

        return { status: response.status, body: parsed, key };
    } finally {
        clearTimeout(timer);
    }
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
