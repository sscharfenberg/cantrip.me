<script setup lang="ts">
/******************************************************************************
 * Per-deck-card "implicit deckbox" status flag (mode B).
 *
 * Mirrors `CollectionStatusBadge` (mode C) but renders a single
 * storage-icon badge whose colour summarises *coverage* rather than the
 * five-way status taxonomy. The tooltip carries the full breakdown.
 *
 * State mapping:
 *   success — needed copies fully owned AND all of them sit in the
 *             deck's `container_id`. The silent-good case.
 *   warning — needed copies fully owned but at least one sits in a
 *             different container. Logistical hint, not a problem.
 *   error   — `missing > 0`, i.e. the user does not own enough copies
 *             of this printing to back the row.
 *
 * Shown only in mode B — the controller leaves
 * `card.collection_implicit_status` unset for modes A and C.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import type { CollectionImplicitStatus } from "Types/deckPage.ts";
const props = defineProps<{
    /** Per-row counts produced by `DeckCollectionStatusService::implicitStatusForDeck`. */
    status: CollectionImplicitStatus;
    /** Total copies needed by the deck card row. Used to phrase the tooltip. */
    quantity: number;
    /** Layout variant — text rows use `inline`, image grid uses `corner`. */
    variant?: "inline" | "corner";
}>();
const { t } = useI18n();
/** Sum of in-deckbox + elsewhere — convenience for tooltip phrasing. */
const owned = computed(() => props.status.in_deckbox + props.status.elsewhere);
/**
 * Coverage colour. `error` whenever any copies are missing; `success`
 * when fully covered AND every copy sits in the deck's deckbox; otherwise
 * `warning` (fully covered but spread across containers).
 */
const colorState = computed<"success" | "warning" | "error">(() => {
    if (props.status.missing > 0) return "error";
    if (props.status.elsewhere === 0) return "success";
    return "warning";
});
const colorClass = computed(() => `collection-implicit--${colorState.value}`);
const variantClass = computed(() => `collection-implicit--${props.variant ?? "inline"}`);
/**
 * Tooltip key, picked by the count shape so each phrase reads naturally.
 * The five branches correspond to the five distinct render combinations:
 *   - `all_in_deckbox`               — everything covered, all here.
 *   - `in_deckbox_and_elsewhere`     — everything covered, mixed.
 *   - `all_elsewhere`                — covered, but none in the deckbox.
 *   - `partial_with_missing`         — partial coverage, some missing.
 *   - `all_missing`                  — owns none.
 */
const tooltip = computed(() => {
    const { in_deckbox, elsewhere, missing } = props.status;
    const needed = props.quantity;
    if (missing === 0 && elsewhere === 0) {
        return t("pages.deck.collection_implicit_status.all_in_deckbox", { needed });
    }
    if (missing === 0 && in_deckbox > 0) {
        return t("pages.deck.collection_implicit_status.in_deckbox_and_elsewhere", {
            in_deckbox,
            elsewhere,
            needed
        });
    }
    if (missing === 0) {
        return t("pages.deck.collection_implicit_status.all_elsewhere", { elsewhere, needed });
    }
    if (owned.value === 0) {
        return t("pages.deck.collection_implicit_status.all_missing", { needed });
    }
    return t("pages.deck.collection_implicit_status.partial_with_missing", {
        owned: owned.value,
        needed,
        in_deckbox,
        elsewhere,
        missing
    });
});
</script>

<template>
    <icon
        v-tooltip="tooltip"
        name="storage"
        :size="1"
        :additional-classes="['collection-implicit', colorClass, variantClass]"
    />
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.collection-implicit {
    flex-basis: map.get(s.$pages, "deck", "flags", "size");

    width: map.get(s.$pages, "deck", "flags", "size");
    height: map.get(s.$pages, "deck", "flags", "size");
    padding: map.get(s.$pages, "deck", "flags", "padding");
    border: map.get(s.$pages, "deck", "flags", "border") solid transparent;

    border-radius: map.get(s.$pages, "deck", "flags", "radius", "text");

    &--success {
        background-color: map.get(c.$state, "success", "background");
        color: map.get(c.$state, "success", "surface");
        border-color: map.get(c.$state, "success", "border");
    }

    &--warning {
        background-color: map.get(c.$state, "warning", "background");
        color: map.get(c.$state, "warning", "surface");
        border-color: map.get(c.$state, "warning", "border");
    }

    &--error {
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
