// The rules that keep a screen right while live updates arrive (DD8, FR11, D12).
//
// Pure functions: no DOM, no timers, no network. They take the current state and return a new one together
// with an `effect` that tells the caller what to do next:
//   null        nothing
//   'wait'      a gap is open; hold on, the missing update may still arrive
//   'snapshot'  the gap was not filled in time; fetch one snapshot (the caller must then call applySnapshot)
//
// Every function takes `now` (milliseconds) as an argument instead of reading a clock, so the timing is testable.
// `now` is the caller's gap clock (performance.now()). The functions that also compare with server times take a second
// instant, `epochNow` (Date.now()), because a server time is on the epoch clock; it defaults to `now`.

import { parseInstant, serverOffsetMs } from './time-left.js';

/** How long a gap may stay open before a snapshot is requested. */
export const GAP_MS = 500;

/**
 * @param {{version: number, remaining: number, log: object[], log_has_more?: boolean, server_time?: string, next_expiry_at?: string|null, viewer?: object}} snapshot
 * @param {number|null} [epochNow]  the device clock (Date.now()) when the snapshot arrived, to work out the offset from the server's
 */
export function initialState(snapshot, epochNow = null) {
    return {
        version: snapshot.version,
        remaining: snapshot.remaining,
        log: [...snapshot.log],
        logHasMore: snapshot.log_has_more ?? false,
        ...clockAndViewer(snapshot, epochNow),
        // The version of the pool the `viewer` block was taken at, so an older own result cannot overwrite a newer one.
        viewerVersion: snapshot.version,
        // Events that arrived ahead of their turn, held until the missing ones come.
        buffered: [],
        // When the oldest open gap began, or null.
        gapSince: null,
        // True from the moment a snapshot is requested until it is applied; keeps the request to one.
        snapshotPending: false,
    };
}

/**
 * A live `pool.updated` event: {version, remaining, entry}.
 * Older or equal versions are ignored, the next version is applied (then any buffered run behind it), and a later
 * version is buffered until the missing ones arrive.
 */
export function applyEvent(state, event, now, epochNow = now) {
    if (event.version <= state.version) {
        return { state, effect: null };
    }

    if (event.version === state.version + 1) {
        return drain(advance(state, event, epochNow), now, true, epochNow);
    }

    return gapEffect(
        { ...state, buffered: withBuffered(state.buffered, event), gapSince: state.gapSince ?? now },
        now,
    );
}

/**
 * Call on a timer while a gap is open. Returns 'snapshot' once the gap has lasted GAP_MS, and only once.
 */
export function checkGap(state, now) {
    return gapEffect(state, now);
}

/**
 * A snapshot from GET /api/pools/{pool}. A snapshot older than the screen is ignored, so the screen never goes back.
 * A newer one replaces version, remaining and log, drops the buffered events it already covers, and carries on with
 * any buffered events that follow it directly.
 */
export function applySnapshot(state, snapshot, now = null, epochNow = now) {
    if (snapshot.version < state.version) {
        return { state: { ...state, snapshotPending: false }, effect: null };
    }

    if (snapshot.version === state.version) {
        // Same version, as with a poll: keep older pages the operator loaded and just refresh what the snapshot shows.
        // The derived expiry entries, the viewer block and the next expiry can change without a version change.
        const log = snapshot.log.reduce((entries, entry) => upsertEntry(entries, entry), state.log);

        return {
            state: {
                ...state,
                remaining: snapshot.remaining,
                log,
                ...clockAndViewer(snapshot, epochNow),
                viewerVersion: snapshot.version,
                snapshotPending: false,
            },
            effect: null,
        };
    }

    const replaced = {
        ...state,
        version: snapshot.version,
        remaining: snapshot.remaining,
        log: [...snapshot.log],
        logHasMore: snapshot.log_has_more ?? false,
        ...clockAndViewer(snapshot, epochNow),
        viewerVersion: snapshot.version,
        buffered: state.buffered.filter((event) => event.version > snapshot.version),
        snapshotPending: false,
    };

    return drain(replaced, now, true, epochNow);
}

/**
 * The result of this screen's own submit (the HTTP response). It carries the pool's version right after the entry, so
 * it follows the same rules as a live event, and the same entry arriving by broadcast as well is a duplicate.
 *
 * The `viewer` block of the result replaces the screen's own only when it is as new as what the screen shows.
 *
 * @param {{allocation: object, pool: {version: number, remaining: number}, viewer?: object}} result
 */
export function applyOwnResult(state, result, now, epochNow = now) {
    const applied = applyEvent(
        state,
        { version: result.pool.version, remaining: result.pool.remaining, entry: entryFromAllocation(result.allocation) },
        now,
        epochNow,
    );

    if (result.viewer === undefined || result.pool.version < applied.state.viewerVersion) {
        return applied;
    }

    return { ...applied, state: { ...applied.state, viewer: result.viewer, viewerVersion: result.pool.version } };
}

/**
 * An older page of the log ("Show older"): GET /api/pools/{pool}/log. The entries go below what is shown, merged by
 * seq so an overlap adds no duplicate; the numbers and the version are not touched. `has_more` decides whether the
 * "Show older" button stays.
 *
 * @param {{entries: object[], has_more: boolean}} page
 */
