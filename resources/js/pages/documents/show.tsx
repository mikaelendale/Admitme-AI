import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowClockwise,
    CheckCircle,
    ClockCounterClockwise,
    DownloadSimple,
    FileText,
    Quotes,
    UploadSimple,
    WarningCircle,
} from '@phosphor-icons/react';
import { Fragment, useState } from 'react';
import { ErrorPill, HintPill } from '@/components/admitme/profile-fields';
import {
    ListCard,
    ListRow,
    Page,
    PageHeader,
    Section,
    StatusBadge,
} from '@/components/page/kit';
import type { BadgeTone } from '@/components/page/kit';
import { Button } from '@/components/ui/button';
import { AppLayoutHeaderMeta } from '@/layouts/app-layout';
import { cn } from '@/lib/utils';

type Version = {
    id: string;
    version: number;
    originalName: string;
    status: string;
    statusLabel: string;
    failureReason: string | null;
    sizeKb: number;
    sha256: string;
    uploadedAt: string | null;
    isCurrent: boolean;
    canRetry: boolean;
};

type ExtractedValue = {
    id: number;
    field: string;
    label: string;
    value: string;
    status: 'candidate' | 'conflict';
    reviewState: 'pending' | 'accepted' | 'rejected' | 'superseded' | null;
    page: number | null;
    quote: string | null;
    versionId: string | null;
    ambiguous: boolean;
    note: string | null;
    profileValue: string | null;
};

type PageProps = {
    document: {
        id: string;
        title: string;
        kindLabel: string;
        currentVersionId: string | null;
    };
    versions: Version[];
    pages: { number: number; text: string }[];
    values: ExtractedValue[];
    profileRevision: number;
};

const REVIEW_LABELS: Record<string, string> = {
    accepted: 'Accepted — saved to your profile',
    rejected: 'Rejected — nothing changed',
    superseded: 'Retired — the file was replaced before review',
};

const STATUS_TONE: Record<string, BadgeTone> = {
    queued: 'neutral',
    processing: 'neutral',
    needs_review: 'warning',
    ready: 'success',
    failed: 'danger',
    superseded: 'neutral',
};

