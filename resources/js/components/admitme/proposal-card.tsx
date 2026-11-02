import { router } from '@inertiajs/react';
import {
    Browsers,
    CaretDown,
    CheckCircle,
    ClipboardText,
    ClockCountdown,
    FileText,
    ListChecks,
    ListPlus,
    SealCheck,
    UserCircle,
    WarningCircle,
    XCircle,
} from '@phosphor-icons/react';
import type { Icon } from '@phosphor-icons/react';
import { useEffect, useId, useMemo, useState } from 'react';
import {
    fieldClass,
    formatMoment,
} from '@/components/admitme/application-parts';
import { IconTile, StatusBadge } from '@/components/page/kit';
import type { BadgeTone, Tone } from '@/components/page/kit';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { useHydrated } from '@/hooks/use-hydrated';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';
import { cn } from '@/lib/utils';

export type ProposalItem = {
    key: string;
    label: string;
    before: string;
    after: string;
    /** Text for the edit box, or null when the item cannot be edited before approval. */
    editValue: string | null;
    reason: string | null;
    /** What the item rests on (task proposals): each point with its basis and, when sourced, a link. */
    evidence?: { text: string; basis: string; url: string | null }[];
    editedByStudent: boolean;
};

export type ProposalData = {
    id: string;
    type: string;
    status: 'pending' | 'applied' | 'rejected' | 'expired' | 'stale' | 'failed';
    title: string;
    revision: number;
    payloadHash: string;
    createdAt: string | null;
    expiresAt: string;
    items: ProposalItem[];
    decision: {
        decision: 'approve' | 'reject';
        selectedKeys: string[];
        receipt: Record<string, string>;
        decidedAt: string | null;
    } | null;
};

const OUTCOME_LABELS: Record<string, string> = {
    applied: 'Applied',
    already_present: 'Already there — nothing to change',
    skipped: 'Not selected',
    stale: 'Not applied — changed since proposed',
    rejected: 'Rejected',
    failed: 'Could not be applied — nothing changed',
    refused: 'Not added — this link can’t be used as a source',
    queued: 'Added — reading the page now',
};

const OUTCOME_TONE: Record<string, BadgeTone> = {
    applied: 'success',
    already_present: 'neutral',
    skipped: 'neutral',
    stale: 'warning',
    rejected: 'neutral',
    failed: 'danger',
    refused: 'warning',
    queued: 'success',
};

const STATUS_COPY: Record<
    ProposalData['status'],
    { label: string; icon: Icon; color: string }
> = {
    pending: {
        label: 'Waiting for your approval — nothing has changed yet',
        icon: ClockCountdown,
        color: 'text-warning',
    },
    applied: {
        label: 'Approved and applied',
        icon: CheckCircle,
        color: 'text-success',
    },
    rejected: {
        label: 'Rejected — nothing changed',
        icon: XCircle,
        color: 'text-muted-foreground',
    },
    expired: {
        label: 'Expired — nothing changed',
        icon: XCircle,
        color: 'text-muted-foreground',
    },
    stale: {
        label: 'Not applied: your data changed after this was proposed. Ask the mentor for a fresh proposal.',
        icon: WarningCircle,
        color: 'text-destructive',
    },
    failed: {
        label: 'Could not be applied — nothing changed',
        icon: WarningCircle,
        color: 'text-destructive',
    },
};

/** A browser fill is only queued by approval: the extension types it, and reports each field back. */
const BROWSER_FILL_STATUS: Partial<Record<ProposalData['status'], string>> = {
    pending: 'Waiting for your approval — nothing has been typed',
    applied:
        'Approved — queued for the AdmitMe extension, which types these into the form tab. You review and submit the form yourself.',
    stale: 'Not queued: the page changed after this was prepared. Observe the page again in the extension.',
};

const TYPE_LOOK: Record<string, { icon: Icon; tone: Tone }> = {
    'profile.update': { icon: UserCircle, tone: 'pink' },
    'college_list.add': { icon: ListPlus, tone: 'green' },
    'college_list.update': { icon: ListChecks, tone: 'teal' },
    'application.update': { icon: ClipboardText, tone: 'orange' },
    'document.values': { icon: FileText, tone: 'sky' },
    'browser.fill': { icon: Browsers, tone: 'blue' },
};

