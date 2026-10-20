// The supervisor's screen: restock, correct or void, and the audit view (M3, FR10, FR6, FR18, FR18.1).
//
// Two parts, like the rest of the client:
//   createSupervisorFlow  the write flow: validate, show "pending", send with a fresh key (resending a lost answer with the
//                         same key, retry-policy.js), apply the answer.
//                         It takes `fetch`, the clocks and the key generator as arguments, so it is tested without a
//                         browser. It never guesses a total: the numbers move only when the server has answered.
//   supervisorPart        the pieces of Alpine state and methods that dashboard.js merges into poolDashboard().

import { OFFLINE_MESSAGE, fetchAudit, postCorrection, postRestock, randomKey } from './api.js';
import { auditView, EMPTY_AUDIT_TEXT } from './audit-view.js';
import { applyOwnCorrection, applyOwnRestock } from './pool-state.js';
import { bannerFor } from './banner-model.js';
import { createRetryRunner } from './retry-policy.js';
import { correctionProblem, restockProblem } from './supervisor-rules.js';

/** A request is on its way: the first send, an automatic resend, or a manual Retry. */
const PENDING_STATES = ['pending', 'resending', 'retrying'];

/**
 * The words for a refused correction, from the server's details (FR6.2, AC-US9.3).
 *
 * @param {{needed: number, free: number}} details
 */
export function refusalText({ needed, free }) {
    return `Needs ${needed} more units, ${free} are free. Restock first.`;
}

/**
 * The write flow of one supervisor on one pool. There are two channels, `restock` and `correction`, each with at most
 * one request outstanding.
 *
 * `dispatch` receives two kinds of action:
 *   {type: 'status', channel, status}  what to show: invalid, offline, pending, done, refused, error, unconfirmed
 *   {type: 'state', state, effect}     the new pool state after the server confirmed, and what to do next (pool-state.js)
 *
 * @param {object} options
 * @param {typeof fetch} options.fetch
 * @param {number|string} options.userId  sent as X-Demo-User
 * @param {number} options.poolId
 * @param {() => object} options.getState  the current pool state
 * @param {(action: object) => void} options.dispatch
 * @param {() => boolean} options.isOnline
 * @param {() => string} [options.newKey]
 * @param {() => number} [options.now]  the same clock the caller uses for checkGap (performance.now)
 * @param {() => number} [options.epochNow]  the epoch clock (Date.now)
 */
