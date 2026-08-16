import { describe, expect, it } from "vitest";
import { annulusPath, arcPath } from "../donutPath.ts";

/******************************************************************************
 * Path parsing helpers.
 *
 * Assertions go through these rather than matching substrings of the emitted
 * `d` attribute. What the module promises is geometry — where the corners sit,
 * which way an arc bends — not a byte sequence, so rounding coordinates or
 * changing whitespace should not turn this file red.
 *****************************************************************************/

/** One parsed path command: its letter and its numeric arguments. */
interface Segment {
    command: string;
    args: number[];
}

/** Every numeric token in a string, in order. Handles exponent notation. */
const numbers = (source: string): number[] => (source.match(/-?\d+(?:\.\d+)?(?:e[+-]?\d+)?/g) ?? []).map(Number);

/**
 * Split a path into its commands.
 *
 * Splitting on uppercase letters is safe: every command here is uppercase and
 * JavaScript prints exponents with a lowercase `e` (`3.06e-15`).
 */
const parse = (d: string): Segment[] =>
    d
        .trim()
        .split(/(?=[A-Z])/)
        .map(segment => ({ command: segment[0], args: numbers(segment.slice(1)) }));

const commands = (d: string): string[] => parse(d).map(s => s.command);

/** An arc command's parsed arguments: `rx ry rotation large-arc sweep x y`. */
interface Arc {
    rx: number;
    ry: number;
    largeArc: number;
    sweep: number;
    end: [number, number];
}

const arcs = (d: string): Arc[] =>
    parse(d)
        .filter(s => s.command === "A")
        .map(({ args }) => ({
            rx: args[0],
            ry: args[1],
            largeArc: args[3],
            sweep: args[4],
            end: [args[5], args[6]]
        }));

/** Every point the path visits: move-to and line-to targets plus arc endpoints. */
const points = (d: string): [number, number][] =>
    parse(d).flatMap(({ command, args }) => {
        if (command === "M" || command === "L") return [[args[0], args[1]] as [number, number]];
        if (command === "A") return [[args[5], args[6]] as [number, number]];
        return [];
    });

const radiusOf = ([x, y]: [number, number]): number => Math.hypot(x, y);
const angleOf = ([x, y]: [number, number]): number => Math.atan2(y, x);

describe("annulusPath", () => {
    const path = annulusPath(50, 30);

    it("draws two closed subpaths, the outer ring and the inner cut-out", () => {
        // The call site sets fill-rule="evenodd", so the second subpath punches
        // the hole regardless of which way either circle is wound.
        expect(commands(path)).toEqual(["M", "A", "A", "Z", "M", "A", "A", "Z"]);
    });

    it("builds each subpath from two half-circles of the right radius", () => {
        const [o1, o2, i1, i2] = arcs(path);

        expect([o1.rx, o1.ry, o2.rx, o2.ry]).toEqual([50, 50, 50, 50]);
        expect([i1.rx, i1.ry, i2.rx, i2.ry]).toEqual([30, 30, 30, 30]);
        // Two 180° hops: both halves carry the large-arc flag.
        expect(arcs(path).map(a => a.largeArc)).toEqual([1, 1, 1, 1]);
    });

    it("starts each subpath at the nine-o'clock point of its radius", () => {
        const [outerStart, , , innerStart] = points(path);

        expect(outerStart).toEqual([-50, 0]);
        expect(innerStart).toEqual([-30, 0]);
    });

    it("keeps every point on one of the two radii", () => {
        for (const point of points(path)) {
            expect([30, 50]).toContain(radiusOf(point));
        }
    });
});

