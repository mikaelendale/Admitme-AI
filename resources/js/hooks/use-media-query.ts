import { useSyncExternalStore } from 'react';

/** Subscribe to a CSS media query. On the server (and before hydration) it reports `fallback`. */
export function useMediaQuery(query: string, fallback = false): boolean {
    return useSyncExternalStore(
        (onChange) => {
            const list = window.matchMedia(query);
            list.addEventListener('change', onChange);

            return () => list.removeEventListener('change', onChange);
        },
        () => window.matchMedia(query).matches,
        () => fallback,
    );
}