function statusCopy(proposal: ProposalData): {
    label: string;
    icon: Icon;
    color: string;
} {
    const base = STATUS_COPY[proposal.status];
    const browserLabel =
        proposal.type === 'browser.fill'
            ? BROWSER_FILL_STATUS[proposal.status]
            : undefined;

    return browserLabel ? { ...base, label: browserLabel } : base;
}

function outcomeLabel(proposal: ProposalData, outcome: string): string {
    if (proposal.type === 'browser.fill' && outcome === 'applied') {
        return 'Queued for the extension';
    }

    return OUTCOME_LABELS[outcome] ?? outcome;
}

/** Short status for the compact chat card. */
const CHAT_STATUS: Record<ProposalData['status'], string> = {
    pending: 'Waiting for your approval',
    applied: 'Approved and applied',
    rejected: 'Rejected — nothing changed',
    expired: 'Expired — nothing changed',
    stale: 'Not applied — your data changed since',
    failed: 'Could not be applied',
};

/**
 * Shows an agent proposal and lets the student approve all or some items (optionally editing values)
 * or reject it. The same component is used in chat and on the Approvals page; state comes from the
 * server, so it survives reloads. `inset` drops the card frame when it sits inside another card;
 * `chat` is the compact card inside a mentor reply.
 */
