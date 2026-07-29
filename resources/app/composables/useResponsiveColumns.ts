import type { ComputedRef, Ref } from "vue";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";

/**
 * Configuration for the responsive column layout.
 *
 * `colGap` must match the CSS `gap` on the container — the ResizeObserver
 * calculation accounts for inter-column gaps when determining how many
 * columns fit.
 */
interface ColumnConfig<T> {
    /** Minimum column width in pixels before a column is dropped. */
    minColWidth: number;
    /** Hard cap — prevents excessive columns on ultra-wide displays. */
    maxColumns: number;
    /** Gap between columns in pixels — must match the CSS `gap` value. */
    colGap: number;
    /**
     * Per-section visual weight for balanced distribution. Without this,
     * sections are distributed evenly by count — fine when every section
     * is roughly the same height, but produces lopsided layouts when one
     * section (e.g. Creatures with 40 rows) dwarfs another (Enchantments
     * with 3). With it, sections are partitioned into contiguous runs that
     * balance the per-column weight sum.
     */
    weight?: (item: T) => number;
}

/** Return type of {@link useResponsiveColumns}. */
export type UseResponsiveColumnsReturn<T> = {
    /** Template ref to bind to the container element. */
    containerRef: Ref<HTMLElement | null>;
    /** Sections distributed into columns, column-first. */
    columns: ComputedRef<T[][]>;
};

/**
 * Distribute a flat list of sections into a responsive multi-column layout.
 *
 * Column count is driven by the container width via ResizeObserver. Sections
 * are distributed column-first: each column is filled top-to-bottom before
 * moving to the next, preserving the source order. Taller columns come first
 * so trailing columns are never empty.
 *
 * Distribution mode depends on `config.weight`:
 *  - omitted: even by item count (legacy behaviour).
 *  - provided: linear partition that balances the per-column weight sum,
 *    so a "Creatures (40)" section sits next to "Lands (24)" instead of
 *    being shoved into one column with "Enchantments (3)".
 *
 * @param sections - Reactive list of sections to distribute.
 * @param config - Layout configuration (column widths, gap, max columns).
 * @returns Container ref and the distributed columns computed.
 */
export function useResponsiveColumns<T>(
    sections: ComputedRef<T[]>,
    config: ColumnConfig<T>
): UseResponsiveColumnsReturn<T> {
    const containerRef = ref<HTMLElement | null>(null);
    const colCount = ref(1);
    let observer: ResizeObserver | null = null;

    onMounted(() => {
        if (!containerRef.value) return;
        observer = new ResizeObserver(([entry]) => {
            const width = entry.contentBoxSize[0].inlineSize;
            // Solve: width >= n * minColWidth + (n - 1) * colGap
            const n = Math.floor((width + config.colGap) / (config.minColWidth + config.colGap));
            colCount.value = Math.max(1, Math.min(n, config.maxColumns));
        });
        observer.observe(containerRef.value);
    });

    onBeforeUnmount(() => {
        observer?.disconnect();
    });

    const columns = computed<T[][]>(() => {
        const items = sections.value;
        const count = Math.min(items.length, colCount.value);
        if (count === 0) return [];

        if (config.weight) {
            return partitionByWeight(items, count, config.weight);
        }

        // The first `extra` columns get one more item than the rest. This
        // keeps taller columns on the left and guarantees every column
        // receives at least one item — avoiding empty trailing columns
        // that would appear with a naive Math.ceil division.
        const cols: T[][] = Array.from({ length: count }, () => []);
        const base = Math.floor(items.length / count);
        const extra = items.length % count;
        let idx = 0;
        for (let c = 0; c < count; c++) {
            const size = c < extra ? base + 1 : base;
            for (let j = 0; j < size; j++) {
                cols[c].push(items[idx++]);
            }
        }
        return cols;
    });

    return { containerRef, columns };
}

/**
 * Linear-partition `items` into `k` contiguous columns minimising the
 * largest per-column weight sum. O(n²k) DP — trivially fast for the
 * <20 sections × ≤4 columns this layout produces.
 *
 * Tie-break: when several partitions reach the same minimum maximum,
 * later split points win, which biases extra weight toward the leading
 * columns and keeps the visual "tall columns on the left" rule.
 */
function partitionByWeight<T>(items: T[], k: number, weight: (item: T) => number): T[][] {
    const n = items.length;
    if (k <= 1) return [items.slice()];
    if (n <= k) return items.map(item => [item]);

    const w = items.map(weight);
    const prefix = new Array<number>(n + 1).fill(0);
    for (let i = 0; i < n; i++) prefix[i + 1] = prefix[i] + w[i];
    const sumRange = (a: number, b: number): number => prefix[b] - prefix[a];

    // dp[i][j] = min possible max-column-sum when partitioning items[0..i) into j columns.
    // splitAt[i][j] = start index of the last (j-th) column for that optimum.
    const dp: number[][] = Array.from({ length: n + 1 }, () => new Array<number>(k + 1).fill(Infinity));
    const splitAt: number[][] = Array.from({ length: n + 1 }, () => new Array<number>(k + 1).fill(0));

    dp[0][0] = 0;
    for (let i = 1; i <= n; i++) dp[i][1] = prefix[i];

    for (let j = 2; j <= k; j++) {
        for (let i = j; i <= n; i++) {
            for (let s = j - 1; s < i; s++) {
                const cost = Math.max(dp[s][j - 1], sumRange(s, i));
                if (cost <= dp[i][j]) {
                    dp[i][j] = cost;
                    splitAt[i][j] = s;
                }
            }
        }
    }

    const cuts: number[] = [n];
    let i = n;
    let j = k;
    while (j > 1) {
        const s = splitAt[i][j];
        cuts.push(s);
        i = s;
        j--;
    }
    cuts.push(0);
    cuts.reverse();

    const result: T[][] = [];
    for (let c = 0; c < k; c++) {
        result.push(items.slice(cuts[c], cuts[c + 1]));
    }
    return result;
}
