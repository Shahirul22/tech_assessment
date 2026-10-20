// The Alpine component behind the dashboard: it glues the pure modules (pool-state, log-renderer, viewer, connection)
// to the page. The logic lives in those modules and is tested there; this file only wires them to the DOM and the network.

import { quickAmounts, stepAmount } from './amount-controls.js';
import { createSubmitFlow, fetchLog, fetchSnapshot } from './api.js';
import { bannerFor } from './banner-model.js';
import { createConnection } from './connection.js';
import { connectEcho } from './echo.js';
import { createExpiryTimer } from './expiry-timer.js';
import { maxAmount, validateAmount } from './form-rules.js';
import { renderEntry } from './log-renderer.js';
import { applyEvent, applyOlderPage, applySnapshot, checkGap, initialState, nextExpiryAt, oldestCursor } from './pool-state.js';
import { supervisorPart } from './supervisor.js';
import { parseInstant, timeLeftText, windowText } from './time-left.js';
import { chooseViewer, loadViewerId, saveViewerId } from './viewer.js';

/** How often an open gap is checked, so a snapshot is requested once it has lasted 500 ms. */
const GAP_CHECK_MS = 250;

/** How often "expires in" is recomputed. */
const CLOCK_TICK_MS = 1000;

const INDICATOR_TEXT = { live: 'Live', reconnecting: 'Reconnecting…', offline: 'Offline' };

