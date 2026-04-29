<script setup lang="ts">
/******************************************************************************
 * Per-deck-card collection-integration status flag.
 *
 * Renders a small icon-as-badge (mirroring `card__illegal` /
 * `card__game-changer`) that summarises whether the deck card is backed by a
 * physical card stack in the user's collection. Shown only in mode C — the
 * controller leaves `card.collection_status` unset for modes A and B.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";

export type CollectionStatus =
    | "claimed_for_this_deck"
    | "available"
    | "claimed_by_other_deck"
    | "wrong_printing"
    | "not_owned";

const props = defineProps<{
    status: CollectionStatus;
    /** Layout variant — text rows use `inline`, image grid uses `corner`. */
    variant?: "inline" | "corner";
}>();

const { t } = useI18n();

const iconName = computed(() => {
    switch (props.status) {
        case "claimed_for_this_deck":
            return "check";
        case "available":
            return "edit";
        case "claimed_by_other_deck":
            return "swords";
        case "wrong_printing":
            return "planned";
        case "not_owned":
        default:
            return "money";
    }
});

const colorClass = computed(() => `collection-status--${props.status}`);
const variantClass = computed(() => `collection-status--${props.variant ?? "inline"}`);

const tooltip = computed(() => t(`pages.deck.collection_status.${props.status}`));
</script>

<template>
    <icon
        v-tooltip="tooltip"
        :name="iconName"
        :size="1"
        :additional-classes="['collection-status', colorClass, variantClass]"
    />
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.collection-status {
    flex-basis: map.get(s.$pages, "deck", "flags", "size");

    width: map.get(s.$pages, "deck", "flags", "size");
    height: map.get(s.$pages, "deck", "flags", "size");
    padding: map.get(s.$pages, "deck", "flags", "padding");
    border: map.get(s.$pages, "deck", "flags", "border") solid transparent;

    border-radius: map.get(s.$pages, "deck", "flags", "radius", "text");

    &--claimed_for_this_deck,
    &--available {
        background-color: map.get(c.$state, "success", "background");
        color: map.get(c.$state, "success", "surface");
        border-color: map.get(c.$state, "success", "border");
    }

    &--claimed_by_other_deck {
        background-color: map.get(c.$state, "warning", "background");
        color: map.get(c.$state, "warning", "surface");
        border-color: map.get(c.$state, "warning", "border");
    }

    &--wrong_printing {
        background-color: map.get(c.$state, "info", "background");
        color: map.get(c.$state, "info", "surface");
        border-color: map.get(c.$state, "info", "border");
    }

    &--not_owned {
        background-color: map.get(c.$state, "error", "background");
        color: map.get(c.$state, "error", "surface");
        border-color: map.get(c.$state, "error", "border");
    }

    &--corner {
        position: absolute;
        bottom: 0.25rem;
        left: 0.25rem;

        border-radius: map.get(s.$pages, "deck", "flags", "radius", "image");
    }
}
</style>
