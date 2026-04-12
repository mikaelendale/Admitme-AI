/// <reference types="vite/client" />

import type Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
    }
}

export {};
