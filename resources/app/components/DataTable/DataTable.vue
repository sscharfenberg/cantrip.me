<script setup lang="ts" generic="T extends { id: string; href?: string }">
import { router } from "@inertiajs/vue3";
import { type Ref, computed, onBeforeUnmount, onMounted, provide, ref, useSlots, watch } from "vue";
import { useI18n } from "vue-i18n";
import DataTableActions from "Components/DataTable/DataTableActions.vue";
import DataTableBody from "Components/DataTable/DataTableBody.vue";
import DataTableCards from "Components/DataTable/DataTableCards.vue";
import DataTableHead from "Components/DataTable/DataTableHead.vue";
import DataTablePagination from "Components/DataTable/DataTablePagination.vue";
import DataTableToolbar from "Components/DataTable/DataTableToolbar.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import type { ColumnDef, TableResponse, SortEntry } from "Types/dataTable";
import { DATA_TABLE_KEY } from "Types/dataTable";
const props = withDefaults(
    defineProps<{
        /** Column definitions controlling rendering, sorting, and visibility. */
        columns: ColumnDef<T>[];
        /** Server-side table response containing rows, pagination, sort, and search state. */
        response: TableResponse<T>;
        /** Whether rows can be selected via checkboxes. */
        selectable?: boolean;
        /**
         * Whether to render the per-row actions column (header + body + card-mode
         * three-dot button). Pass `false` for read-only viewer-mode tables so
         * non-owners don't see an empty actions column / button.
         */
        hasActions?: boolean;
        /** Base URL for Inertia navigation; defaults to current pathname. */
        baseUrl?: string;
    }>(),
    {
        selectable: false,
        hasActions: true,
        baseUrl: ""
    }
);
const { t } = useI18n();
const slots = useSlots();
/** Filter slots to only cell-* and actions slots for forwarding. */
const cellSlotNames = computed(() => Object.keys(slots).filter(name => name.startsWith("cell-")));
/** Filter slots to only header-* slots for forwarding to DataTableHead. */
const headerSlotNames = computed(() => Object.keys(slots).filter(name => name.startsWith("header-")));
// ---------------------------------------------------------------------------
// Sort normalization: server sends object | null, internal is always array
// ---------------------------------------------------------------------------
const sort = computed<SortEntry[]>(() => {
    if (!props.response.sort) return [];
    return [props.response.sort];
});
// ---------------------------------------------------------------------------
// Selection state
// ---------------------------------------------------------------------------
/** IDs of currently selected rows, shared with child components via provide/inject. */
const selectedIds = ref<string[]>([]);
/** Toggle a single row's selection state. */
function toggleSelection(id: string) {
    const idx = selectedIds.value.indexOf(id);
    if (idx === -1) {
        selectedIds.value.push(id);
    } else {
        selectedIds.value.splice(idx, 1);
    }
}
/** Toggle selection for all rows on the current page (select all / deselect all). */
function togglePageSelection(ids: string[]) {
    const allSelected = ids.every(id => selectedIds.value.includes(id));
    if (allSelected) {
        selectedIds.value = selectedIds.value.filter(id => !ids.includes(id));
    } else {
        const missing = ids.filter(id => !selectedIds.value.includes(id));
        selectedIds.value.push(...missing);
    }
}
/** Clear selection on sort/filter/search change, preserve on page change. */
watch(
    () => [props.response.sort, props.response.search, props.response.filters],
    () => {
        selectedIds.value = [];
    },
    { deep: true }
);
provide(DATA_TABLE_KEY, {
    selectedIds,
    toggleSelection,
    togglePageSelection
});
// ---------------------------------------------------------------------------
// Row IDs for header checkbox
// ---------------------------------------------------------------------------
/** All row IDs on the current page, used by the header checkbox. */
const rowIds = computed(() => props.response.rows.map(row => row.id));
// ---------------------------------------------------------------------------
// Loading state — tracks Inertia navigation
// ---------------------------------------------------------------------------
/** True while an Inertia navigation is in flight, used to show the loading overlay. */
const isLoading = ref(false);
router.on("start", () => {
    isLoading.value = true;
});
router.on("finish", () => {
    isLoading.value = false;
});
// ---------------------------------------------------------------------------
// aria-live announcements
// ---------------------------------------------------------------------------
/** Screen reader announcement text, updated on sort and page changes. */
const announcement = ref("");
watch(sort, newSort => {
    if (newSort.length === 0) return;
    const entry = newSort[0];
    const col = props.columns.find(c => c.key === entry.key);
    const label = col?.label ?? entry.key;
    announcement.value =
        entry.direction === "asc"
            ? t("components.datatable.sorted_asc", { column: label })
            : t("components.datatable.sorted_desc", { column: label });
});
watch(
    () => props.response.page,
    page => {
        if (!props.response.pageSize) return;
        const totalPages = Math.ceil(props.response.total / props.response.pageSize);
        announcement.value = t("components.datatable.page_status", {
            page,
            total: totalPages,
            size: props.response.rows.length
        });
    }
);
// ---------------------------------------------------------------------------
// Row action popover
// ---------------------------------------------------------------------------
/** The row whose action popover is currently open, or null. */
const activeRow = ref<T | null>(null) as Ref<T | null>;
/** Reference to the three-dot button that opened the popover, used for focus return. */
const actionButtonRef = ref<HTMLElement | null>(null);
/** Open the action popover for a row, anchored to the trigger button. */
function onAction(row: T, el: HTMLElement) {
    activeRow.value = row;
    actionButtonRef.value = el;
}
/** Reset popover state and return focus to the three-dot button that opened it. */
function onCloseActions() {
    const triggerEl = actionButtonRef.value;
    activeRow.value = null;
    actionButtonRef.value = null;
    triggerEl?.focus();
}
// ---------------------------------------------------------------------------
// Navigation helpers — emit Inertia requests
// ---------------------------------------------------------------------------
/**
 * Merge new params into the current URL, preserving existing query state
 * (e.g. pageSize survives a page navigation). Params set to null are removed.
 */
