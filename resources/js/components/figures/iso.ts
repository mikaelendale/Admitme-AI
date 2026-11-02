/**
 * A tiny isometric kit for hairline figures: one projection, rounded solids, paint order by depth.
 * Coordinates are world units (x right-back, y left-back, z up); `lens` projects them into the viewBox.
 * Numbers are snapped and printed with fixed decimals so server and browser always agree.
 */

export type Camera = {
    yaw: number;
    pitch: number;
    scale: number;
    shift: [number, number];
};
export type Pt = [number, number];

const snap = (v: number) => Math.round(v * 1e9) / 1e9;

/** Fixed-precision coordinate, never "-0". */
export const fx = (v: number): string =>
    (Math.abs(v) < 0.005 ? 0 : v).toFixed(2);

export type Lens = {
    at: (x: number, y: number, z: number) => Pt;
    depth: (x: number, y: number) => number;
    /** On-screen radii of a ground circle of radius r. */
    flat: (r: number) => Pt;
};

export function lens({ yaw, pitch, scale, shift: [dx, dy] }: Camera): Lens {
    const [cy, sy, cp, sp] = [
        Math.cos(yaw),
        Math.sin(yaw),
        Math.cos(pitch),
        Math.sin(pitch),
    ].map(snap);

    return {
        at: (x, y, z) => [
            (x * cy - y * sy) * scale + dx,
            ((x * sy + y * cy) * sp - z * cp) * scale + dy,
        ],
        depth: (x, y) => x * sy + y * cy,
        flat: (r) => [r * scale, r * scale * sp],
    };
}

/** The classic hairline camera: 45° yaw, 30° pitch. */
export const HAIRLINE = (scale: number, shift: Pt): Camera => ({
    yaw: Math.PI / 4,
    pitch: Math.PI / 6,
    scale,
    shift,
});

function roundedOutline(
    cx: number,
    cy: number,
    w: number,
    d: number,
    r: number,
    steps = 5,
): Pt[] {
    const hw = w / 2;
    const hd = d / 2;
    const rr = Math.min(r, hw, hd);
    const corners: Array<[number, number, number]> = [
        [cx + hw - rr, cy + hd - rr, 0],
        [cx - hw + rr, cy + hd - rr, Math.PI / 2],
        [cx - hw + rr, cy - hd + rr, Math.PI],
        [cx + hw - rr, cy - hd + rr, (3 * Math.PI) / 2],
    ];
    const pts: Pt[] = [];

    for (const [ox, oy, start] of corners) {
        for (let i = 0; i <= steps; i++) {
            const a = start + (i / steps) * (Math.PI / 2);
            pts.push([ox + Math.cos(a) * rr, oy + Math.sin(a) * rr]);
        }
    }

    return pts;
}

const path = (pts: Pt[], close = true) =>
    `M${pts.map(([x, y]) => `${fx(x)} ${fx(y)}`).join('L')}${close ? 'Z' : ''}`;

export type Prism = {
    top: string;
    side: string;
    crease: string;
    depth: number;
    topPts: Pt[];
};

/**
 * A rounded box from z0 to z1. `side` is the visible band (fill it with the face colour first), `top` the lid,
 * `crease` the single dim line at the corner nearest the viewer.
 */
export function prism(
    l: Lens,
    o: {
        x: number;
        y: number;
        w: number;
        d: number;
        z0: number;
        z1: number;
        r?: number;
    },
): Prism {
    const outline = roundedOutline(o.x, o.y, o.w, o.d, o.r ?? 0.18);
    const top = outline.map(([x, y]) => l.at(x, y, o.z1));
    const bottom = outline.map(([x, y]) => l.at(x, y, o.z0));
    const depths = outline.map(([x, y]) => l.depth(x, y));

    let iL = 0;
    let iR = 0;
    top.forEach(([sx], i) => {
        if (sx < top[iL][0]) {
            iL = i;
        }

        if (sx > top[iR][0]) {
            iR = i;
        }
    });

    const walk = (from: number, to: number, dir: 1 | -1) => {
        const idx: number[] = [];

        for (let i = from; ; i = (i + dir + outline.length) % outline.length) {
            idx.push(i);

            if (i === to) {
                break;
            }
        }

        return idx;
    };
    const forward = walk(iL, iR, 1);
    const backward = walk(iL, iR, -1);
    const avg = (idx: number[]) =>
        idx.reduce((sum, i) => sum + depths[i], 0) / idx.length;
    const near = avg(forward) >= avg(backward) ? forward : backward;

    const band = [
        ...near.map((i) => top[i]),
        ...[...near].reverse().map((i) => bottom[i]),
    ];
    const nearest = depths.indexOf(Math.max(...depths));

    return {
        top: path(top),
        side: path(band),
        crease: path([top[nearest], bottom[nearest]], false),
        depth: l.depth(o.x, o.y),
        topPts: top,
    };
}

export type Disc = {
    top: { cx: number; cy: number; rx: number; ry: number };
    side: string;
    depth: number;
};

/** An upright cylinder (coin, pin head, tree canopy) from z0 to z1. */
export function disc(
    l: Lens,
    o: { x: number; y: number; r: number; z0: number; z1: number },
): Disc {
    const [rx, ry] = l.flat(o.r);
    const [cx, cyTop] = l.at(o.x, o.y, o.z1);
    const [, cyBottom] = l.at(o.x, o.y, o.z0);

    return {
        top: { cx, cy: cyTop, rx, ry },
        side: `M${fx(cx - rx)} ${fx(cyTop)}L${fx(cx - rx)} ${fx(cyBottom)}A${fx(rx)} ${fx(ry)} 0 0 0 ${fx(cx + rx)} ${fx(cyBottom)}L${fx(cx + rx)} ${fx(cyTop)}Z`,
        depth: l.depth(o.x, o.y),
    };
}

/** A straight line between two world points. */
export function seg(
    l: Lens,
    a: [number, number, number],
    b: [number, number, number],
): string {
    return path([l.at(...a), l.at(...b)], false);
}

/** A closed flat shape on a plane, given world points. */
export function poly(
    l: Lens,
    pts: Array<[number, number, number]>,
    close = true,
): string {
    return path(
        pts.map((p) => l.at(...p)),
        close,
    );
}
