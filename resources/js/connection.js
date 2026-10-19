// What the screen tells the operator about its live connection, and when it falls back to polling (FR11, FR11.1, D12).
//
// Three states, shown as words:
//   live          the socket is connected and the browser is online
//   reconnecting  the browser is online but the socket is not connected
//   offline       the browser has no network (this overrides the other two)
//
// While not live, the screen asks for a fresh snapshot every `pollMs` (10 s). On arriving at live from any other
// state it asks once, because updates may have been missed in between. No DOM and no Echo here: the caller feeds in
// the socket state and the browser's online/offline events.

/**
 * @param {object} options
 * @param {(state: 'live'|'reconnecting'|'offline') => void} options.onState  called when the state changes
 * @param {() => void} options.onSnapshotNeeded  called to ask for a snapshot
 * @param {number} [options.pollMs]  polling interval while not live
 * @param {boolean} [options.isOnline]  whether the browser is online now
 */
export function createConnection({ onState, onSnapshotNeeded, pollMs = 10_000, isOnline = true }) {
    // Until the socket says otherwise it is still connecting.
    let socket = 'connecting';
    let online = Boolean(isOnline);
    let current = null;
    let timer = null;
    let disposed = false;

    function compute() {
        if (!online) {
            return 'offline';
        }

        return socket === 'connected' ? 'live' : 'reconnecting';
    }

    function startPolling() {
        timer = setInterval(() => onSnapshotNeeded(), pollMs);
    }

    function stopPolling() {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    }

    function evaluate() {
        if (disposed) {
            return;
        }

        const next = compute();

        if (next === current) {
            return;
        }

        current = next;

        if (next === 'live') {
            stopPolling();
        } else if (timer === null) {
            // The clock starts when the connection drops. Moving between reconnecting and offline keeps it running.
            startPolling();
        }

        onState(next);

        if (next === 'live') {
            onSnapshotNeeded();
        }
    }

    evaluate();

    return {
        /** The socket's own state: connected, connecting, unavailable, failed or disconnected. */
        socketState(state) {
            socket = state;
            evaluate();
        },

        /** The browser's online/offline events. */
        browserOnline(value) {
            online = Boolean(value);
            evaluate();
        },

        dispose() {
            disposed = true;
            stopPolling();
        },
    };
}