export default function DocumentShowPage({
    document,
    versions,
    pages,
    values,
    profileRevision,
}: PageProps) {
    const current = versions.find((version) => version.isCurrent) ?? null;
    const currentValues = values.filter(
        (value) => value.versionId === document.currentVersionId,
    );
    const olderValues = values.filter(
        (value) => value.versionId !== document.currentVersionId,
    );
    const pending = currentValues.filter(
        (value) => value.reviewState === 'pending',
    ).length;
    const quotesByPage = currentValues.reduce<Record<number, string[]>>(
        (acc, value) => {
            if (value.page && value.quote) {
                acc[value.page] = [...(acc[value.page] ?? []), value.quote];
            }

            return acc;
        },
        {},
    );
    const replaceForm = useForm<{ file: File | null }>({ file: null });
    const reading =
        current?.status === 'processing' || current?.status === 'queued';

    return (
        <>
            <Head title={document.title} />
            <AppLayoutHeaderMeta>{document.title}</AppLayoutHeaderMeta>
            <Page width="wide">
                <PageHeader
                    eyebrow={
                        <span className="inline-flex items-center gap-2">
                            {document.kindLabel}
                            {current ? (
                                <span>· version {current.version}</span>
                            ) : null}
                        </span>
                    }
                    title={document.title}
                    description={
                        pending > 0
                            ? `${pending} ${pending === 1 ? 'value' : 'values'} to check against the page. Accepted values are saved to your profile with this page as their source.`
                            : reading
                              ? 'Reading this file…'
                              : 'Every value found here has been reviewed.'
                    }
                    actions={
                        <>
                            {current ? (
                                <StatusBadge
                                    tone={
                                        STATUS_TONE[current.status] ?? 'neutral'
                                    }
                                    className={cn(reading && 'animate-pulse')}
                                >
                                    {current.statusLabel}
                                </StatusBadge>
                            ) : null}
                            <DeleteDocument documentId={document.id} />
                        </>
                    }
                />

                {current?.status === 'failed' ? (
                    <div
                        role="alert"
                        className="mb-8 rounded-2xl border border-destructive/30 bg-destructive/5 p-4 text-sm"
                    >
                        <p className="flex items-center gap-1.5 font-medium text-destructive">
                            <WarningCircle
                                className="size-4"
                                weight="fill"
                                aria-hidden
                            />
                            This file could not be read
                        </p>
                        <p className="mt-1 text-foreground/80">
                            {current.failureReason}
                        </p>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {current.canRetry ? (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="gap-1.5 rounded-full"
                                    onClick={() =>
                                        router.post(
                                            `/documents/${document.id}/versions/${current.id}/retry`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <ArrowClockwise
                                        className="size-4"
                                        aria-hidden
                                    />
                                    Try again
                                </Button>
                            ) : null}
                            <Button
                                size="sm"
                                variant="outline"
                                className="rounded-full"
                                asChild
                            >
                                <Link href="/profile-setup?section=academics">
                                    Enter values manually
                                </Link>
                            </Button>
                        </div>
                    </div>
                ) : null}

                <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                    <Section
                        title="Values found"
                        icon={CheckCircle}
                        className="min-w-0"
                    >
                        {currentValues.length === 0 ? (
                            <p className="rounded-2xl border border-dashed border-border px-5 py-8 text-center text-[13.5px] text-muted-foreground">
                                {reading
                                    ? 'Still reading this file…'
                                    : 'No profile values were found in this version.'}
                            </p>
                        ) : (
                            <ul className="space-y-3">
                                {currentValues.map((value) => (
                                    <ValueCard
                                        key={value.id}
                                        value={value}
                                        profileRevision={profileRevision}
                                    />
                                ))}
                            </ul>
                        )}

                        {olderValues.length > 0 ? (
                            <details className="mt-4 text-[13px] text-muted-foreground">
                                <summary className="cursor-pointer select-none hover:text-foreground">
                                    Values from earlier versions (
                                    {olderValues.length})
                                </summary>
                                <ul className="mt-2 space-y-1">
                                    {olderValues.map((value) => (
                                        <li key={value.id}>
                                            {value.label}: {value.value} —{' '}
                                            {REVIEW_LABELS[
                                                value.reviewState ?? ''
                                            ] ?? value.reviewState}
                                        </li>
                                    ))}
                                </ul>
                            </details>
                        ) : null}
                    </Section>

                    <Section
                        title="Source text"
                        icon={FileText}
                        className="min-w-0"
                    >
                        {pages.length === 0 ? (
                            <p className="rounded-2xl border border-dashed border-border px-5 py-8 text-center text-[13.5px] text-muted-foreground">
                                No text has been read from this version.
                            </p>
                        ) : (
                            <div className="max-h-[70vh] scrollbar-thin space-y-3 overflow-y-auto rounded-2xl bg-muted/50 p-3">
                                {pages.map((page) => (
                                    <article
                                        key={page.number}
                                        className="rounded-xl bg-card px-4 py-3 shadow-card"
                                        aria-label={`Page ${page.number}`}
                                    >
                                        <p className="mb-1.5 text-[12px] text-muted-foreground">
                                            Page {page.number}
                                        </p>
                                        <p className="text-[12.5px] leading-relaxed whitespace-pre-wrap text-foreground/90">
                                            <Highlighted
                                                text={page.text}
                                                quotes={
                                                    quotesByPage[page.number] ??
                                                    []
                                                }
                                            />
                                        </p>
                                    </article>
                                ))}
                            </div>
                        )}
                    </Section>
                </div>

                <Section
                    title="Versions"
                    icon={ClockCounterClockwise}
                    className="mt-4"
                >
                    <ListCard>
                        {versions.map((version) => (
                            <ListRow
                                key={version.id}
                                icon={
                                    <span className="nums flex size-8 items-center justify-center rounded-lg bg-muted text-[12px] font-semibold text-foreground">
                                        v{version.version}
                                    </span>
                                }
                                title={version.originalName}
                                description={`${version.sizeKb} KB · sha256 ${version.sha256}…${version.isCurrent ? ' · current' : ''}`}
                                trailing={
                                    <>
                                        <StatusBadge
                                            tone={
                                                STATUS_TONE[version.status] ??
                                                'neutral'
                                            }
                                        >
                                            {version.statusLabel}
                                        </StatusBadge>
                                        <a
                                            href={`/documents/${document.id}/versions/${version.id}/file`}
                                            aria-label={`Download version ${version.version}`}
                                            className="inline-flex size-8 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                        >
                                            <DownloadSimple
                                                className="size-4"
                                                aria-hidden
                                            />
                                        </a>
                                    </>
                                }
                            />
                        ))}
                    </ListCard>
                    <form
                        className="mt-3 flex flex-wrap items-center gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            replaceForm.post(
                                `/documents/${document.id}/versions`,
                                { forceFormData: true, preserveScroll: true },
                            );
                        }}
                    >
                        <input
                            type="file"
                            aria-label="Replacement file"
                            accept=".pdf,.docx,.txt,.png,.jpg,.jpeg"
                            onChange={(event) =>
                                replaceForm.setData(
                                    'file',
                                    event.target.files?.[0] ?? null,
                                )
                            }
                            className="block h-9 max-w-xs cursor-pointer rounded-xl border border-input bg-background text-[13px] text-muted-foreground file:mr-3 file:h-full file:cursor-pointer file:border-0 file:border-r file:border-border file:bg-muted file:px-3 file:text-[13px] file:font-medium file:text-foreground"
                        />
                        <Button
                            type="submit"
                            size="sm"
                            variant="outline"
                            className="h-9 gap-1.5 rounded-xl"
                            disabled={
                                replaceForm.processing || !replaceForm.data.file
                            }
                        >
                            <UploadSimple className="size-4" aria-hidden />
                            Upload a new version
                        </Button>
                        <ErrorPill message={replaceForm.errors.file} />
                    </form>
                    <HintPill className="mt-3">
                        Earlier versions stay. Values you haven&apos;t reviewed
                        are retired, and confirmed profile values are never
                        overwritten automatically.
                    </HintPill>
                </Section>
            </Page>
        </>
    );
}

