import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

export type SharedReverbConfig = {
    key: string;
    wsHost: string;
    wsPort: number;
    wssPort: number;
    scheme: string;
};

export function createLaravelEcho(
    csrfToken: string,
    cfg: SharedReverbConfig,
): Echo<'reverb'> | null {
    if (!cfg.key) {
        return null;
    }

    window.Pusher = Pusher;

    return new Echo({
        broadcaster: 'reverb',
        key: cfg.key,
        wsHost: cfg.wsHost,
        wsPort: cfg.wsPort,
        wssPort: cfg.wssPort,
        forceTLS: cfg.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                Accept: 'application/json',
            },
        },
    });
}

export type CouncilBroadcastPayload = {
    run_id?: string;
    type: string;
    data: Record<string, unknown>;
};
