'use client';

import {
    FileText,
    Copy,
    TrashIcon,
    ArrowsClockwiseIcon,
    LinkSimpleIcon,
    ShareIcon,
    Download,
    DotsThreeVerticalIcon,
} from '@phosphor-icons/react';
import { PromptInputBox } from '@/components/ai-prompt-box';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { AppLayoutHeaderTrailing } from '@/layouts/app-layout';
import { dashboard } from '@/routes';

interface ReferenceCard {
    title: string;
    url: string;
}

const REFERENCES: ReferenceCard[] = [
    {
        title: 'Incorporating natural ecosystems..',
        url: 'https://pmc.ncbi.nlm.nih.gov/articles',
    },
    {
        title: 'Clarifying the effect of biodiversity..',
        url: 'https://pmc.ncbi.nlm.nih.gov/articles',
    },
    {
        title: 'Benefits Supplied to Human societies..',
        url: 'https://pmc.ncbi.nlm.nih.gov/articles',
    },
    {
        title: 'Benefits..',
        url: 'https://pmc.ncbi.nlm.nih.gov/articles',
    },
    {
        title: 'Benefits..',
        url: 'https://pmc.ncbi.nlm.nih.gov/articles',
    },
    {
        title: 'Benefits..',
        url: 'https://pmc.ncbi.nlm.nih.gov/articles',
    },
];

function DashboardHeaderActions() {
    return (
        <>
            <div className="hidden shrink-0 items-center gap-1 md:flex md:gap-2">
                <button
                    type="button"
                    className="rounded-full border border-border bg-accent p-2 text-muted-foreground shadow-sm transition-colors hover:bg-muted hover:text-foreground"
                    aria-label="Documents"
                >
                    <FileText size={18} />
                </button>
                <button
                    type="button"
                    className="rounded-full border border-border bg-accent p-2 text-muted-foreground shadow-sm transition-colors hover:bg-muted hover:text-foreground"
                    aria-label="Share"
                >
                    <ShareIcon size={18} />
                </button>
                <button
                    type="button"
                    className="rounded-full border border-border bg-accent p-2 text-muted-foreground shadow-sm transition-colors hover:bg-muted hover:text-foreground"
                    aria-label="Download"
                >
                    <Download size={18} />
                </button>
                <button
                    type="button"
                    className="rounded-full border border-border bg-accent p-2 text-muted-foreground shadow-sm transition-colors hover:bg-muted hover:text-foreground"
                    aria-label="More options"
                >
                    <DotsThreeVerticalIcon size={18} />
                </button>
            </div>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="shrink-0 md:hidden"
                        aria-label="Chat actions"
                    >
                        <DotsThreeVerticalIcon className="size-5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-48">
                    <DropdownMenuItem className="gap-2">
                        <FileText className="size-4" />
                        Documents
                    </DropdownMenuItem>
                    <DropdownMenuItem className="gap-2">
                        <ShareIcon className="size-4" />
                        Share
                    </DropdownMenuItem>
                    <DropdownMenuItem className="gap-2">
                        <Download className="size-4" />
                        Download
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </>
    );
}

