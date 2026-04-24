<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import CardFaceImage from "Components/Card/CardFaceImage.vue";
import NumVisible from "Components/Card/CardSearch/NumVisible.vue";
import Icon from "Components/UI/Icon.vue";
import type { DeckSearchResult } from "Types/deckPage.ts";

const { t } = useI18n();

const props = defineProps<{
    results: DeckSearchResult[];
    canAddToMain: boolean;
    canAddToSide: boolean;
    adding: boolean;
}>();

const emit = defineEmits<{
    add: [result: DeckSearchResult, zone: string];
}>();

/** The printing id of the card currently showing the zone picker. */
const pickingZoneFor = ref<string | null>(null);

function onCardClick(result: DeckSearchResult) {
    if (props.adding || !result.printing) return;
    if (props.canAddToMain && props.canAddToSide) {
        pickingZoneFor.value = result.printing.id;
    } else if (props.canAddToMain) {
        emit("add", result, "main");
    } else if (props.canAddToSide) {
        emit("add", result, "side");
    }
}

function pickZone(result: DeckSearchResult, zone: string) {
    pickingZoneFor.value = null;
    emit("add", result, zone);
}

const PAGE_SIZE = 20;
const visibleCount = ref(PAGE_SIZE);
const sentinel = ref<HTMLElement | null>(null);
const visibleResults = computed(() => props.results.slice(0, visibleCount.value));
let skipNext = true;

watch(
    () => props.results,
    () => {
        visibleCount.value = PAGE_SIZE;
        skipNext = true;
        pickingZoneFor.value = null;
    }
);

let observer: IntersectionObserver | null = null;

watch(sentinel, el => {
    observer?.disconnect();
    if (!el) return;
    observer = new IntersectionObserver(
        entries => {
            if (skipNext) {
                skipNext = false;
                return;
            }
            if (entries[0]?.isIntersecting && visibleCount.value < props.results.length) {
                visibleCount.value = Math.min(visibleCount.value + PAGE_SIZE, props.results.length);
            }
        },
        { root: document.getElementById("modal-body") }
    );
    observer.observe(el);
});

onUnmounted(() => observer?.disconnect());
</script>

<template>
    <num-visible v-if="results.length > PAGE_SIZE" :visible-count="visibleCount" :num-total-results="results.length" />
    <ul class="card-add-results">
        <li
            v-for="result in visibleResults"
            :key="result.printing!.id"
            class="card-add-results__item"
            @click="onCardClick(result)"
        >
            <card-face-image v-if="result.printing" :card="result.printing" interactive tooltip-container="#modal" />
            <span
                v-if="result.violates_companion"
                v-tooltip="{ content: t('pages.deck.add.companion_warning'), container: '#modal' }"
                class="card-add-results__companion-warning"
            >
                <icon name="warning" :size="2" />
            </span>
            <div v-if="pickingZoneFor === result.printing?.id" class="card-add-results__zone-picker">
                <button type="button" @click.stop="pickZone(result, 'main')">
                    {{ t("enums.zone.main") }}
                </button>
                <button type="button" @click.stop="pickZone(result, 'side')">
                    {{ t("enums.zone.side") }}
                </button>
            </div>
        </li>
    </ul>
    <div ref="sentinel" class="card-add-results__sentinel" />
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;
@use "Abstracts/z-indexes" as z;

.card-add-results {
    display: grid;
    grid-template-columns: repeat(
        auto-fill,
        minmax(min(map.get(s.$pages, "deck", "add-card", "results-min"), 100%), 1fr)
    );

    padding: 0;
    margin: 0;
    gap: map.get(s.$pages, "deck", "add-card", "gap");

    list-style: none;

    &__item {
        position: relative;

        &:hover {
            z-index: map.get(z.$index, "main");
        }
    }

    &__zone-picker {
        display: flex;
        position: absolute;
        inset: 0;
        z-index: map.get(z.$index, "select");
        align-items: center;
        justify-content: center;
        flex-direction: column;

        gap: 0.5rem;

        background-color: map.get(c.$pages, "deck", "add", "zone", "background");
        color: map.get(c.$pages, "deck", "add", "zone", "surface");
        border-radius: map.get(s.$pages, "deck", "add-card", "radius");

        > button {
            padding: map.get(s.$pages, "deck", "zone-btn", "padding");
            border: 0;

            background-color: map.get(c.$pages, "deck", "add", "zone-btn", "background");
            color: map.get(c.$pages, "deck", "add", "zone-btn", "surface");
            border-radius: map.get(s.$pages, "deck", "zone-btn", "radius");

            cursor: pointer;

            transition:
                background-color map.get(ti.$timings, "fast") ease,
                color map.get(ti.$timings, "fast") ease;

            &:hover {
                background-color: map.get(c.$pages, "deck", "add", "zone-btn", "background-hover");
                color: map.get(c.$pages, "deck", "add", "zone-btn", "surface-hover");
            }
        }
    }

    &__sentinel {
        height: 1px;
    }

    // Soft-warning badge anchored to the top-right corner of the card face
    // when `violates_companion` is set. Non-blocking — the user can still
    // add the card (maybe they plan to swap companions after).
    &__companion-warning {
        display: flex;
        position: absolute;
        top: 0.25rem;
        right: 0.25rem;
        z-index: map.get(z.$index, "select");
        align-items: center;
        justify-content: center;

        width: 1.75rem;
        height: 1.75rem;

        background-color: map.get(c.$state, "warning", "background");
        color: map.get(c.$state, "warning", "surface");
        border-radius: 50%;

        pointer-events: auto;
    }
}

:deep(.face-image) {
    border-radius: map.get(s.$pages, "deck", "add-card", "radius");
}
</style>
