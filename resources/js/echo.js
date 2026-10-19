// The live connection: Laravel Echo on the Reverb broadcaster, listening to the pool channel (DD7, D12).
// The host, port and key are the VITE_REVERB_* values that were baked into the build (.env.docker).

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

/**
 * @param {object} options
 * @param {number} options.poolId
 * @param {(event: {version: number, remaining: number, entry: object}) => void} options.onEvent  a `pool.updated` broadcast
 * @param {(state: string) => void} options.onSocketState  the socket's own state: connecting, connected, unavailable, failed, disconnected
 * @param {Record<string, string|undefined>} [options.env]
 * @returns {{disconnect: () => void}}
 */
export function connectEcho({ poolId, onEvent, onSocketState, env = import.meta.env }) {
    const port = Number(env.VITE_REVERB_PORT ?? 80);

    const echo = new Echo({
        broadcaster: 'reverb',
        key: env.VITE_REVERB_APP_KEY,
        wsHost: env.VITE_REVERB_HOST,
        wsPort: port,
        wssPort: port,
        forceTLS: (env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    // Report every change of the socket's state, and the state it is in right now.
    const connection = echo.connector.pusher.connection;
    connection.bind('state_change', ({ current }) => onSocketState(current));
    onSocketState(connection.state);

    // A public channel; the leading dot means "this exact event name", not one under the app namespace.
    echo.channel(`pool.${poolId}`).listen('.pool.updated', onEvent);

    return { disconnect: () => echo.disconnect() };
}