function buildUrl(params: Record<string, string | number | null>) {
    const base = props.baseUrl || window.location.pathname;
    const url = new URL(base, window.location.origin);
    // Carry over current query params so existing state (pageSize, sort, etc.) is preserved
    const currentParams = new URLSearchParams(window.location.search);
    for (const [key, value] of currentParams) {
        url.searchParams.set(key, value);
    }
    // Apply the new params on top
    for (const [key, value] of Object.entries(params)) {
        if (value === null || value === "") {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, String(value));
        }
    }
    return url.pathname + url.search;
}
/** Toggle sort direction for a column and navigate to page 1. */
function onSort(key: string) {
    const current = sort.value.find(s => s.key === key);
    let direction: "asc" | "desc" = "asc";
    if (current) {
        direction = current.direction === "asc" ? "desc" : "asc";
    }
    router.get(buildUrl({ sort: key, dir: direction, page: 1 }), {}, { preserveState: true, preserveScroll: true });
}
/** Navigate with updated search query, resetting to page 1. */
function onSearch(query: string) {
    router.get(buildUrl({ search: query || null, page: 1 }), {}, { preserveState: true, preserveScroll: true });
}
/** Navigate to a specific page number. */
function onNavigate(page: number) {
    router.get(buildUrl({ page }), {}, { preserveState: true, preserveScroll: true });
}
/** Change page size and reset to page 1. */
function onPageSizeChange(size: number) {
    router.get(buildUrl({ pageSize: size, page: 1 }), {}, { preserveState: true, preserveScroll: true });
}
// ---------------------------------------------------------------------------
// Sticky header detection via IntersectionObserver
// ---------------------------------------------------------------------------
/** Sentinel element positioned at the top of the table wrapper for sticky detection. */
const stickysentinel = ref<HTMLElement | null>(null);
/** True when the table header is stuck (scrolled past the sentinel). */
const isStuck = ref(false);
let observer: IntersectionObserver | null = null;
onMounted(() => {
    if (!stickysentinel.value) return;
    const offset = getComputedStyle(stickysentinel.value.closest(".dt")!)
        .getPropertyValue("--datatable-sticky-offset")
        .trim();
    const margin = offset ? `-${offset} 0px 0px 0px` : "0px";
    observer = new IntersectionObserver(
        ([entry]) => {
            isStuck.value = !entry.isIntersecting;
        },
        { rootMargin: margin }
    );
    observer.observe(stickysentinel.value);
});
onBeforeUnmount(() => {
    observer?.disconnect();
});
</script>

<template>
    <div class="dt" :class="{ 'dt--loading': isLoading }">
        <data-table-toolbar :search="response.search" :selected-count="selectedIds.length" @search="onSearch">
            <template v-if="$slots['toolbar-actions']" #actions>
                <slot name="toolbar-actions" :selected-ids="selectedIds" />
            </template>
        </data-table-toolbar>

        <div class="dt__wrapper">
            <div v-if="isLoading" class="dt__overlay">
                <loading-spinner :size="3" />
            </div>

            <!-- Sentinel for sticky header detection -->
            <div ref="stickysentinel" class="dt__sticky-sentinel" />

            <!-- Desktop: table layout -->
            <table class="dt__table" :aria-busy="isLoading">
                <data-table-head
                    :columns="columns"
                    :sort="sort"
                    :selectable="selectable"
                    :has-actions="hasActions"
                    :row-ids="rowIds"
                    :stuck="isStuck"
                    @sort="onSort"
                >
                    <template v-for="name in headerSlotNames" :key="name" #[name]="slotProps">
                        <slot :name="name" v-bind="slotProps" />
                    </template>
                </data-table-head>
                <data-table-body
                    :columns="columns"
                    :rows="response.rows"
                    :selectable="selectable"
                    :has-actions="hasActions"
                    @action="onAction"
                >
                    <template v-for="name in cellSlotNames" :key="name" #[name]="slotProps">
                        <slot :name="name" v-bind="slotProps" />
                    </template>
                </data-table-body>
            </table>

            <!-- Mobile: card layout -->
            <data-table-cards
                :columns="columns"
                :rows="response.rows"
                :selectable="selectable"
                :has-actions="hasActions"
                @action="onAction"
            >
                <template v-for="name in cellSlotNames" :key="name" #[name]="slotProps">
                    <slot :name="name" v-bind="slotProps" />
                </template>
            </data-table-cards>
        </div>

        <!-- Empty state -->
        <div v-if="response.rows.length === 0 && !isLoading" class="dt__empty">
            <slot name="empty" />
        </div>

        <!-- Pagination -->
        <data-table-pagination
            v-if="response.pageSize && response.total > 0"
            :page="response.page"
            :page-size="response.pageSize"
            :total="response.total"
            @navigate="onNavigate"
            @page-size-change="onPageSizeChange"
        />

        <!-- Row action popover -->
        <data-table-actions
            v-if="hasActions"
            :row="activeRow"
            :trigger-el="actionButtonRef"
            @close="onCloseActions"
        >
            <template v-if="activeRow" #default>
                <slot name="actions" :row="activeRow" />
            </template>
        </data-table-actions>

        <!-- Screen reader announcements -->
        <div class="sr-only" aria-live="polite">{{ announcement }}</div>
    </div>
</template>