export function ProposalCard({
    proposalId,
    initial,
    variant = 'card',
}: {
    proposalId?: string;
    initial?: ProposalData;
    variant?: 'card' | 'inset' | 'chat';
}) {
    const [proposal, setProposal] = useState<ProposalData | null>(
        initial ?? null,
    );
    const [loadError, setLoadError] = useState<string | null>(null);
    const [selected, setSelected] = useState<Set<string>>(
        () => new Set(initial?.items.map((item) => item.key) ?? []),
    );
    const [edits, setEdits] = useState<Record<string, string>>({});
    const [editing, setEditing] = useState<Set<string>>(() => new Set());
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    // Decided proposals open folded; one decided here stays open so its outcome is in view.
    const [itemsOpen, setItemsOpen] = useState(
        initial ? initial.status === 'pending' : true,
    );
    const hydrated = useHydrated();
    const itemsId = useId();
    // One key per card: a double click or network retry replays the same decision instead of making a new one.
    const idempotencyKey = useMemo(() => crypto.randomUUID(), []);
    const id = initial?.id ?? proposalId;

    useEffect(() => {
        if (initial || !proposalId) {
            return;
        }

        let cancelled = false;

        fetch(`/approvals/${proposalId}`, {
            credentials: 'same-origin',
            headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
        })
            .then(async (res) => {
                if (!res.ok) {
                    throw new Error('Could not load this proposal.');
                }

                const data = (await res.json()) as { proposal: ProposalData };

                if (!cancelled) {
                    setProposal(data.proposal);
                    setSelected(
                        new Set(data.proposal.items.map((item) => item.key)),
                    );
                    setItemsOpen(data.proposal.status === 'pending');
                }
            })
            .catch((err: unknown) => {
                if (!cancelled) {
                    setLoadError(
                        err instanceof Error
                            ? err.message
                            : 'Could not load this proposal.',
                    );
                }
            });

        return () => {
            cancelled = true;
        };
    }, [initial, proposalId]);

    const chat = variant === 'chat';
    const frame = chat
        ? 'overflow-hidden rounded-xl border border-border bg-background'
        : variant === 'card'
          ? 'rounded-2xl border border-border bg-card not-first:mt-3'
          : 'rounded-xl bg-muted/50';
    const pad = chat ? 'px-3' : variant === 'card' ? 'px-4 @md:px-5' : 'px-3.5';

    if (loadError) {
        return (
            <p role="alert" className="error-pill">
                <WarningCircle
                    weight="fill"
                    className="size-3.5 shrink-0"
                    aria-hidden
                />
                <span>{loadError}</span>
            </p>
        );
    }

    if (!proposal || !id) {
        return chat ? (
            <div
                className={cn(frame, 'space-y-2 px-3 py-3')}
                aria-label="Loading proposal"
            >
                <div className="h-3.5 w-2/5 animate-pulse rounded bg-muted" />
                <div className="h-3 w-4/5 animate-pulse rounded bg-muted" />
            </div>
        ) : (
            <p
                className={cn(
                    frame,
                    'px-4 py-3 text-[13px] text-muted-foreground',
                )}
            >
                Loading proposal…
            </p>
        );
    }

    const pending = proposal.status === 'pending';
    const showItems = pending || itemsOpen;
    const status = statusCopy(proposal);
    const StatusIcon = status.icon;
    const look = TYPE_LOOK[proposal.type] ?? {
        icon: SealCheck,
        tone: 'violet' as Tone,
    };
    // Only an applied proposal can have mixed outcomes worth summing up (e.g. "1 applied · 1 not selected").
    const outcomes =
        proposal.status === 'applied'
            ? Object.values(proposal.decision?.receipt ?? {})
            : [];
    const outcomeSummary = [...new Set(outcomes)]
        .map(
            (outcome) =>
                `${outcomes.filter((o) => o === outcome).length} ${outcomeLabel(proposal, outcome).toLowerCase()}`,
        )
        .join(' · ');

    async function submit(decision: 'approve' | 'reject') {
        if (!proposal) {
            return;
        }

        setBusy(true);
        setError(null);

        const changedEdits = Object.fromEntries(
            Object.entries(edits).filter(([key, value]) => {
                const item = proposal.items.find(
                    (candidate) => candidate.key === key,
                );

                return (
                    selected.has(key) &&
                    item?.editValue !== null &&
                    item?.editValue !== value
                );
            }),
        );

        try {
            const res = await fetch(`/approvals/${proposal.id}/decision`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
                body: JSON.stringify({
                    decision,
                    selected_keys:
                        decision === 'approve' ? Array.from(selected) : [],
                    edits: changedEdits,
                    proposal_revision: proposal.revision,
                    payload_hash: proposal.payloadHash,
                    idempotency_key: idempotencyKey,
                }),
            });
            const data = (await res.json().catch(() => ({}))) as {
                proposal?: ProposalData;
                message?: string;
            };

            if (!res.ok || !data.proposal) {
                setError(
                    data.message ??
                        'The decision was not saved. Nothing changed.',
                );

                return;
            }

            setProposal(data.proposal);
            // Only the nav badge refreshes; the page behind the card stays as it is (no reload while the student
            // is reading or chatting). Other screens show the change when they are next opened.
            router.reload({ only: ['pending_approval_count'] });
            window.dispatchEvent(
                new CustomEvent('admitme:data-changed', {
                    detail: { type: data.proposal.type },
                }),
            );
        } catch {
            setError(
                'The decision was not saved. Check your connection; nothing changed.',
            );
        } finally {
            setBusy(false);
        }
    }

    const toggle = (set: Set<string>, key: string, on: boolean) => {
        const next = new Set(set);

        if (on) {
            next.add(key);
        } else {
            next.delete(key);
        }

        return next;
    };

    const LookIcon = look.icon;
    const chatStatus =
        proposal.type === 'browser.fill' && proposal.status === 'applied'
            ? 'Approved — queued for the extension'
            : CHAT_STATUS[proposal.status];

    return (
        <div className={cn('@container text-left', frame)}>
            <div
                className={cn(
                    'flex items-start gap-3',
                    pad,
                    chat ? 'py-2.5' : 'pt-4',
                    !chat && (showItems ? 'pb-3' : 'pb-4'),
                )}
            >
                {variant === 'card' ? (
                    <IconTile
                        icon={look.icon}
                        tone={look.tone}
                        variant="soft"
                        className="hidden @xs:flex"
                    />
                ) : null}
                {chat ? (
                    <LookIcon
                        aria-hidden
                        className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    />
                ) : null}
                <div className="min-w-0 flex-1">
                    <p
                        className={cn(
                            'leading-snug text-foreground',
                            chat
                                ? 'text-[13.5px] font-medium'
                                : 'text-[14.5px] font-semibold',
                        )}
                    >
                        {proposal.title}
                    </p>
                    {chat && pending ? null : (
                        <p
                            className={cn(
                                'flex items-start gap-1.5 leading-snug text-muted-foreground',
                                chat
                                    ? 'mt-0.5 text-[12.5px]'
                                    : 'mt-1 text-[13px]',
                            )}
                        >
                            <StatusIcon
                                weight="fill"
                                className={cn(
                                    'mt-px size-3.5 shrink-0',
                                    status.color,
                                )}
                                aria-hidden
                            />
                            <span>{chat ? chatStatus : status.label}</span>
                        </p>
                    )}
                    {!showItems && outcomeSummary ? (
                        <p className="mt-1 text-[12.5px] text-muted-foreground">
                            {outcomeSummary}
                        </p>
                    ) : null}
                </div>
                {!pending ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        aria-expanded={itemsOpen}
                        aria-controls={itemsId}
                        onClick={() => setItemsOpen(!itemsOpen)}
                        className={cn(
                            'shrink-0 gap-1 rounded-full text-[12.5px] text-muted-foreground',
                            chat ? '-mr-1 h-6 px-2' : '-mr-1.5 h-7 px-2.5',
                        )}
                    >
                        {itemsOpen
                            ? 'Hide'
                            : `${proposal.items.length} ${proposal.items.length === 1 ? 'item' : 'items'}`}
                        <CaretDown
                            aria-hidden
                            className={cn(
                                'size-3.5 transition-transform duration-200',
                                itemsOpen && 'rotate-180',
                            )}
                        />
                    </Button>
                ) : null}
            </div>

            <ul
                id={itemsId}
                hidden={!showItems}
                className={cn(
                    'divide-y divide-border border-t border-border',
                    variant === 'inset' &&
                        'divide-foreground/10 border-foreground/10',
                )}
            >
                {proposal.items.map((item) => {
                    const outcome = proposal.decision?.receipt[item.key];
                    const included = !pending || selected.has(item.key);
                    const canEdit =
                        pending &&
                        item.editValue !== null &&
                        selected.has(item.key);
                    const showEdit =
                        canEdit &&
                        (editing.has(item.key) ||
                            (edits[item.key] !== undefined &&
                                edits[item.key] !== item.editValue));

                    return (
                        <li
                            key={item.key}
                            className={cn(
                                'flex',
                                chat ? 'gap-2.5 py-2' : 'gap-3 py-3',
                                pad,
                            )}
                        >
                            {pending ? (
                                <Checkbox
                                    className="mt-0.5"
                                    checked={selected.has(item.key)}
                                    aria-label={`Include ${item.label}`}
                                    onCheckedChange={(checked) =>
                                        setSelected((prev) =>
                                            toggle(
                                                prev,
                                                item.key,
                                                checked === true,
                                            ),
                                        )
                                    }
                                />
                            ) : null}
                            <div
                                className={cn(
                                    'min-w-0 flex-1 transition-opacity',
                                    !included && 'opacity-55',
                                )}
                            >
                                <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                                    <p
                                        className={cn(
                                            'min-w-0 leading-snug font-medium text-foreground',
                                            chat
                                                ? 'text-[13px]'
                                                : 'text-[14px]',
                                        )}
                                    >
                                        {item.label}
                                    </p>
                                    {outcome ? (
                                        <StatusBadge
                                            tone={
                                                OUTCOME_TONE[outcome] ??
                                                'neutral'
                                            }
                                        >
                                            {outcomeLabel(proposal, outcome)}
                                        </StatusBadge>
                                    ) : null}
                                </div>
                                <p
                                    className={cn(
                                        'mt-0.5 leading-snug break-words text-muted-foreground',
                                        chat ? 'text-[12.5px]' : 'text-[13px]',
                                    )}
                                >
                                    {proposal.type === 'college_list.add' ? (
                                        <>
                                            Adds{' '}
                                            <span className="font-medium text-foreground">
                                                {item.after}
                                            </span>{' '}
                                            to your saved list
                                        </>
                                    ) : (
                                        <>
                                            <span className="line-through decoration-muted-foreground/50">
                                                {item.before}
                                            </span>
                                            {' → '}
                                            <span className="font-medium text-foreground">
                                                {item.after}
                                            </span>
                                            {item.editedByStudent
                                                ? ' (edited by you)'
                                                : ''}
                                        </>
                                    )}
                                    {canEdit && !showEdit ? (
                                        <>
                                            {' '}
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setEditing((prev) =>
                                                        toggle(
                                                            prev,
                                                            item.key,
                                                            true,
                                                        ),
                                                    )
                                                }
                                                className="ml-1 text-[12.5px] text-muted-foreground underline decoration-muted-foreground/40 underline-offset-2 transition-colors hover:text-foreground hover:decoration-foreground"
                                                aria-label={`Edit the value for ${item.label}`}
                                            >
                                                Edit
                                            </button>
                                        </>
                                    ) : null}
                                </p>
                                {showEdit ? (
                                    <input
                                        className={cn(
                                            fieldClass,
                                            'mt-2 h-8 text-[13.5px]',
                                        )}
                                        aria-label={`New value for ${item.label}`}
                                        value={
                                            edits[item.key] ??
                                            item.editValue ??
                                            ''
                                        }
                                        onChange={(event) =>
                                            setEdits((prev) => ({
                                                ...prev,
                                                [item.key]: event.target.value,
                                            }))
                                        }
                                    />
                                ) : null}
                                {item.reason ? (
                                    <p
                                        className={cn(
                                            'leading-snug text-muted-foreground',
                                            chat
                                                ? 'mt-1 line-clamp-2 text-[12px]'
                                                : 'mt-1.5 text-[12.5px]',
                                        )}
                                    >
                                        {chat
                                            ? item.reason
                                            : `Why: ${item.reason}`}
                                    </p>
                                ) : null}
                                {item.evidence && item.evidence.length > 0 ? (
                                    <details className="group mt-1.5 text-[12.5px] text-muted-foreground">
                                        <summary className="inline-flex cursor-pointer list-none items-center gap-1 rounded select-none hover:text-foreground [&::-webkit-details-marker]:hidden">
                                            Evidence ({item.evidence.length})
                                            <CaretDown
                                                aria-hidden
                                                className="size-3 transition-transform duration-200 group-open:rotate-180"
                                            />
                                        </summary>
                                        <ul className="mt-1.5 space-y-1 border-l border-border pl-3">
                                            {item.evidence.map(
                                                (point, index) => (
                                                    <li
                                                        key={index}
                                                        className="leading-snug"
                                                    >
                                                        {point.text}{' '}
                                                        <span className="italic">
                                                            —{' '}
                                                            {point.url ? (
                                                                <a
                                                                    href={
                                                                        point.url
                                                                    }
                                                                    target="_blank"
                                                                    rel="noopener noreferrer"
                                                                    className="underline underline-offset-2 hover:text-foreground"
                                                                >
                                                                    {
                                                                        point.basis
                                                                    }
                                                                </a>
                                                            ) : (
                                                                point.basis
                                                            )}
                                                        </span>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </details>
                                ) : null}
                            </div>
                        </li>
                    );
                })}
            </ul>

            {error ? (
                <div className={cn('pb-3', pad)}>
                    <p role="alert" className="error-pill">
                        <WarningCircle
                            weight="fill"
                            className="size-3.5 shrink-0"
                            aria-hidden
                        />
                        <span>{error}</span>
                    </p>
                </div>
            ) : null}

            {pending ? (
                <div
                    className={cn(
                        'flex flex-wrap items-center gap-2 border-t border-border',
                        chat ? 'py-2' : 'py-3',
                        pad,
                        variant === 'inset' && 'border-foreground/10',
                    )}
                >
                    <Button
                        size="sm"
                        className={cn(
                            'rounded-full',
                            chat ? 'h-7 px-3.5 text-[12.5px]' : 'h-8 px-4',
                        )}
                        disabled={busy || selected.size === 0}
                        onClick={() => void submit('approve')}
                    >
                        {selected.size === proposal.items.length
                            ? 'Approve'
                            : `Approve ${selected.size} selected`}
                    </Button>
                    <Button
                        size="sm"
                        variant={chat ? 'ghost' : 'outline'}
                        className={cn(
                            'rounded-full',
                            chat
                                ? 'h-7 px-3 text-[12.5px] text-muted-foreground'
                                : 'h-8 px-4',
                        )}
                        disabled={busy}
                        onClick={() => void submit('reject')}
                    >
                        Reject
                    </Button>
                    {chat ? (
                        <span
                            className="ml-auto flex min-w-0 items-center gap-1.5 text-[12px] text-muted-foreground"
                            title={
                                hydrated
                                    ? `Nothing changes until you approve. Expires ${formatMoment(proposal.expiresAt, 'date')}.`
                                    : undefined
                            }
                        >
                            <ClockCountdown
                                aria-hidden
                                className="size-3.5 shrink-0 text-warning"
                                weight="fill"
                            />
                            <span className="truncate">
                                Waiting for your approval
                            </span>
                        </span>
                    ) : hydrated ? (
                        <span className="ml-auto text-[12px] text-muted-foreground">
                            Expires {formatMoment(proposal.expiresAt, 'date')}
                        </span>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
