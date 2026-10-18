<script setup lang="ts">
/******************************************************************************
 * The −/+ pair at the top of a card stack's row actions in the collection
 * and container tables, as on the deck page. "−" on the last card is a
 * delete, so it asks the table to open the delete confirmation instead —
 * and says so in its label. Key it by row id (see `useCardStackAmount`).
 *****************************************************************************/
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import { type AmountRow, useCardStackAmount } from "Composables/useCardStackAmount.ts";
const props = defineProps<{
    /** The stack the menu is open for. */
    row: AmountRow;
    /** The rows the table shows — the one with `row.id` gets the new amount at once. */
    rows: AmountRow[];
    /** Page props holding the page's sums, reloaded after each saved change. */
    totals: string[];
}>();
/** @emits delete — "−" was clicked on the last card. */
const emit = defineEmits<{ delete: [] }>();
const { amount, canIncrement, increment, decrement } = useCardStackAmount({
    row: props.row,
    rows: () => props.rows,
    totals: props.totals
});
const { t } = useI18n();
/** Set on the first click, so a screen reader hears the new amount — and nothing on open. */
const changed = ref(false);
const announcement = computed(() => (changed.value ? t("pages.collection.amount.announce", amount.value) : ""));
const onIncrement = (): void => {
    increment();
    changed.value = true;
};
const onDecrement = (): void => {
    if (decrement()) changed.value = true;
    else emit("delete");
};
</script>

<template>
    <li class="popover-list-multi">
        <button
            type="button"
            class="popover-list-item"
            :aria-label="amount > 1 ? $t('pages.collection.amount.decrement') : $t('pages.container_page.delete.link')"
            @click="onDecrement"
        >
            <icon name="subtract" :size="1" />
        </button>
        <button
            type="button"
            class="popover-list-item"
            :disabled="!canIncrement"
            :aria-label="$t('pages.collection.amount.increment')"
            @click="onIncrement"
        >
            <icon name="add" :size="1" />
        </button>
        <span class="sr-only" aria-live="polite">{{ announcement }}</span>
    </li>
</template>
