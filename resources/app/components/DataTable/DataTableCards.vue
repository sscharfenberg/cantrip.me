<script setup lang="ts" generic="T extends { id: string; href?: string }">
import { router } from "@inertiajs/vue3";
import { computed, inject, useSlots } from "vue";
import Checkbox from "Components/Form/Checkbox.vue";
import Icon from "Components/UI/Icon.vue";
import type { ColumnDef } from "Types/dataTable";
import { DATA_TABLE_KEY } from "Types/dataTable";
const props = defineProps<{
    /** Column definitions, used to determine card primary field and visible fields. */
    columns: ColumnDef<T>[];
    /** Data rows for the current page. */
    rows: T[];
    /** Whether to render per-card selection checkboxes. */
    selectable: boolean;
    /** Whether to render the per-card three-dot actions button. */
    hasActions: boolean;
}>();
const emit = defineEmits<{
    /** Emitted when a card's three-dot action button is clicked. */
    action: [row: T, el: HTMLElement];
}>();
const provided = inject(DATA_TABLE_KEY)!;
const slots = useSlots();
/** The primary column shown at the top of each card. First match wins. */
const primaryCol = computed(() => props.columns.find(c => c.cardPrimary) ?? null);
/** Columns visible in card mode, excluding the primary. */
const cardColumns = computed(() => props.columns.filter(c => c.visibleInCard && c.key !== primaryCol.value?.key));
/** Return card columns that have a non-empty value for the given row. */
function visibleCardColumns(row: T) {
    return cardColumns.value.filter(col => {
        const val = row[col.key as keyof T];
        return val !== null && val !== undefined && val !== "" && val !== 0;
    });
}
/** Navigate to the row's detail page when the card is tapped (if href is set). */
function onCardClick(row: T) {
    if (row.href) router.visit(row.href);
}
/** Emit the action event with the row and trigger button for popover anchoring. */
function onActionClick(row: T, event: MouseEvent) {
    emit("action", row, event.currentTarget as HTMLElement);
}
</script>

<template>
    <div class="dt-cards">
        <article
            v-for="row in rows"
            :key="row.id"
            class="dt-cards__card"
            :class="{ 'dt-cards__card--clickable': !!row.href }"
            @click="row.href && onCardClick(row)"
        >
            <div class="dt-cards__header">
                <div v-if="selectable" class="dt-cards__check" @click.stop>
                    <checkbox
                        :ref-id="`dt-card-select-${row.id}`"
                        :checked-initially="provided.selectedIds.value.includes(row.id)"
                        :label="$t('components.datatable.select_row')"
                        @change="provided.toggleSelection(row.id)"
                    />
                </div>
                <div v-if="primaryCol" class="dt-cards__primary">
                    <slot v-if="slots[`cell-${primaryCol.key}`]" :name="`cell-${primaryCol.key}`" :row="row" />
                    <template v-else>{{ row[primaryCol.key] }}</template>
                </div>
                <button
                    v-if="hasActions"
                    type="button"
                    class="dt-cards__action popover-button popover-button--rounded"
                    :style="{ 'anchor-name': `--dt-action-${row.id}` }"
                    @click.stop="onActionClick(row, $event)"
                    :aria-label="$t('components.datatable.row_actions')"
                >
                    <icon name="more" />
                </button>
            </div>
            <dl class="dt-cards__fields">
                <template v-for="col in visibleCardColumns(row)" :key="col.key">
                    <dt class="dt-cards__label">{{ col.label }}</dt>
                    <dd class="dt-cards__value">
                        <slot v-if="slots[`cell-${col.key}`]" :name="`cell-${col.key}`" :row="row" />
                        <template v-else>{{ row[col.key] }}</template>
                    </dd>
                </template>
            </dl>
        </article>
    </div>
</template>
