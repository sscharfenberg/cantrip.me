<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from "vue";
import { searchSets, setsOfPrintings } from "@/utils/printingSets.ts";
import type { PrintingSet } from "@/utils/printingSets.ts";
import CardFaceImage from "Components/Card/CardFaceImage.vue";
import NumVisible from "Components/Card/CardSearch/NumVisible.vue";
import Switch from "Components/Form/Switch.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import type { DeckPrinting } from "Types/defaultCardImage.ts";
import DeckCardSwitchPrintingSetSearch from "./DeckCardSwitchPrintingSetSearch.vue";
const emit = defineEmits<{
    close: [];
    /** Fired when the user picks a new printing — the caller owns the PATCH + any optimistic state updates. */
    select: [printing: DeckPrinting];
}>();
const props = defineProps<{
    /** URL of the GET endpoint that returns `DeckPrinting[]`. */
    printingsUrl: string;
    /** Card name, interpolated into the modal title. */
    name: string;
}>();
/** Number of printings rendered per scroll batch. */
const PAGE_SIZE = 20;
/** True while the printings XHR is in flight. */
const loading = ref(true);
/** True when the printings fetch failed. */
const error = ref(false);
/** Printings returned by the API, newest set first. */
const printings = ref<DeckPrinting[]>([]);
/** When true, only printings the user owns in a non-deckbox container are shown. */
const onlyCollection = ref(false);
/** Printings left by the `onlyCollection` toggle — the pool the set search searches. */
const availablePrintings = computed<DeckPrinting[]>(() =>
    onlyCollection.value ? printings.value.filter(p => p.in_collection) : printings.value
);
/** The available printings' sets, newest first, each with its printing count. */
const sets = computed<PrintingSet[]>(() => setsOfPrintings(availablePrintings.value));
/** The applied set search: debounced by the search input, empty while nothing is searched. */
const setQuery = ref("");
/** The set picked from the search's dropdown, shown as a chip. Never set while `setQuery` is. */
const selectedSet = ref<PrintingSet | null>(null);
/** Sets matching the search, best match first — the dropdown's rows. */
const matchingSets = computed<PrintingSet[]>(() => (setQuery.value ? searchSets(sets.value, setQuery.value) : []));
/**
 * Printings narrowed by the toggle and then by the picked set — or, while
 * nothing is picked, by every set the dropdown offers. The current printing
 * is pinned to the top so the user sees their current selection first.
 */
const filteredPrintings = computed<DeckPrinting[]>(() => {
    const codes = selectedSet.value
        ? new Set([selectedSet.value.code])
        : setQuery.value
          ? new Set(matchingSets.value.map(set => set.code))
          : null;
    return availablePrintings.value
        .filter(p => !codes || codes.has(p.set.code))
        .sort((a, b) => Number(b.is_current) - Number(a.is_current));
});
// Typing a new search replaces the picked set.
watch(setQuery, query => {
    if (query) selectedSet.value = null;
});
/** The set search, refocused when its chip is removed. */
const setSearch = useTemplateRef<InstanceType<typeof DeckCardSwitchPrintingSetSearch>>("setSearch");
/** Remove the picked set's chip and hand focus back to the search, so it isn't lost with the chip. */
function removeSelectedSet(): void {
    selectedSet.value = null;
    setSearch.value?.focus();
}
/** How many printings are currently visible (grows as the user scrolls). */
const visibleCount = ref(PAGE_SIZE);
/** The slice of printings currently rendered in the DOM. */
const visiblePrintings = computed<DeckPrinting[]>(() => filteredPrintings.value.slice(0, visibleCount.value));
/** The printings list's own scroll container — the toolbar above it stays put. */
const scroller = ref<HTMLElement | null>(null);
/** Ref to the invisible sentinel element that triggers infinite scroll. */
const sentinel = ref<HTMLElement | null>(null);
/**
 * Restart from the top of the first batch whenever a filter changes which
 * printings are listed — keyed by id, so a longer search that still matches
 * the same sets leaves the scroll position alone.
 */
watch(
    () => filteredPrintings.value.map(p => p.id).join(),
    () => {
        visibleCount.value = PAGE_SIZE;
        if (scroller.value) scroller.value.scrollTop = 0;
    }
);
let observer: IntersectionObserver | null = null;
/**
 * Observe the sentinel element. When it enters the viewport, load the next
 * batch of printings. The observer is recreated whenever the sentinel mounts.
 * Uses the list's scroll container as root so its intersections are detected.
 */
