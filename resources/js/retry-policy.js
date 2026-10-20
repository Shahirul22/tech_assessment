// When a sent request that got no answer is sent again by itself, and when that stops (FR21.3, FR21.5, FR21.6, FR21.7, D5).
//
// One send, then up to three resends after 1 s, 2 s and 4 s. A resend only happens while the browser is online; going
// offline, `abortWait()` or `dispose()` ends the round at once. Nothing is stored and nothing is queued: the round
// lives only as long as the page. The caller sends the same idempotency key every time, so the runner never sees keys.
// The clock is passed in (`sleep`), so the rules are tested without real timers.

/**
 * @typedef {{kind: 'answered', value: *} | {kind: 'no-answer'}} SendResult
 * @typedef {{outcome: 'answered', value: *} | {outcome: 'gave-up'} | {outcome: 'stopped-offline'} | {outcome: 'disposed'}} RoundResult
 */

/**
 * @param {object} options
 * @param {number[]} [options.waitsMs]  the wait before each resend; its length is the number of resends
 * @param {() => boolean} options.isOnline
 * @param {(ms: number) => Promise<void>} [options.sleep]
 * @param {(info: {attempt: number, of: number, phase: 'waiting'|'sending'}) => void} [options.onResending]
 */
export function createRetryRunner({ waitsMs = [1000, 2000, 4000], isOnline, sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms)), onResending = () => {} }) {
    let disposed = false;
    // Ends the wait in progress, if any.
    let wake = null;

    function wait(ms) {
        return new Promise((resolve) => {
            wake = resolve;
            sleep(ms).then(resolve, resolve);
        }).finally(() => {
            wake = null;
        });
    }

    return {
        /**
         * @param {() => Promise<SendResult>} sendOnce  never rejects
         * @returns {Promise<RoundResult>}
         */
        async run(sendOnce) {
            const of = waitsMs.length;
            let result = await sendOnce();

            for (let index = 0; ; index++) {
                if (result.kind === 'answered') {
                    return { outcome: 'answered', value: result.value };
                }

                if (disposed) {
                    return { outcome: 'disposed' };
                }

                if (index === of) {
                    return { outcome: 'gave-up' };
                }

                if (!isOnline()) {
                    return { outcome: 'stopped-offline' };
                }

                onResending({ attempt: index + 1, of, phase: 'waiting' });
                await wait(waitsMs[index]);

                if (disposed) {
                    return { outcome: 'disposed' };
                }

                if (!isOnline()) {
                    return { outcome: 'stopped-offline' };
                }

                onResending({ attempt: index + 1, of, phase: 'sending' });
                result = await sendOnce();
            }
        },

        /** Ends the wait in progress; the round then stops if the browser is offline. */
        abortWait() {
            wake?.();
        },

        /** Stops the round for good: no further send, no further callback. */
        dispose() {
            disposed = true;
            wake?.();
        },
    };
}