export function createSupervisorFlow({ fetch, userId, poolId, getState, dispatch, isOnline, newKey = randomKey, now = () => performance.now(), epochNow = () => Date.now(), sleep, waitsMs }) {
    const channels = {
        restock: { attempt: null, inFlight: false },
        correction: { attempt: null, inFlight: false },
    };
    // After dispose() nothing more is dispatched: the viewer changed.
    let disposed = false;

    const status = (channel, value) => {
        if (!disposed) {
            dispatch({ type: 'status', channel, status: value });
        }
    };

    // One runner per channel, so a restock and a correction resend independently.
    const runners = Object.fromEntries(Object.keys(channels).map((channel) => [channel, createRetryRunner({
        waitsMs,
        isOnline,
        sleep,
        onResending: ({ attempt: number, of, phase }) => status(channel, { state: 'resending', key: channels[channel].attempt.key, attempt: number, of, phase }),
    })]));

    /**
     * One call with the attempt's key. Resolves, never rejects: a definite answer, or no answer.
     * Offline it sends nothing, which counts as no answer; the runner then stops because the browser is offline.
     */
    async function sendOnce(channel) {
        const { attempt } = channels[channel];
        let reply;

        try {
            reply = await attempt.call({ fetch, userId, poolId, key: attempt.key, isOnline });
        } catch {
            // No answer: the request may or may not have arrived. The same key makes a resend safe.
            return { kind: 'no-answer' };
        }

        if (reply.offline) {
            return { kind: 'no-answer' };
        }

        if (reply.status === 200 || reply.status === 201) {
            return reply.body ? { kind: 'answered', value: reply } : { kind: 'no-answer' };
        }

        // A server failure (including 503 pool_busy) does not say whether the entry was stored: resend with the same key.
        return reply.status >= 500 ? { kind: 'no-answer' } : { kind: 'answered', value: reply };
    }

    /**
     * @param {'pending'|'retrying'} firstState  what the screen says while the first send of this round is out
     */
    async function send(channel, firstState) {
        const slot = channels[channel];
        const { attempt } = slot;

        slot.inFlight = true;
        // Synchronously, before the request is made: the screen shows it, and the numbers have not moved.
        status(channel, { state: firstState, key: attempt.key });

        const round = await runners[channel].run(() => sendOnce(channel));

        slot.inFlight = false;

        if (disposed) {
            return;
        }

        if (round.outcome === 'gave-up') {
            return status(channel, { state: 'unconfirmed', key: attempt.key });
        }

        if (round.outcome === 'stopped-offline') {
            return status(channel, { state: 'unconfirmed', key: attempt.key, message: OFFLINE_MESSAGE });
        }

        const reply = round.value;

        if (reply.status === 200 || reply.status === 201) {
            return confirmed(channel, reply.body, reply.status === 200);
        }

        slot.attempt = null;
        status(channel, failure(reply));
    }

    function confirmed(channel, result, replayed) {
        const slot = channels[channel];
        const apply = channel === 'restock' ? applyOwnRestock : applyOwnCorrection;
        const { state, effect } = apply(getState(), result, now(), epochNow());

        slot.attempt = null;
        slot.inFlight = false;
        dispatch({ type: 'state', state, effect });
        status(channel, { state: 'done', replayed, result });
    }

    async function start(channel, problem, call) {
        const slot = channels[channel];

        if (slot.inFlight) {
            return { started: false, reason: 'pending' };
        }

        // A request that may have been stored must be settled with Retry, not duplicated with a new key.
        if (slot.attempt !== null) {
            return { started: false, reason: 'unconfirmed' };
        }

        if (problem !== null) {
            status(channel, { state: 'invalid', message: problem });

            return { started: false, reason: 'invalid' };
        }

        // Offline: send nothing, keep nothing, queue nothing. Going back online sends nothing by itself.
        if (!isOnline()) {
            status(channel, { state: 'offline', message: OFFLINE_MESSAGE });

            return { started: false, reason: 'offline' };
        }

        slot.attempt = { key: newKey(), call };
        await send(channel, 'pending');

        return { started: true };
    }

    return {
        /**
         * @param {string|number} amountText  what was typed
         * @param {string} [noteText]
         */
        restock(amountText, noteText = '') {
            const problem = restockProblem(amountText);
            const amount = problem === null ? Number(String(amountText).trim()) : 0;
            const note = String(noteText ?? '').trim();

            return start('restock', problem, (options) => postRestock({ ...options, amount, note: note === '' ? null : note }));
        },

        /**
         * @param {{allocationSeq: number, kind: 'amend'|'void', amountText?: string, reasonText: string, currentUnits: number}} form
         */
        correct({ allocationSeq, kind, amountText, reasonText, currentUnits }) {
            const problem = correctionProblem({ kind, amountText, reasonText, currentUnits });
            const amount = problem === null && kind === 'amend' ? Number(String(amountText).trim()) : null;
            const reason = String(reasonText ?? '').trim();

            return start('correction', problem, (options) => postCorrection({ ...options, allocationSeq, kind, amount, reason }));
        },

        /**
         * Send the unconfirmed request again with the SAME key, so the server records it once and returns the original result.
         *
         * @param {'restock'|'correction'} channel
         */
        async retry(channel) {
            const slot = channels[channel];

            if (slot.inFlight) {
                return { started: false, reason: 'pending' };
            }

            if (slot.attempt === null) {
                return { started: false, reason: 'nothing to retry' };
            }

            if (!isOnline()) {
                status(channel, { state: 'unconfirmed', key: slot.attempt.key, message: OFFLINE_MESSAGE });

                return { started: false, reason: 'offline' };
            }

            await send(channel, 'retrying');

            return { started: true };
        },

        /** The browser went offline: cut any waiting resend short; each round then stops as "not confirmed". */
        goOffline() {
            Object.values(runners).forEach((runner) => runner.abortWait());
        },

        /** The viewer changed: stop resending and drop anything this flow would still dispatch. */
        dispose() {
            disposed = true;
            Object.values(runners).forEach((runner) => runner.dispose());
        },
    };
}

/**
 * What the screen says about an answer that recorded nothing.
 */
