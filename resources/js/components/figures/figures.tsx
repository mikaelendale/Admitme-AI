import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { disc, fx, HAIRLINE, lens, poly, prism, seg } from './iso';
import type { Disc, Lens, Prism } from './iso';

/*
 * Hairline figures for AdmitMe (svg-figures, hairline grammar): one projection, one stroke weight,
 * faces filled with the surface colour so solids hide what is behind them, a single dim crease per
 * solid, and exactly one bright edge in the brand orange for the thing the figure is about.
 */

function Figure({
    viewBox,
    label,
    className,
    children,
}: {
    viewBox: string;
    label: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <svg
            viewBox={viewBox}
            role="img"
            aria-label={label}
            className={cn('iso-figure', className)}
        >
            {children}
        </svg>
    );
}

function Solid({
    p,
    bright = false,
    className,
}: {
    p: Prism;
    bright?: boolean;
    className?: string;
}) {
    return (
        <g className={className}>
            <path d={p.side} className="fig-line fig-face" />
            <path d={p.crease} className="fig-crease" />
            <path
                d={p.top}
                className={cn('fig-line fig-face', bright && 'fig-bright')}
            />
        </g>
    );
}

function Cylinder({
    c,
    bright = false,
    className,
}: {
    c: Disc;
    bright?: boolean;
    className?: string;
}) {
    return (
        <g className={className}>
            <path d={c.side} className="fig-line fig-face" />
            <ellipse
                cx={fx(c.top.cx)}
                cy={fx(c.top.cy)}
                rx={fx(c.top.rx)}
                ry={fx(c.top.ry)}
                className={cn('fig-line fig-face', bright && 'fig-bright')}
            />
        </g>
    );
}

/** A path that draws itself in once (static under reduced motion). */
function Drawn({ d, className }: { d: string; className?: string }) {
    return (
        <path
            d={d}
            pathLength={1}
            className={cn('fig-line fig-bright fig-draw', className)}
        />
    );
}

/** A rounded window or panel on a vertical face (plane y = const), from (x0,z0) to (x1,z1). */
function facePanel(
    l: Lens,
    y: number,
    x0: number,
    x1: number,
    z0: number,
    z1: number,
): string {
    return poly(l, [
        [x0, y, z0],
        [x1, y, z0],
        [x1, y, z1],
        [x0, y, z1],
    ]);
}

/* ─── Home banner: a small campus ─────────────────────────────────────────────────────────── */

