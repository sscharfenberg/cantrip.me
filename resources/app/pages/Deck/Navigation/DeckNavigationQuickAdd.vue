<script setup lang="ts">
import { router, usePage } from "@inertiajs/vue3";
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, useTemplateRef, watch } from "vue";
import ColorIdentity from "Components/Card/ColorIdentity.vue";
import ManaCost from "Components/Card/ManaCost.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import { markRecentlyAdded } from "Composables/useRecentlyAdded.ts";
import type { DeckCardRow, QuickAddCardResult } from "Types/deckPage.ts";
const props = defineProps<{
    /** Deck UUID — used to build the search endpoint URL. */
    deckId: string;
    /** Whether the deck's format enforces color identity — gates the badge in result rows. */
    enforcesColorIdentity: boolean;
    /** Format max copies per card (1 for singleton, 4 for most constructed). */
    maxCopies: number;
    /** All deck cards — used after add to count copies and prune maxed-out result rows. */
    cards: DeckCardRow[];
}>();
/** Quick-add input value, debounced before triggering a search. */
const quickAddQuery = ref("");
/** Current results from the most recent successful fetch. */
const results = ref<QuickAddCardResult[]>([]);
/** True while a search request is in flight. */
const processing = ref(false);
/** True when the most recent search completed with zero hits — drives the empty-state row. */
const noResults = ref(false);
/** Whether the popover should be visible: there are hits OR a finished search produced none. */
const hasContent = computed(() => results.value.length > 0 || noResults.value);
/** Template ref to the popover element so we can imperatively show/hide it. */
const popoverRef = useTemplateRef<HTMLElement>("popoverRef");
/** Template ref to the wrapper — used to scope click-outside detection. */
const wrapperRef = useTemplateRef<HTMLDivElement>("wrapperRef");
/** Mirror of the browser's popover-open state, kept in sync via the `toggle` event. */
const isOpen = ref(false);
/** Per-row UI state for the add-card flow, keyed by oracle_card_id. */
const rowState = reactive<Record<string, "adding" | "added">>({});
const page = usePage();
let quickAddDebounce: ReturnType<typeof setTimeout> | null = null;
/** Cancels stale in-flight requests when a new search fires. */
let abortController: AbortController | null = null;
async function searchOracleCards(q: string): Promise<void> {
    if (q.trim().length < 2) {
        results.value = [];
        noResults.value = false;
        return;
    }
    if (abortController) abortController.abort();
    abortController = new AbortController();
    processing.value = true;
    try {
        const params = new URLSearchParams({ q });
        const response = await fetch(`/api/decks/${props.deckId}/oracle-cards?${params}`, {
            signal: abortController.signal
        });
        if (response.ok) {
            const data = (await response.json()) as QuickAddCardResult[];
            results.value = data;
            noResults.value = data.length === 0;
        }
    } catch (e) {
        if (e instanceof DOMException && e.name === "AbortError") return;
        throw e;
    } finally {
        processing.value = false;
    }
}
watch(quickAddQuery, value => {
    if (quickAddDebounce) clearTimeout(quickAddDebounce);
    quickAddDebounce = setTimeout(() => {
        void searchOracleCards(value);
    }, 500);
});
/** Open the popover if there's something to show (hits or empty-state) and it isn't already open. */
function openPopover(): void {
    if (!hasContent.value || isOpen.value) return;
    popoverRef.value?.showPopover?.();
}
/** Close the popover if it's open. */
function closePopover(): void {
    if (!isOpen.value) return;
    popoverRef.value?.hidePopover?.();
}
// Open the popover when there's content (hits or empty-state), close when neither.
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
/** Re-open the popover when the input regains focus, if there are results. */
function onInputFocus(): void {
    openPopover();
}
/** Esc closes the popover — replaces the `popover="auto"` light-dismiss behavior. */
function onKeyDown(event: KeyboardEvent): void {
    if (event.key === "Escape" && isOpen.value) {
        closePopover();
    }
}
/** Click anywhere outside the wrapper (input + popover) closes the popover. */
function onDocumentClick(event: MouseEvent): void {
    if (!isOpen.value) return;
    const target = event.target as Node;
    if (wrapperRef.value?.contains(target) || popoverRef.value?.contains(target)) return;
    closePopover();
}
/**
 * Add the clicked card to the deck (zone: main). The row swaps to a tick +
 * "added" confirmation while the request is in flight and for ~800ms after,
 * then reverts. The button is disabled the whole time so a second click
 * can't fire a duplicate add. On success, the deck-card list is reloaded
 * and the new row is briefly highlighted via {@link markRecentlyAdded}.
 */
