/**
 * CSRF + Accept headers so Laravel treats XHR as expecting JSON on errors (419/422),
 * while successful routes can still return text/event-stream.
 */
export function readXsrfTokenFromCookie(): string | null {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    if (!match?.[1]) {
        return null;
    }

    try {
        return decodeURIComponent(match[1]);
    } catch {
        return match[1];
    }
}

export function readCsrfTokenFromMeta(): string | null {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? null
    );
}

export function buildLaravelJsonHeaders(
    inertiaCsrfToken?: string | null,
): Record<string, string> {
    const token =
        inertiaCsrfToken?.trim() || readCsrfTokenFromMeta()?.trim() || '';

    const xsrf = readXsrfTokenFromCookie();

    return {
        'Content-Type': 'application/json',
        // Prefer JSON for validation / CSRF error bodies; stream still returned on success.
        Accept: 'application/json, text/event-stream',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token !== '' ? { 'X-CSRF-TOKEN': token } : {}),
        ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
    };
}
