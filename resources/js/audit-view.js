// How the supervisor's audit view reads (FR15, FR16, AC-US3.4, AC-US6.3).
//
// Text only: nothing here depends on colour. The server decides every number; this only turns the audit response
// into words.

import { parseInstant, returnTimeText } from './time-left.js';

export const EMPTY_AUDIT_TEXT = 'No corrections yet';

/**
 * @param {object} audit  the audit response (GET /pools/{pool}/audit)
 * @param {string} [timeZone]  an IANA time zone; the device's when omitted
 * @returns {{empty: boolean, emptyText: string, rows: ReturnType<typeof auditRows>, hindsight: string[]}}
 */
export function auditView(audit, timeZone) {
    const rows = auditRows(audit, timeZone);

    return {
        empty: rows.length === 0,
        emptyText: EMPTY_AUDIT_TEXT,
        rows,
        hindsight: (audit?.hindsight ?? []).map((window) => hindsightText(window, timeZone)),
    };
}

/**
 * One row per correction, oldest first, as the server sent them.
 *
 * @param {object} audit
 * @param {string} [timeZone]
 * @returns {{seq: number, text: string, points: string[]}[]}
 */
export function auditRows(audit, timeZone) {
    const corrections = audit?.corrections ?? [];
    const serverNowMs = audit?.server_time ? parseInstant(audit.server_time) : Date.now();

    return corrections.map((correction) => {
        const who = correction.operator.name;
        const change = correction.kind === 'void'
            ? `voided (${correction.from} → 0)`
            : `${correction.from} → ${correction.to}`;
        const status = correction.status.before === correction.status.after
            ? `${who}: ${correction.status.after} (unchanged)`
            : `${who}: ${correction.status.before} → ${correction.status.after}`;

        return {
            seq: correction.seq,
            text: `${correction.supervisor.name} · #${correction.allocation_seq} ${who} · ${change} · ${correction.reason} · ${status}`,
            points: correction.status.points.map((point) => (
                `${point.label} (${returnTimeText(point.at, serverNowMs, timeZone)}): ${point.before} → ${point.after}`
            )),
        };
    });
}

/**
 * "Overdrawn in hindsight: lowest -20 between 10:00 and 13:00". The past dip is shown, never blocked (D11).
 *
 * @param {{from: {at: string}, to: {at: string}|null, lowest: number}} window
 * @param {string} [timeZone]
 */
export function hindsightText(window, timeZone) {
    const from = clock(window.from.at, timeZone);
    const to = window.to === null ? null : clock(window.to.at, timeZone);
    // The same day reads as a time of day; a window that crosses midnight names the days.
    const sameDay = to !== null && from.date === to.date;
    const start = sameDay || to === null ? from.time : `${from.date} ${from.time}`;
    const end = to === null ? 'now' : sameDay ? to.time : `${to.date} ${to.time}`;

    return `Overdrawn in hindsight: lowest ${window.lowest} between ${start} and ${end}`;
}

function clock(iso, timeZone) {
    const at = new Date(parseInstant(iso));
    const time = new Intl.DateTimeFormat('en-GB', { timeZone, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(at);
    const date = new Intl.DateTimeFormat('en-GB', { timeZone, day: 'numeric', month: 'short' }).format(at);

    return { time, date };
}
