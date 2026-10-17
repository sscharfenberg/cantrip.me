<script setup lang="ts">
/******************************************************************************
 * How much of a mana source a card counts as, shown while the sources
 * for a spell are highlighted (a spell picked on a "Mana sources" row) —
 * "¾" on a Farseek, with a tooltip listing each step of its worth ("¼
 * from mana value 4, ½ from mana value 5" for a Cultivate). Only for
 * partial sources: lands count fully and stay unmarked, so a selection
 * doesn't paint a "1" on every land. The donut's production ring counts
 * every source as one, so its selection shows no badges.
 *
 * Two states, matching the card's own highlight:
 *   - counts toward the selected spell — the badge shows that weight,
 *     its share of that spell's source count;
 *   - a later source (`useDeckHighlight().isLaterSource`) that only
 *     counts toward bigger spells — muted, showing the most it ever
 *     counts as, and the tooltip says it is not counted yet.
 *
 * A badge with its own tooltip rather than a tooltip on the card: the
 * text view's hover preview lives on the card name, and this badge sits
 * beside it like the game-changer / MLD flags, so the two never compete.
 * The image view has no hover preview; there it takes the free top-left
 * corner.
 *
 * Renders nothing unless `useDeckHighlight().productionShare` has a share
 * for the card, so the card views can place it unconditionally.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { type HighlightableCard, useDeckHighlight } from "Composables/useDeckHighlight.ts";

const props = defineProps<{
    card: HighlightableCard;
    /** Layout variant — text rows use `inline`, image grid uses `corner`. */
    variant?: "inline" | "corner";
}>();

const { t } = useI18n();
const { highlight, productionShare } = useDeckHighlight();

/** Karsten's weights are quarters; show them as the fractions he writes. */
const FRACTIONS: Record<string, string> = { "0.25": "¼", "0.5": "½", "0.75": "¾" };
const fraction = (weight: number): string => FRACTIONS[String(weight)] ?? "";

const share = computed(() => productionShare(props.card));
const isLater = computed(() => share.value !== null && share.value.weight === 0);
const label = computed(() => {
    if (share.value === null) return "";
    if (!isLater.value) return fraction(share.value.weight);
    return fraction(share.value.steps[share.value.steps.length - 1].weight);
});

/** HTML tooltip — FloatingVue runs with `html: true` (main.ts) — so the "not yet" note gets its own line. */
const tooltip = computed(() => {
    const spell = highlight.value.spellSources;
    if (share.value === null || spell === null) return "";
    const steps = share.value.steps
        .map(step => t("pages.deck.source_share_step", { share: fraction(step.weight), mv: step.fromMv }))
        .join(", ");
    const color = spell.colors.map(c => t(`pages.deck.stats.colors.color.${c}`)).join(" / ");
    const lines = [t("pages.deck.source_share", { color, steps })];
    if (isLater.value) {
        lines.push(t("pages.deck.source_share_later", { mv: spell.mv }));
    }
    return lines.join("<br />");
});
const classes = computed(() => [
    `source-share--${props.variant ?? "inline"}`,
    { "source-share--later": isLater.value }
]);
</script>

<template>
    <span v-if="share !== null" v-tooltip="tooltip" class="source-share" :class="classes">{{ label }}</span>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.source-share {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;

    min-width: map.get(s.$pages, "deck", "flags", "size");
    height: map.get(s.$pages, "deck", "flags", "size");
    padding: 0 map.get(s.$pages, "deck", "flags", "padding");
    border: map.get(s.$pages, "deck", "flags", "border") solid map.get(c.$state, "info", "border");

    background-color: map.get(c.$state, "info", "background");
    color: map.get(c.$state, "info", "surface");
    border-radius: map.get(s.$pages, "deck", "flags", "radius", "text");

    font-weight: 600;
    line-height: 1;

    cursor: help;

    &--corner {
        position: absolute;
        top: 0.25rem;
        left: 0.25rem;

        min-width: 1.5rem;
        height: 1.5rem;

        background-color: map.get(c.$pages, "deck", "quantity", "background");
        color: map.get(c.$pages, "deck", "quantity", "surface");
        border-radius: map.get(s.$pages, "deck", "flags", "radius", "image");
        border-color: transparent;
    }

    &--later {
        opacity: 0.8;

        border-style: dashed;

        font-weight: 400;
    }

    &--corner#{&}--later {
        border-color: map.get(c.$pages, "deck", "quantity", "surface");
    }
}
</style>
