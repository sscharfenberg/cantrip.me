<script setup lang="ts">
/******************************************************************************
 * Per-deck-card availability flag for a *planned* deck.
 *
 * Third sibling of `CollectionStatusBadge` (mode C) and
 * `CollectionImplicitBadge` (mode B), and mutually exclusive with both:
 * while a deck is planned it answers the planning question — "can my
 * collection cover this slot, or do I have to buy / trade for it?" —
 * whatever tracking mode the deck sits in. Once the deck is set to
 * finished, the mode badges take over.
 *
 * Icons and colours are borrowed from the two tracking badges on
 * purpose, so a user who has seen either already reads this one:
 *   check  / success — enough free copies of this exact printing.
 *   planned / warning — the `wrong_printing` icon: some copies free,
 *                       but not enough of this printing.
 *   money  / error   — the `not_owned` icon: nothing free, get one.
 *
 * "Free" excludes copies another deck holds — claimed via a mode-C
 * pivot row, or sitting in a container designated as another deck's
 * deckbox. The tooltip spells the numbers out, including how many
 * copies are held elsewhere.
 *
 * Shown only for owners of a planned deck with the collection master
 * switch on — the controller leaves `card.collection_availability`
 * unset in every other case.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import type { CollectionAvailability } from "Types/deckPage.ts";
const props = defineProps<{
    /** Per-row counts produced by `DeckCollectionStatusService::availabilityForDeck`. */
    availability: CollectionAvailability;
    /** Layout variant — text rows use `inline`, image grid uses `corner`. */
    variant?: "inline" | "corner";
}>();
const { t } = useI18n();
const iconName = computed<string>(() => {
    switch (props.availability.state) {
        case "available":
            return "check";
        case "partial":
            return "planned";
        case "unavailable":
        default:
            return "money";
    }
});
const colorClass = computed(() => `collection-availability--${props.availability.state}`);
const variantClass = computed(() => `collection-availability--${props.variant ?? "inline"}`);
/**
 * Tooltip, picked by the count shape so each phrase reads naturally.
 * HTML tooltip — FloatingVue is configured with `html: true` in main.ts —
 * so the "N more held by other decks" hint can hang below on its own line
 * instead of running the sentence long.
 */
const tooltip = computed<string>(() => {
    const { state, needed, exact, other, blocked } = props.availability;
    const lines: string[] = [];
    if (state === "available") {
        lines.push(t("pages.deck.collection_availability.available", { exact, needed }));
    } else if (state === "partial" && exact === 0) {
        lines.push(t("pages.deck.collection_availability.partial_other", { other, needed }));
    } else if (state === "partial" && other === 0) {
        lines.push(t("pages.deck.collection_availability.partial_printing", { exact, needed }));
    } else if (state === "partial") {
        lines.push(t("pages.deck.collection_availability.partial_mixed", { exact, needed, other }));
    } else if (blocked > 0) {
        lines.push(t("pages.deck.collection_availability.unavailable_held", { blocked, needed }));
    } else {
        lines.push(t("pages.deck.collection_availability.unavailable_missing", { needed }));
    }
    // The unavailable_held phrase already names `blocked` — don't say it twice.
    if (blocked > 0 && state !== "unavailable") {
        lines.push(t("pages.deck.collection_availability.also_held", { blocked }));
    }
    return lines.join("<br />");
});
</script>

<template>
    <icon
        v-tooltip="tooltip"
        :name="iconName"
        :size="1"
        :additional-classes="['collection-availability', colorClass, variantClass]"
    />
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.collection-availability {
    flex-basis: map.get(s.$pages, "deck", "flags", "size");

    width: map.get(s.$pages, "deck", "flags", "size");
    height: map.get(s.$pages, "deck", "flags", "size");
    padding: map.get(s.$pages, "deck", "flags", "padding");
    border: map.get(s.$pages, "deck", "flags", "border") solid transparent;

    border-radius: map.get(s.$pages, "deck", "flags", "radius", "text");

    &--available {
        background-color: map.get(c.$state, "success", "background");
        color: map.get(c.$state, "success", "surface");
        border-color: map.get(c.$state, "success", "border");
    }

    &--partial {
        background-color: map.get(c.$state, "warning", "background");
        color: map.get(c.$state, "warning", "surface");
        border-color: map.get(c.$state, "warning", "border");
    }

    &--unavailable {
        background-color: map.get(c.$state, "error", "background");
        color: map.get(c.$state, "error", "surface");
        border-color: map.get(c.$state, "error", "border");
    }

    &--corner {
        position: absolute;
        bottom: 0.25rem;
        left: 0.25rem;

        width: 1.5rem;
        height: 1.5rem;

        border-radius: map.get(s.$pages, "deck", "flags", "radius", "image");
    }
}
</style>
