import { Head, Link, useForm } from '@inertiajs/react';
import {
    Article,
    Certificate,
    ClipboardText,
    CurrencyCircleDollar,
    FileText,
    Files,
    MagnifyingGlass,
    Student,
    UploadSimple,
} from '@phosphor-icons/react';
import type { Icon } from '@phosphor-icons/react';
import { useMemo, useRef, useState } from 'react';
import { ErrorPill, HintPill } from '@/components/admitme/profile-fields';
import {
    EmptyState,
    FolderCard,
    IconTile,
    Page,
    PageHeader,
    Section,
    StatusBadge,
} from '@/components/page/kit';
import type { BadgeTone, Tone } from '@/components/page/kit';
import { Button } from '@/components/ui/button';
import { useHydrated } from '@/hooks/use-hydrated';
import { cn } from '@/lib/utils';

type DocumentRow = {
    id: string;
    title: string;
    kind: string;
    kindLabel: string;
    version: number | null;
    status: string | null;
    statusLabel: string | null;
    failureReason: string | null;
    uploadedAt: string | null;
    pendingValues: number;
};

type PageProps = {
    documents: DocumentRow[];
    kinds: Record<string, string>;
    ocrAvailable: boolean;
    maxUploadMb: number;
};

/** One look per document type, used for folders and rows. */
const KIND_STYLE: Record<string, { icon: Icon; tone: Tone; folder: string }> = {
    transcript: { icon: Student, tone: 'blue', folder: 'Transcripts' },
    test_score_report: {
        icon: Certificate,
        tone: 'violet',
        folder: 'Test reports',
    },
    resume: { icon: ClipboardText, tone: 'green', folder: 'Resumes' },
    recommendation: { icon: Article, tone: 'pink', folder: 'Recommendations' },
    financial: {
        icon: CurrencyCircleDollar,
        tone: 'amber',
        folder: 'Financial',
    },
    other: { icon: FileText, tone: 'orange', folder: 'Other' },
};

const STATUS_TONE: Record<string, BadgeTone> = {
    queued: 'neutral',
    processing: 'neutral',
    needs_review: 'warning',
    ready: 'success',
    failed: 'danger',
    superseded: 'neutral',
};

const selectClass =
    'h-10 w-full rounded-xl border border-input bg-background px-3 text-sm text-foreground outline-none focus-visible:border-foreground/30 focus-visible:ring-3 focus-visible:ring-ring/25';

