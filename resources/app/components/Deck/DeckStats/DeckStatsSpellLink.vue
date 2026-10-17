<script setup lang="ts">
/******************************************************************************
 * The spell a "Mana sources" row is priced for — "Eight-and-a-Half-Tails
 * (mana value 2)" — as a button that opens the spell picker.
 *
 * A button rather than a link: it opens a modal and navigates nowhere.
 * Styled as a chip — a faint tint, a border, an edit icon — so it reads as
 * something to click inside the row's sentence; a spell the user picked
 * gets a firmer border, since that row no longer follows the automatic
 * choice. Going back to automatic is the section's "reset" link, or the
 * picker's "Automatic" entry.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";

const props = defineProps<{
    /** Name of the spell the row is priced for. */
    card: string;
    /** That spell's mana value. */
    mv: number;
    /** True when the user picked the spell, rather than the row choosing it. */
    picked: boolean;
    /** The row's color name(s), for the accessible label ("White", "White / Blue"). */
    colorLabel: string;
}>();

/** @emits pick — Open the spell picker for this row. */
const emit = defineEmits<{ pick: [] }>();

const { t } = useI18n();

const spell = computed(() => t("pages.deck.stats.colors.karsten.spell", { card: props.card, mv: props.mv }));
</script>

<template>
    <button
        v-tooltip="t('pages.deck.stats.colors.karsten.change_spell')"
        type="button"
        class="spell-link"
        :class="{ 'spell-link--picked': picked }"
        :aria-label="
            t(
                picked
                    ? 'pages.deck.stats.colors.karsten.change_spell_aria_picked'
                    : 'pages.deck.stats.colors.karsten.change_spell_aria',
                { colors: colorLabel, spell }
            )
        "
        @click="emit('pick')"
    >
        {{ spell }}<icon name="edit" :size="0" :additional-classes="['spell-link__icon']" />
    </button>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.spell-link {
    padding: map.get(s.$components, "spell-link", "padding");
    border: map.get(s.$components, "spell-link", "border") solid map.get(c.$components, "spell-link", "border");

    background-color: map.get(c.$components, "spell-link", "background");
    color: map.get(c.$components, "spell-link", "surface");
    border-radius: map.get(s.$components, "spell-link", "radius");

    font: inherit;

    // The row's hanging indent is inherited; the chip's own content would
    // otherwise start left of the chip.
    text-indent: 0;

    cursor: pointer;

    transition:
        background-color map.get(ti.$timings, "fast") linear,
        border-color map.get(ti.$timings, "fast") linear;

    &--picked {
        border-color: map.get(c.$components, "spell-link", "border-picked");
    }

    &:hover,
    &:focus-visible {
        background-color: map.get(c.$components, "spell-link", "background-hover");
        border-color: map.get(c.$components, "spell-link", "border-picked");
    }

    &__icon {
        margin-left: map.get(s.$components, "spell-link", "gap");

        vertical-align: -0.1em;
    }
}
</style>