export function CampusFigure({ className }: { className?: string }) {
    const l = lens(HAIRLINE(14, [178, 112]));
    const ground = prism(l, {
        x: 0,
        y: 0,
        w: 15,
        d: 11,
        z0: -0.3,
        z1: 0,
        r: 0.7,
    });
    const hall = prism(l, {
        x: -1.2,
        y: -0.6,
        w: 7.2,
        d: 3.8,
        z0: 0,
        z1: 2.4,
        r: 0.15,
    });
    const roof = prism(l, {
        x: -1.2,
        y: -0.6,
        w: 6.4,
        d: 3.0,
        z0: 2.4,
        z1: 2.85,
        r: 0.15,
    });
    const tower = prism(l, {
        x: 3.7,
        y: -2.7,
        w: 2,
        d: 2,
        z0: 0,
        z1: 4.6,
        r: 0.12,
    });
    const cap = disc(l, { x: 3.7, y: -2.7, r: 0.8, z0: 4.6, z1: 5.0 });
    const annex = prism(l, {
        x: 4.7,
        y: 2.5,
        w: 2.8,
        d: 2.0,
        z0: 0,
        z1: 1.4,
        r: 0.15,
    });
    const trees = [
        { x: -5.6, y: 2.8 },
        { x: -6.2, y: -2.4 },
        { x: 1.6, y: 4.0 },
    ];
    const front = -0.6 + 1.9;

    return (
        <Figure
            viewBox="0 0 360 200"
            label="A campus: a main hall, a tower with a flag, a small annex and trees"
            className={className}
        >
            <Solid p={ground} />
            <path
                d={seg(l, [-1.55, front, 0], [-1.55, 5.4, 0])}
                className="fig-crease"
            />
            <path
                d={seg(l, [-0.85, front, 0], [-0.85, 5.4, 0])}
                className="fig-crease"
            />
            <g className="fig-lift">
                <Solid p={hall} />
                <Solid p={roof} />
                {[-4.3, -2.9, 0.3, 1.7].map((x0) => (
                    <path
                        key={x0}
                        d={facePanel(l, front, x0, x0 + 0.75, 0.8, 1.75)}
                        className="fig-crease"
                    />
                ))}
                <path
                    d={facePanel(l, front, -1.55, -0.85, 0, 1.25)}
                    className="fig-line"
                />
            </g>
            <Solid p={tower} />
            {[1.4, 2.6, 3.6].map((z) => (
                <path
                    key={z}
                    d={facePanel(l, -2.7 + 1, 3.35, 4.05, z, z + 0.6)}
                    className="fig-crease"
                />
            ))}
            <Cylinder c={cap} />
            <path
                d={seg(l, [3.7, -2.7, 5.0], [3.7, -2.7, 6.5])}
                className="fig-line"
            />
            <g className="campus-flag">
                <path
                    d={poly(l, [
                        [3.7, -2.7, 6.5],
                        [3.7, -1.45, 6.3],
                        [3.7, -1.45, 5.85],
                        [3.7, -2.7, 6.05],
                    ])}
                    className="fig-line fig-bright"
                    style={{ fill: 'var(--brand-soft)' }}
                />
            </g>
            <Solid p={annex} />
            {trees.map((t) => {
                const trunk = disc(l, {
                    x: t.x,
                    y: t.y,
                    r: 0.12,
                    z0: 0,
                    z1: 0.7,
                });
                const lower = disc(l, {
                    x: t.x,
                    y: t.y,
                    r: 0.78,
                    z0: 0.7,
                    z1: 1.1,
                });
                const upper = disc(l, {
                    x: t.x,
                    y: t.y,
                    r: 0.55,
                    z0: 1.1,
                    z1: 1.45,
                });

                return (
                    <g key={`${t.x}:${t.y}`}>
                        <Cylinder c={trunk} />
                        <Cylinder c={lower} />
                        <Cylinder c={upper} />
                    </g>
                );
            })}
        </Figure>
    );
}

/* ─── Onboarding: one figure per step ─────────────────────────────────────────────────────── */

const STEP_VIEWBOX = '0 0 240 170';

function CalendarFigure({ className }: { className?: string }) {
    const l = lens(HAIRLINE(28, [120, 92]));
    const base = prism(l, {
        x: 0,
        y: 0,
        w: 4.6,
        d: 4.6,
        z0: 0,
        z1: 0.55,
        r: 0.3,
    });
    const page = prism(l, {
        x: 0,
        y: 0.15,
        w: 4.3,
        d: 4.1,
        z0: 0.55,
        z1: 0.68,
        r: 0.25,
    });
    const cells: Array<[number, number]> = [];

    for (let row = 0; row < 3; row++) {
        for (let col = 0; col < 4; col++) {
            cells.push([-1.55 + col * 1.03, -0.75 + row * 1.0]);
        }
    }

    const cell = (x: number, y: number) =>
        poly(l, [
            [x - 0.36, y - 0.33, 0.68],
            [x + 0.36, y - 0.33, 0.68],
            [x + 0.36, y + 0.33, 0.68],
            [x - 0.36, y + 0.33, 0.68],
        ]);
    const target = cells[6];

    return (
        <Figure
            viewBox={STEP_VIEWBOX}
            label="A calendar with the start date marked"
            className={className}
        >
            <Solid p={base} />
            <g className="fig-lift">
                <Solid p={page} />
                {cells.map(([x, y], i) =>
                    i === 6 ? null : (
                        <path key={i} d={cell(x, y)} className="fig-crease" />
                    ),
                )}
                <Drawn d={cell(target[0], target[1])} />
                {[-1.0, 1.0].map((x) => (
                    <Cylinder
                        key={x}
                        c={disc(l, { x, y: -1.75, r: 0.2, z0: 0.68, z1: 1.25 })}
                    />
                ))}
            </g>
        </Figure>
    );
}