export default function Dashboard() {
    return (
        <>
            <AppLayoutHeaderTrailing>
                <DashboardHeaderActions />
            </AppLayoutHeaderTrailing>

            <div className="flex min-h-0 min-w-0 flex-1 flex-col">
                <div className="min-h-0 flex-1 overflow-y-auto px-3 py-4 sm:px-5 sm:py-6 md:px-6 md:py-8">
                    <div className="mx-auto w-full min-w-0 max-w-3xl">
                            <div className="mb-6 sm:mb-8">
                                <div className="mb-3 flex justify-end sm:mb-4">
                                    <p className="max-w-[min(100%,85%)] rounded-lg bg-muted px-3 py-2 text-sm leading-relaxed text-foreground sm:max-w-[85%] sm:px-4 sm:py-2.5">
                                        Please try to find a paper about
                                        natural ecosystems
                                    </p>
                                </div>
                            </div>

                            <div className="min-w-0">
                                <div className="mb-6 space-y-4 text-sm leading-relaxed text-foreground sm:space-y-5">
                                    <p className="text-pretty">
                                        Certainly! Here are some scholarly
                                        papers related to natural ecosystems that
                                        you might find useful:
                                    </p>

                                    <ol className="list-decimal space-y-4 pl-4 marker:text-muted-foreground sm:pl-5">
                                        <li className="min-w-0 pl-1 text-sm">
                                            <strong className="text-balance">
                                                "Incorporating natural ecosystems
                                                into global health and food
                                                security"
                                            </strong>
                                            <ul className="mt-2 ml-3 list-disc space-y-1 text-muted-foreground sm:ml-4">
                                                <li>
                                                    Published by: PMC (PubMed
                                                    Central)
                                                </li>
                                                <li className="wrap-break-word">
                                                    Summary: This paper discusses
                                                    how natural ecosystems such
                                                    as forests, coral reefs,
                                                    mangroves, and grasslands
                                                    provide essential goods and
                                                    services that support human
                                                    health and food security.{' '}
                                                    <a
                                                        href="#"
                                                        className="break-all text-primary underline-offset-2 hover:underline"
                                                    >
                                                        pmc.ncbi.nlm.nih.gov
                                                    </a>
                                                </li>
                                            </ul>
                                        </li>

                                        <li className="mt-4 min-w-0 pl-1 text-sm">
                                            <strong className="text-balance">
                                                "Clarifying the effect of
                                                biodiversity on productivity in
                                                natural ecosystems"
                                            </strong>
                                            <ul className="mt-2 ml-3 list-disc space-y-1 text-muted-foreground sm:ml-4">
                                                <li>
                                                    Published by: Nature
                                                    Communications
                                                </li>
                                                <li className="wrap-break-word">
                                                    Summary: The study estimates
                                                    that increases in plot-level
                                                    species richness caused
                                                    productivity to decline,
                                                    challenging prior studies
                                                    that suggested a positive
                                                    relationship. It utilizes
                                                    longitudinal data from 43
                                                    grasslands in 11 countries
                                                    to draw causal inferences
                                                    from observational data.{' '}
                                                    <a
                                                        href="#"
                                                        className="break-all text-primary underline-offset-2 hover:underline"
                                                    >
                                                        theaustralian.com.au+15nature.com
                                                    </a>
                                                </li>
                                            </ul>
                                        </li>
                                    </ol>

                                    <p className="text-pretty">
                                        If you need assistance accessing any of
                                        these papers or require further
                                        information, feel free to ask!
                                    </p>
                                </div>

                                <div
                                    className="-mx-1 mb-6 flex snap-x snap-mandatory gap-3 overflow-x-auto px-1 py-3 sm:py-4"
                                    style={{
                                        scrollbarWidth: "none",
                                        scrollbarColor: "rgba(100,100,100,0.2) transparent"
                                    }}
                                >
                                    <style>
                                        {`
                                            /* Hide scrollbar track background on Chrome, show only thin, faded thumb */
                                            .dashboard-thin-scroll::-webkit-scrollbar {
                                                height: 6px;
                                            }
                                            .dashboard-thin-scroll::-webkit-scrollbar-thumb {
                                                background: rgba(100,100,100,0.2);
                                                border-radius: 4px;
                                            }
                                            .dashboard-thin-scroll::-webkit-scrollbar-track {
                                                background: transparent;
                                            }
                                        `}
                                    </style>
                                    <div className="dashboard-thin-scroll gap-2 flex w-full">
                                        {REFERENCES.map((ref, idx) => (
                                            <div
                                                key={idx}
                                                className="group w-[min(12.5rem,calc(100vw-2.5rem))] shrink-0 snap-start cursor-pointer rounded-lg border border-border bg-card p-3 transition-shadow hover:shadow-sm sm:min-w-45 sm:max-w-45"
                                            >
                                                <h4 className="mb-2 line-clamp-2 text-sm font-medium text-card-foreground">
                                                    {ref.title}
                                                </h4>
                                                <div className="flex min-w-0 items-center gap-1 text-xs text-muted-foreground group-hover:text-foreground">
                                                    <LinkSimpleIcon
                                                        size={12}
                                                        className="shrink-0"
                                                    />
                                                    <span className="truncate">
                                                        {ref.url}
                                                    </span>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                <div className="flex flex-wrap gap-1 sm:gap-2">
                                    <button
                                        type="button"
                                        className="rounded-lg p-2 transition-colors hover:bg-muted"
                                        aria-label="Regenerate"
                                    >
                                        <ArrowsClockwiseIcon
                                            size={16}
                                            className="text-muted-foreground"
                                        />
                                    </button>
                                    <button
                                        type="button"
                                        className="rounded-lg p-2 transition-colors hover:bg-muted"
                                        aria-label="Copy"
                                    >
                                        <Copy
                                            size={16}
                                            className="text-muted-foreground"
                                        />
                                    </button>
                                    <button
                                        type="button"
                                        className="rounded-lg p-2 transition-colors hover:bg-muted"
                                        aria-label="Delete"
                                    >
                                        <TrashIcon
                                            size={16}
                                            className="text-muted-foreground"
                                        />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                <footer className="relative shrink-0 px-3 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:px-4 md:px-6 ">
                    <div className="mx-auto w-full min-w-0 max-w-3xl">
                        <PromptInputBox
                            onSend={(message, files) =>
                                console.log(message, files)
                            }
                        />
                    </div>
                </footer>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard.url() },
        { title: 'Natural ecosystems', href: dashboard.url() },
    ],
};
