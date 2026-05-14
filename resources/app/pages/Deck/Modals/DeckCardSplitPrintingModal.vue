<script setup lang="ts">
import { router, usePage } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import CardFaceImage from "Components/Card/CardFaceImage.vue";
import NumVisible from "Components/Card/CardSearch/NumVisible.vue";
import FormGroup from "Components/Form/FormGroup.vue";
import Switch from "Components/Form/Switch.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import type { DefaultCardImage } from "Types/defaultCardImage.ts";
/** A printing of the deck card's oracle card, plus collection/current flags. */
type Printing = DefaultCardImage & { in_collection: boolean; is_current: boolean };
const emit = defineEmits<{ close: [] }>();
const props = defineProps<{
    /** UUID of the deck this card belongs to. */
    deckId: string;
    /** UUID of the deck card entry. */
    cardId: string;
    /** Card name, interpolated into the modal title. */
    name: string;
    /** Current number of copies — total that must be distributed across printings. */
    quantity: number;
}>();
/** Number of printings rendered per scroll batch. */
const PAGE_SIZE = 20;
/** True while the printings XHR is in flight. */
const loading = ref(true);
/** True when the printings fetch failed. */
const error = ref(false);
/** True while the split PATCH is in flight. */
const submitting = ref(false);
/** Printings returned by the API, sorted so the current printing is pinned first. */
const printings = ref<Printing[]>([]);
/** Quantity assigned to each printing by default_card_id. Starts all-at-current. */
const assigned = ref<Record<string, number>>({});
/** When true, only printings the user owns in a non-deckbox container are shown. */
const onlyCollection = ref(false);
/** Filtered printings by the onlyCollection toggle. */
const filteredPrintings = computed<Printing[]>(() =>
    onlyCollection.value ? printings.value.filter(p => p.in_collection) : printings.value
);
/** How many printings are currently visible (grows as the user scrolls). */
const visibleCount = ref(PAGE_SIZE);
const visiblePrintings = computed<Printing[]>(() => filteredPrintings.value.slice(0, visibleCount.value));
/** Ref to the invisible sentinel element that triggers infinite scroll. */
const sentinel = ref<HTMLElement | null>(null);
/** Sum of all assigned quantities. */
const totalAssigned = computed<number>(() => Object.values(assigned.value).reduce((sum, n) => sum + n, 0));
/** Number of distinct printings with a positive assignment. */
const distinctAssigned = computed<number>(() => Object.values(assigned.value).filter(n => n > 0).length);
/** Whether the current selection is a valid split. */
const isValid = computed<boolean>(() => totalAssigned.value === props.quantity && distinctAssigned.value >= 2);
/** AbortController so the in-flight request is cancelled if the modal is unmounted. */
let abortController: AbortController | null = null;
onMounted(async () => {
    abortController = new AbortController();
    try {
        const response = await fetch(`/api/decks/${props.deckId}/cards/${props.cardId}/printings`, {
            headers: { Accept: "application/json" },
            signal: abortController.signal
        });
        if (response.ok) {
            const data = (await response.json()) as Printing[];
            // Pin the current printing to the top so the common case (tweak one copy away)
            // stays a minimal-scroll interaction. Order is frozen for the lifetime of the modal.
            data.sort((a, b) => Number(b.is_current) - Number(a.is_current));
            printings.value = data;
            const current = data.find(p => p.is_current);
            if (current) assigned.value = { [current.id]: props.quantity };
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
watch(filteredPrintings, () => {
    visibleCount.value = PAGE_SIZE;
});
let observer: IntersectionObserver | null = null;
watch(sentinel, el => {
    observer?.disconnect();
    if (!el) return;
    const root = document.getElementById("modal-body");
    observer = new IntersectionObserver(
        entries => {
            if (root !== null && root.scrollTop === 0) return;
            if (entries[0]?.isIntersecting && visibleCount.value < filteredPrintings.value.length) {
                visibleCount.value = Math.min(visibleCount.value + PAGE_SIZE, filteredPrintings.value.length);
            }
        },
        { root }
    );
    observer.observe(el);
});
/** Get the quantity currently assigned to a printing (0 if unset). */
function qtyOf(printing: Printing): number {
    return assigned.value[printing.id] ?? 0;
}
/**
 * Whether this printing can accept one more copy. False when the pool is
 * full and there's no other printing to steal from (otherwise clicking
 * would silently no-op).
 */
function canIncrement(printing: Printing): boolean {
    if (totalAssigned.value < props.quantity) return true;
    return Object.keys(assigned.value).some(id => id !== printing.id && (assigned.value[id] ?? 0) > 0);
}
/** Increment this printing, taking one copy from any other printing with a positive assignment. */
function increment(printing: Printing): void {
    if (totalAssigned.value >= props.quantity) {
        // Steal from the first other printing with qty > 0.
        const donor = Object.keys(assigned.value).find(id => id !== printing.id && (assigned.value[id] ?? 0) > 0);
        if (donor === undefined) return;
        assigned.value[donor] = (assigned.value[donor] ?? 0) - 1;
        if (assigned.value[donor] === 0) delete assigned.value[donor];
    }
    assigned.value[printing.id] = qtyOf(printing) + 1;
}
/** Decrement this printing; removes the entry when it reaches 0. */
function decrement(printing: Printing): void {
    const current = qtyOf(printing);
    if (current <= 0) return;
    if (current === 1) delete assigned.value[printing.id];
    else assigned.value[printing.id] = current - 1;
}
const page = usePage();
/**
 * POST the split, reload the deck cards, then close the modal. The user sees
 * the in-modal overlay spinner during the round-trip so the page below the
 * modal has already rerendered by the time the modal unmounts.
 */
async function applySplit(): Promise<void> {
    if (!isValid.value || submitting.value) return;
    submitting.value = true;
    const splits = Object.entries(assigned.value)
        .filter(([, qty]) => qty > 0)
        .map(([default_card_id, quantity]) => ({ default_card_id, quantity }));
    const response = await fetch(`/api/decks/${props.deckId}/cards/${props.cardId}/split`, {
        method: "PATCH",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": page.props.csrfToken as string,
            Accept: "application/json"
        },
        body: JSON.stringify({ splits })
    });
    if (!response.ok) {
        submitting.value = false;
        return;
    }
    await new Promise<void>(resolve => {
        router.reload({
            only: ["cards", "deck", "violations", "tokens"],
            onFinish: () => resolve()
        });
    });
    submitting.value = false;
    emit("close");
}
</script>

<template>
    <modal @close="emit('close')">
        <template #header>
            <i18n-t keypath="pages.deck.split_printing.title" scope="global">
                <template #card
                    ><cite>{{ name }}</cite></template
                >
            </i18n-t>
        </template>
        <div v-if="loading" class="split-printing__loading">
            <loading-spinner :size="4" :branded="true" />
            <p>{{ $t("pages.deck.switch_printing.loading") }}</p>
        </div>
        <div v-else-if="error" class="split-printing__error">
            <icon name="error" :size="2" />
            <p>{{ $t("pages.deck.switch_printing.error") }}</p>
        </div>
        <template v-else>
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
            <div class="split-printing__meta">
                <num-visible
                    v-if="filteredPrintings.length > PAGE_SIZE"
                    :visible-count="Math.min(visibleCount, filteredPrintings.length)"
                    :num-total-results="filteredPrintings.length"
                />
                <div class="split-printing__total" :class="{ 'split-printing__total--invalid': !isValid }">
                    {{ $t("pages.deck.split_printing.assigned") }}: {{ totalAssigned }} / {{ quantity }}
                </div>
            </div>
            <div class="split-printing__list">
                <div v-for="printing in visiblePrintings" :key="printing.id" class="split-printing__item">
                    <card-face-image :card="printing" tooltip-container="#modal-body" />
                    <div class="split-printing__stepper">
                        <button
                            type="button"
                            :disabled="qtyOf(printing) <= 0"
                            :aria-label="$t('pages.deck.card_quantity.decrement')"
                            @click="decrement(printing)"
                            class="btn-default"
                        >
                            <icon name="subtract" :size="1" />
                        </button>
                        <span>{{ qtyOf(printing) }}</span>
                        <button
                            type="button"
                            :disabled="!canIncrement(printing)"
                            :aria-label="$t('pages.deck.card_quantity.increment')"
                            @click="increment(printing)"
                            class="btn-default"
                        >
                            <icon name="add" :size="1" />
                        </button>
                    </div>
                </div>
            </div>
            <div ref="sentinel" class="split-printing__sentinel" />
            <div v-if="submitting" class="split-printing__overlay">
                <loading-spinner :size="4" :branded="true" />
            </div>
        </template>
        <template v-if="!loading && !error" #footer>
            <button type="button" class="btn-primary" :disabled="!isValid || submitting" @click="applySplit">
                <icon name="save" />
                {{ $t("pages.deck.split_printing.submit") }}
            </button>
        </template>
    </modal>
</template>

<style lang="scss" scoped>
/**
 * other styles are in resources/app/styles/components/deck/_split-printing.scss
 */
.split-printing__meta:deep(.results__count) {
    margin-bottom: 0;
}
</style>
