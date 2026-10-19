// One timer that fires when the next temporary allocation expires (FR5.10, NFR6, DD5).
//
// It only says "it is time"; the caller fetches a snapshot. The timer and the clock are passed in, so it is testable.

/** The longest delay setTimeout accepts is 2^31 - 1 ms; longer ones are cut and re-armed. */
const MAX_DELAY_MS = 2_147_000_000;

/** Wait this long after the due instant, so the server has certainly passed it when the snapshot is fetched. */
const GRACE_MS = 250;

/**
 * @param {object} options
 * @param {() => void} options.onDue  called once when the scheduled instant has passed
 * @param {() => number} options.nowMs  the client's clock in epoch milliseconds
 * @param {typeof setTimeout} [options.setTimer]
 * @param {typeof clearTimeout} [options.clearTimer]
 */
export function createExpiryTimer({ onDue, setTimer = setTimeout, clearTimer = clearTimeout, nowMs }) {
    let handle = null;

    function cancel() {
        if (handle !== null) {
            clearTimer(handle);
            handle = null;
        }
    }

    function arm(dueAtMs) {
        const delay = Math.max(0, dueAtMs + GRACE_MS - nowMs());

        if (delay > MAX_DELAY_MS) {
            handle = setTimer(() => arm(dueAtMs), MAX_DELAY_MS);

            return;
        }

        handle = setTimer(() => {
            handle = null;
            onDue();
        }, delay);
    }

    return {
        /**
         * Replace any pending timer. Null (nothing is held) arms nothing.
         *
         * @param {number|null} dueAtMs  the due instant on the client's clock
         */
        schedule(dueAtMs) {
            cancel();

            if (dueAtMs !== null && dueAtMs !== undefined) {
                arm(dueAtMs);
            }
        },

        dispose() {
            cancel();
        },
    };
}
