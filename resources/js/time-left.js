// How time is shown on screen (FR5.6, FR5.7, FR13, A-M2.4).
//
// Pure functions: no DOM and no clock. Every instant is passed in, so the text is testable and the screen can use the
// server's clock (corrected by an offset) instead of the device's.

const SECOND = 1000;
const MINUTE = 60 * SECOND;
const HOUR = 60 * MINUTE;

/**
 * How far the device clock is behind the server's: add it to the device's `Date.now()` to get the server's time.
 *
 * @param {string} serverTimeIso  `server_time` of a snapshot
 * @param {number} clientNowMs  the device clock when that snapshot arrived
 */
export function serverOffsetMs(serverTimeIso, clientNowMs) {
    return parseInstant(serverTimeIso) - clientNowMs;
}

/**
 * "3 h" from 2 hours up, "45 min" from 1 minute, "under 1 min" below that, "now" at 0 or less.
 *
 * @param {number} msLeft
 */
export function timeLeftText(msLeft) {
    if (msLeft <= 0) {
        return 'now';
    }

    if (msLeft >= 2 * HOUR) {
        return `${Math.floor(msLeft / HOUR)} h`;
    }

    if (msLeft >= MINUTE) {
        return `${Math.floor(msLeft / MINUTE)} min`;
    }

    return 'under 1 min';
}

/**
 * The length of a window: "24 h", "2 min" or "30 s".
 *
 * @param {number} seconds
 */
export function windowText(seconds) {
    if (seconds >= 3600 && seconds % 3600 === 0) {
        return `${seconds / 3600} h`;
    }

    if (seconds >= 60) {
        return `${Math.floor(seconds / 60)} min`;
    }

    return `${seconds} s`;
}

/**
 * When units come back, in the viewer's own time zone: "10:00 today", "10:00 tomorrow" or "20 Oct 10:00".
 * Today and tomorrow are judged on the server's clock, so a wrong device clock cannot move them.
 *
 * @param {string} expiresIso  `expires_at`
 * @param {number} serverNowMs  the server's current time in epoch milliseconds
 * @param {string} [timeZone]  an IANA time zone; the device's when omitted
 */
export function returnTimeText(expiresIso, serverNowMs, timeZone) {
    const expires = parts(parseInstant(expiresIso), timeZone);
    const today = parts(serverNowMs, timeZone);
    const dayDifference = Math.round((Date.UTC(expires.year, expires.month - 1, expires.day) - Date.UTC(today.year, today.month - 1, today.day)) / (24 * HOUR));
    const clock = `${expires.hour}:${expires.minute}`;

    if (dayDifference === 0) {
        return `${clock} today`;
    }

    if (dayDifference === 1) {
        return `${clock} tomorrow`;
    }

    return `${expires.day} ${expires.monthName} ${clock}`;
}

/**
 * An API timestamp as epoch milliseconds. Date.parse keeps three decimals of a second; the API sends six.
 *
 * @param {string} iso
 */
export function parseInstant(iso) {
    return Date.parse(iso.replace(/(\.\d{3})\d+/, '$1'));
}

function parts(epochMs, timeZone) {
    const formatted = new Intl.DateTimeFormat('en-GB', {
        timeZone,
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(new Date(epochMs));
    const get = (type) => formatted.find((part) => part.type === type).value;
    const monthName = new Intl.DateTimeFormat('en-GB', { timeZone, month: 'short' }).format(new Date(epochMs));

    return {
        year: Number(get('year')),
        month: Number(get('month')),
        day: Number(get('day')),
        hour: get('hour'),
        minute: get('minute'),
        monthName,
    };
}
