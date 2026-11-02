import { Head, Link, useForm } from '@inertiajs/react';
import {
    Article,
    Buildings,
    CaretRight,
    Files,
    NotePencil,
    Plus,
    TextAlignLeft,
} from '@phosphor-icons/react';
import type { Icon } from '@phosphor-icons/react';
import { ErrorPill } from '@/components/admitme/profile-fields';
import {
    Field,
    NativeSelect,
    Optional,
    fieldClass,
    textareaClass,
} from '@/components/essay-suite/ui';
import {
    EmptyState,
    IconTile,
    Page,
    PageHeader,
    Section,
} from '@/components/page/kit';
import type { Tone } from '@/components/page/kit';
import { Button } from '@/components/ui/button';
import { useHydrated } from '@/hooks/use-hydrated';
import { AppLayoutHeaderMeta } from '@/layouts/app-layout';

type Piece = {
    id: string;
    title: string;
    kind: string;
    college: string | null;
    wordLimit: number | null;
    words: number | null;
    version: number | null;
    policy: string;
    updatedAt: string | null;
};

const KIND: Record<string, { icon: Icon; tone: Tone }> = {
    personal_statement: { icon: Article, tone: 'blue' },
    supplement: { icon: Buildings, tone: 'orange' },
    short_answer: { icon: TextAlignLeft, tone: 'teal' },
    other: { icon: NotePencil, tone: 'violet' },
};

