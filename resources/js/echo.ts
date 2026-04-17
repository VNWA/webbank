import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher?: typeof Pusher;
    }
}

function createEcho(): InstanceType<typeof Echo> | null {
    if (import.meta.env.SSR || typeof window === 'undefined') {
        return null;
    }

    window.Pusher = Pusher;

    const key = import.meta.env.VITE_REVERB_APP_KEY ?? '';

    if (key === '') {
        return null;
    }

    return new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}

const echo = createEcho();

export default echo;
