<script setup lang="ts">
/******************************************************************************
 * What a deck card's copies are, beyond the card itself: foil, etched,
 * proxy, a language other than English. One small flag per kind — the
 * collection's own vocabulary (`star` foil, `texture` etched, `print`
 * proxy, the language's flag) — and one tooltip that spells them out.
 *
 * Each copy counts as its owner has it (`DeckCardCopiesService`): a claimed
 * copy as its stack, the rest as the deck plans them. A flag that covers
 * only some of the copies shows how many ("2" on the foil star of a 4-copy
 * row); one that covers all of them shows none.
 *
 * Renders nothing for an English nonfoil real card, so an untouched deck
 * looks as it always did and the card views can place it unconditionally.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import type { DeckCardCopyGroup } from "Types/deckPage.ts";

const props = defineProps<{
    copies: DeckCardCopyGroup[];
    /** Layout variant — text rows use `inline`, image grid uses `corner`. */
    variant?: "inline" | "corner";
    /** CSS selector for the FloatingVue tooltip container — `#modal-body` inside a modal. Defaults to `body`. */
    tooltipContainer?: string;
}>();

const { t } = useI18n();

/** One flag: a finish, the proxy flag, or a language. */
interface Marker {
    key: string;
    count: number;
    icon: string | null;
    flag: string | null;
    text: string;
}

/** Resolve the flag image URL for a language code. */
const flagSrc = (lang: string): string => new URL(`../../assets/flags/${lang}.svg`, import.meta.url).href;

const total = computed(() => props.copies.reduce((sum, group) => sum + group.count, 0));

/** Copies matching a test, summed over the groups. */
const countWhere = (test: (group: DeckCardCopyGroup) => boolean): number =>
    props.copies.filter(test).reduce((sum, group) => sum + group.count, 0);

/** "Foil" for every copy, "2 of 4 foil" for some. */
const describe = (kind: string, count: number, params: Record<string, string> = {}): string =>
    count === total.value
        ? t(`pages.deck.copies.marker.${kind}_all`, params)
        : t(`pages.deck.copies.marker.${kind}_some`, { ...params, count, total: total.value });

const markers = computed<Marker[]>(() => {
    const list: Marker[] = [];
    const finishes = [
        { key: "foil", icon: "star" },
        { key: "etched", icon: "texture" }
    ] as const;
    for (const finish of finishes) {
        const count = countWhere(group => group.finish === finish.key);
        if (count > 0) list.push({ ...finish, count, flag: null, text: describe(finish.key, count) });
    }
    const proxies = countWhere(group => group.proxy);
    if (proxies > 0)
        list.push({ key: "proxy", icon: "print", count: proxies, flag: null, text: describe("proxy", proxies) });

    const languages = [...new Set(props.copies.map(group => group.language))].filter(lang => lang !== "en");
    for (const lang of languages) {
        const count = countWhere(group => group.language === lang);
        const language = t(`enums.card_languages.${lang}`);
        list.push({
            key: `lang-${lang}`,
            icon: null,
            flag: flagSrc(lang),
            count,
            text: describe("language", count, { language })
        });
    }

    return list;
});

/** HTML tooltip — FloatingVue runs with `html: true` (main.ts) — one line per flag. */
const tooltip = computed(() => markers.value.map(marker => marker.text).join("<br />"));
const label = computed(() => markers.value.map(marker => marker.text).join(", "));
</script>

<template>
    <span
        v-if="markers.length"
        v-tooltip="{ content: tooltip, container: tooltipContainer ?? 'body' }"
        class="deck-copies"
        :class="`deck-copies--${variant ?? 'inline'}`"
        role="img"
        :aria-label="label"
    >
        <span
            v-for="marker in markers"
            :key="marker.key"
            class="deck-copies__marker"
            :class="`deck-copies__marker--${marker.key}`"
        >
            <icon v-if="marker.icon" :name="marker.icon" :size="0" />
            <img v-else-if="marker.flag" :src="marker.flag" alt="" class="deck-copies__flag" />
            <span v-if="marker.count < total" class="deck-copies__count">{{ marker.count }}</span>
        </span>
    </span>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.deck-copies {
    display: inline-flex;
    align-items: center;
    flex-shrink: 0;

    gap: 2px;

    cursor: help;

    &__marker {
        display: inline-flex;
        align-items: center;
        justify-content: center;

        min-width: map.get(s.$pages, "deck", "flags", "size");
        height: map.get(s.$pages, "deck", "flags", "size");
        padding: 0 map.get(s.$pages, "deck", "flags", "padding");
        border: map.get(s.$pages, "deck", "flags", "border") solid map.get(c.$state, "info", "border");
        gap: 2px;

        background-color: map.get(c.$state, "info", "background");
        color: map.get(c.$state, "info", "surface");
        border-radius: map.get(s.$pages, "deck", "flags", "radius", "text");

        font-weight: 600;
        line-height: 1;
    }

    &__flag {
        width: auto;
        height: 0.75rem;
    }

    &__count {
        font-size: 0.75rem;
    }

    &--corner {
        position: absolute;
        bottom: 2rem;
        left: 0.25rem;
        align-items: flex-start;

        flex-direction: column-reverse;
    }

    &--corner &__marker {
        min-width: 1.5rem;
        height: 1.5rem;

        background-color: map.get(c.$pages, "deck", "quantity", "background");
        color: map.get(c.$pages, "deck", "quantity", "surface");
        border-radius: map.get(s.$pages, "deck", "flags", "radius", "image");
        border-color: transparent;
    }
}
</style>