function SheetsFigure({ className }: { className?: string }) {
    const l = lens(HAIRLINE(26, [124, 96]));
    const sheets = [0, 1, 2].map((i) =>
        prism(l, {
            x: -0.35 * i,
            y: 0.3 * i,
            w: 4.0,
            d: 5.0,
            z0: 0.34 * i,
            z1: 0.34 * i + 0.1,
            r: 0.2,
        }),
    );
    const topZ = 0.78;
    const line = (y: number, x0: number, x1: number) =>
        seg(l, [x0 - 0.7, y + 0.6, topZ], [x1 - 0.7, y + 0.6, topZ]);

    return (
        <Figure
            viewBox={STEP_VIEWBOX}
            label="A stack of grade reports with one grade highlighted"
            className={className}
        >
            <Solid p={sheets[0]} />
            <Solid p={sheets[1]} />
            <g className="fig-lift">
                <Solid p={sheets[2]} />
                {[-2.0, -1.2, 0.4, 1.2].map((y) => (
                    <path
                        key={y}
                        d={line(y, -1.4, 1.4)}
                        className="fig-crease"
                    />
                ))}
                <Drawn d={line(-0.4, -1.4, 0.8)} />
            </g>
        </Figure>
    );
}

function BooksFigure({ className }: { className?: string }) {
    const l = lens(HAIRLINE(24, [116, 122]));
    const shelf = prism(l, {
        x: 0,
        y: 0,
        w: 6.2,
        d: 2.0,
        z0: -0.25,
        z1: 0,
        r: 0.2,
    });
    const books = [
        { x: -2.3, w: 0.7, h: 3.0 },
        { x: -1.5, w: 0.8, h: 2.55 },
        { x: -0.65, w: 0.65, h: 3.35, bright: true },
        { x: 0.1, w: 0.7, h: 2.8 },
    ];
    const flatA = prism(l, {
        x: 1.85,
        y: 0.05,
        w: 2.0,
        d: 1.5,
        z0: 0,
        z1: 0.42,
        r: 0.1,
    });
    const flatB = prism(l, {
        x: 1.95,
        y: 0.0,
        w: 1.7,
        d: 1.3,
        z0: 0.42,
        z1: 0.8,
        r: 0.1,
    });

    return (
        <Figure
            viewBox={STEP_VIEWBOX}
            label="Books on a shelf, one standing out"
            className={className}
        >
            <Solid p={shelf} />
            {books.map((b) => (
                <Solid
                    key={b.x}
                    p={prism(l, {
                        x: b.x,
                        y: 0,
                        w: b.w,
                        d: 1.45,
                        z0: 0,
                        z1: b.h,
                        r: 0.08,
                    })}
                    bright={b.bright}
                    className={b.bright ? 'fig-lift' : undefined}
                />
            ))}
            <Solid p={flatA} />
            <Solid p={flatB} />
        </Figure>
    );
}