async function addCard(card: QuickAddCardResult): Promise<void> {
    if (!card.default_card_id || rowState[card.id]) return;
    rowState[card.id] = "adding";
    try {
        const response = await fetch(`/api/decks/${props.deckId}/cards`, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": page.props.csrfToken as string
            },
            body: JSON.stringify({
                default_card_id: card.default_card_id,
                zone: "main"
            })
        });
        if (!response.ok) {
            delete rowState[card.id];
            return;
        }
        rowState[card.id] = "added";
        router.reload({
            only: ["cards", "deck", "violations"],
            onSuccess: () => markRecentlyAdded(card.id)
        });
        setTimeout(() => {
            delete rowState[card.id];
            // Once the flash is over, drop the row from results if the card
            // has hit the format's per-card max — singleton (1 copy max) or
            // constructed 4-of. Basic lands and "any number of cards named X"
            // cards are exempt and stay in the popover indefinitely.
            const exempt = card.is_basic_land || card.has_unlimited_copies;
            if (exempt) return;
            const inDeckCount = props.cards
                .filter(c => c.oracle_card_id === card.id)
                .reduce((sum, c) => sum + c.quantity, 0);
            if (inDeckCount >= props.maxCopies) {
                results.value = results.value.filter(r => r.id !== card.id);
            }
        }, 800);
    } catch {
        delete rowState[card.id];
    }
}
onMounted(() => {
    document.addEventListener("keydown", onKeyDown);
    document.addEventListener("click", onDocumentClick);
});
onUnmounted(() => {
    document.removeEventListener("keydown", onKeyDown);
    document.removeEventListener("click", onDocumentClick);
});
</script>

<template>
    <div ref="wrapperRef" class="quickadd">
        <div class="form-group">
            <div class="form-group__input">
                <div class="form-group__slot">
                    <div class="form-group__field">
                        <input
                            v-model="quickAddQuery"
                            type="text"
                            class="form-input"
                            :placeholder="$t('pages.deck.quick_add.label')"
                            @focus="onInputFocus"
                        />
                    </div>
                    <loading-spinner v-if="processing" class="form-group--validating colored" :size="1.5" />
                </div>
            </div>
        </div>
        <div ref="popoverRef" popover="manual" class="quickadd__results" @toggle="onToggle">
            <ul class="quickadd__list">
                <li v-if="noResults" class="quickadd__empty">
                    <icon name="error" :size="1" />
                    {{ $t("pages.deck.quick_add.no_results") }}
                </li>
                <li v-for="card in results" :key="card.id">
                    <button
                        type="button"
                        class="quickadd__result"
                        :disabled="!!rowState[card.id] || !card.default_card_id"
                        @click="addCard(card)"
                    >
                        <span class="quickadd__row">
                            <span class="quickadd__name">{{ card.name }}</span>
                            <color-identity v-if="enforcesColorIdentity" :color-identity="card.color_identity" />
                        </span>
                        <span v-if="rowState[card.id] === 'added'" class="quickadd__row quickadd__added">
                            <icon name="check" :size="1" />
                            {{ $t("pages.deck.quick_add.added") }}
                        </span>
                        <span v-else class="quickadd__row">
                            <template v-for="(face, fi) in card.faces" :key="fi">
                                <span v-if="fi > 0" class="quickadd__face-sep">//</span>
                                <span class="quickadd__type">{{ face.type_line }}</span>
                                <mana-cost :mana-cost="face.mana_cost" />
                            </template>
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

    anchor-name: --quickadd-input;
}

.quickadd {
    position: relative;

    &__results {
        position: fixed;
        inset: unset;
        top: anchor(bottom);
        left: anchor(left);
        z-index: map.get(z.$index, "select");

        overflow: hidden;

        min-width: map.get(s.$pages, "deck", "quick-add", "results-min");
        max-width: map.get(s.$pages, "deck", "quick-add", "results-max");
        padding: map.get(s.$pages, "deck", "quick-add", "padding");
        border: 0;
        margin: 0;
        margin-block-start: 4px;

        background-color: map.get(c.$pages, "deck", "quick-add", "background");
        color: map.get(c.$pages, "deck", "quick-add", "surface");
        border-radius: map.get(s.$pages, "deck", "quick-add", "radius");

        /**
         * fallbacks are tried in order — first flip-block (vertical only), then flip-inline (horizontal only),
         * then the combined flip for corner cases where neither single flip fits.
         */
        position-anchor: --quickadd-input;
        position-try-fallbacks:
            flip-block,
            flip-inline,
            flip-block flip-inline;

        /**
         * Only apply flex layout when open — otherwise our scoped specificity beats the UA's
         * `[popover]:not(:popover-open) { display: none }` rule and the list stays visible
         * after light dismiss.
         */
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
        flex-direction: column;

        width: 100%;
        padding: map.get(s.$pages, "deck", "quick-add", "button-padding");
        border: 0;
        gap: map.get(s.$pages, "deck", "quick-add", "gap");

        background-color: map.get(c.$pages, "deck", "quick-add", "item-background");

        text-align: left;

        cursor: pointer;

        &:hover {
            background-color: map.get(c.$pages, "deck", "quick-add", "item-background-hover");
            color: map.get(c.$pages, "deck", "quick-add", "item-surface-hover");
        }
    }

    &__row {
        display: flex;

        gap: map.get(s.$pages, "deck", "quick-add", "row-gap");

        :deep(.color-identity) {
            margin-left: auto;
        }
    }
}
</style>
