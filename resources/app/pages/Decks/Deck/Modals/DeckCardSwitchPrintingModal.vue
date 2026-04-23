<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import CardFaceImage from "Components/Card/CardFaceImage.vue";
import NumVisible from "Components/Card/CardSearch/NumVisible.vue";
import FormGroup from "Components/Form/FormGroup.vue";
import Switch from "Components/Form/Switch.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import type { DeckPrinting } from "Types/defaultCardImage";
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
/** Printings returned by the API. */
const printings = ref<DeckPrinting[]>([]);
/** When true, only printings the user owns in a non-deckbox container are shown. */
const onlyCollection = ref(false);
/** Printings filtered by the current `onlyCollection` toggle. */
const filteredPrintings = computed<DeckPrinting[]>(() =>
    onlyCollection.value ? printings.value.filter(p => p.in_collection) : printings.value
);
/** How many printings are currently visible (grows as the user scrolls). */
const visibleCount = ref(PAGE_SIZE);
/** The slice of printings currently rendered in the DOM. */
const visiblePrintings = computed<DeckPrinting[]>(() => filteredPrintings.value.slice(0, visibleCount.value));
/** Ref to the invisible sentinel element that triggers infinite scroll. */
const sentinel = ref<HTMLElement | null>(null);
/** Reset visible count whenever the filtered list shrinks/grows (toggle). */
watch(filteredPrintings, () => {
    visibleCount.value = PAGE_SIZE;
});
let observer: IntersectionObserver | null = null;
/**
 * Observe the sentinel element. When it enters the viewport, load the next
 * batch of printings. The observer is recreated whenever the sentinel mounts.
 * Uses the modal body as root so inner-scroll intersections are detected.
 */
watch(sentinel, el => {
    observer?.disconnect();
    if (!el) return;
    const root = document.getElementById("modal-body");
    observer = new IntersectionObserver(
        entries => {
            // Skip fires while the user hasn't scrolled — the sentinel can
            // already be inside the modal body's visible area on mount if
            // the first batch doesn't overflow yet.
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
            const data = (await response.json()) as DeckPrinting[];
            // Pin the current printing to the top so the user sees their
            // current selection first. Order is frozen for the lifetime of the modal.
            data.sort((a, b) => Number(b.is_current) - Number(a.is_current));
            printings.value = data;
        } else {
            error.value = true;
        }
    } catch (e) {
        if (e instanceof DOMException && e.name === "AbortError") return;
        error.value = true;
    }
    finally {
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
    <modal @close="emit('close')">
        <template #header>
            <i18n-t keypath="pages.deck.switch_printing.title" scope="global">
                <template #card
                    ><cite>{{ name }}</cite></template
                >
            </i18n-t>
        </template>
        <form class="form">
            <form-group class="switch-label">
                <Switch
                    ref-id="only_collection_printings"
                    :label="$t('pages.deck.switch_printing.only_collection_printings')"
                    :checked-initially="onlyCollection"
                    @change="onlyCollection = $event"
                />
                {{ $t("pages.deck.switch_printing.only_collection_printings") }}
            </form-group>
        </form>
        <div v-if="loading" class="switch-printing__loading">
            <loading-spinner :size="4" :branded="true" />
            <p>{{ $t("pages.deck.switch_printing.loading") }}</p>
        </div>
        <div v-else-if="error" class="switch-printing__error">
            <icon name="error" :size="2" />
            <p>{{ $t("pages.deck.switch_printing.error") }}</p>
        </div>
        <template v-else>
            <num-visible
                v-if="filteredPrintings.length > PAGE_SIZE"
                :visible-count="Math.min(visibleCount, filteredPrintings.length)"
                :num-total-results="filteredPrintings.length"
            />
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
    </modal>
</template>

<style lang="scss" scoped>
/**
 * other styles are in resources/app/styles/components/deck/_switch-printing.scss
 */
.switch-label:deep(.form-group__field) {
    display: flex;
    align-items: center;

    gap: 1rem;
}
</style>
