<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Badge from "Components/UI/Badge.vue";
import Icon from "Components/UI/Icon.vue";
import type { DeckCardCount } from "Types/deckPage.ts";
const props = defineProps<{
    /** Three-part deck card count (mainboard / companion / sideboard). */
    count: DeckCardCount;
}>();
const { t } = useI18n();
// HTML tooltip — FloatingVue is configured with `html: true` in main.ts.
// Empty parts (companion=0 or side=0) are skipped so the tooltip only
// lists what's actually in the deck.
const tooltip = computed<string>(() => {
    const lines = [t("pages.deck.card_count_tooltip.title")];
    lines.push(t("pages.deck.card_count_tooltip.main", { count: props.count.main }, props.count.main));
    if (props.count.companion > 0) {
        lines.push(t("pages.deck.card_count_tooltip.companion", { count: props.count.companion }));
    }
    if (props.count.side > 0) {
        lines.push(t("pages.deck.card_count_tooltip.side", { count: props.count.side }, props.count.side));
    }
    return lines.join("<br />");
});
</script>

<template>
    <badge v-tooltip="tooltip" type="info" class="deck-card-count">
        <icon name="deck" :size="1" />
        <span class="deck-card-count__main">{{ count.main }}</span>
        <span v-if="count.companion > 0" class="deck-card-count__companion"> + {{ count.companion }}</span>
        <span v-if="count.side > 0" class="deck-card-count__side"> / {{ count.side }}</span>
    </badge>
</template>

<style lang="scss" scoped>
.deck-card-count {
    white-space: nowrap;
}
</style>
