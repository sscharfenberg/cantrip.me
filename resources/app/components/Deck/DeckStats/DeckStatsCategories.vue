<script setup lang="ts">
import { ref, watchEffect } from "vue";
import type { BreakdownBucket } from "Composables/useDeckStats.ts";
const props = defineProps<{
    /** Additive type breakdown (Creature, Planeswalker, Battle, Artifact, Enchantment, Instant, Sorcery, Land). */
    types: BreakdownBucket[];
    /** User-defined category breakdown, sorted alphabetically; "Uncategorized" appended when present. */
    categories: BreakdownBucket[];
}>();
/** Which view the user is currently looking at. Toggled in the chart header. */
const view = ref<"types" | "categories">("types");
watchEffect(() => {
    // smoke test — replace with the bar chart in the next pass.
     
    console.log(
        "[DeckStatsCategories] view:",
        view.value,
        "types:",
        props.types,
        "categories:",
        props.categories,
    );
});
</script>

<template>
    <div class="deck-stats-categories">categories / types placeholder (view: {{ view }})</div>
</template>
