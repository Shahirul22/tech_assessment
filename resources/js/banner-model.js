// What the screen says about a request, in words and a shape (FR20, FR20.1, FR20.2, FR21, FR22.2, DD7, DD8).
//
// One pure function for the three banners (request, restock, correction): a status goes in, the words, the shape and
// the action come out. No DOM. Colour is never the only sign: every state has its own words and its own shape.
//
//   pending      Pending                                  ring
//   resending    Retrying automatically, attempt n of 3   cycle
//   retrying     Retrying                                 dot-ring
//   unconfirmed  Not confirmed (+ Retry)                  triangle
//   offline      Offline — nothing was sent               bar-square
//   done         4 granted / 4 of 6 granted / None …      check (a grant), triangle (partial, none)
//   refused      Refused — {reason}                       cross

import { doneText as requestDoneText } from './api.js';

/**
 * @typedef {'ring'|'cycle'|'dot-ring'|'check'|'triangle'|'cross'|'bar-square'} BannerShape
 * @typedef {{kind: string, shape: BannerShape, text: string, note: string, action: 'retry'|null}} Banner
 */

/**
 * @param {{state: string, [key: string]: *}} status  a status of the submit flow or the supervisor flow
 * @param {object} [context]
 * @param {number} [context.serverNowMs]  the server's time now, for "returns 10:00 today"
 * @param {'request'|'restock'|'correction'} [context.channel]
 * @param {(status: object) => string} [context.doneText]  the words of a confirmed result; for the request channel the default is `doneText` of api.js
 * @returns {Banner|null}  null when nothing is to be shown (idle, or a message the form shows itself)
 */
export function bannerFor(status, { serverNowMs = Date.now(), channel = 'request', doneText } = {}) {
    switch (status?.state) {
        case 'pending':
            return banner('progress', 'ring', 'Pending');

        case 'resending':
            return banner('progress', 'cycle', `Retrying automatically, attempt ${status.attempt} of ${status.of}`);

        case 'retrying':
            return banner('progress', 'dot-ring', 'Retrying');

        case 'done':
            return done(status, { serverNowMs, channel, doneText });

        case 'refused':
            return banner('refused', 'cross', `Refused — ${status.message}`);

        case 'error':
            // A reply without a structured error: the server's own words are not known.
            return status.code === 'error'
                ? banner('error', 'cross', `Error — ${status.message}`)
                : banner('refused', 'cross', `Refused — ${status.message}`);

        case 'unconfirmed':
            return banner('unconfirmed', 'triangle', 'Not confirmed', status.message ?? '', 'retry');

        case 'offline':
            return banner('offline', 'bar-square', 'Offline — nothing was sent');

        default:
            return null;
    }
}

function done(status, { serverNowMs, channel, doneText }) {
    if (channel !== 'request') {
        return banner('success', 'check', doneText ? doneText(status) : 'Done');
    }

    const text = doneText ? doneText(status) : requestDoneText(status, serverNowMs);

    if (status.granted === 0) {
        return banner('none', 'triangle', text);
    }

    return banner(status.granted < status.requested ? 'partial' : 'success', status.granted < status.requested ? 'triangle' : 'check', text);
}

function banner(kind, shape, text, note = '', action = null) {
    return { kind, shape, text, note, action };
}