export default function DocumentsPage({
    documents,
    kinds,
    ocrAvailable,
    maxUploadMb,
}: PageProps) {
    const form = useForm<{ file: File | null; kind: string; title: string }>({
        file: null,
        kind: 'transcript',
        title: '',
    });
    const hydrated = useHydrated();
    const [folder, setFolder] = useState<string>('all');
    const [query, setQuery] = useState('');
    const fileRef = useRef<HTMLInputElement>(null);

    const counts = useMemo(() => {
        const out: Record<string, number> = {};

        for (const doc of documents) {
            out[doc.kind] = (out[doc.kind] ?? 0) + 1;
        }

        return out;
    }, [documents]);

    const shown = documents.filter(
        (doc) =>
            (folder === 'all' || doc.kind === folder) &&
            (query.trim() === '' ||
                doc.title.toLowerCase().includes(query.trim().toLowerCase())),
    );
    const toReview = documents.reduce((n, doc) => n + doc.pendingValues, 0);

    return (
        <>
            <Head title="Documents" />
            <Page width="wide">
                <PageHeader
                    title="Documents"
                    description="AdmitMe reads your transcripts and reports and lists the values it found. Nothing reaches your profile until you check it. Files are private to you."
                />

                <Section>
                    <form
                        className="grid gap-3 rounded-2xl border border-dashed border-border bg-card p-4 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/documents', {
                                forceFormData: true,
                                preserveScroll: true,
                            });
                        }}
                    >
                        <div className="min-w-0">
                            <label
                                htmlFor="document-file"
                                className="mb-1.5 block text-[13px] text-muted-foreground"
                            >
                                File · PDF, Word or text, up to {maxUploadMb} MB
                            </label>
                            <input
                                ref={fileRef}
                                id="document-file"
                                type="file"
                                accept=".pdf,.docx,.txt,.png,.jpg,.jpeg"
                                onChange={(event) =>
                                    form.setData(
                                        'file',
                                        event.target.files?.[0] ?? null,
                                    )
                                }
                                className="block h-10 w-full cursor-pointer rounded-xl border border-input bg-background text-[13px] text-muted-foreground file:mr-3 file:h-full file:cursor-pointer file:border-0 file:border-r file:border-border file:bg-muted file:px-3 file:text-[13px] file:font-medium file:text-foreground"
                            />
                        </div>
                        <div className="min-w-0">
                            <label
                                htmlFor="document-kind"
                                className="mb-1.5 block text-[13px] text-muted-foreground"
                            >
                                Type
                            </label>
                            <select
                                id="document-kind"
                                className={selectClass}
                                value={form.data.kind}
                                onChange={(event) =>
                                    form.setData('kind', event.target.value)
                                }
                            >
                                {Object.entries(kinds).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="min-w-0">
                            <label
                                htmlFor="document-title"
                                className="mb-1.5 block text-[13px] text-muted-foreground"
                            >
                                Name (optional)
                            </label>
                            <input
                                id="document-title"
                                className={selectClass}
                                placeholder="e.g. Year 12 transcript"
                                value={form.data.title}
                                onChange={(event) =>
                                    form.setData('title', event.target.value)
                                }
                            />
                        </div>
                        <Button
                            type="submit"
                            disabled={form.processing || !form.data.file}
                            className="h-10 gap-1.5 rounded-xl px-4"
                        >
                            <UploadSimple className="size-4" aria-hidden />
                            {form.processing ? 'Uploading…' : 'Upload'}
                        </Button>
                        {form.errors.file || form.errors.kind ? (
                            <ErrorPill
                                className="sm:col-span-4"
                                message={form.errors.file ?? form.errors.kind}
                            />
                        ) : null}
                    </form>
                    {!ocrAvailable ? (
                        <HintPill className="mt-3">
                            <span>
                                Scans and photos can&apos;t be read yet. Upload
                                a PDF with selectable text, or{' '}
                                <Link
                                    href="/profile-setup?section=academics"
                                    className="font-medium text-foreground underline-offset-2 hover:underline"
                                >
                                    enter your grades yourself
                                </Link>
                                .
                            </span>
                        </HintPill>
                    ) : null}
                </Section>

                {documents.length === 0 ? (
                    <EmptyState
                        icon={
                            <IconTile
                                icon={Files}
                                tone="orange"
                                variant="glyph"
                                size="lg"
                            />
                        }
                        title="No documents yet"
                        description="Uploading is optional — you can also answer the questions in profile setup."
                        action={
                            <Button
                                variant="outline"
                                asChild
                                className="rounded-full"
                            >
                                <Link href="/profile-setup">
                                    Open profile setup
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <>
                        <Section title="Folders" icon={Files}>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                <FolderCard
                                    title="All documents"
                                    count={`${documents.length} ${documents.length === 1 ? 'file' : 'files'}`}
                                    icon={Files}
                                    tone="slate"
                                    active={folder === 'all'}
                                    onClick={() => setFolder('all')}
                                />
                                {Object.keys(kinds)
                                    .filter((kind) => (counts[kind] ?? 0) > 0)
                                    .map((kind) => {
                                        const style =
                                            KIND_STYLE[kind] ??
                                            KIND_STYLE.other;

                                        return (
                                            <FolderCard
                                                key={kind}
                                                title={style.folder}
                                                count={`${counts[kind]} ${counts[kind] === 1 ? 'file' : 'files'}`}
                                                icon={style.icon}
                                                tone={style.tone}
                                                active={folder === kind}
                                                onClick={() =>
                                                    setFolder(
                                                        folder === kind
                                                            ? 'all'
                                                            : kind,
                                                    )
                                                }
                                            />
                                        );
                                    })}
                            </div>
                        </Section>

                        <Section
                            title={
                                toReview > 0
                                    ? `Files · ${toReview} ${toReview === 1 ? 'value' : 'values'} to check`
                                    : 'Files'
                            }
                            icon={FileText}
                            actions={
                                <label className="relative block">
                                    <span className="sr-only">
                                        Search documents
                                    </span>
                                    <MagnifyingGlass className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <input
                                        value={query}
                                        onChange={(e) =>
                                            setQuery(e.target.value)
                                        }
                                        placeholder="Search"
                                        className="h-8 w-40 rounded-lg border border-input bg-background pr-2.5 pl-8 text-[13px] outline-none focus-visible:border-foreground/30 sm:w-56"
                                    />
                                </label>
                            }
                        >
                            <div className="overflow-hidden rounded-2xl border border-border bg-card">
                                <div className="hidden grid-cols-[minmax(0,2.2fr)_minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,0.9fr)] gap-4 border-b border-border bg-muted/40 px-5 py-2.5 text-[12.5px] text-muted-foreground md:grid">
                                    <span>Name</span>
                                    <span>Type</span>
                                    <span>Status</span>
                                    <span className="text-right">Uploaded</span>
                                </div>
                                {shown.length === 0 ? (
                                    <p className="px-5 py-8 text-center text-[13.5px] text-muted-foreground">
                                        No documents match.
                                    </p>
                                ) : (
                                    <ul className="divide-y divide-border">
                                        {shown.map((doc) => {
                                            const style =
                                                KIND_STYLE[doc.kind] ??
                                                KIND_STYLE.other;

                                            return (
                                                <li key={doc.id}>
                                                    <Link
                                                        href={`/documents/${doc.id}`}
                                                        className="grid w-full grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-1 px-5 py-3.5 text-left transition-colors hover:bg-muted/50 md:grid-cols-[minmax(0,2.2fr)_minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,0.9fr)] md:gap-4"
                                                    >
                                                        <span className="flex min-w-0 items-center gap-3 md:col-span-1">
                                                            <IconTile
                                                                icon={
                                                                    style.icon
                                                                }
                                                                tone={
                                                                    style.tone
                                                                }
                                                                variant="soft"
                                                                size="sm"
                                                                className="size-8"
                                                            />
                                                            <span className="min-w-0">
                                                                <span className="block truncate text-[14.5px] font-medium text-foreground">
                                                                    {doc.title}
                                                                </span>
                                                                {doc.pendingValues >
                                                                0 ? (
                                                                    <span className="block text-[12.5px] text-warning">
                                                                        {
                                                                            doc.pendingValues
                                                                        }{' '}
                                                                        {doc.pendingValues ===
                                                                        1
                                                                            ? 'value'
                                                                            : 'values'}{' '}
                                                                        to check
                                                                    </span>
                                                                ) : doc.failureReason ? (
                                                                    <span className="block truncate text-[12.5px] text-destructive">
                                                                        {
                                                                            doc.failureReason
                                                                        }
                                                                    </span>
                                                                ) : doc.version &&
                                                                  doc.version >
                                                                      1 ? (
                                                                    <span className="block text-[12.5px] text-muted-foreground">
                                                                        Version{' '}
                                                                        {
                                                                            doc.version
                                                                        }
                                                                    </span>
                                                                ) : null}
                                                            </span>
                                                        </span>
                                                        <span className="col-start-2 truncate text-[13.5px] text-muted-foreground md:col-start-auto">
                                                            {doc.kindLabel}
                                                        </span>
                                                        <span className="col-start-2 md:col-start-auto">
                                                            {doc.status ? (
                                                                <StatusBadge
                                                                    tone={
                                                                        STATUS_TONE[
                                                                            doc
                                                                                .status
                                                                        ] ??
                                                                        'neutral'
                                                                    }
                                                                    className={cn(
                                                                        (doc.status ===
                                                                            'queued' ||
                                                                            doc.status ===
                                                                                'processing') &&
                                                                            'animate-pulse',
                                                                    )}
                                                                >
                                                                    {
                                                                        doc.statusLabel
                                                                    }
                                                                </StatusBadge>
                                                            ) : null}
                                                        </span>
                                                        <span className="nums col-start-2 text-[13px] text-muted-foreground md:col-start-auto md:text-right">
                                                            {hydrated &&
                                                            doc.uploadedAt
                                                                ? new Date(
                                                                      doc.uploadedAt,
                                                                  ).toLocaleDateString(
                                                                      undefined,
                                                                      {
                                                                          month: 'short',
                                                                          day: 'numeric',
                                                                          year: 'numeric',
                                                                      },
                                                                  )
                                                                : ''}
                                                        </span>
                                                    </Link>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                            </div>
                        </Section>
                    </>
                )}
            </Page>
        </>
    );
}
