import { useSyncExternalStore } from 'react';

const subscribe = () => () => {};

/**
 * False during server rendering and hydration, true afterwards. Use it for output that depends on the
 * browser (local time, stored preferences, viewport) so the server HTML and the first client render match.
 */
export function useHydrated(): boolean {
    return useSyncExternalStore(
        subscribe,
        () => true,
        () => false,
    );
}
