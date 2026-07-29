<script setup lang="ts" generic="T extends { id: string }">
import { computed, onUnmounted, ref, watch } from "vue";
import NumVisible from "Components/Card/CardSearch/NumVisible.vue";
const props = defineProps<{
    results: T[];
    /** Total matches the server found (may exceed `results.length` because the
     *  server caps the returned page). Used by `NumVisible` to show e.g.
     *  "20 of 10,535" when the user is browsing a partial slice. */
    totalResults: number;
}>();
const emit = defineEmits<{
    change: [card: T];
}>();
/** Number of results rendered per page / scroll batch. */
const PAGE_SIZE = 20;
/** The id of the currently highlighted result, or null if none selected. */
const selectedId = ref<string | null>(null);
/** How many results are currently visible (grows as the user scrolls). */
const visibleCount = ref(PAGE_SIZE);
/** Ref to the invisible sentinel element that triggers infinite scroll. */
const sentinel = ref<HTMLElement | null>(null);
/** The slice of results currently rendered in the DOM. */
const visibleResults = computed(() => props.results.slice(0, visibleCount.value));
/** Marks the clicked card as selected and notifies the parent. */
function selectCard(card: T) {
    selectedId.value = card.id;
    emit("change", card);
}
/** Reset visible count whenever the results list is replaced (new search). */
watch(
    () => props.results,
    () => {
        visibleCount.value = PAGE_SIZE;
    }
);
let observer: IntersectionObserver | null = null;
/**
 * Observe the sentinel element. When it enters the viewport, load the next
 * batch of results. The observer is recreated whenever the sentinel mounts.
 */
watch(sentinel, el => {
    observer?.disconnect();
    if (!el) return;
    observer = new IntersectionObserver(entries => {
        if (entries[0]?.isIntersecting && visibleCount.value < props.results.length) {
            visibleCount.value = Math.min(visibleCount.value + PAGE_SIZE, props.results.length);
        }
    });
    observer.observe(el);
});
onUnmounted(() => observer?.disconnect());
</script>

<template>
    <div class="results-wrapper">
        <num-visible v-if="totalResults > PAGE_SIZE" :visible-count="visibleCount" :num-total-results="totalResults" />
        <ul class="results">
            <li
                v-for="card in visibleResults"
                :key="card.id"
                class="result"
                :class="{ 'result--active': card.id === selectedId }"
                @click="selectCard(card)"
            >
                <slot name="result" :card="card" />
            </li>
        </ul>
        <div ref="sentinel" class="results__sentinel" />
    </div>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/shadows" as sh;
@use "Abstracts/sizes" as s;

.results-wrapper {
    display: contents;
}

.results {
    display: grid;
    grid-template-columns: repeat(
        auto-fit,
        minmax(min(#{map.get(s.$components, "face-image", "min-width")}, 100%), 1fr)
    );

    padding: 0;
    margin: 0;
    gap: 1ch;

    list-style: none;

    &__sentinel {
        height: 1px;
    }
}

.result {
    cursor: pointer;

    &--active .card-image {
        box-shadow: map.get(sh.$main, "card-image-active");
    }
}
</style>
