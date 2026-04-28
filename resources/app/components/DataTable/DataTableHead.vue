<script setup lang="ts" generic="T extends { id: string }">
import { computed, inject } from "vue";
import Checkbox from "Components/Form/Checkbox.vue";
import type { ColumnDef, SortEntry } from "Types/dataTable";
import { DATA_TABLE_KEY } from "Types/dataTable";
const props = defineProps<{
    /** Column definitions for rendering header cells. */
    columns: ColumnDef<T>[];
    /** Current sort state, used to display sort indicators. */
    sort: SortEntry[];
    /** Whether to render the select-all checkbox column. */
    selectable: boolean;
    /** Whether to render the per-row actions column. */
    hasActions: boolean;
    /** All row IDs on the current page, for the select-all checkbox logic. */
    rowIds: string[];
    /** True when the header is in its sticky (scrolled) state. */
    stuck: boolean;
}>();
const emit = defineEmits<{
    /** Emitted with the column key when a sortable header is clicked. */
    sort: [key: string];
}>();
const provided = inject(DATA_TABLE_KEY)!;
/** Determine aria-sort attribute for a column. */
function ariaSort(col: ColumnDef<T>): "ascending" | "descending" | "none" | undefined {
    if (!col.sortable) return undefined;
    const entry = props.sort.find(s => s.key === col.key);
    if (!entry) return "none";
    return entry.direction === "asc" ? "ascending" : "descending";
}
/** Get current sort direction for a column, or null if not sorted. */
function sortDir(col: ColumnDef<T>): "asc" | "desc" | null {
    const entry = props.sort.find(s => s.key === col.key);
    return entry?.direction ?? null;
}
/** Header checkbox state: true = all selected, false = none, 'indeterminate' = some. */
const headerCheckState = computed(() => {
    if (props.rowIds.length === 0) return false;
    const selectedOnPage = props.rowIds.filter(id => provided.selectedIds.value.includes(id));
    if (selectedOnPage.length === 0) return false;
    if (selectedOnPage.length === props.rowIds.length) return true;
    return "indeterminate";
});
/** Toggle select-all: selects all rows on the page, or deselects if all are already selected. */
function onHeaderCheckbox() {
    provided.togglePageSelection(props.rowIds);
}
</script>

<template>
    <thead class="dt-head" :class="{ 'dt-head--stuck': stuck }">
        <tr>
            <th v-if="selectable" class="dt-head__check" @click.stop>
                <checkbox
                    ref-id="dt-select-all"
                    :checked-initially="headerCheckState === true"
                    :indeterminate="headerCheckState === 'indeterminate'"
                    :label="$t('components.datatable.select_all')"
                    @change="onHeaderCheckbox"
                />
            </th>
            <th
                v-for="col in columns"
                :key="col.key"
                :style="{ width: col.width ?? 'auto' }"
                :aria-sort="ariaSort(col)"
            >
                <button
                    v-if="col.sortable"
                    type="button"
                    class="dt-head__sort-btn"
                    :class="{
                        'dt-head__sort-btn--asc': sortDir(col) === 'asc',
                        'dt-head__sort-btn--desc': sortDir(col) === 'desc'
                    }"
                    @click="emit('sort', col.key)"
                    :style="{ textAlign: col.align ?? 'left' }"
                    :aria-label="$slots[`header-${col.key}`] ? col.label : undefined"
                >
                    <slot :name="`header-${col.key}`" :column="col">{{ col.label }}</slot>
                </button>
                <span v-else :style="{ textAlign: col.align ?? 'left' }">
                    <slot :name="`header-${col.key}`" :column="col">{{ col.label }}</slot>
                </span>
            </th>
            <th v-if="hasActions" class="dt-head__actions">
                <span class="sr-only">{{ $t("components.datatable.actions") }}</span>
            </th>
        </tr>
    </thead>
</template>
