<script setup lang="ts">
import { watchEffect } from "vue";
import type { ColorPipTally } from "Composables/useDeckStats.ts";
const props = defineProps<{
    /** WUBRG pip totals across all non-land card costs (deck + commanders + companion). */
    cost: ColorPipTally;
    /** WUBRG totals from `produced_mana × quantity`. */
    production: ColorPipTally;
}>();
watchEffect(() => {
    // smoke test — replace with the donut chart in the next pass.
     
    console.log("[DeckStatsColors] cost:", props.cost, "production:", props.production);
});
</script>

<template>
    <div class="deck-stats-colors">colors placeholder</div>
</template>
