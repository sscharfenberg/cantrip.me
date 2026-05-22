<script setup lang="ts">
import { ref, watch } from "vue";
import Icon from "Components/UI/Icon.vue";
const props = defineProps<{
    /** Current search query from the server response, synced to the input. */
    search: string | null;
    /** Number of currently selected rows, shown as a badge. */
    selectedCount: number;
}>();
const emit = defineEmits<{
    /** Emitted with the debounced search query after the user stops typing. */
    search: [query: string];
}>();
/** Local search input value, debounced before emitting to the parent. */
const query = ref(props.search ?? "");
let debounceTimer: ReturnType<typeof setTimeout> | null = null;
watch(query, value => {
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        emit("search", value);
    }, 600);
});
/** Sync external search prop back to local input (e.g. on Inertia navigation). */
watch(
    () => props.search,
    value => {
        query.value = value ?? "";
    }
);
</script>

<template>
    <div class="dt-toolbar">
        <div class="form-group">
            <div class="form-group__addon"><icon name="search" /></div>
            <input
                v-model="query"
                type="search"
                :placeholder="$t('components.datatable.search_placeholder')"
                :aria-label="$t('components.datatable.search')"
                class="form-input"
            />
        </div>
        <span v-if="selectedCount > 0" class="dt-toolbar__selection">
            {{ $t("components.datatable.items_selected", { count: selectedCount }) }}
        </span>
        <div v-if="$slots.actions" class="dt-toolbar__actions">
            <slot name="actions" />
        </div>
    </div>
</template>