function MapFigure({ className }: { className?: string }) {
    const l = lens(HAIRLINE(24, [120, 98]));
    const land = prism(l, {
        x: 0,
        y: 0,
        w: 7.0,
        d: 5.4,
        z0: -0.2,
        z1: 0,
        r: 0.6,
    });
    const pins = [
        { x: -2.2, y: -0.4 },
        { x: 0.4, y: 1.4 },
        { x: 2.1, y: -1.5, bright: true },
    ];
    const route = poly(
        l,
        pins.map((p) => [p.x, p.y, 0] as [number, number, number]),
        false,
    );

    return (
        <Figure
            viewBox={STEP_VIEWBOX}
            label="A map with three places pinned and a route between them"
            className={className}
        >
            <Solid p={land} />
            <path d={route} className="fig-crease" strokeDasharray="3 4" />
            {[...pins]
                .sort((a, b) => a.x + a.y - (b.x + b.y))
                .map((p) => (
                    <g
                        key={`${p.x}:${p.y}`}
                        className={p.bright ? 'fig-lift' : undefined}
                    >
                        <ellipse
                            cx={fx(l.at(p.x, p.y, 0)[0])}
                            cy={fx(l.at(p.x, p.y, 0)[1])}
                            rx={fx(l.flat(0.32)[0])}
                            ry={fx(l.flat(0.32)[1])}
                            className="fig-crease"
                        />
                        <path
                            d={seg(l, [p.x, p.y, 0], [p.x, p.y, 1.7])}
                            className="fig-line"
                        />
                        <Cylinder
                            c={disc(l, {
                                x: p.x,
                                y: p.y,
                                r: 0.36,
                                z0: 1.7,
                                z1: 2.05,
                            })}
                            bright={p.bright}
                        />
                    </g>
                ))}
        </Figure>
    );
}

function CoinsFigure({ className }: { className?: string }) {
    const l = lens(HAIRLINE(26, [120, 112]));
    const stacks = [
        { x: -1.9, y: 0.2, n: 3 },
        { x: 0.1, y: -1.1, n: 5, bright: true },
        { x: 1.7, y: 1.1, n: 2 },
    ].sort((a, b) => a.x + a.y - (b.x + b.y));
    const h = 0.34;

    return (
        <Figure
            viewBox={STEP_VIEWBOX}
            label="Stacks of coins, the tallest one highlighted"
            className={className}
        >
            {stacks.map((s) => (
                <g
                    key={`${s.x}:${s.y}`}
                    className={s.bright ? 'fig-lift' : undefined}
                >
                    {Array.from({ length: s.n }, (_, i) => (
                        <Cylinder
                            key={i}
                            c={disc(l, {
                                x: s.x,
                                y: s.y,
                                r: 0.95,
                                z0: i * h,
                                z1: (i + 1) * h,
                            })}
                            bright={s.bright && i === s.n - 1}
                        />
                    ))}
                </g>
            ))}
        </Figure>
    );
}

function DocumentsFigure({ className }: { className?: string }) {
    const l = lens(HAIRLINE(27, [120, 92]));
    const pages = [0, 1, 2].map((i) =>
        prism(l, {
            x: 0.25 * i,
            y: -0.2 * i,
            w: 3.8,
            d: 5.0,
            z0: 0.24 * i,
            z1: 0.24 * i + 0.09,
            r: 0.2,
        }),
    );
    const z = 0.57;
    const rows = [-1.6, -0.9, -0.2, 0.5, 1.2];

    return (
        <Figure
            viewBox={STEP_VIEWBOX}
            label="A stack of documents being read line by line"
            className={className}
        >
            <Solid p={pages[0]} />
            <Solid p={pages[1]} />
            <g className="fig-lift">
                <Solid p={pages[2]} />
                {rows.map((y) => (
                    <path
                        key={y}
                        d={seg(l, [-0.9, y - 0.4, z], [1.4, y - 0.4, z])}
                        className="fig-crease"
                    />
                ))}
                <Drawn
                    d={seg(l, [-1.25, 0.15, z], [1.75, 0.15, z])}
                    className="doc-scan"
                />
            </g>
        </Figure>
    );
}

