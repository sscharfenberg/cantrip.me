/** Column definition for the DataTable component. */
export interface ColumnDef<T extends { id: string }> {
    /** Field name in row data — must match a key on T. */
    key: keyof T & string;
    /** Display label (already translated by the parent). */
    label: string;
    /** Whether this column can be sorted. Default false. */
    sortable?: boolean;
    /** CSS width value. Default 'auto'. */
    width?: string;
    /** Show this column in the mobile card layout. Default false. */
    visibleInCard?: boolean;
    /** Primary column shown at the top of the card view. Only the first wins. */
    cardPrimary?: boolean;
    /** Text alignment. Default 'left'. */
    align?: "left" | "center" | "right";
    /** Extra CSS class(es) applied to the <td> for this column. */
    cellClass?: string;
}

/** Server response shape for paginated, sortable table data. */
export interface TableResponse<T> {
    rows: T[];
    total: number;
    page: number;
    /** null means no pagination. */
    pageSize: number | null;
    sort: { key: string; direction: "asc" | "desc" } | null;
    search: string | null;
    /**
     * Filters the server applied, keyed by query parameter — echoed back so the
     * filter UI shows exactly what narrowed the rows. null when none are active.
     */
    filters: Record<string, string | string[]> | null;
}

/** Internal normalized sort entry. */
export interface SortEntry {
    key: string;
    direction: "asc" | "desc";
}

/** Selection state managed by DataTable. */
export interface SelectionState {
    ids: string[];
}

/** Injection key for the DataTable provide/inject pattern. */
export const DATA_TABLE_KEY = Symbol("DataTable") as InjectionKey<DataTableProvide>;

import type { InjectionKey, Ref } from "vue";

/** Shape provided by DataTable to child components. */
export interface DataTableProvide {
    /** Currently selected row IDs. */
    selectedIds: Ref<string[]>;
    /** Toggle selection for a single row. */
    toggleSelection: (id: string) => void;
    /** Select/deselect all visible rows on the current page. */
    togglePageSelection: (ids: string[]) => void;
}
