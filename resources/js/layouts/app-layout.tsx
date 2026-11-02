'use client';

import { Link, usePage } from '@inertiajs/react';
import { ChatCircle, List as ListIcon } from '@phosphor-icons/react';
import {
    createContext,
    Fragment,
    useContext,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import type { Dispatch, ReactNode, SetStateAction } from 'react';
import {
    DOCKED_QUERY,
    MentorProvider,
    useMentor,
} from '@/components/mentor/mentor-context';
import { MentorPanel } from '@/components/mentor/mentor-panel';
import { CommandMenu } from '@/components/shell/command-menu';
import { navItemFor, Sidebar } from '@/components/shell/sidebar';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { Toaster } from '@/components/ui/sonner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useHydrated } from '@/hooks/use-hydrated';
import { useMediaQuery } from '@/hooks/use-media-query';
import { cn } from '@/lib/utils';
import type { AppLayoutProps } from '@/types';

type HeaderSlots = {
    setTrailing: Dispatch<SetStateAction<ReactNode>>;
    setMeta: Dispatch<SetStateAction<ReactNode>>;
};

const HeaderSlotsContext = createContext<HeaderSlots | null>(null);

function useHeaderSlots(): HeaderSlots {
    const slots = useContext(HeaderSlotsContext);

    if (!slots) {
        throw new Error('Header slots are only available inside AppLayout');
    }

    return slots;
}

export function useSetAppLayoutHeaderTrailing(): Dispatch<
    SetStateAction<ReactNode>
> {
    return useHeaderSlots().setTrailing;
}

/** Actions shown at the right of the content header while this component is mounted. */
export function AppLayoutHeaderTrailing({ children }: { children: ReactNode }) {
    const { setTrailing } = useHeaderSlots();
    useLayoutEffect(() => {
        setTrailing(children);

        return () => setTrailing(null);
    }, [children, setTrailing]);

    return null;
}

/** A short count or status after the page title in the content header. */
export function AppLayoutHeaderMeta({ children }: { children: ReactNode }) {
    const { setMeta } = useHeaderSlots();
    useLayoutEffect(() => {
        setMeta(children);

        return () => setMeta(null);
    }, [children, setMeta]);

    return null;
}

const SIDEBAR_COOKIE = 'sidebar_state';

const DOCK_WIDTH_KEY = 'admitme.mentor.width';
const DOCK_MIN = 340;
const DOCK_STEP = 24;

/** The widest the dock may get while leaving the page a usable column. */
function dockMax(): number {
    return Math.max(DOCK_MIN, Math.min(760, window.innerWidth - 640));
}

function readDockWidth(): number | null {
    try {
        const stored = Number(window.localStorage.getItem(DOCK_WIDTH_KEY));

        return Number.isFinite(stored) && stored >= DOCK_MIN ? stored : null;
    } catch {
        return null;
    }
}

function writeDockWidth(width: number | null): void {
    try {
        if (width === null) {
            window.localStorage.removeItem(DOCK_WIDTH_KEY);
        } else {
            window.localStorage.setItem(
                DOCK_WIDTH_KEY,
                String(Math.round(width)),
            );
        }
    } catch {
        /* storage unavailable: the width just isn't remembered */
    }
}

/**
 * The docked mentor panel. Its left edge drags to resize (arrow keys when focused, double-click to reset);
 * the width is remembered per browser. The server renders the default width; a saved one applies after
 * hydration so the first client render matches the HTML.
 */
function MentorDock() {
    const hydrated = useHydrated();
    // undefined until the student resizes in this session; then their width (null = back to the default).
    const [chosen, setWidth] = useState<number | null | undefined>(undefined);
    const [dragging, setDragging] = useState(false);
    const dockRef = useRef<HTMLDivElement>(null);
    // A press becomes a drag only after a few pixels, so a click or double-click never resizes.
    const drag = useRef<{
        startX: number;
        startWidth: number;
        moved: boolean;
        latest: number;
    } | null>(null);
    const lastPress = useRef(0);
    const saved = hydrated && chosen === undefined ? readDockWidth() : null;
    const width =
        chosen !== undefined
            ? chosen
            : saved !== null
              ? Math.min(saved, dockMax())
              : null;

    const current = () =>
        width ?? dockRef.current?.getBoundingClientRect().width ?? 400;
    const clamp = (value: number) =>
        Math.min(dockMax(), Math.max(DOCK_MIN, value));

    const onPointerDown = (event: React.PointerEvent<HTMLDivElement>) => {
        if (event.button !== 0) {
            return;
        }

        event.preventDefault();

        // A second press in quick succession is a double-click: back to the default width. (Detected here
        // because a pressed handle captures the pointer, which browsers handle differently for dblclick.)
        if (event.timeStamp - lastPress.current < 400) {
            lastPress.current = 0;
            reset();

            return;
        }

        lastPress.current = event.timeStamp;
        event.currentTarget.setPointerCapture(event.pointerId);
        const start = current();
        drag.current = {
            startX: event.clientX,
            startWidth: start,
            moved: false,
            latest: start,
        };
    };

    const onPointerMove = (event: React.PointerEvent<HTMLDivElement>) => {
        const state = drag.current;

        if (!state) {
            return;
        }

        // The dock sits on the right, so moving the edge left makes it wider.
        const delta = state.startX - event.clientX;

        if (!state.moved && Math.abs(delta) < 3) {
            return;
        }

        if (!state.moved) {
            state.moved = true;
            setDragging(true);
        }

        state.latest = clamp(state.startWidth + delta);
        setWidth(state.latest);
    };

    const endDrag = () => {
        const state = drag.current;
        drag.current = null;

        if (state?.moved) {
            setDragging(false);
            writeDockWidth(state.latest);
        }
    };

    const onKeyDown = (event: React.KeyboardEvent<HTMLDivElement>) => {
        const keys: Record<string, () => number> = {
            ArrowLeft: () => current() + DOCK_STEP,
            ArrowRight: () => current() - DOCK_STEP,
            Home: () => DOCK_MIN,
            End: () => dockMax(),
        };
        const next = keys[event.key];

        if (next) {
            event.preventDefault();
            const value = clamp(next());
            setWidth(value);
            writeDockWidth(value);
        }
    };

    const reset = () => {
        setWidth(null);
        writeDockWidth(null);
    };

    return (
        <div
            ref={dockRef}
            className="relative hidden w-[400px] shrink-0 xl:flex 2xl:w-[440px]"
            style={width !== null ? { width } : undefined}
        >
            <div
                role="separator"
                aria-orientation="vertical"
                aria-label="Resize mentor panel"
                aria-valuemin={DOCK_MIN}
                aria-valuemax={760}
                aria-valuenow={Math.round(width ?? 400)}
                tabIndex={0}
                title="Drag to resize · double-click to reset"
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={endDrag}
                onPointerCancel={endDrag}
                onKeyDown={onKeyDown}
                className="group absolute inset-y-3 -left-2 z-10 flex w-2 cursor-col-resize touch-none items-center justify-center outline-none"
            >
                <span
                    aria-hidden
                    className={cn(
                        'h-10 w-[3px] rounded-full bg-foreground/0 transition-colors duration-150 group-hover:bg-foreground/25 group-focus-visible:bg-ring',
                        dragging &&
                            'bg-foreground/40 group-hover:bg-foreground/40',
                    )}
                />
            </div>
            <aside
                aria-label="Mentor"
                className={cn(
                    'flex w-full overflow-hidden rounded-2xl border border-border bg-background shadow-window',
                    dragging && 'select-none',
                )}
            >
                <MentorPanel variant="docked" />
            </aside>
            {/* While dragging, keep the cursor and stop the page from selecting text under the pointer. */}
            {dragging ? (
                <div
                    aria-hidden
                    className="fixed inset-0 z-50 cursor-col-resize"
                />
            ) : null}
        </div>
    );
}

function ContentHeader({
    breadcrumbs,
    trailing,
    meta,
    onOpenNav,
}: {
    breadcrumbs: NonNullable<AppLayoutProps['breadcrumbs']>;
    trailing: ReactNode;
    meta: ReactNode;
    onOpenNav: () => void;
}) {
    const { currentUrl } = useCurrentUrl();
    const mentor = useMentor();
    const section = navItemFor(currentUrl.split('?')[0]);
    const trail =
        breadcrumbs.length > 0
            ? breadcrumbs
            : section
              ? [{ title: section.label, href: section.href }]
              : [];
    const SectionIcon = section?.icon;

    return (
        <header className="flex h-14 shrink-0 items-center gap-2 border-b border-border px-3 sm:px-4">
            <button
                type="button"
                onClick={onOpenNav}
                className="inline-flex size-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground lg:hidden"
                aria-label="Open navigation"
            >
                <ListIcon className="size-5" />
            </button>
            <nav
                aria-label="Breadcrumb"
                className="flex min-w-0 flex-1 items-center gap-2 text-[14px]"
            >
                {SectionIcon ? (
                    <SectionIcon className="hidden size-[18px] shrink-0 text-muted-foreground sm:block" />
                ) : null}
                {trail.map((item, i) => {
                    const last = i === trail.length - 1;

                    return (
                        <Fragment key={i}>
                            {i > 0 ? (
                                <span
                                    aria-hidden
                                    className="hidden text-muted-foreground/50 sm:inline"
                                >
                                    /
                                </span>
                            ) : null}
                            {last ? (
                                <span
                                    className="min-w-0 truncate font-medium text-foreground"
                                    aria-current="page"
                                >
                                    {item.title}
                                </span>
                            ) : (
                                <Link
                                    href={item.href}
                                    className="hidden shrink-0 truncate text-muted-foreground transition-colors hover:text-foreground sm:inline"
                                >
                                    {item.title}
                                </Link>
                            )}
                        </Fragment>
                    );
                })}
                {meta ? (
                    <span className="hidden truncate text-muted-foreground md:inline">
                        · {meta}
                    </span>
                ) : null}
            </nav>
            {trailing ? (
                <div className="flex shrink-0 items-center gap-2">
                    {trailing}
                </div>
            ) : null}
            <Tooltip>
                <TooltipTrigger asChild>
                    <button
                        type="button"
                        onClick={mentor.toggle}
                        aria-label={mentor.open ? 'Hide mentor' : 'Open mentor'}
                        aria-pressed={mentor.open}
                        className={cn(
                            'relative inline-flex size-9 shrink-0 items-center justify-center rounded-lg border transition-colors',
                            mentor.open
                                ? 'border-transparent bg-muted text-foreground'
                                : 'border-border text-muted-foreground hover:bg-muted hover:text-foreground',
                        )}
                    >
                        <ChatCircle
                            className="size-[18px]"
                            weight={mentor.open ? 'fill' : 'regular'}
                        />
                        {mentor.isTyping && !mentor.open ? (
                            <span
                                aria-hidden
                                className="absolute top-1.5 right-1.5 size-1.5 animate-pulse rounded-full bg-brand"
                            />
                        ) : null}
                    </button>
                </TooltipTrigger>
                <TooltipContent side="bottom">
                    {mentor.open ? 'Hide mentor' : 'Ask your mentor'}
                </TooltipContent>
            </Tooltip>
        </header>
    );
}

function Shell({ children, breadcrumbs = [] }: AppLayoutProps) {
    const mentor = useMentor();
    const { setOpen: setMentorOpen } = mentor;
    const docked = useMediaQuery(DOCKED_QUERY, true);
    const page = usePage<{ sidebarOpen?: boolean }>();
    const [expanded, setExpanded] = useState(page.props.sidebarOpen !== false);
    const [navOpen, setNavOpen] = useState(false);
    const [searchOpen, setSearchOpen] = useState(false);
    const [trailing, setTrailing] = useState<ReactNode>(null);
    const [meta, setMeta] = useState<ReactNode>(null);

    const toggleSidebar = () => {
        const next = !expanded;
        setExpanded(next);
        document.cookie = `${SIDEBAR_COOKIE}=${next ? 'true' : 'false'};path=/;max-age=31536000;SameSite=Lax`;
    };

    // The overlay mentor steps aside when the student moves to another page (a partial reload keeps it).
    const path = page.url.split('?')[0];
    const lastPath = useRef(path);
    useEffect(() => {
        if (path !== lastPath.current) {
            lastPath.current = path;

            if (!docked) {
                setMentorOpen(false);
            }
        }
    }, [path, docked, setMentorOpen]);

    return (
        <HeaderSlotsContext.Provider value={{ setTrailing, setMeta }}>
            <div className="flex h-svh max-h-svh min-h-0 w-full overflow-hidden bg-canvas">
                <aside
                    aria-label="Sidebar"
                    className={cn(
                        'hidden h-full shrink-0 transition-[width] duration-200 ease-out lg:block',
                        expanded ? 'w-[248px]' : 'w-[60px]',
                    )}
                >
                    <Sidebar
                        collapsed={!expanded}
                        onToggleCollapsed={toggleSidebar}
                        onSearch={() => setSearchOpen(true)}
                    />
                </aside>

                <div className="flex min-h-0 min-w-0 flex-1 gap-2 lg:py-2 lg:pr-2">
                    <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden bg-background lg:rounded-2xl lg:border lg:border-border lg:shadow-window">
                        <ContentHeader
                            breadcrumbs={breadcrumbs}
                            trailing={trailing}
                            meta={meta}
                            onOpenNav={() => setNavOpen(true)}
                        />
                        <main
                            id="main"
                            className="flex min-h-0 min-w-0 flex-1 scrollbar-thin flex-col overflow-y-auto overscroll-contain"
                        >
                            {children}
                        </main>
                    </div>

                    {docked && mentor.open ? <MentorDock /> : null}
                </div>
            </div>

            <Sheet open={!docked && mentor.open} onOpenChange={mentor.setOpen}>
                <SheetContent
                    side="right"
                    showCloseButton={false}
                    aria-describedby={undefined}
                    className="gap-0 overflow-hidden border-0 p-0 data-[side=right]:w-full sm:rounded-2xl sm:border sm:border-border sm:data-[side=right]:inset-y-2 sm:data-[side=right]:right-2 sm:data-[side=right]:h-auto sm:data-[side=right]:max-w-[420px]"
                >
                    <SheetTitle className="sr-only">Mentor</SheetTitle>
                    <MentorPanel variant="overlay" />
                </SheetContent>
            </Sheet>

            <Sheet open={navOpen} onOpenChange={setNavOpen}>
                <SheetContent
                    side="left"
                    showCloseButton={false}
                    aria-describedby={undefined}
                    className="w-[min(100vw,280px)] gap-0 border-r-0 bg-canvas p-0 data-[side=left]:w-[min(100vw,280px)]"
                >
                    <SheetTitle className="sr-only">Navigation</SheetTitle>
                    <Sidebar
                        collapsed={false}
                        variant="drawer"
                        onSearch={() => {
                            setNavOpen(false);
                            setSearchOpen(true);
                        }}
                        onNavigate={() => setNavOpen(false)}
                    />
                </SheetContent>
            </Sheet>

            <CommandMenu open={searchOpen} onOpenChange={setSearchOpen} />
            <Toaster richColors closeButton position="top-right" />
        </HeaderSlotsContext.Provider>
    );
}

export default function AppLayout(props: AppLayoutProps) {
    return (
        <MentorProvider>
            <Shell {...props} />
        </MentorProvider>
    );
}
