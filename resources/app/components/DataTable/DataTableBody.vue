<script setup lang="ts" generic="T extends { id: string; href?: string }">
import { router } from "@inertiajs/vue3";
import { inject, useSlots } from "vue";
import Checkbox from "Components/Form/Checkbox.vue";
import Icon from "Components/UI/Icon.vue";
import type { ColumnDef } from "Types/dataTable";
import { DATA_TABLE_KEY } from "Types/dataTable";
defineProps<{
    /** Column definitions controlling which cells to render per row. */
    columns: ColumnDef<T>[];
    /** Data rows for the current page. */
    rows: T[];
    /** Whether to render per-row selection checkboxes. */
    selectable: boolean;
    /** Whether to render the per-row three-dot actions button. */
    hasActions: boolean;
}>();
const emit = defineEmits<{
    /** Emitted when a row's three-dot action button is clicked. */
    action: [row: T, el: HTMLElement];
}>();
const provided = inject(DATA_TABLE_KEY)!;
const slots = useSlots();
/** Navigate to the row's detail page when the row is clicked (if href is set). */
function onRowClick(row: T) {
    if (row.href) router.visit(row.href);
}
/** Emit the action event with the row and the trigger button element for popover anchoring. */
function onActionClick(row: T, event: MouseEvent) {
    emit("action", row, event.currentTarget as HTMLElement);
}
</script>

<template>
    <tbody class="dt-body">
        <tr
            v-for="row in rows"
            :key="row.id"
            :class="{ 'dt-body__row--clickable': !!row.href }"
            @click="row.href && onRowClick(row)"
        >
            <td v-if="selectable" class="dt-body__check" @click.stop>
                <checkbox
                    :ref-id="`dt-select-${row.id}`"
                    :checked-initially="provided.selectedIds.value.includes(row.id)"
                    :label="$t('components.datatable.select_row')"
                    @change="provided.toggleSelection(row.id)"
                />
            </td>
            <td
                v-for="col in columns"
                :key="col.key"
                :class="col.cellClass"
                :style="{ textAlign: col.align ?? 'left' }"
            >
                <slot v-if="slots[`cell-${col.key}`]" :name="`cell-${col.key}`" :row="row" />
                <template v-else>{{ row[col.key] }}</template>
            </td>
            <td v-if="hasActions" class="dt-body__actions">
                <button
                    type="button"
                    class="popover-button popover-button--rounded"
                    :style="{ 'anchor-name': `--dt-action-${row.id}` }"
                    @click.stop="onActionClick(row, $event)"
                    :aria-label="$t('components.datatable.row_actions')"
                >
                    <icon name="more" />
                </button>
            </td>
        </tr>
    </tbody>
</template>
