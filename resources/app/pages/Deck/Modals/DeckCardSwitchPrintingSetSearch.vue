<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, useTemplateRef, watch } from "vue";
import { useI18n } from "vue-i18n";
import type { PrintingSet } from "@/utils/printingSets.ts";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
const props = defineProps<{
    /** Sets matching the current query, best match first — the dropdown's rows. */
    results: PrintingSet[];
    /** Disables the input, e.g. while the printings are still loading. */
    disabled?: boolean;
}>();
/** The applied search text: debounced, trimmed, and empty below {@link MIN_QUERY_LENGTH}. */
const query = defineModel<string>("query", { required: true });
const emit = defineEmits<{
    /** A set was chosen from the dropdown — the caller narrows the printings to it. */
    pick: [set: PrintingSet];
}>();
/** Shortest text that counts as a search. */
const MIN_QUERY_LENGTH = 2;
/** Delay between the last keystroke and the search applying. */
const DEBOUNCE_MS = 250;
/** What the input currently holds, applied to {@link query} once typing pauses. */
const text = ref("");
/** True while a keystroke waits for the debounce — drives the spinner. */
const pending = ref(false);
/** Whether the popover should be visible: a search is applied, so there are hits or the empty-state row. */
const hasContent = computed(() => query.value !== "");
/** Template ref to the popover element so we can imperatively show/hide it. */
const popoverRef = useTemplateRef<HTMLElement>("popoverRef");
/** Template ref to the wrapper — it contains the popover too, which scopes click-outside detection. */
const wrapperRef = useTemplateRef<HTMLDivElement>("wrapperRef");
/** Template ref to the search input, focused through {@link focus}. */
const inputRef = useTemplateRef<HTMLInputElement>("inputRef");
/**
 * Whether the popover is showing. Set by this component as it shows or hides
 * it rather than mirrored from the `toggle` event: that event arrives a task
 * later, and an Enter or Esc pressed in between would act on a stale state. A
 * `popover="manual"` never closes by itself, so nothing else changes it.
 */
const isOpen = ref(false);
const { t } = useI18n();
let debounce: ReturnType<typeof setTimeout> | null = null;
/** Drop a keystroke still waiting for the debounce. */
function cancelDebounce(): void {
    if (debounce) clearTimeout(debounce);
    debounce = null;
    pending.value = false;
}
/** Apply the typed text as the query now, without waiting for the debounce. */
function applyText(): void {
    cancelDebounce();
    const trimmed = text.value.trim();
    query.value = trimmed.length >= MIN_QUERY_LENGTH ? trimmed : "";
}
/**
 * Read the input through `@input` rather than a `v-model` watcher, so that
 * clearing the input after a pick doesn't schedule another search.
 */
function onInput(event: Event): void {
    text.value = (event.target as HTMLInputElement).value;
    if (debounce) clearTimeout(debounce);
    pending.value = true;
    debounce = setTimeout(applyText, DEBOUNCE_MS);
}
/** Open the popover if there's something to show (hits or empty-state) and it isn't already open. */
function openPopover(): void {
    if (!hasContent.value || isOpen.value) return;
    popoverRef.value?.showPopover?.();
    isOpen.value = true;
}
/** Close the popover if it's open. */
function closePopover(): void {
    if (!isOpen.value) return;
    popoverRef.value?.hidePopover?.();
    isOpen.value = false;
}
// Open the popover when a search is applied — unless focus left the input
// while the debounce ran — and close it when the search is cleared.
watch(hasContent, async show => {
    if (show) {
        await nextTick();
        if (document.activeElement === inputRef.value) openPopover();
    } else {
        closePopover();
    }
});
/** Hand the chosen set to the caller and clear the search it came from. */
function pick(set: PrintingSet): void {
    cancelDebounce();
    text.value = "";
    query.value = "";
    closePopover();
    emit("pick", set);
}
/**
 * Enter picks the best match, applying a search still waiting for its
 * debounce first. An Enter that confirms an IME composition is left alone.
 */
async function onEnter(event: KeyboardEvent): Promise<void> {
    if (event.isComposing) return;
    event.preventDefault();
    applyText();
    await nextTick();
    const best = props.results[0];
    if (best) pick(best);
}
/**
 * Esc closes the popover — replaces the `popover="auto"` light-dismiss
 * behavior. Cancelling the keydown keeps the surrounding modal open: a dialog
 * only treats Escape as a close request when the keydown goes uncancelled.
 */
function onEscape(event: KeyboardEvent): void {
    if (!isOpen.value) return;
    event.preventDefault();
    event.stopPropagation();
    closePopover();
}
/** Click anywhere outside the wrapper (input + popover) closes the popover. */
function onDocumentClick(event: MouseEvent): void {
    if (!isOpen.value || wrapperRef.value?.contains(event.target as Node)) return;
    closePopover();
}
/** Focus the search input — the caller does this when the chip it replaced the input with goes away. */
function focus(): void {
    inputRef.value?.focus();
}
defineExpose({ focus });
onMounted(() => document.addEventListener("click", onDocumentClick));
onUnmounted(() => {
    document.removeEventListener("click", onDocumentClick);
    cancelDebounce();
});
</script>