export function applyOlderPage(state, page) {
    return {
        ...state,
        log: page.entries.reduce((entries, entry) => upsertEntry(entries, entry), state.log),
        logHasMore: page.has_more,
    };
}

/**
 * Where the next "Show older" page starts: the cursor of the last (oldest) row on screen. Null when the log is empty.
 */
export function oldestCursor(state) {
    return state.log.length === 0 ? null : entryKey(state.log[state.log.length - 1]);
}

/**
 * The server time of the next expiry in the pool, in epoch milliseconds, or null when nothing is held.
 */
export function nextExpiryAt(state) {
    return state.nextExpiryAt;
}

/**
 * The allocation in an HTTP result, in the shape of a log entry (plan §6).
 */
export function entryFromAllocation(allocation) {
    return {
        seq: allocation.seq,
        cursor: String(allocation.seq),
        kind: 'allocation',
        at: allocation.effective_at,
        operator: allocation.operator,
        type: allocation.type,
        requested: allocation.requested,
        granted: allocation.granted,
        outcome: allocation.outcome,
        expires_at: allocation.expires_at ?? null,
    };
}

function advance(state, event, epochNow = null) {
    return {
        ...state,
        version: event.version,
        remaining: event.remaining,
        log: upsertEntry(state.log, event.entry),
        nextExpiryAt: lowered(state, event.entry.expires_at, epochNow),
    };
}

/**
 * An applied event can only bring the next expiry closer: an earlier expiry that is still ahead replaces it. Raising or
 * clearing it is left to a snapshot, which knows about every holding.
 */
function lowered(state, expiresAtIso, epochNow) {
    if (typeof expiresAtIso !== 'string') {
        return state.nextExpiryAt;
    }

    const expiresAt = parseInstant(expiresAtIso);
    const serverNow = epochNow === null ? null : epochNow + state.serverOffsetMs;

    if (serverNow !== null && expiresAt <= serverNow) {
        return state.nextExpiryAt;
    }

    return state.nextExpiryAt === null || expiresAt < state.nextExpiryAt ? expiresAt : state.nextExpiryAt;
}

/**
 * What a snapshot says about the clock and the viewer. A missing field (an older server) leaves a neutral value.
 */
function clockAndViewer(snapshot, epochNow) {
    return {
        viewer: snapshot.viewer ?? null,
        serverOffsetMs: snapshot.server_time && epochNow !== null ? serverOffsetMs(snapshot.server_time, epochNow) : 0,
        nextExpiryAt: snapshot.next_expiry_at ? parseInstant(snapshot.next_expiry_at) : null,
    };
}

/**
 * Apply buffered events that now follow directly, and drop any the screen has already passed.
 *
 * @param {boolean} moved  true when the state just moved forward, which restarts the gap clock for what is still missing
 * @param {number|null} epochNow  the epoch clock, for lowering the next expiry
 */
function drain(state, now, moved, epochNow = null) {
    let current = { ...state, buffered: state.buffered.filter((event) => event.version > state.version) };

    for (;;) {
        const next = current.buffered.find((event) => event.version === current.version + 1);

        if (next === undefined) {
            break;
        }

        current = advance({ ...current, buffered: current.buffered.filter((event) => event !== next) }, next, epochNow);
        moved = true;
    }

    if (current.buffered.length === 0) {
        return { state: { ...current, gapSince: null }, effect: null };
    }

    return gapEffect({ ...current, gapSince: moved ? now : current.gapSince }, now);
}

function gapEffect(state, now) {
    if (state.buffered.length === 0 || state.snapshotPending) {
        return { state, effect: null };
    }

    if (state.gapSince !== null && now - state.gapSince >= GAP_MS) {
        return { state: { ...state, snapshotPending: true }, effect: 'snapshot' };
    }

    return { state, effect: 'wait' };
}

function withBuffered(buffered, event) {
    if (buffered.some((held) => held.version === event.version)) {
        return buffered;
    }

    return [...buffered, event].sort((a, b) => a.version - b.version);
}

/**
 * The key of a log entry: its cursor ("12" for a stored entry, "13x12" for an expiry derived after entry 13).
 */
function entryKey(entry) {
    return entry.cursor ?? String(entry.seq);
}

/**
 * Where an entry sits, as the server orders the log: (anchor, rank, allocation seq), a stored entry being (seq, 0, 0).
 */
function position(entry) {
    const [anchor, allocationSeq] = entryKey(entry).split('x');

    return allocationSeq === undefined ? [Number(anchor), 0, 0] : [Number(anchor), 1, Number(allocationSeq)];
}

function compareNewestFirst(a, b) {
    const [pa, pb] = [position(a), position(b)];

    for (let i = 0; i < 3; i++) {
        if (pa[i] !== pb[i]) {
            return pb[i] - pa[i];
        }
    }

    return 0;
}

/**
 * Insert or replace a log entry by cursor, newest first. An expiry arriving in several snapshots stays one row.
 */
function upsertEntry(log, entry) {
    const key = entryKey(entry);

    return [...log.filter((existing) => entryKey(existing) !== key), entry].sort(compareNewestFirst);
}
