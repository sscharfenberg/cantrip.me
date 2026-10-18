<script setup lang="ts">
/******************************************************************************
 * Oracle card lookup on the welcome page: a search field like the data
 * tables' that, after a pause in typing, lists every oracle card matching
 * the query — by name in any printed language, and with the collection
 * search's `set:` / `number:` shortcuts — in a popover styled like the deck
 * page's quick add. Picking a card opens its detail modal.
 *
 * The popover handling mirrors the quick add's: a manual popover anchored
 * under the input, opened when there is something to show, closed by
 * Escape or a click outside, reopened when the input gets focus again.
 *****************************************************************************/
import { computed, nextTick, onMounted, onUnmounted, ref, useTemplateRef, watch } from "vue";
import { useI18n } from "vue-i18n";
import CardPreviewModal from "Components/Card/CardPreviewModal.vue";
import ColorIdentity from "Components/Card/ColorIdentity.vue";
import ManaCost from "Components/Card/ManaCost.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import type { OracleLookupCard, OracleLookupResponse } from "Types/oracleLookup.ts";
/** How long typing has to pause before the lookup runs — the quick add's delay. */
const DEBOUNCE_MS = 500;
const { t } = useI18n();
const query = ref("");
/** Cards from the most recent finished lookup. */
const results = ref<OracleLookupCard[]>([]);
/** True when more cards matched than the lookup returned. */
const truncated = ref(false);
/** True while a lookup is in flight. */
const processing = ref(false);
/** True when the most recent lookup finished with no cards — drives the empty-state row. */
const noResults = ref(false);
const hasContent = computed(() => results.value.length > 0 || noResults.value);
const popoverRef = useTemplateRef<HTMLElement>("popoverRef");
const wrapperRef = useTemplateRef<HTMLDivElement>("wrapperRef");
const inputRef = useTemplateRef<HTMLInputElement>("inputRef");
/** Mirror of the popover's open state, kept in sync through its `toggle` event. */
const isOpen = ref(false);
/** Preview-modal endpoint, or null while the modal is closed. */
const previewUrl = ref<string | null>(null);
let debounce: ReturnType<typeof setTimeout> | null = null;
/** Cancels a lookup still in flight when a newer one starts. */
let abortController: AbortController | null = null;
/**
 * Run a lookup. Anything shorter than two characters clears the results
 * instead — the server would find nothing either.
 */
async function lookup(q: string): Promise<void> {
    abortController?.abort();
    if (q.trim().length < 2) {
        results.value = [];
        truncated.value = false;
        noResults.value = false;
        processing.value = false;
        return;
    }
    const controller = new AbortController();
    abortController = controller;
    processing.value = true;
    try {
        const response = await fetch(`/api/cards/lookup?${new URLSearchParams({ q })}`, {
            headers: { Accept: "application/json" },
            signal: controller.signal
        });
        if (!response.ok) return;
        const data = (await response.json()) as OracleLookupResponse;
        results.value = data.cards;
        truncated.value = data.truncated;
        noResults.value = data.cards.length === 0;
    } catch (e) {
        if (e instanceof DOMException && e.name === "AbortError") return;
        throw e;
    } finally {
        // Only the newest lookup may end the spinner — an aborted one
        // finishing late must not hide it while its successor still runs.
        if (abortController === controller) processing.value = false;
    }
}
watch(query, value => {
    if (debounce) clearTimeout(debounce);
    debounce = setTimeout(() => void lookup(value), DEBOUNCE_MS);
});
function openPopover(): void {
    if (!hasContent.value || isOpen.value) return;
    popoverRef.value?.showPopover?.();
}
function closePopover(): void {
    if (!isOpen.value) return;
    popoverRef.value?.hidePopover?.();
}
watch(hasContent, async show => {
    if (show && !isOpen.value) {
        await nextTick();
        openPopover();
    } else if (!show && isOpen.value) {
        closePopover();
    }
});
function onToggle(event: Event): void {
    isOpen.value = (event as ToggleEvent).newState === "open";
}
function onKeyDown(event: KeyboardEvent): void {
    if (event.key === "Escape") closePopover();
}
function onDocumentClick(event: MouseEvent): void {
    if (!isOpen.value) return;
    const target = event.target as Node;
    if (wrapperRef.value?.contains(target)) return;
    closePopover();
}
/** Open a card's detail modal; the results stay, to come back to once it closes. */
function openCard(card: OracleLookupCard): void {
    if (!card.printing_id) return;
    closePopover();
    previewUrl.value = `/cards/${card.printing_id}/preview`;
}
/** Back from the modal: focusing the input reopens the results. */
function closeCard(): void {
    previewUrl.value = null;
    void nextTick(() => inputRef.value?.focus());
}
onMounted(() => {
    document.addEventListener("keydown", onKeyDown);
    document.addEventListener("click", onDocumentClick);
});
onUnmounted(() => {
    document.removeEventListener("keydown", onKeyDown);
    document.removeEventListener("click", onDocumentClick);
    if (debounce) clearTimeout(debounce);
    abortController?.abort();
});
</script>