<template>
    <div ref="wrapperRef" class="set-search" @keydown.esc="onEscape">
        <div class="form-group">
            <div class="form-group__input">
                <div class="form-group__slot">
                    <div class="form-group__addon" aria-hidden="true"><icon name="search" /></div>
                    <div class="form-group__field">
                        <input
                            ref="inputRef"
                            :value="text"
                            type="text"
                            class="form-input"
                            autocomplete="off"
                            :placeholder="t('pages.deck.switch_printing.set_search.placeholder')"
                            :aria-label="t('pages.deck.switch_printing.set_search.label')"
                            :disabled="disabled"
                            @input="onInput"
                            @focus="openPopover"
                            @keydown.enter="onEnter"
                        />
                    </div>
                    <loading-spinner v-if="pending" class="form-group--validating colored" :size="1.5" />
                </div>
            </div>
        </div>
        <div ref="popoverRef" popover="manual" class="set-search__results">
            <ul class="set-search__list">
                <li v-if="hasContent && !results.length" class="set-search__empty">
                    <icon name="error" :size="1" />
                    {{ t("pages.deck.switch_printing.set_search.no_results") }}
                </li>
                <li v-for="set in results" :key="set.code">
                    <button type="button" class="set-search__result" @click="pick(set)">
                        <img v-if="set.path" :src="set.path" alt="" class="set-search__icon" />
                        <span class="set-search__code">[{{ set.code.toUpperCase() }}]</span>
                        <span class="set-search__name">{{ set.name }}</span>
                        <span
                            class="set-search__count"
                            :title="t('pages.deck.switch_printing.set_search.count', { count: set.count }, set.count)"
                        >
                            {{ set.count }}
                        </span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/z-indexes" as z;

:deep(.form-group__input) {
    flex-basis: 100%;

    anchor-name: --set-search-input;
}

.set-search {
    position: relative;

    &__results {
        position: fixed;
        inset: unset;
        top: anchor(bottom);
        left: anchor(left);
        z-index: map.get(z.$index, "select");

        overflow: hidden;

        // As wide as the input it drops down from.
        width: anchor-size(width);
        min-width: map.get(s.$pages, "deck", "quick-add", "results-min");
        padding: map.get(s.$pages, "deck", "quick-add", "padding");
        border: 0;
        margin: 0;
        margin-block-start: 4px;

        background-color: map.get(c.$pages, "deck", "quick-add", "background");
        color: map.get(c.$pages, "deck", "quick-add", "surface");
        border-radius: map.get(s.$pages, "deck", "quick-add", "radius");

        position-anchor: --set-search-input;
        position-try-fallbacks: flip-block;

        // Only while open — otherwise this beats the UA's
        // `[popover]:not(:popover-open) { display: none }`.
        &:popover-open {
            display: block;
        }

        &::before {
            position: absolute;
            inset: 0;
            z-index: -1;

            border: map.get(s.$pages, "deck", "quick-add", "border") solid transparent;

            background: linear-gradient(
                    to bottom right,
                    map.get(c.$pages, "deck", "quick-add", "border-from"),
                    map.get(c.$pages, "deck", "quick-add", "border-to")
                )
                border-box;

            border-radius: inherit;
            mask:
                linear-gradient(black, black) border-box,
                linear-gradient(black, black) padding-box;
            mask-composite: subtract;

            content: "";
        }
    }

    &__list {
        display: flex;
        flex-direction: column;

        overflow-y: auto;

        max-height: 40dvh;
        padding: 0;
        margin: 0;

        gap: map.get(s.$pages, "deck", "quick-add", "gap");

        list-style: none;

        > li {
            padding: map.get(s.$pages, "deck", "quick-add", "item", "padding");
        }
    }

    &__result {
        display: flex;
        align-items: center;

        width: 100%;
        padding: map.get(s.$pages, "deck", "quick-add", "button-padding");
        border: 0;
        gap: map.get(s.$pages, "deck", "quick-add", "row-gap");

        background-color: map.get(c.$pages, "deck", "quick-add", "item-background");
        color: map.get(c.$pages, "deck", "quick-add", "item-surface");

        font: inherit;
        text-align: left;

        cursor: pointer;

        &:hover,
        &:focus-visible {
            background-color: map.get(c.$pages, "deck", "quick-add", "item-background-hover");
            color: map.get(c.$pages, "deck", "quick-add", "item-surface-hover");
        }
    }

    &__icon {
        flex-shrink: 0;

        width: map.get(s.$components, "select", "image", "width");
        height: map.get(s.$components, "select", "image", "width");

        object-fit: contain;
    }

    &__code {
        flex-shrink: 0;

        font-variant-numeric: tabular-nums;
    }

    &__name {
        overflow: hidden;
        min-width: 0;

        flex: 1 1 auto;

        white-space: nowrap;
        text-overflow: ellipsis;
    }

    &__count {
        flex-shrink: 0;

        opacity: 0.7;

        font-variant-numeric: tabular-nums;
    }

    &__empty {
        display: flex;
        align-items: center;

        gap: 1ch;
    }
}
</style>

<style lang="scss">
// Set icons are black-filled SVGs; dark mode needs them inverted. Unscoped,
// because the theme-dark mixin roots the selector at :root.
@use "Abstracts/mixins" as m;

@include m.theme-dark(".set-search__icon") {
    filter: invert(1);
}
</style>