/**
 * Deleting removes every version's file, its searchable text, values it proposed that you haven't confirmed,
 * and any export that contains it. Values you already confirmed stay on your profile.
 */
function DeleteDocument({ documentId }: { documentId: string }) {
    const [confirming, setConfirming] = useState(false);

    return confirming ? (
        <div className="flex max-w-xs flex-col items-end gap-1.5 text-right">
            <p className="text-[12px] leading-snug text-muted-foreground">
                Delete every version, its text and unconfirmed values, and
                exports that include it? Values you confirmed stay.
            </p>
            <div className="flex gap-1.5">
                <Button
                    size="xs"
                    variant="destructive"
                    onClick={() => router.delete(`/documents/${documentId}`)}
                >
                    Yes, delete
                </Button>
                <Button
                    size="xs"
                    variant="ghost"
                    onClick={() => setConfirming(false)}
                >
                    Keep it
                </Button>
            </div>
        </div>
    ) : (
        <Button
            size="sm"
            variant="ghost"
            className="text-muted-foreground"
            onClick={() => setConfirming(true)}
        >
            Delete document…
        </Button>
    );
}

function ValueCard({
    value,
    profileRevision,
}: {
    value: ExtractedValue;
    profileRevision: number;
}) {
    const [editing, setEditing] = useState(false);
    const [edited, setEdited] = useState(value.value);
    const [busy, setBusy] = useState(false);
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const pending = value.reviewState === 'pending';

    function decide(action: 'accept' | 'reject') {
        setBusy(true);
        router.post(
            `/document-values/${value.id}/decision`,
            {
                action,
                value: editing ? edited : null,
                expected_revision: profileRevision,
            },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    }

    return (
        <li
            className={cn(
                'rounded-2xl border bg-card p-4',
                value.status === 'conflict'
                    ? 'border-warning/50'
                    : 'border-border',
            )}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-[13px] text-muted-foreground">
                        {value.label}
                    </p>
                    <p className="mt-0.5 text-[18px] leading-tight font-semibold tracking-tight text-foreground">
                        {value.value}
                    </p>
                </div>
                {value.page ? (
                    <StatusBadge>Page {value.page}</StatusBadge>
                ) : null}
            </div>
            {value.quote ? (
                <p className="mt-2.5 flex gap-1.5 text-[13px] leading-snug text-muted-foreground">
                    <Quotes
                        className="mt-0.5 size-3.5 shrink-0"
                        weight="fill"
                        aria-hidden
                    />
                    <span>{value.quote}</span>
                </p>
            ) : null}
            {value.status === 'conflict' ? (
                <p className="mt-2.5 rounded-lg bg-warning/10 px-2.5 py-1.5 text-[12.5px] text-foreground/85">
                    Your profile says{' '}
                    <strong className="font-semibold">
                        {value.profileValue ?? '—'}
                    </strong>
                    . Accepting replaces it; rejecting keeps it.
                </p>
            ) : null}
            {value.ambiguous || value.note ? (
                <p className="mt-2 text-[12.5px] text-muted-foreground">
                    {value.ambiguous ? 'Unclear: ' : ''}
                    {value.note}
                </p>
            ) : null}

            {pending ? (
                <div className="mt-3.5 flex flex-wrap items-center gap-2">
                    {editing ? (
                        <input
                            className="h-8 w-40 rounded-lg border border-input bg-background px-2.5 text-[13px] text-foreground outline-none focus-visible:border-foreground/30"
                            aria-label={`Correct ${value.label}`}
                            value={edited}
                            onChange={(event) => setEdited(event.target.value)}
                        />
                    ) : null}
                    <Button
                        size="sm"
                        className="rounded-full"
                        disabled={busy}
                        onClick={() => decide('accept')}
                    >
                        {editing ? 'Save my value' : 'Accept'}
                    </Button>
                    {!editing ? (
                        <Button
                            size="sm"
                            variant="outline"
                            className="rounded-full"
                            disabled={busy}
                            onClick={() => setEditing(true)}
                        >
                            Edit
                        </Button>
                    ) : null}
                    <Button
                        size="sm"
                        variant="ghost"
                        className="rounded-full"
                        disabled={busy}
                        onClick={() => decide('reject')}
                    >
                        Reject
                    </Button>
                </div>
            ) : (
                <p className="mt-3 text-[12.5px] font-medium text-foreground/80">
                    {REVIEW_LABELS[value.reviewState ?? ''] ??
                        value.reviewState}
                </p>
            )}
            {pending && (errors?.proposal || errors?.expected_revision) ? (
                <ErrorPill
                    className="mt-2"
                    message={errors.proposal ?? errors.expected_revision}
                />
            ) : null}
        </li>
    );
}

function Highlighted({ text, quotes }: { text: string; quotes: string[] }) {
    if (quotes.length === 0) {
        return <>{text}</>;
    }

    const escaped = quotes
        .filter(Boolean)
        .map((quote) =>
            quote
                .replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
                .replace(/\s+/g, '\\s+'),
        );
    const parts = text.split(new RegExp(`(${escaped.join('|')})`, 'gi'));

    return (
        <>
            {parts.map((part, index) =>
                index % 2 === 1 ? (
                    <mark
                        key={index}
                        className="rounded bg-amber-200/70 px-0.5 text-foreground dark:bg-amber-400/30"
                    >
                        {part}
                    </mark>
                ) : (
                    <Fragment key={index}>{part}</Fragment>
                ),
            )}
        </>
    );
}

DocumentShowPage.layout = {
    breadcrumbs: [{ title: 'Documents', href: '/documents' }],
};
