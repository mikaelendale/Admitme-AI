import { useMediaQuery } from '@/hooks/use-media-query';

const MOBILE_BREAKPOINT = 768;

/** Phone width. False on the server and until hydration, like before. */
export function useIsMobile(): boolean {
    return useMediaQuery(`(max-width: ${MOBILE_BREAKPOINT - 1}px)`, false);
}
