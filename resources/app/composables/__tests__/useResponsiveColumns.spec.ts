import { describe, expect, it } from "vitest";
import { computed } from "vue";
import type { ComputedRef } from "vue";
import { resizeObservers } from "@/test/observers.ts";
import { withSetup } from "@/test/withSetup.ts";
import { useResponsiveColumns } from "../useResponsiveColumns.ts";

/** A section with a name and a visual weight, standing in for a card group. */
interface Section {
    key: string;
    weight: number;
}

const section = (key: string, weight = 1): Section => ({ key, weight });

const CONFIG = { minColWidth: 300, maxColumns: 4, colGap: 20 };

/**
 * Mount the composable, bind the container ref to a real element, and report
 * the observed width so the column count settles.
 *
 * The ResizeObserver is `resources/app/test/observers.ts`'s fake, which records
 * itself instead of measuring anything — jsdom has no layout.
 */
const layout = (
    sections: ComputedRef<Section[]>,
    width: number,
    config: Partial<typeof CONFIG> & { weight?: (s: Section) => number } = {}
) => {
    // The container ref is assigned inside `setup()`, before `onMounted` runs,
    // which is exactly the order a template ref binding achieves in the app.
    const [result, app] = withSetup(() => {
        const columns = useResponsiveColumns(sections, { ...CONFIG, ...config });
        columns.containerRef.value = document.createElement("div");
        return columns;
    });

    resizeObservers[resizeObservers.length - 1].trigger({ inlineSize: width });

    return { columns: result.columns, app };
};

const keys = (columns: Section[][]): string[][] => columns.map(column => column.map(s => s.key));

describe("useResponsiveColumns — column count", () => {
    const sections = computed(() => ["a", "b", "c", "d", "e", "f"].map(k => section(k)));

    it("uses one column before the container has been measured", () => {
        const [result] = withSetup(() => useResponsiveColumns(sections, CONFIG));

        expect(result.columns.value).toHaveLength(1);
    });

    it("fits as many columns as the measured width allows", () => {
        // 300px columns with a 20px gap: 620 fits two, 940 fits three.
        expect(layout(sections, 620).columns.value).toHaveLength(2);
        expect(layout(sections, 940).columns.value).toHaveLength(3);
    });

    it("counts the inter-column gap against the available width", () => {
        // One pixel short of 2×300 + 20: without the gap term this would still
        // report two columns and the layout would overflow its container.
        expect(layout(sections, 619).columns.value).toHaveLength(1);
    });

    it("drops to one column below the minimum width", () => {
        expect(layout(sections, 299).columns.value).toHaveLength(1);
        expect(layout(sections, 0).columns.value).toHaveLength(1);
    });

    it("never exceeds the configured maximum", () => {
        expect(layout(sections, 10_000).columns.value).toHaveLength(4);
    });

    it("never makes more columns than there are sections", () => {
        const two = computed(() => [section("a"), section("b")]);

        expect(layout(two, 10_000).columns.value).toHaveLength(2);
    });

    it("returns no columns at all for an empty list", () => {
        const none = computed<Section[]>(() => []);

        expect(layout(none, 10_000).columns.value).toEqual([]);
    });

    it("re-flows when the container is resized", () => {
        const { columns } = layout(sections, 620);
        expect(columns.value).toHaveLength(2);

        resizeObservers[resizeObservers.length - 1].trigger({ inlineSize: 940 });

        expect(columns.value).toHaveLength(3);
    });

    it("stops observing when the component goes away", () => {
        const { app } = layout(sections, 620);
        const observer = resizeObservers[resizeObservers.length - 1];

        app.unmount();

        expect(observer.disconnected).toBe(true);
    });
});

describe("useResponsiveColumns — even distribution", () => {
    it("fills column-first, preserving source order", () => {
        const sections = computed(() => ["a", "b", "c", "d"].map(k => section(k)));

        expect(keys(layout(sections, 620).columns.value)).toEqual([
            ["a", "b"],
            ["c", "d"]
        ]);
    });

    it("gives the remainder to the leading columns, so none trails empty", () => {
        const sections = computed(() => ["a", "b", "c", "d", "e"].map(k => section(k)));

        expect(keys(layout(sections, 940).columns.value)).toEqual([["a", "b"], ["c", "d"], ["e"]]);
    });
});

describe("useResponsiveColumns — weighted distribution", () => {
    const weight = (s: Section): number => s.weight;

    it("balances by weight instead of by count", () => {
        // Naive halving would put the 40-row section next to the 3-row one.
        const sections = computed(() => [section("creatures", 40), section("enchantments", 3), section("lands", 24)]);

        expect(keys(layout(sections, 620, { weight }).columns.value)).toEqual([
            ["creatures"],
            ["enchantments", "lands"]
        ]);
    });

    it("keeps each column contiguous in source order", () => {
        const sections = computed(() =>
            [section("a", 1), section("b", 10), section("c", 1), section("d", 10)].map(s => s)
        );

        const columns = keys(layout(sections, 620, { weight }).columns.value);

        expect(columns.flat()).toEqual(["a", "b", "c", "d"]);
    });

    it("gives every section its own column when there are no more sections than columns", () => {
        const sections = computed(() => [section("a", 100), section("b", 1)]);

        expect(keys(layout(sections, 620, { weight }).columns.value)).toEqual([["a"], ["b"]]);
    });

    it("biases the extra weight toward the leading columns", () => {
        // Three equal sections over two columns has two optimal splits — 2/1
        // and 1/2. The documented tie-break (later split points win) is what
        // picks the taller-on-the-left one.
        const sections = computed(() => [section("a", 1), section("b", 1), section("c", 1)]);

        expect(keys(layout(sections, 620, { weight }).columns.value)).toEqual([["a", "b"], ["c"]]);
    });

    it("puts everything in one column when only one fits", () => {
        const sections = computed(() => [section("a", 1), section("b", 5)]);

        expect(keys(layout(sections, 100, { weight }).columns.value)).toEqual([["a", "b"]]);
    });
});