function ClipboardFigure({ className }: { className?: string }) {
    const l = lens(HAIRLINE(25, [120, 92]));
    const board = prism(l, {
        x: 0,
        y: 0,
        w: 4.4,
        d: 5.6,
        z0: 0,
        z1: 0.2,
        r: 0.3,
    });
    const clip = prism(l, {
        x: 0,
        y: -2.55,
        w: 1.6,
        d: 0.6,
        z0: 0.2,
        z1: 0.55,
        r: 0.12,
    });
    const z = 0.2;
    const box = (y: number) =>
        poly(l, [
            [-1.55, y - 0.3, z],
            [-0.95, y - 0.3, z],
            [-0.95, y + 0.3, z],
            [-1.55, y + 0.3, z],
        ]);
    const check = (y: number) =>
        poly(
            l,
            [
                [-1.5, y, z],
                [-1.27, y + 0.22, z],
                [-0.9, y - 0.3, z],
            ],
            false,
        );
    const rows = [-1.3, 0, 1.3];

    return (
        <Figure
            viewBox={STEP_VIEWBOX}
            label="A checklist on a clipboard with the last item being ticked"
            className={className}
        >
            <Solid p={board} />
            <Solid p={clip} />
            <g className="fig-lift">
                {rows.map((y, i) => (
                    <g key={y}>
                        <path d={box(y)} className="fig-crease" />
                        <path
                            d={seg(l, [-0.5, y, z], [1.55, y, z])}
                            className="fig-crease"
                        />
                        {i < 2 ? (
                            <path d={check(y)} className="fig-line" />
                        ) : (
                            <Drawn d={check(y)} />
                        )}
                    </g>
                ))}
            </g>
        </Figure>
    );
}

function PassportFigure({ className }: { className?: string }) {
    const l = lens(HAIRLINE(30, [120, 96]));
    const card = prism(l, {
        x: 0,
        y: 0,
        w: 5.2,
        d: 3.4,
        z0: 0,
        z1: 0.22,
        r: 0.35,
    });
    const z = 0.22;
    const avatar = disc(l, { x: -1.35, y: 0.1, r: 0.75, z0: z, z1: z + 0.05 });

    return (
        <Figure
            viewBox={STEP_VIEWBOX}
            label="An ID card for the student"
            className={className}
        >
            <g className="fig-lift">
                <Solid p={card} />
                <Cylinder c={avatar} />
                {[-0.6, 0.1, 0.8].map((y) => (
                    <path
                        key={y}
                        d={seg(l, [0.1, y, z], [2.0, y, z])}
                        className="fig-crease"
                    />
                ))}
                <Drawn d={seg(l, [0.1, -1.25, z], [1.5, -1.25, z])} />
            </g>
        </Figure>
    );
}

export type StepFigureKey =
    | 'consent'
    | 'situation'
    | 'academics'
    | 'interests'
    | 'destinations'
    | 'budget'
    | 'documents'
    | 'review';

export function StepFigure({
    step,
    className,
}: {
    step: StepFigureKey | string;
    className?: string;
}) {
    switch (step) {
        case 'consent':
            return <PassportFigure className={className} />;
        case 'situation':
            return <CalendarFigure className={className} />;
        case 'academics':
            return <SheetsFigure className={className} />;
        case 'interests':
            return <BooksFigure className={className} />;
        case 'destinations':
            return <MapFigure className={className} />;
        case 'budget':
            return <CoinsFigure className={className} />;
        case 'documents':
            return <DocumentsFigure className={className} />;
        default:
            return <ClipboardFigure className={className} />;
    }
}

/* ─── Empty states ───────────────────────────────────────────────────────────────────────── */

export function TrayFigure({ className }: { className?: string }) {
    const l = lens(HAIRLINE(16, [80, 66]));
    const tray = prism(l, {
        x: 0,
        y: 0,
        w: 4.6,
        d: 3.6,
        z0: 0,
        z1: 0.5,
        r: 0.35,
    });
    const inner = prism(l, {
        x: 0,
        y: 0,
        w: 3.8,
        d: 2.8,
        z0: 0.2,
        z1: 0.5,
        r: 0.28,
    });
    const z = 0.5;

    return (
        <Figure
            viewBox="0 0 160 110"
            label="An empty tray"
            className={className}
        >
            <Solid p={tray} />
            <path d={inner.top} className="fig-crease" />
            <Drawn
                d={poly(
                    l,
                    [
                        [-0.9, 0.1, z + 1.2],
                        [-0.35, 0.55, z + 0.75],
                        [0.95, -0.7, z + 2.0],
                    ],
                    false,
                )}
            />
        </Figure>
    );
}
