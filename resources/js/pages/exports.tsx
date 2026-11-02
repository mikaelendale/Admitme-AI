import { Head, router, useForm } from '@inertiajs/react';
import {
    Archive,
    CircleNotch,
    DownloadSimple,
    FileZip,
    Info,
    ListChecks,
    Trash,
    WarningCircle,
} from '@phosphor-icons/react';
import { useEffect } from 'react';
import InputError from '@/components/input-error';
import {
    EmptyState,
    IconTile,
    ListCard,
    Page,
    PageHeader,
    Section,
    StatusBadge,
} from '@/components/page/kit';
import type { BadgeTone, Tone } from '@/components/page/kit';
import { CheckboxInput } from '@/components/settings/kit';
import { Button } from '@/components/ui/button';
import { useHydrated } from '@/hooks/use-hydrated';

type ExportRow = {
    id: string;
    status: 'queued' | 'building' | 'ready' | 'failed' | 'expired' | 'deleted';
    statusReason: string | null;
    sections: string[];
    requestedVia: string;
    createdAt: string | null;
    expiresAt: string | null;
    size: number | null;
    included: number;
    omitted: { type: string; id: string; reason: string }[];
    downloadUrl: string | null;
};

const SECTION_LABELS: Record<string, string> = {
    profile: 'Profile',
    college_list: 'College list',
    applications: 'Applications and checklists',
    tasks: 'Tasks and plans',
    writing: 'Writing',
    documents: 'Documents',
};

const STATUS: Record<
    ExportRow['status'],
    { label: string; badge: BadgeTone; tone: Tone }
> = {
    queued: { label: 'Queued', badge: 'info', tone: 'blue' },
    building: { label: 'Building', badge: 'info', tone: 'blue' },
    ready: { label: 'Ready', badge: 'success', tone: 'green' },
    failed: { label: 'Failed', badge: 'danger', tone: 'red' },
    expired: { label: 'Expired', badge: 'neutral', tone: 'slate' },
    deleted: { label: 'Deleted', badge: 'neutral', tone: 'slate' },
};

