/**
 * SVG path builders for donut (annular) charts. Used by both the
 * deck-view color donut and the generic StatsDonut tile so the two
 * visualisations stay geometrically identical — same corner-rounding,
 * same radial-gap projection, same single-segment annulus fallback.
 *
 * All inputs and outputs are in viewBox units; the callers pick their
 * own viewBox dimensions and radii.
 */

/**
 * Build a full annulus path as two concentric circles in a single
 * `<path>` with `fill-rule="evenodd"` so the inner disc cuts out
 * cleanly. Use this for one-segment (100%) rings — no gap math is
 * needed since there's no neighbour to separate from, and the entire
 * annulus becomes one clickable target.
 */
export const annulusPath = (rOuter: number, rInner: number): string =>
    `M${-rOuter},0 A${rOuter},${rOuter} 0 1 0 ${rOuter},0 A${rOuter},${rOuter} 0 1 0 ${-rOuter},0 Z` +
    ` M${-rInner},0 A${rInner},${rInner} 0 1 1 ${rInner},0 A${rInner},${rInner} 0 1 1 ${-rInner},0 Z`;

/**
 * Build a single keystone arc segment between two angles, with separate
 * half-gap offsets at the outer and inner radii (so the visual gap
 * between neighbouring segments stays radial rather than narrowing
 * toward the center) and `cornerRadius`-sized tangent arcs replacing
 * each of the four corners (so the keystone reads as rounded rather
 * than sharp).
 *
 * The corner approximation treats the radial sides as a 90° meeting of
 * the (locally tangent) ring and the (locally radial) line — slightly
 * inexact when the gap is non-zero, but the SVG renderer fits a smooth
 * curve through the two endpoints regardless and the visual error is
 * sub-pixel.
 *
 * The radius is auto-clamped per segment so very thin slices fall back
 * to sharp corners instead of overlapping themselves; pass a smaller
 * `cornerRadius` if a caller wants softer rounding throughout.
 */
export const arcPath = (
    theta0Outer: number,
    theta1Outer: number,
    theta0Inner: number,
    theta1Inner: number,
    rOuter: number,
    rInner: number,
    cornerRadius = 5
): string => {
    const sweepOuter = theta1Outer - theta0Outer;
    const sweepInner = theta1Inner - theta0Inner;
    const cr = Math.min(
        cornerRadius,
        (sweepOuter * rOuter) / 2,
        (sweepInner * rInner) / 2,
        (rOuter - rInner) / 2
    );

    if (cr < 0.5) {
        const x1 = rOuter * Math.cos(theta0Outer);
        const y1 = rOuter * Math.sin(theta0Outer);
        const x2 = rOuter * Math.cos(theta1Outer);
        const y2 = rOuter * Math.sin(theta1Outer);
        const x3 = rInner * Math.cos(theta1Inner);
        const y3 = rInner * Math.sin(theta1Inner);
        const x4 = rInner * Math.cos(theta0Inner);
        const y4 = rInner * Math.sin(theta0Inner);
        const largeArc = sweepOuter > Math.PI ? 1 : 0;
        return `M${x1},${y1} A${rOuter},${rOuter} 0 ${largeArc} 1 ${x2},${y2} L${x3},${y3} A${rInner},${rInner} 0 ${largeArc} 0 ${x4},${y4} Z`;
    }

    const dOuter = cr / rOuter;
    const dInner = cr / rInner;

    const aArcX = rOuter * Math.cos(theta0Outer + dOuter);
    const aArcY = rOuter * Math.sin(theta0Outer + dOuter);
    const aLineX = (rOuter - cr) * Math.cos(theta0Outer);
    const aLineY = (rOuter - cr) * Math.sin(theta0Outer);
    const bArcX = rOuter * Math.cos(theta1Outer - dOuter);
    const bArcY = rOuter * Math.sin(theta1Outer - dOuter);
    const bLineX = (rOuter - cr) * Math.cos(theta1Outer);
    const bLineY = (rOuter - cr) * Math.sin(theta1Outer);
    const cLineX = (rInner + cr) * Math.cos(theta1Inner);
    const cLineY = (rInner + cr) * Math.sin(theta1Inner);
    const cArcX = rInner * Math.cos(theta1Inner - dInner);
    const cArcY = rInner * Math.sin(theta1Inner - dInner);
    const dArcX = rInner * Math.cos(theta0Inner + dInner);
    const dArcY = rInner * Math.sin(theta0Inner + dInner);
    const dLineX = (rInner + cr) * Math.cos(theta0Inner);
    const dLineY = (rInner + cr) * Math.sin(theta0Inner);

    const largeArcOuter = sweepOuter - 2 * dOuter > Math.PI ? 1 : 0;
    const largeArcInner = sweepInner - 2 * dInner > Math.PI ? 1 : 0;

    return (
        `M${aArcX},${aArcY}` +
        ` A${rOuter},${rOuter} 0 ${largeArcOuter} 1 ${bArcX},${bArcY}` +
        ` A${cr},${cr} 0 0 1 ${bLineX},${bLineY}` +
        ` L${cLineX},${cLineY}` +
        ` A${cr},${cr} 0 0 1 ${cArcX},${cArcY}` +
        ` A${rInner},${rInner} 0 ${largeArcInner} 0 ${dArcX},${dArcY}` +
        ` A${cr},${cr} 0 0 1 ${dLineX},${dLineY}` +
        ` L${aLineX},${aLineY}` +
        ` A${cr},${cr} 0 0 1 ${aArcX},${aArcY} Z`
    );
};