export function poolDashboard() {
    // Handles that must not be wrapped by Alpine's reactivity: they are not data.
    let connection = null;
    let echo = null;
    let gapTimer = null;
    let refreshing = false;
    let refreshWaitingForUser = false;
    let submitFlow = null;
    let loadingOlderNow = false;
    let refreshQueued = false;
    let expiryTimer = null;
    let clockTimer = null;

    const dashboard = {
        pool: { id: null, name: '' },
        // The pool state: version, remaining, log, and what is waiting for a missing update (see pool-state.js).
        state: initialState({ version: 0, remaining: 0, log: [] }),
        users: [],
        // The demo user who is looking, chosen with the picker and remembered in localStorage (key fo.userId).
        userId: null,
        // For a supervisor: which of the two views is open.
        tab: 'pool',
        // 'live', 'reconnecting' or 'offline' (see connection.js).
        connectionState: 'reconnecting',
        // The request form: what was typed, and what the submit flow last reported (see api.js).
        amount: '',
        status: { state: 'idle' },
        // "Show older": a page is on its way, or the last one failed.
        loadingOlder: false,
        olderError: '',
        // The chosen kind of request: 'consumed' or 'temporary'.
        type: 'consumed',
        // The server's time now, in epoch milliseconds: the device clock plus the offset taken from the last snapshot.
        nowServerMs: Date.now(),

        init() {
            // The server already put the first snapshot in the page, so the first paint shows real numbers.
            const snapshot = JSON.parse(document.getElementById('initial-snapshot').textContent);

            this.pool = snapshot.pool;
            this.state = initialState(snapshot, Date.now());
            this.nowServerMs = Date.now() + this.state.serverOffsetMs;

            // One timer for the next expiry, and a once-a-second tick that keeps "expires in" moving (FR5.10, NFR6).
            expiryTimer = createExpiryTimer({ onDue: () => this.refresh({ rerunIfBusy: true }), nowMs: () => Date.now() });
            clockTimer = window.setInterval(() => { this.nowServerMs = Date.now() + this.state.serverOffsetMs; }, CLOCK_TICK_MS);
            this.scheduleExpiry();

            // Remember a change of viewer, and look at the pool when the viewer cannot see the supervisor tab.
            this.$watch('userId', (id) => {
                if (id !== null) {
                    saveViewerId(window.localStorage, id);
                }

                if (!this.isSupervisor) {
                    this.tab = 'pool';
                }

                this.newSubmitFlow();
                this.newSupervisorFlow();
            });

            // Opening the supervisor tab reads the audit (FR16).
            this.$watch('tab', (tab) => {
                if (tab === 'supervisor') {
                    this.loadAudit();
                }
            });

            // A new result or state is brought into view, so a thumb user does not have to look for it (DD13).
            this.$watch('status', () => {
                if (this.banner) {
                    this.$nextTick(() => document.getElementById('result-banner')?.scrollIntoView({ block: 'nearest' }));
                }
            });

            this.startLive();
            this.loadUsers();
        },

        /** Connect, and keep the screen right while updates arrive, fail or go missing. */
        startLive() {
            connection = createConnection({
                isOnline: navigator.onLine,
                onState: (state) => { this.connectionState = state; },
                onSnapshotNeeded: () => this.refresh(),
            });

            window.addEventListener('online', () => connection.browserOnline(true));
            window.addEventListener('offline', () => {
                connection.browserOnline(false);
                // A resend that is waiting stops now and the request shows as not confirmed (FR21.3).
                submitFlow?.goOffline();
                this.supervisorGoOffline();
            });

            echo = connectEcho({
                poolId: this.pool.id,
                onEvent: (event) => this.receive(event),
                onSocketState: (state) => connection.socketState(state),
            });
        },

        /** A fresh submit flow for the current viewer. Switching viewer drops whatever the previous one had outstanding. */
        newSubmitFlow() {
            // Stop the old viewer's resends and drop a late answer, so it cannot reach the new viewer's screen.
            submitFlow?.dispose();
            this.amount = '';
            this.status = { state: 'idle' };

            if (this.userId === null) {
                submitFlow = null;

                return;
            }

            submitFlow = createSubmitFlow({
                fetch: window.fetch.bind(window),
                userId: this.userId,
                poolId: this.pool.id,
                getState: () => this.state,
                getType: () => this.type,
                isOnline: () => navigator.onLine,
                // The same clock as receive() and the gap timer, so a gap opened by an own result times out.
                now: () => performance.now(),
                dispatch: (action) => {
                    if (action.type === 'status') {
                        this.status = action.status;

                        if (action.status.state === 'done') {
                            this.amount = '';
                        }

                        return;
                    }

                    this.setState(action.state);
                    this.handle(action.effect);
                },
            });
        },

        submit() {
            return submitFlow?.submit(this.amount);
        },

        retry() {
            return submitFlow?.retry();
        },

        /** A quick amount tap: it fills the field, it never adds to it and never submits (DD10). */
        pickAmount(value) {
            this.amount = String(value);
            this.amountChanged();
        },

        /** The + and − buttons: one more or one less, kept between 1 and what is left. Never submits. */
        step(delta) {
            const next = stepAmount(this.amount, delta, this.state.remaining);

            if (next !== null) {
                this.pickAmount(next);
            }
        },

        /** Typing clears an old result so a stale banner is not mistaken for the new request. */
        amountChanged() {
            if (this.status.state === 'done' || this.status.state === 'error') {
                this.status = { state: 'idle' };
            }
        },

        /** A live update. Applied in version order; a gap is held, and if it is not filled a snapshot is fetched. */
        receive(event) {
            const { state, effect } = applyEvent(this.state, event, performance.now(), Date.now());

            this.setState(state);
            this.handle(effect);

            // Another supervisor's correction changes the audit too.
            if (event.entry?.kind === 'correction' && this.isSupervisor && this.tab === 'supervisor') {
                this.loadAudit();
            }
        },

        /** Keep the new pool state, and point the expiry timer at the next expiry it knows of. */
        setState(state) {
            this.state = state;
            this.nowServerMs = Date.now() + state.serverOffsetMs;
            this.scheduleExpiry();
        },

        /** The next expiry is in server time; the timer runs on the device clock, so move it by the offset. */
        scheduleExpiry() {
            const due = nextExpiryAt(this.state);

            expiryTimer?.schedule(due === null ? null : due - this.state.serverOffsetMs);
        },

        /** Do what the reducer asked for. */
        handle(effect) {
            if (effect === 'snapshot') {
                this.refresh();
            }

            this.watchGap();
        },

        /** While an update is missing, look at the clock every 250 ms; stop when nothing is missing. */
        watchGap() {
            if (this.state.buffered.length === 0) {
                window.clearInterval(gapTimer);
                gapTimer = null;

                return;
            }

            if (gapTimer !== null) {
                return;
            }

            gapTimer = window.setInterval(() => {
                const { state, effect } = checkGap(this.state, performance.now());

                this.setState(state);

                if (effect === 'snapshot') {
                    this.refresh();
                }

                if (this.state.buffered.length === 0) {
                    window.clearInterval(gapTimer);
                    gapTimer = null;
                }
            }, GAP_CHECK_MS);
        },

        /**
         * Fetch one snapshot and apply it. Skipped while one is already on its way; a failure keeps what is on screen.
         * An expiry asks to be re-run if one is busy, because the snapshot on its way may have been built just before it.
         */
        async refresh({ rerunIfBusy = false } = {}) {
            if (refreshing) {
                refreshQueued = refreshQueued || rerunIfBusy;

                return;
            }

            if (this.userId === null) {
                // The snapshot is read as the chosen demo user, who is not known until the user list has loaded.
                refreshWaitingForUser = true;

                return;
            }

            refreshing = true;

            try {
                const snapshot = await fetchSnapshot({ fetch: window.fetch.bind(window), userId: this.userId, poolId: this.pool.id });
                const { state, effect } = applySnapshot(this.state, snapshot, performance.now(), Date.now());

                this.setState(state);
                this.handle(effect);
            } catch {
                // The server or the network is not there. Keep the numbers on screen; the next poll or reconnect tries again.
                this.state = { ...this.state, snapshotPending: false };
            } finally {
                refreshing = false;

                if (refreshQueued) {
                    refreshQueued = false;
                    this.refresh();
                }
            }
        },

        /** "Show older": fetch the page of entries before the oldest one on screen and add it below. */
        async loadOlder() {
            const before = oldestCursor(this.state);

            if (loadingOlderNow || before === null || this.userId === null) {
                return;
            }

            loadingOlderNow = true;
            this.loadingOlder = true;
            this.olderError = '';

            try {
                const page = await fetchLog({ fetch: window.fetch.bind(window), userId: this.userId, poolId: this.pool.id, before });

                this.state = applyOlderPage(this.state, page);
            } catch {
                this.olderError = 'Could not load older entries. Try again.';
            } finally {
                loadingOlderNow = false;
                this.loadingOlder = false;
            }
        },

        async loadUsers() {
            try {
                const response = await fetch('/api/users', { headers: { Accept: 'application/json' } });
                const { users } = await response.json();

                this.users = users;
                this.userId = chooseViewer(users, loadViewerId(window.localStorage))?.id ?? null;

                if (refreshWaitingForUser) {
                    refreshWaitingForUser = false;
                    this.refresh();
                }
            } catch {
                // Without the list the picker stays empty and no entry is marked as the viewer's own.
            }
        },

        get remaining() {
            return this.state.remaining;
        },

        // The request form (FR1.1, FR1.4, FR12, FR12.1)

        get maxAmount() {
            return maxAmount(this.state.remaining);
        },

        /** The quick-amount buttons for the remaining last shown; the ones above it are listed but not usable. */
        get quickAmountList() {
            return quickAmounts(this.state.remaining);
        },

        /** The field as a number, 0 when it holds nothing readable (the same reading the stepper uses). */
        get amountValue() {
            const text = String(this.amount ?? '').trim();

            return /^[0-9]+$/.test(text) ? Number(text) : 0;
        },

        get canDecrease() {
            const next = stepAmount(this.amount, -1, this.state.remaining);

            return next !== null && next !== this.amountValue;
        },

        get canIncrease() {
            const next = stepAmount(this.amount, 1, this.state.remaining);

            return next !== null && next !== this.amountValue;
        },

        get leftText() {
            return `${this.maxAmount} left`;
        },

        get validation() {
            return validateAmount(this.amount, this.state.remaining);
        },

        /** What is wrong with the typed amount, shown once something has been typed. */
        get amountMessage() {
            return String(this.amount ?? '').trim() !== '' && !this.validation.ok ? this.validation.message : '';
        },

        // Status and temporary holdings (FR4.3, FR13). Both come from the viewer block the server builds.

        /** The viewer's own status, in words. */
        get statusText() {
            return this.state.viewer?.status ?? '';
        },

        /** Under the Temporary option: how long units are held, by the viewer's tier. */
        get windowHint() {
            const seconds = this.state.viewer?.window_seconds;

            return seconds ? `Returns after ${windowText(seconds)}` : '';
        },

        /** The viewer's held temporary units, soonest first. A holding drops off the moment its time is up. */
        get holdings() {
            return (this.state.viewer?.holdings ?? [])
                .map((holding) => ({ ...holding, msLeft: parseInstant(holding.expires_at) - this.nowServerMs }))
                .filter((holding) => holding.msLeft > 0)
                .map((holding) => ({
                    key: holding.allocation_seq,
                    units: holding.units,
                    timeLeft: timeLeftText(holding.msLeft),
                }));
        },

        get isOffline() {
            return this.connectionState === 'offline';
        },

        /** The request is on its way: first send, an automatic resend, or a manual Retry. */
        get isPending() {
            return ['pending', 'resending', 'retrying'].includes(this.status.state);
        },

        get isUnconfirmed() {
            return this.status.state === 'unconfirmed';
        },

        get submitDisabled() {
            return !this.validation.ok || this.isPending || this.isUnconfirmed || this.isOffline;
        },

        get submitLabel() {
            if (this.maxAmount === 0) {
                return '0 left';
            }

            return this.isPending ? 'Sending…' : 'Request';
        },

        /** The full-width result of the last request, in words and a shape, or null (banner-model.js). */
        get banner() {
            return bannerFor(this.status, { serverNowMs: Date.now() + (this.state.serverOffsetMs ?? 0), channel: 'request' });
        },

        get indicatorText() {
            return INDICATOR_TEXT[this.connectionState];
        },

        get viewer() {
            return this.users.find((user) => user.id === this.userId) ?? null;
        },

        get isOperator() {
            return this.viewer?.role === 'operator';
        },

        get isSupervisor() {
            return this.viewer?.role === 'supervisor';
        },

        get showPool() {
            return !this.isSupervisor || this.tab === 'pool';
        },

        get showSupervisor() {
            return this.isSupervisor && this.tab === 'supervisor';
        },

        /** The log as the page shows it: a word for the kind, the text, and whether the entry is the viewer's own. */
        get rows() {
            const rows = this.state.log.map((entry, index) => ({
                key: entry.cursor ?? `entry-${index}`,
                pending: false,
                ...renderEntry(entry, this.userId),
                // A supervisor can correct an allocation that has not been voided; the form shows what it holds now.
                correctable: entry.kind === 'allocation' && entry.voided !== true,
                seq: entry.seq,
                units: entry.corrected_to ?? entry.granted,
                operatorName: entry.operator?.name ?? '',
            }));

            // A request that is on its way, or whose answer was lost, shows at the top at once. It is not in the totals.
            if (this.isPending || this.isUnconfirmed) {
                rows.unshift({
                    key: 'pending',
                    pending: true,
                    own: true,
                    label: this.status.state === 'pending' ? 'Pending' : this.isPending ? 'Retrying…' : 'Not confirmed',
                    text: `${this.viewer?.name ?? 'You'}: requesting ${this.status.amount}`,
                });
            }

            return rows;
        },

        optionLabel(user) {
            return user.role === 'supervisor' ? `${user.name} (supervisor)` : `${user.name} (${user.tier})`;
        },
    };

    // The supervisor's screen is its own module; merge it with the property descriptors kept, so getters stay getters.
    return Object.defineProperties(dashboard, Object.getOwnPropertyDescriptors(supervisorPart()));
}