function formatSize(bytes: number | null): string {
    if (!bytes) {
        return '';
    }

    return bytes > 1048576
        ? `${(bytes / 1048576).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/** A checkbox row of the "What to include" list. The input carries the accessible name. */
function IncludeRow({
    label,
    ariaLabel,
    checked,
    onChange,
    meta,
    nested = false,
}: {
    label: string;
    ariaLabel: string;
    checked: boolean;
    onChange: () => void;
    meta?: string;
    nested?: boolean;
}) {
    return (
        <label
            className={
                nested
                    ? 'flex cursor-pointer items-center gap-3 py-1.5 text-[14px] text-foreground'
                    : 'flex cursor-pointer items-center gap-3 px-5 py-3.5 text-[14.5px] text-foreground transition-colors hover:bg-muted/40'
            }
        >
            <CheckboxInput
                checked={checked}
                onChange={onChange}
                aria-label={ariaLabel}
            />
            <span className="min-w-0 flex-1 truncate">{label}</span>
            {meta ? (
                <span className="nums shrink-0 text-[13px] text-muted-foreground">
                    {meta}
                </span>
            ) : null}
        </label>
    );
}

export default function ExportsPage({
    exports,
    documents,
    writing,
    excluded,
}: {
    exports: ExportRow[];
    documents: { id: string; title: string }[];
    writing: { id: string; title: string }[];
    excluded: string[];
}) {
    const form = useForm<{
        sections: string[];
        document_ids: string[];
        writing_piece_ids: string[];
    }>({
        sections: ['profile', 'college_list', 'applications', 'tasks'],
        document_ids: [],
        writing_piece_ids: [],
    });
    const hydrated = useHydrated();
    const building = exports.some(
        (e) => e.status === 'queued' || e.status === 'building',
    );

    // While an archive is being built, refresh the list now and then.
    useEffect(() => {
        if (!building) {
            return;
        }

        const timer = window.setTimeout(
            () => router.reload({ only: ['exports'] }),
            3000,
        );

        return () => window.clearTimeout(timer);
    }, [building, exports]);

    const toggle = (
        key: 'sections' | 'document_ids' | 'writing_piece_ids',
        value: string,
    ) =>
        form.setData(
            key,
            form.data[key].includes(value)
                ? form.data[key].filter((v) => v !== value)
                : [...form.data[key], value],
        );

    const localTime = (iso: string | null) =>
        iso && hydrated
            ? new Date(iso).toLocaleString(undefined, {
                  month: 'short',
                  day: 'numeric',
                  year: 'numeric',
                  hour: 'numeric',
                  minute: '2-digit',
              })
            : '';

    return (
        <>
            <Head title="Your data" />
            <Page width="narrow">
                <PageHeader
                    title="Your data"
                    description="Download a copy of what AdmitMe holds about you, as it is right now. Only you can download it, and the link stops working after 24 hours."
                />

                <Section title="What to include" icon={ListChecks}>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/exports', { preserveScroll: true });
                        }}
                    >
                        <fieldset className="overflow-hidden rounded-2xl border border-border bg-card">
                            <legend className="sr-only">What to include</legend>
                            <ul className="divide-y divide-border">
                                {Object.entries(SECTION_LABELS).map(
                                    ([key, label]) => {
                                        const checked =
                                            form.data.sections.includes(key);
                                        const items =
                                            key === 'documents'
                                                ? documents
                                                : key === 'writing'
                                                  ? writing
                                                  : null;

                                        return (
                                            <li key={key}>
                                                <IncludeRow
                                                    label={label}
                                                    ariaLabel={`Include ${label}`}
                                                    checked={checked}
                                                    onChange={() =>
                                                        toggle('sections', key)
                                                    }
                                                    meta={
                                                        items
                                                            ? `${items.length} ${key === 'documents' ? (items.length === 1 ? 'file' : 'files') : items.length === 1 ? 'piece' : 'pieces'}`
                                                            : undefined
                                                    }
                                                />
                                                {checked &&
                                                key === 'documents' ? (
                                                    <fieldset className="px-5 pb-3.5 pl-[50px]">
                                                        <legend className="mb-1 text-[12.5px] text-muted-foreground">
                                                            The current version
                                                            of each document
                                                        </legend>
                                                        {documents.length ===
                                                        0 ? (
                                                            <p className="text-[13px] text-muted-foreground">
                                                                No documents
                                                                uploaded.
                                                            </p>
                                                        ) : null}
                                                        {documents.map((d) => (
                                                            <IncludeRow
                                                                key={d.id}
                                                                nested
                                                                label={d.title}
                                                                ariaLabel={`Include document ${d.title}`}
                                                                checked={form.data.document_ids.includes(
                                                                    d.id,
                                                                )}
                                                                onChange={() =>
                                                                    toggle(
                                                                        'document_ids',
                                                                        d.id,
                                                                    )
                                                                }
                                                            />
                                                        ))}
                                                    </fieldset>
                                                ) : null}
                                                {checked &&
                                                key === 'writing' ? (
                                                    <fieldset className="px-5 pb-3.5 pl-[50px]">
                                                        <legend className="mb-1 text-[12.5px] text-muted-foreground">
                                                            The latest saved
                                                            version of each
                                                            piece, with its
                                                            feedback
                                                        </legend>
                                                        {writing.length ===
                                                        0 ? (
                                                            <p className="text-[13px] text-muted-foreground">
                                                                No saved
                                                                writing.
                                                            </p>
                                                        ) : null}
                                                        {writing.map((p) => (
                                                            <IncludeRow
                                                                key={p.id}
                                                                nested
                                                                label={p.title}
                                                                ariaLabel={`Include writing ${p.title}`}
                                                                checked={form.data.writing_piece_ids.includes(
                                                                    p.id,
                                                                )}
                                                                onChange={() =>
                                                                    toggle(
                                                                        'writing_piece_ids',
                                                                        p.id,
                                                                    )
                                                                }
                                                            />
                                                        ))}
                                                    </fieldset>
                                                ) : null}
                                            </li>
                                        );
                                    },
                                )}
                            </ul>
                        </fieldset>

                        <div className="mt-4 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <p className="flex items-start gap-2 text-[13px] leading-snug text-muted-foreground">
                                <Info
                                    className="mt-px size-4 shrink-0"
                                    aria-hidden
                                />
                                <span>
                                    Never included: {excluded.join('; ')}.
                                </span>
                            </p>
                            <Button
                                type="submit"
                                className="h-9 shrink-0 gap-1.5 self-end rounded-full px-4 sm:self-auto"
                                disabled={
                                    form.processing ||
                                    form.data.sections.length === 0
                                }
                            >
                                <FileZip className="size-4" />
                                Prepare export
                            </Button>
                        </div>
                        <InputError
                            message={form.errors.sections}
                            className="mt-2"
                        />
                    </form>
                </Section>

                <Section title="Your exports" icon={Archive}>
                    {exports.length === 0 ? (
                        <EmptyState
                            icon={
                                <IconTile
                                    icon={FileZip}
                                    tone="violet"
                                    variant="glyph"
                                />
                            }
                            title="None yet."
                            description="Exports you prepare appear here until their link expires."
                        />
                    ) : (
                        <ListCard>
                            {exports.map((e) => {
                                const status =
                                    STATUS[e.status] ?? STATUS.deleted;
                                const details = [
                                    e.status === 'ready' && e.expiresAt
                                        ? `${e.included} files`
                                        : null,
                                    e.status === 'ready' && e.expiresAt
                                        ? formatSize(e.size)
                                        : null,
                                    e.status === 'ready' &&
                                    e.expiresAt &&
                                    hydrated
                                        ? `link expires ${localTime(e.expiresAt)}`
                                        : null,
                                    e.statusReason,
                                ].filter(Boolean);

                                return (
                                    <li
                                        key={e.id}
                                        className="flex items-start gap-4 px-5 py-4"
                                        data-export-status={e.status}
                                    >
                                        <span className="mt-0.5 flex shrink-0">
                                            <IconTile
                                                icon={FileZip}
                                                tone={status.tone}
                                                variant="glyph"
                                            />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[15px] font-medium text-foreground">
                                                <span className="min-w-0">
                                                    {e.sections
                                                        .map(
                                                            (s) =>
                                                                SECTION_LABELS[
                                                                    s
                                                                ] ?? s,
                                                        )
                                                        .join(', ')}
                                                </span>
                                                <StatusBadge
                                                    tone={status.badge}
                                                >
                                                    {e.status === 'queued' ||
                                                    e.status === 'building' ? (
                                                        <CircleNotch
                                                            className="size-3 animate-spin"
                                                            aria-hidden
                                                        />
                                                    ) : null}
                                                    {status.label}
                                                </StatusBadge>
                                            </p>
                                            <p className="nums mt-1 text-[13px] leading-snug text-muted-foreground">
                                                Requested{' '}
                                                {localTime(e.createdAt)}
                                                {e.requestedVia === 'chat'
                                                    ? ' from chat'
                                                    : ''}
                                                {details.length > 0
                                                    ? ` · ${details.join(' · ')}`
                                                    : ''}
                                            </p>
                                            {e.omitted.length > 0 ? (
                                                <p className="mt-1.5 flex items-start gap-1.5 text-[13px] leading-snug text-amber-700 dark:text-amber-300">
                                                    <WarningCircle
                                                        className="mt-px size-4 shrink-0"
                                                        aria-hidden
                                                    />
                                                    <span>
                                                        Left out:{' '}
                                                        {e.omitted
                                                            .map(
                                                                (o) =>
                                                                    `${o.type} (${o.reason})`,
                                                            )
                                                            .join('; ')}
                                                    </span>
                                                </p>
                                            ) : null}
                                        </div>
                                        <div className="-mr-1 flex shrink-0 items-center gap-1">
                                            {e.downloadUrl ? (
                                                <Button
                                                    size="sm"
                                                    asChild
                                                    className="gap-1.5 rounded-full px-3.5 max-sm:size-8 max-sm:px-0"
                                                >
                                                    <a href={e.downloadUrl}>
                                                        <DownloadSimple
                                                            className="size-4"
                                                            aria-hidden
                                                        />
                                                        <span className="max-sm:sr-only">
                                                            Download
                                                        </span>
                                                    </a>
                                                </Button>
                                            ) : null}
                                            {[
                                                'ready',
                                                'queued',
                                                'building',
                                                'failed',
                                            ].includes(e.status) ? (
                                                <Button
                                                    size="icon-sm"
                                                    variant="ghost"
                                                    className="rounded-full text-muted-foreground hover:text-destructive"
                                                    aria-label="Delete this export now"
                                                    onClick={() =>
                                                        router.delete(
                                                            `/exports/${e.id}`,
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <Trash
                                                        className="size-4"
                                                        aria-hidden
                                                    />
                                                </Button>
                                            ) : null}
                                        </div>
                                    </li>
                                );
                            })}
                        </ListCard>
                    )}
                </Section>
            </Page>
        </>
    );
}

ExportsPage.layout = {
    breadcrumbs: [{ title: 'Your data', href: '/exports' }],
};