<template>
    <div ref="wrapperRef" class="oracle-lookup">
        <div class="form-group">
            <div class="form-group__input">
                <div class="form-group__slot">
                    <div class="form-group__addon" aria-hidden="true"><icon name="search" /></div>
                    <div class="form-group__field">
                        <input
                            ref="inputRef"
                            v-model="query"
                            type="search"
                            class="form-input"
                            :placeholder="t('pages.welcome.lookup.placeholder')"
                            :aria-label="t('pages.welcome.lookup.label')"
                            @focus="openPopover"
                        />
                    </div>
                    <loading-spinner v-if="processing" class="form-group--validating colored" :size="1.5" />
                </div>
            </div>
        </div>
        <div ref="popoverRef" popover="manual" class="oracle-lookup__results" @toggle="onToggle">
            <ul class="oracle-lookup__list">
                <li v-if="noResults" class="oracle-lookup__note">
                    <icon name="error" :size="1" />
                    {{ t("pages.welcome.lookup.no_results") }}
                </li>
                <li v-for="card in results" :key="card.id">
                    <button
                        type="button"
                        class="oracle-lookup__result"
                        :disabled="!card.printing_id"
                        @click="openCard(card)"
                    >
                        <span class="oracle-lookup__row">
                            <span class="oracle-lookup__name">{{ card.name }}</span>
                            <color-identity :color-identity="card.color_identity" />
                        </span>
                        <span class="oracle-lookup__row oracle-lookup__faces">
                            <template v-for="(face, fi) in card.faces" :key="fi">
                                <span v-if="fi > 0" class="oracle-lookup__face-sep">//</span>
                                <span class="oracle-lookup__face">
                                    <span class="oracle-lookup__type">{{ face.type_line }}</span>
                                    <mana-cost :mana-cost="face.mana_cost" />
                                </span>
                            </template>
                        </span>
                    </button>
                </li>
                <li v-if="truncated" class="oracle-lookup__note">
                    <icon name="info" :size="1" />
                    {{ t("pages.welcome.lookup.truncated", { count: results.length }) }}
                </li>
            </ul>
        </div>
        <card-preview-modal v-if="previewUrl" :preview-url="previewUrl" @close="closeCard" />
    </div>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/z-indexes" as z;

:deep(.form-group__input) {
    flex-basis: 100%;

    anchor-name: --oracle-lookup-input;
}

.oracle-lookup {
    position: relative;

    &__results {
        position: fixed;
        inset: unset;
        top: anchor(bottom);
        left: anchor(left);
        z-index: map.get(z.$index, "select");

        overflow: hidden;

        // Wider than the quick add, but never wider than the viewport less
        // its gutters — on a phone the list takes the full width.
        width: min(#{map.get(s.$pages, "welcome", "lookup", "results-max")}, calc(100vw - 2rem));
        min-width: min(#{map.get(s.$pages, "welcome", "lookup", "results-min")}, calc(100vw - 2rem));
        padding: map.get(s.$pages, "welcome", "lookup", "padding");
        border: 0;
        margin: 0;
        margin-block-start: 4px;

        background-color: map.get(c.$pages, "welcome", "lookup", "background");
        color: map.get(c.$pages, "welcome", "lookup", "surface");
        border-radius: map.get(s.$pages, "welcome", "lookup", "radius");

        position-anchor: --oracle-lookup-input;
        position-try-fallbacks:
            flip-block,
            flip-inline,
            flip-block flip-inline;

        // Only while open — otherwise this beats the UA's
        // `[popover]:not(:popover-open) { display: none }`.
        &:popover-open {
            display: block;
        }

        &::before {
            position: absolute;
            inset: 0;
            z-index: -1;

            border: map.get(s.$pages, "welcome", "lookup", "border") solid transparent;

            background: linear-gradient(
                    to bottom right,
                    map.get(c.$pages, "welcome", "lookup", "border-from"),
                    map.get(c.$pages, "welcome", "lookup", "border-to")
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

        gap: map.get(s.$pages, "welcome", "lookup", "gap");

        list-style: none;

        > li {
            padding: map.get(s.$pages, "welcome", "lookup", "item", "padding");
        }
    }

    &__result {
        display: flex;
        flex-direction: column;

        width: 100%;
        padding: map.get(s.$pages, "welcome", "lookup", "button-padding");
        border: 0;
        gap: map.get(s.$pages, "welcome", "lookup", "gap");

        background-color: map.get(c.$pages, "welcome", "lookup", "item-background");
        color: map.get(c.$pages, "welcome", "lookup", "item-surface");

        font: inherit;
        text-align: left;

        cursor: pointer;

        &:hover,
        &:focus-visible {
            background-color: map.get(c.$pages, "welcome", "lookup", "item-background-hover");
            color: map.get(c.$pages, "welcome", "lookup", "item-surface-hover");
        }

        &:disabled {
            cursor: default;
        }
    }

    &__row {
        display: flex;
        align-items: center;

        gap: map.get(s.$pages, "welcome", "lookup", "row-gap");

        :deep(.color-identity) {
            margin-left: auto;
        }
    }

    // A double-faced card's faces wrap onto a second line rather than squeeze
    // when the list is narrow; each face keeps its type and cost together.
    &__faces {
        flex-wrap: wrap;

        row-gap: 0;
    }

    &__face {
        display: inline-flex;
        align-items: center;

        gap: map.get(s.$pages, "welcome", "lookup", "row-gap");
    }

    &__note {
        display: flex;
        align-items: center;

        gap: 1ch;
    }
}
</style>
