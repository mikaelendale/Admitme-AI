/**
 * Small shared pieces for the Applications, Approvals and Tasks screens (and the cards they embed):
 * field styles, status tones and date formatting that reads the same on the server and in the browser.
 */

import type { BadgeTone, Tone } from '@/components/page/kit';

/** Text inputs and selects. */
export const fieldClass =
    'h-9 w-full min-w-0 rounded-lg border border-input bg-background px-3 text-[14px] text-foreground outline-none transition-[border-color,box-shadow] placeholder:text-muted-foreground focus-visible:border-foreground/30 focus-visible:ring-3 focus-visible:ring-ring/25 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-input/30';

/** Multi-line text. */
export const textareaClass =
    'block min-h-20 w-full min-w-0 rounded-lg border border-input bg-background px-3 py-2 text-[14px] text-foreground outline-none transition-[border-color,box-shadow] placeholder:text-muted-foreground focus-visible:border-foreground/30 focus-visible:ring-3 focus-visible:ring-ring/25 dark:bg-input/30';

/** A field label above its input. */
export const labelClass = 'mb-1.5 block text-[13px] text-muted-foreground';

/** Application status → badge tone and the colour of the college icon in lists. */
export const APPLICATION_STATUS: Record<
    string,
    { tone: BadgeTone; icon: Tone }
> = {
    preparing: { tone: 'neutral', icon: 'blue' },
    ready_for_review: { tone: 'info', icon: 'violet' },
    ready_to_submit: { tone: 'info', icon: 'amber' },
    submitted: { tone: 'success', icon: 'green' },
    decision_received: { tone: 'success', icon: 'teal' },
    closed: { tone: 'neutral', icon: 'slate' },
};

const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

/** A calendar day ("2027-02-01") as "Feb 1, 2027". No time zone involved, so it is safe to render on the server. */
export function formatDay(day: string | null | undefined): string | null {
    if (!day) {
        return null;
    }

    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(day);

    return match
        ? `${MONTHS[Number(match[2]) - 1]} ${Number(match[3])}, ${match[1]}`
        : day;
}

/** A moment in the student's own time zone. Browser-only: call it once hydrated. */
export function formatMoment(
    iso: string | null | undefined,
    style: 'date' | 'datetime' = 'datetime',
): string | null {
    if (!iso) {
        return null;
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return style === 'date'
        ? date.toLocaleDateString(undefined, {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
          })
        : date.toLocaleString(undefined, {
              month: 'short',
              day: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
          });
}