watch(sentinel, el => {
    observer?.disconnect();
    if (!el) return;
    const root = scroller.value;
    observer = new IntersectionObserver(
        entries => {
            // Skip fires while the user hasn't scrolled — the sentinel can
            // already be inside the scroll container's visible area on mount
            // if the first batch doesn't overflow yet.
            if (root !== null && root.scrollTop === 0) return;
            if (entries[0]?.isIntersecting && visibleCount.value < filteredPrintings.value.length) {
                visibleCount.value = Math.min(visibleCount.value + PAGE_SIZE, filteredPrintings.value.length);
            }
        },
        { root }
    );
    observer.observe(el);
});
/** AbortController so the in-flight request is cancelled if the modal is unmounted. */
let abortController: AbortController | null = null;
onMounted(async () => {
    abortController = new AbortController();
    try {
        const response = await fetch(props.printingsUrl, {
            headers: { Accept: "application/json" },
            signal: abortController.signal
        });
        if (response.ok) {
            printings.value = (await response.json()) as DeckPrinting[];
        } else {
            error.value = true;
        }
    } catch (e) {
        if (e instanceof DOMException && e.name === "AbortError") return;
        error.value = true;
    } finally {
        loading.value = false;
    }
});
onBeforeUnmount(() => {
    if (abortController) abortController.abort();
    observer?.disconnect();
});
/**
 * Bubble the selected printing up to the caller and close. The caller owns
 * the PATCH + any optimistic state updates — the modal is a pure picker.
 */
function pickPrinting(printing: DeckPrinting): void {
    if (printing.is_current) return;
    emit("select", printing);
    emit("close");
}
</script>

<template>
    <modal inner-scroll @close="emit('close')">
        <template #header>
            <i18n-t keypath="pages.deck.switch_printing.title" scope="global">
                <template #card
                    ><cite>{{ name }}</cite></template
                >
            </i18n-t>
        </template>
        <div class="switch-printing">
            <div class="switch-printing__toolbar">
                <div class="switch-printing__available">
                    <Switch
                        ref-id="only_collection_printings"
                        :checked-initially="onlyCollection"
                        @change="onlyCollection = $event"
                    />
                    <label for="only_collection_printings">
                        {{ $t("pages.deck.switch_printing.only_collection_printings") }}
                    </label>
                </div>
                <deck-card-switch-printing-set-search
                    ref="setSearch"
                    v-model:query="setQuery"
                    class="switch-printing__search"
                    :results="matchingSets"
                    :disabled="loading || error"
                    @pick="selectedSet = $event"
                />
                <div v-if="selectedSet || filteredPrintings.length > PAGE_SIZE" class="switch-printing__status">
                    <button
                        v-if="selectedSet"
                        type="button"
                        class="badge info switch-printing__chip"
                        :aria-label="$t('pages.deck.switch_printing.set_search.remove', { set: selectedSet.name })"
                        @click="removeSelectedSet"
                    >
                        <img
                            v-if="selectedSet.path"
                            :src="selectedSet.path"
                            alt=""
                            class="switch-printing__chip-icon"
                        />
                        [{{ selectedSet.code.toUpperCase() }}] {{ selectedSet.name }}
                        <icon name="clear" :size="0" />
                    </button>
                    <num-visible
                        v-if="filteredPrintings.length > PAGE_SIZE"
                        class="switch-printing__count"
                        :visible-count="Math.min(visibleCount, filteredPrintings.length)"
                        :num-total-results="filteredPrintings.length"
                    />
                </div>
            </div>
            <div ref="scroller" class="switch-printing__scroller">
                <div v-if="loading" class="switch-printing__loading">
                    <loading-spinner :size="4" :branded="true" />
                    <p>{{ $t("pages.deck.switch_printing.loading") }}</p>
                </div>
                <div v-else-if="error" class="switch-printing__error">
                    <icon name="error" :size="2" />
                    <p>{{ $t("pages.deck.switch_printing.error") }}</p>
                </div>
                <div v-else-if="!filteredPrintings.length" class="switch-printing__empty">
                    <icon name="filter-off" :size="2" />
                    <p>{{ $t("pages.deck.switch_printing.no_printings") }}</p>
                </div>
                <template v-else>
                    <div class="switch-printing__list">
                        <button
                            v-for="printing in visiblePrintings"
                            type="button"
                            :key="printing.id"
                            :class="{ 'switch-printing__list-item--current': printing.is_current }"
                            @click="pickPrinting(printing)"
                        >
                            <card-face-image :card="printing" tooltip-container="#modal-body" />
                            <span
                                v-if="printing.is_current"
                                class="switch-printing__current-badge"
                                :aria-label="$t('pages.deck.switch_printing.currently_selected')"
                            >
                                <icon name="check" :size="3" />
                                {{ $t("pages.deck.switch_printing.currently_selected") }}
                            </span>
                            <!--                <span v-if="printing.in_collection">{{ $t("pages.deck.switch_printing.in_collection") }}</span>-->
                            <!--                <span v-else>{{ $t("pages.deck.switch_printing.not_in_collection") }}</span>-->
                        </button>
                    </div>
                    <div ref="sentinel" class="switch-printing__sentinel" />
                </template>
            </div>
        </div>
    </modal>
</template>

<style lang="scss" scoped>
/**
 * other styles are in resources/app/styles/components/deck/_switch-printing.scss
 */
// The pill's own bottom margin spaces it from a results grid; the toolbar
// already spaces its rows.
.switch-printing__count:deep(.results__count) {
    margin: 0;
}
</style>
