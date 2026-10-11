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
    /** Emitted with the debounced, trimmed search query after the user stops typing. */
    search: [query: string];
}>();
/** How long typing has to pause before the search is sent. */
const DEBOUNCE_MS = 750;
/**
 * Shortest query worth sending — a single letter matches nearly every row.
 * An emptied input is still sent, since that clears the search.
 */
const MIN_QUERY_LENGTH = 2;
/** Local search input value, debounced before emitting to the parent. */
const query = ref(props.search ?? "");
/**
 * The query the table is showing or loading results for — the last one this
 * input sent, or the one the server arrived with. Trimmed, because Laravel's
 * TrimStrings middleware trims it on the way in and echoes it back that way.
 */
let sentQuery = (props.search ?? "").trim();
let debounceTimer: ReturnType<typeof setTimeout> | null = null;
/** Drop a debounced search that has not gone out yet. */
function cancelPending() {
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = null;
}
/**
 * The query to send for what the input holds now, or null when there is
 * nothing to send: it is too short, or it is what the table already shows —
 * typing back to the current search or only adding whitespace would just
 * fetch the same rows again.
 */
function sendableQuery(): string | null {
    const trimmed = query.value.trim();
    if (trimmed === sentQuery) return null;
    if (trimmed !== "" && trimmed.length < MIN_QUERY_LENGTH) return null;
    return trimmed;
}
function send(value: string) {
    cancelPending();
    sentQuery = value;
    emit("search", value);
}
watch(query, () => {
    cancelPending();
    const next = sendableQuery();
    if (next === null) return;
    debounceTimer = setTimeout(() => send(next), DEBOUNCE_MS);
});
/**
 * Enter searches right away instead of waiting out the debounce. Not while
 * an IME composition is open, though — there Enter confirms the composed
 * characters, which have not reached the input's value yet.
 */
function onEnter(event: KeyboardEvent) {
    if (event.isComposing) return;
    const next = sendableQuery();
    if (next !== null) send(next);
}
/**
 * Sync an external search change back to the input (browser back/forward, a
 * link carrying `?search=`). The server's echo of a query this input sent is
 * not one: the user has often typed on while it was loading, and copying the
 * echo into the input would swallow those keystrokes — or, since the echo is
 * trimmed, the space they just typed between two words.
 */
watch(
    () => props.search,
    value => {
        const incoming = (value ?? "").trim();
        if (debounceTimer || incoming === sentQuery) return;
        sentQuery = incoming;
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
                @keydown.enter="onEnter"
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