function PieceRow({ piece, hydrated }: { piece: Piece; hydrated: boolean }) {
    const kind = KIND[piece.kind] ?? KIND.other;
    const words = piece.version
        ? `${piece.words ?? 0}${piece.wordLimit ? ` of ${piece.wordLimit}` : ''} words`
        : null;
    const saved =
        hydrated && piece.updatedAt
            ? new Date(piece.updatedAt).toLocaleDateString(undefined, {
                  month: 'short',
                  day: 'numeric',
              })
            : null;

    return (
        <li>
            <Link
                href={`/writing/${piece.id}`}
                className="flex w-full items-center gap-4 px-5 py-4 text-left transition-colors outline-none hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:ring-inset"
            >
                <IconTile icon={kind.icon} tone={kind.tone} variant="glyph" />
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-[15px] font-medium text-foreground">
                        {piece.title}
                    </span>
                    <span className="mt-0.5 line-clamp-2 block text-[13.5px] leading-snug text-muted-foreground">
                        {piece.college ?? 'Not for one college'} ·{' '}
                        {piece.policy}
                    </span>
                    <span className="nums mt-0.5 block text-[13px] text-muted-foreground sm:hidden">
                        {words
                            ? `Version ${piece.version} · ${words}`
                            : 'Not started'}
                    </span>
                </span>
                <span className="nums hidden shrink-0 text-right sm:block">
                    <span className="block text-[13.5px] text-foreground">
                        {words ?? 'Not started'}
                    </span>
                    {piece.version || saved ? (
                        <span className="block text-[12.5px] text-muted-foreground">
                            {[
                                piece.version
                                    ? `Version ${piece.version}`
                                    : null,
                                saved,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </span>
                    ) : null}
                </span>
                <CaretRight
                    className="size-4 shrink-0 text-muted-foreground/60"
                    aria-hidden
                />
            </Link>
        </li>
    );
}

export default function WritingIndex({
    pieces,
    colleges,
}: {
    pieces: Piece[];
    colleges: { id: string; name: string }[];
}) {
    const form = useForm({
        title: '',
        college_list_school_id: '',
        prompt_text: '',
        word_limit: '',
        kind: 'personal_statement',
    });
    const hydrated = useHydrated();

    const focusNewPiece = () => {
        const input = document.getElementById('piece-title');
        input?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        input?.focus({ preventScroll: true });
    };

    return (
        <>
            <Head title="Writing" />
            <AppLayoutHeaderMeta>{`${pieces.length} ${pieces.length === 1 ? 'piece' : 'pieces'}`}</AppLayoutHeaderMeta>
            <Page width="wide">
                <PageHeader
                    title="Writing"
                    description="Your essays, in your words. Every save is kept as a version you can go back to, and the coach only comments — it never writes or rewrites your text."
                    actions={
                        <Button
                            type="button"
                            onClick={focusNewPiece}
                            className="h-9 gap-2 rounded-full px-4"
                        >
                            <Plus
                                className="size-4"
                                weight="bold"
                                aria-hidden
                            />
                            New piece
                        </Button>
                    }
                />

                <div className="@container">
                    <div className="grid items-start gap-x-10 gap-y-9 @4xl:grid-cols-[minmax(0,1fr)_340px]">
                        <Section
                            title="Your pieces"
                            icon={Files}
                            className="mb-0"
                        >
                            {pieces.length === 0 ? (
                                <EmptyState
                                    icon={
                                        <IconTile
                                            icon={NotePencil}
                                            tone="violet"
                                            variant="glyph"
                                            size="lg"
                                        />
                                    }
                                    title="No pieces yet"
                                    description="Start a new piece, or open an essay step from an application."
                                />
                            ) : (
                                <ul className="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
                                    {pieces.map((piece) => (
                                        <PieceRow
                                            key={piece.id}
                                            piece={piece}
                                            hydrated={hydrated}
                                        />
                                    ))}
                                </ul>
                            )}
                        </Section>

                        <Section
                            title="New piece"
                            icon={NotePencil}
                            className="mb-0 @4xl:sticky @4xl:top-6"
                        >
                            <form
                                className="@container rounded-2xl border border-border bg-card p-5"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    form.post('/writing');
                                }}
                            >
                                <div className="grid gap-4 @lg:grid-cols-2">
                                    <Field label="Title" htmlFor="piece-title">
                                        <input
                                            id="piece-title"
                                            value={form.data.title}
                                            onChange={(e) =>
                                                form.setData(
                                                    'title',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g. Why Lakeside"
                                            aria-invalid={
                                                form.errors.title
                                                    ? true
                                                    : undefined
                                            }
                                            className={fieldClass}
                                        />
                                        <ErrorPill
                                            message={form.errors.title}
                                            className="mt-2"
                                        />
                                    </Field>
                                    <Field
                                        label={
                                            <>
                                                For college
                                                <Optional />
                                            </>
                                        }
                                        htmlFor="piece-college"
                                    >
                                        <NativeSelect
                                            id="piece-college"
                                            value={
                                                form.data.college_list_school_id
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'college_list_school_id',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            <option value="">
                                                Not for one college
                                            </option>
                                            {colleges.map((c) => (
                                                <option key={c.id} value={c.id}>
                                                    {c.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </Field>
                                    <Field
                                        label={
                                            <>
                                                Prompt
                                                <Optional />
                                            </>
                                        }
                                        htmlFor="piece-prompt"
                                        className="@lg:col-span-2"
                                    >
                                        <textarea
                                            id="piece-prompt"
                                            value={form.data.prompt_text}
                                            onChange={(e) =>
                                                form.setData(
                                                    'prompt_text',
                                                    e.target.value,
                                                )
                                            }
                                            rows={3}
                                            placeholder="The question you are answering"
                                            className={textareaClass}
                                        />
                                    </Field>
                                    <Field
                                        label={
                                            <>
                                                Word limit
                                                <Optional />
                                            </>
                                        }
                                        htmlFor="piece-limit"
                                    >
                                        <input
                                            id="piece-limit"
                                            type="number"
                                            min={10}
                                            inputMode="numeric"
                                            value={form.data.word_limit}
                                            onChange={(e) =>
                                                form.setData(
                                                    'word_limit',
                                                    e.target.value,
                                                )
                                            }
                                            aria-invalid={
                                                form.errors.word_limit
                                                    ? true
                                                    : undefined
                                            }
                                            className={fieldClass}
                                        />
                                        <ErrorPill
                                            message={form.errors.word_limit}
                                            className="mt-2"
                                        />
                                    </Field>
                                    <Field label="Kind" htmlFor="piece-kind">
                                        <NativeSelect
                                            id="piece-kind"
                                            value={form.data.kind}
                                            onChange={(e) =>
                                                form.setData(
                                                    'kind',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            <option value="personal_statement">
                                                Personal statement
                                            </option>
                                            <option value="supplement">
                                                College essay (supplement)
                                            </option>
                                            <option value="short_answer">
                                                Short answer
                                            </option>
                                            <option value="other">Other</option>
                                        </NativeSelect>
                                    </Field>
                                </div>
                                <div className="mt-5 flex justify-end">
                                    <Button
                                        type="submit"
                                        className="h-10 w-full rounded-xl px-5 @lg:w-auto"
                                        disabled={
                                            form.processing ||
                                            form.data.title.trim().length < 2
                                        }
                                    >
                                        Create
                                    </Button>
                                </div>
                            </form>
                        </Section>
                    </div>
                </div>
            </Page>
        </>
    );
}

WritingIndex.layout = { breadcrumbs: [{ title: 'Writing', href: '/writing' }] };