describe("arcPath", () => {
    /** A quarter-turn segment of a 50/30 ring, with no gap between neighbours. */
    const quarter = (cornerRadius?: number): string => arcPath(0, Math.PI / 2, 0, Math.PI / 2, 50, 30, cornerRadius);

    describe("with rounded corners", () => {
        const path = quarter();

        it("replaces each of the four corners with a tangent arc", () => {
            // outer arc, corner, radial line, corner, inner arc, corner,
            // radial line, corner — then close.
            expect(commands(path)).toEqual(["M", "A", "A", "L", "A", "A", "A", "L", "A", "Z"]);
        });

        it("closes the path back onto its own starting point", () => {
            const visited = points(path);
            const [start] = visited;
            const [endX, endY] = visited[visited.length - 1];

            expect(endX).toBeCloseTo(start[0], 9);
            expect(endY).toBeCloseTo(start[1], 9);
        });

        it("runs one long arc along each of the ring's radii", () => {
            const longArcs = arcs(path).filter(a => a.rx === 50 || a.rx === 30);

            expect(longArcs.map(a => a.rx)).toEqual([50, 30]);
            // Opposite sweep directions: out along the rim, back along the hole.
            expect(longArcs.map(a => a.sweep)).toEqual([1, 0]);
        });

        it("pulls each radial line's ends one corner radius in from the arc it leaves", () => {
            // The four line endpoints are the keystone's real corners; without
            // this, a path could keep the right command order and still be the
            // wrong shape.
            const lineEnds = parse(path)
                .filter(s => s.command === "L")
                .map(s => [s.args[0], s.args[1]] as [number, number]);
            const cornerRadii = points(path)
                .map(radiusOf)
                .filter(r => Math.abs(r - 50) > 1e-9 && Math.abs(r - 30) > 1e-9);

            expect(lineEnds).toHaveLength(2);
            // Default corner radius is 5, so the inset points sit at 45 and 35.
            for (const radius of cornerRadii) {
                expect([45, 35].some(expected => Math.abs(radius - expected) < 1e-9)).toBe(true);
            }
        });

        it("keeps each radial line truly radial — both ends on the same angle", () => {
            // A line-to's start is the previous command's endpoint, so pair
            // consecutive visited points. Command order is M A A L A A A L A:
            // the L at index 3 closes the trailing edge, the one at 7 the
            // leading edge.
            const visited = points(path);
            const radialLines: [[number, number], [number, number]][] = [
                [visited[2], visited[3]],
                [visited[6], visited[7]]
            ];

            for (const [from, to] of radialLines) {
                expect(angleOf(from)).toBeCloseTo(angleOf(to), 9);
                // …and the line really crosses the ring rather than skimming it.
                expect(Math.abs(radiusOf(from) - radiusOf(to))).toBeGreaterThan(1);
            }
        });

        it("keeps every point inside the ring it was given", () => {
            for (const point of points(path)) {
                expect(radiusOf(point)).toBeGreaterThanOrEqual(30 - 1e-9);
                expect(radiusOf(point)).toBeLessThanOrEqual(50 + 1e-9);
            }
        });

        it("honours a smaller corner radius", () => {
            const cornerRadiiOf = (d: string): number[] =>
                arcs(d)
                    .filter(a => a.rx !== 50 && a.rx !== 30)
                    .map(a => a.rx);

            expect(new Set(cornerRadiiOf(quarter(2)))).toEqual(new Set([2]));
            expect(new Set(cornerRadiiOf(quarter()))).toEqual(new Set([5]));
        });

        it("clamps the corner radius to half the ring thickness", () => {
            // A 50/48 ring is 2 units thick, so 5-unit corners would overlap.
            const thin = arcPath(0, Math.PI / 2, 0, Math.PI / 2, 50, 48, 5);

            expect(arcs(thin).some(a => a.rx === 1)).toBe(true);
        });

        it("clamps the corner radius to half the inner arc length", () => {
            // The inner arc is the shorter one, so on a narrow segment it — not
            // the outer arc and not the thickness — is what limits rounding.
            const narrow = arcPath(0, 0.4, 0, 0.4, 50, 4, 5);

            // (sweepInner * rInner) / 2 = 0.8, the smallest of the four terms.
            expect(arcs(narrow).some(a => Math.abs(a.rx - 0.8) < 1e-9)).toBe(true);
        });

        it("sets the large-arc flag once a segment passes a half turn", () => {
            const majority = arcPath(0, (3 * Math.PI) / 2, 0, (3 * Math.PI) / 2, 50, 30);

            expect(arcs(majority).find(a => a.rx === 50)?.largeArc).toBe(1);
            expect(arcs(quarter()).find(a => a.rx === 50)?.largeArc).toBe(0);
        });

        it("flags the two long arcs independently", () => {
            // Corner trimming shortens each arc by its own corner angle
            // (cr/radius), which is larger on the inner arc. A segment can
            // therefore straddle the half-turn boundary — long outside, short
            // inside — and the two flags must not be derived from one sweep.
            const straddling = arcPath(0, 3.5, 0.05, 3.45, 50, 30);

            expect(arcs(straddling).find(a => a.rx === 50)?.largeArc).toBe(1);
            expect(arcs(straddling).find(a => a.rx === 30)?.largeArc).toBe(0);
        });
    });

    describe("with corners too small to round", () => {
        // A sliver: the clamp drives the corner radius under 0.5, so the
        // builder falls back to sharp corners rather than self-overlapping.
        const sliver = arcPath(0, 0.01, 0, 0.01, 50, 30);

        it("draws the plain keystone: two arcs joined by two radial lines", () => {
            expect(commands(sliver)).toEqual(["M", "A", "L", "A", "Z"]);
        });

        it("puts its four corners on the two ring radii", () => {
            const radii = points(sliver).map(radiusOf);

            expect(radii.map(r => Math.round(r))).toEqual([50, 50, 30, 30]);
        });

        it("starts on the outer radius at the segment's first angle", () => {
            expect(points(sliver)[0]).toEqual([50, 0]);
        });

        it("flags the two arcs independently", () => {
            // Same latent case as the rounded branch: a thin ring keeps the
            // corner radius under the rounding threshold at any sweep, so this
            // is the branch where a shared flag actually shipped.
            const straddling = arcPath(0, 3.2, 0.1, 3.1, 50, 49.5);

            expect(commands(straddling)).toEqual(["M", "A", "L", "A", "Z"]);
            expect(arcs(straddling).find(a => a.rx === 50)?.largeArc).toBe(1);
            expect(arcs(straddling).find(a => a.rx === 49.5)?.largeArc).toBe(0);
        });

        it("flags both arcs long when both sweeps pass a half turn", () => {
            const wide = arcPath(0, 3.5, 0, 3.5, 50, 49.5);

            expect(arcs(wide).map(a => a.largeArc)).toEqual([1, 1]);
        });
    });

    it("projects the gap radially, so the inner arc spans a narrower angle", () => {
        // Callers pass a wider angular gap at the inner radius than at the outer
        // one, keeping the visual gap between neighbouring segments a constant
        // width rather than pinching toward the centre. The inner arc must
        // therefore sit strictly inside the outer arc's angular span.
        const path = arcPath(0.1, 1.4, 0.3, 1.2, 50, 30);
        const outerAngles = points(path)
            .filter(p => Math.abs(radiusOf(p) - 50) < 1e-9)
            .map(angleOf);
        const innerAngles = points(path)
            .filter(p => Math.abs(radiusOf(p) - 30) < 1e-9)
            .map(angleOf);

        expect(Math.min(...innerAngles)).toBeGreaterThan(Math.min(...outerAngles));
        expect(Math.max(...innerAngles)).toBeLessThan(Math.max(...outerAngles));
    });
});
