<script setup lang="ts">
/******************************************************************************
 * One list of a format's tab — its banned or its restricted cards.
 *
 * Cards banned by name are grouped by colour the way the deck text view
 * groups by type: same headlines, same rows, same balanced column split.
 * The blanket bans follow as one line each, the way Wizards' own lists put
 * them ("25 cards with the card type Conspiracy"), each opening onto its
 * cards. The headline counts both.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import DeckGroupHeadline from "@/pages/Deck/Sections/DeckGroupHeadline.vue";
import BannedRestrictedCardRow from "@/pages/Guest/BannedRestrictedCardRow.vue";
import ColorIdentity from "Components/Card/ColorIdentity.vue";
import Accordion from "Components/UI/Accordion.vue";
import Headline from "Components/UI/Headline.vue";
import { useResponsiveColumns } from "Composables/useResponsiveColumns.ts";
import type { BannedRestrictedCard, BannedRestrictedCards, BannedRestrictedGroupKey } from "Types/bannedRestricted.ts";
const props = defineProps<{
    legality: "banned" | "restricted";
    list: BannedRestrictedCards;
    /** Every card on the page, by oracle id. */
    cards: Record<string, BannedRestrictedCard>;
}>();
/** @emits preview — Id of the printing whose detail modal should open. */
const emit = defineEmits<{ preview: [printingId: string] }>();
const { t } = useI18n();
/** Resolve oracle ids to their cards, dropping any the payload lacks. */
const resolve = (ids: string[]) => ids.flatMap(id => (props.cards[id] ? [{ id, ...props.cards[id] }] : []));
const sections = computed(() => props.list.groups.map(group => ({ key: group.group, cards: resolve(group.cards) })));
const categories = computed(() =>
    props.list.categories.map(category => ({ key: category.category, cards: resolve(category.cards) }))
);
/**
 * The mana symbols a group's headline shows, as a `ColorIdentity` string: its
 * own colour, all five for multicolor, `{C}` for colorless (null). Lands are
 * no colour, so they get none.
 */
const SYMBOLS: Partial<Record<BannedRestrictedGroupKey, string | null>> = {
    W: "W",
    U: "U",
    B: "B",
    R: "R",
    G: "G",
    multicolor: "WUBRG",
    colorless: null
};
const total = computed(() =>
    [...sections.value, ...categories.value].reduce((sum, part) => sum + part.cards.length, 0)
);
const { containerRef, columns } = useResponsiveColumns(sections, {
    minColWidth: 280,
    maxColumns: 3,
    colGap: 16,
    weight: section => 1 + section.cards.length
});
</script>

<template>
    <section class="banned-restricted-list">
        <headline :size="3">{{ t(`enums.card_legalities.${legality}`) }} ({{ total }})</headline>
        <div ref="containerRef" class="text-card-groups">
            <div v-for="(col, ci) in columns" :key="ci" class="text-card-groups__column">
                <section v-for="section in col" :key="section.key" class="text-card-group">
                    <deck-group-headline :level="4">
                        <span class="group-label">
                            <color-identity
                                v-if="section.key in SYMBOLS"
                                :color-identity="SYMBOLS[section.key] ?? null"
                                aria-hidden="true"
                            />
                            {{ t(`pages.banned_restricted.groups.${section.key}`) }} ({{ section.cards.length }})
                        </span>
                    </deck-group-headline>
                    <ul class="text-card-group__list">
                        <banned-restricted-card-row
                            v-for="card in section.cards"
                            :key="card.id"
                            :card="card"
                            @preview="id => emit('preview', id)"
                        />
                    </ul>
                </section>
            </div>
        </div>
        <div
            v-if="categories.length"
            class="banned-restricted-categories"
            :class="{ 'banned-restricted-categories--after-groups': sections.length }"
        >
            <accordion v-for="category in categories" :key="category.key" class="banned-restricted-category">
                <template #head>
                    <span class="banned-restricted-category__label">{{
                        t(
                            `pages.banned_restricted.categories.${category.key}`,
                            { count: category.cards.length },
                            category.cards.length
                        )
                    }}</span>
                </template>
                <template #body>
                    <div class="text-card-group">
                        <ul class="text-card-group__list banned-restricted-category__cards">
                            <banned-restricted-card-row
                                v-for="card in category.cards"
                                :key="card.id"
                                :card="card"
                                @preview="id => emit('preview', id)"
                            />
                        </ul>
                    </div>
                </template>
            </accordion>
        </div>
    </section>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/sizes" as s;

.banned-restricted-list + .banned-restricted-list {
    margin-top: map.get(s.$pages, "banned-restricted", "list", "margin");
}

.group-label {
    display: inline-flex;
    align-items: center;

    gap: 0.75ch;
}

.banned-restricted-categories {
    display: flex;
    flex-direction: column;

    gap: map.get(s.$pages, "banned-restricted", "categories", "gap");

    // Only below colour groups — a list of nothing but blanket bans starts
    // right under its headline.
    &--after-groups {
        margin-top: map.get(s.$pages, "banned-restricted", "categories", "margin");
    }
}

.banned-restricted-category {
    &__label {
        text-align: left;
    }

    // One long list would leave most of a wide screen empty: flow the rows
    // into as many columns as fit. Overrides the global list's flex column.
    &__cards {
        display: grid;
        grid-template-columns: repeat(
            auto-fill,
            minmax(min(100%, #{map.get(s.$pages, "banned-restricted", "categories", "column")}), 1fr)
        );
    }
}
</style>