function failure(reply) {
    const error = reply.body?.error;

    if (error?.code === 'insufficient_free_units' && error.details) {
        return { state: 'refused', code: error.code, message: refusalText(error.details) };
    }

    if (error?.code === 'validation_failed') {
        const fields = error.details?.fields ?? {};

        if (fields.amount?.[0] === 'no_change') {
            return { state: 'error', status: reply.status, code: 'no_change', message: 'No change' };
        }

        const first = Object.values(fields).flat()[0];

        return { state: 'error', status: reply.status, code: error.code, message: first ?? error.message };
    }

    return {
        state: 'error',
        status: reply.status,
        code: error?.code ?? 'error',
        message: error?.message ?? 'Something went wrong.',
    };
}

/**
 * The supervisor's part of the dashboard component. dashboard.js merges it into poolDashboard() with the property
 * descriptors kept, so the getters stay getters. It uses what the dashboard has: `state`, `userId`, `pool`, `tab`,
 * `isSupervisor`, `isOffline`, `setState()`, `handle()` and `refresh()`.
 */
export function supervisorPart() {
    // Handles that must not be wrapped by Alpine's reactivity.
    let flow = null;
    let loadingAudit = false;

    return {
        // Restock form.
        restockAmount: '',
        restockNote: '',
        restockStatus: { state: 'idle' },

        // Correction form: the entry picked from the log, and what was typed.
        correction: { seq: null, units: null, operatorName: '' },
        correctionKind: 'amend',
        correctionAmount: '',
        correctionReason: '',
        correctionStatus: { state: 'idle' },

        // The audit view, already turned into text (audit-view.js).
        audit: { empty: true, emptyText: EMPTY_AUDIT_TEXT, rows: [], hindsight: [] },
        auditError: '',

        /** A fresh flow for the current viewer; a supervisor gets one, an operator none. */
        newSupervisorFlow() {
            // Stop the old viewer's resends and drop a late answer, so it cannot reach the new viewer's screen.
            flow?.dispose();
            this.restockAmount = '';
            this.restockNote = '';
            this.restockStatus = { state: 'idle' };
            this.clearCorrection();

            if (!this.isSupervisor || this.userId === null) {
                flow = null;

                return;
            }

            flow = createSupervisorFlow({
                fetch: window.fetch.bind(window),
                userId: this.userId,
                poolId: this.pool.id,
                getState: () => this.state,
                isOnline: () => navigator.onLine,
                now: () => performance.now(),
                dispatch: (action) => this.supervisorDispatch(action),
            });
        },

        supervisorDispatch(action) {
            if (action.type === 'state') {
                this.setState(action.state);
                this.handle(action.effect);

                return;
            }

            if (action.channel === 'restock') {
                this.restockStatus = action.status;

                if (action.status.state === 'done') {
                    this.restockAmount = '';
                    this.restockNote = '';
                }
            } else {
                this.correctionStatus = action.status;

                if (action.status.state === 'done') {
                    this.clearCorrection(true);
                }
            }

            // A confirmed write changes the audit; read it again (FR16).
            if (action.status.state === 'done' && !action.status.replayed) {
                this.loadAudit();
            }
        },

        /** The browser went offline: waiting resends stop and show as not confirmed. */
        supervisorGoOffline() {
            flow?.goOffline();
        },

        submitRestock() {
            return flow?.restock(this.restockAmount, this.restockNote);
        },

        retryRestock() {
            return flow?.retry('restock');
        },

        submitCorrection() {
            if (this.correction.seq === null) {
                this.correctionStatus = { state: 'invalid', message: 'Pick an entry in the log with its Correct button' };

                return undefined;
            }

            return flow?.correct({
                allocationSeq: this.correction.seq,
                kind: this.correctionKind,
                amountText: this.correctionAmount,
                reasonText: this.correctionReason,
                currentUnits: this.correction.units,
            });
        },

        retryCorrection() {
            return flow?.retry('correction');
        },

        /** The "Correct" button of a log row: fill the form with the entry and what it holds now. */
        startCorrection(row) {
            this.correction = { seq: row.seq, units: row.units, operatorName: row.operatorName };
            this.correctionKind = 'amend';
            this.correctionAmount = '';
            this.correctionReason = '';
            this.correctionStatus = { state: 'idle' };
            this.tab = 'supervisor';
        },

        clearCorrection(keepResult = false) {
            this.correction = { seq: null, units: null, operatorName: '' };
            this.correctionKind = 'amend';
            this.correctionAmount = '';
            this.correctionReason = '';

            if (!keepResult) {
                this.correctionStatus = { state: 'idle' };
            }
        },

        /** Typing clears an old result so a stale banner is not mistaken for the new request. */
        restockEdited() {
            if (['done', 'error', 'refused', 'invalid', 'offline'].includes(this.restockStatus.state)) {
                this.restockStatus = { state: 'idle' };
            }
        },

        correctionEdited() {
            if (['done', 'error', 'refused', 'invalid', 'offline'].includes(this.correctionStatus.state)) {
                this.correctionStatus = { state: 'idle' };
            }
        },

        /** Read the audit and turn it into text. A failure keeps what is shown and says so. */
        async loadAudit() {
            if (loadingAudit || !this.isSupervisor || this.userId === null) {
                return;
            }

            loadingAudit = true;

            try {
                const audit = await fetchAudit({ fetch: window.fetch.bind(window), userId: this.userId, poolId: this.pool.id });

                this.audit = auditView(audit);
                this.auditError = '';
            } catch {
                this.auditError = 'Could not load the audit. Try again.';
            } finally {
                loadingAudit = false;
            }
        },

        // Restock form state.

        get restockMessage() {
            if (this.restockStatus.state === 'invalid') {
                return this.restockStatus.message;
            }

            return String(this.restockAmount ?? '').trim() !== '' ? (restockProblem(this.restockAmount) ?? '') : '';
        },

        get isRestockPending() {
            return PENDING_STATES.includes(this.restockStatus.state);
        },

        get isRestockUnconfirmed() {
            return this.restockStatus.state === 'unconfirmed';
        },

        get restockDisabled() {
            return restockProblem(this.restockAmount) !== null || this.isRestockPending || this.isRestockUnconfirmed || this.isOffline;
        },

        get restockLabel() {
            return this.isRestockPending ? 'Sending…' : 'Add units';
        },

        get restockBanner() {
            return bannerFor(this.restockStatus, {
                channel: 'restock',
                doneText: (status) => `Added ${status.result.restock.amount} units. Remaining: ${status.result.pool.remaining}`,
            });
        },

        // Correction form state.

        get correctionTarget() {
            const { seq, units, operatorName } = this.correction;

            return seq === null
                ? 'Pick an entry in the log with its Correct button.'
                : `Entry #${seq} · ${operatorName} · holds ${units} now`;
        },

        get correctionMessage() {
            if (this.correctionStatus.state === 'invalid') {
                return this.correctionStatus.message;
            }

            if (this.correction.seq === null) {
                return '';
            }

            const typed = this.correctionAmount !== '' || this.correctionReason !== '' || this.correctionKind === 'void';

            return typed ? (correctionProblem(this.correctionInput) ?? '') : '';
        },

        get correctionInput() {
            return {
                kind: this.correctionKind,
                amountText: this.correctionAmount,
                reasonText: this.correctionReason,
                currentUnits: this.correction.units,
            };
        },

        get isCorrectionPending() {
            return PENDING_STATES.includes(this.correctionStatus.state);
        },

        get isCorrectionUnconfirmed() {
            return this.correctionStatus.state === 'unconfirmed';
        },

        get correctionDisabled() {
            return this.correction.seq === null
                || correctionProblem(this.correctionInput) !== null
                || this.isCorrectionPending
                || this.isCorrectionUnconfirmed
                || this.isOffline;
        },

        get correctionLabel() {
            if (this.isCorrectionPending) {
                return 'Sending…';
            }

            return this.correctionKind === 'void' ? 'Void entry' : 'Correct entry';
        },

        get correctionBanner() {
            return bannerFor(this.correctionStatus, {
                channel: 'correction',
                doneText: (status) => {
                    const { correction, pool } = status.result;

                    return correction.kind === 'void'
                        ? `Voided entry #${correction.allocation_seq} (${correction.from} → 0). Remaining: ${pool.remaining}`
                        : `Entry #${correction.allocation_seq} changed ${correction.from} → ${correction.to}. Remaining: ${pool.remaining}`;
                },
            });
        },
    };
}
