/**
 * User-facing copy when essay endpoints return HTTP 429.
 * Uses Retry-After (seconds) when present; otherwise a generic hourly fallback.
 */
export function buildEssayRateLimitMessage(
    variant: 'roast' | 'score' | 'whySchool',
    res: Response,
): string {
    const retryAfter = res.headers.get('Retry-After');

    if (retryAfter !== null && retryAfter !== '') {
        const secs = Number.parseInt(retryAfter, 10);

        if (!Number.isNaN(secs) && secs > 0) {
            const when = new Date(Date.now() + secs * 1000).toLocaleTimeString(
                undefined,
                {
                    hour: 'numeric',
                    minute: '2-digit',
                },
            );

            if (variant === 'roast') {
                return `You've reached the roast limit for this hour. You can roast more essays after ${when}.`;
            }

            if (variant === 'score') {
                return `You've reached the scoring limit for this hour. You can score more essays after ${when}.`;
            }

            return `You've reached the Why This School limit for this hour. You can generate more drafts after ${when}.`;
        }
    }

    if (variant === 'roast') {
        return "You've reached the roast limit for this hour. You can roast more essays in about an hour.";
    }

    if (variant === 'score') {
        return "You've reached the scoring limit for this hour. You can score more essays in about an hour.";
    }

    return "You've reached the Why This School limit for this hour. You can generate more drafts in about an hour.";
}
