<script setup lang="ts">
import { computed } from "vue";
import Accordion from "Components/UI/Accordion.vue";
import Icon from "Components/UI/Icon.vue";
import type { DeckCardRow, DeckViolation } from "Types/deckPage.ts";
const props = defineProps<{
    /** All legality violations for the deck. */
    violations: DeckViolation[];
    /** All cards in the deck — used to resolve offending card_ids to names. */
    cards: DeckCardRow[];
}>();
/** Total number of violations — used in the panel header. */
const count = computed(() => props.violations.length);
/** deck_card id → card name lookup. */
const cardNames = computed(() => {
    const map: Record<string, string> = {};
    for (const card of props.cards) {
        map[card.id] = card.name;
    }
    return map;
});
/** Deduplicated, comma-joined card names for a per-card violation. */
const violationNames = (cardIds: string[]): string => {
    const names = new Set<string>();
    for (const id of cardIds) {
        const name = cardNames.value[id];
        if (name) {
            names.add(name);
        }
    }
    return [...names].join(", ");
};
</script>

<template>
    <accordion class="legality-panel">
        <template #head>
            <span class="legality-panel__head">
                <icon name="error" :size="2" />
                <i18n-t keypath="pages.deck.legality.title" scope="global" :plural="count">
                    <template #count
                        ><strong>{{ count }}</strong></template
                    >
                </i18n-t>
            </span>
        </template>
        <template #body>
            <ul class="legality-panel__list">
                <li v-for="(violation, index) in violations" :key="index" class="legality-panel__row">
                    <icon name="error" :size="1" :additional-classes="['legality-panel__icon']" />
                    <span>
                        <template v-if="violation.type === 'companion_restriction'">
                            <i18n-t
                                :keypath="`pages.deck.legality.companion_restriction.${violation.message_key}`"
                                scope="global"
                                :plural="violation.card_ids.length"
                            >
                                <template #count
                                    ><strong>{{ violation.card_ids.length }}</strong></template
                                >
                            </i18n-t>
                            <span class="legality-panel__names">{{ violationNames(violation.card_ids) }}</span>
                        </template>
                        <template v-else-if="violation.type === 'companion_size_restriction'">
                            <i18n-t keypath="pages.deck.legality.companion_restriction.yorion" scope="global">
                                <template #current
                                    ><strong>{{ violation.current }}</strong></template
                                >
                                <template #min
                                    ><strong>{{ violation.min }}</strong></template
                                >
                            </i18n-t>
                        </template>
                        <template v-else-if="violation.type === 'commander_banned'">
                            <i18n-t
                                keypath="pages.deck.legality.commander_banned"
                                scope="global"
                                :plural="violation.names.length"
                            >
                                <template #count
                                    ><strong>{{ violation.names.length }}</strong></template
                                >
                            </i18n-t>
                            <span class="legality-panel__names">{{ violation.names.join(", ") }}</span>
                        </template>
                        <template v-else-if="'card_ids' in violation">
                            <i18n-t
                                :keypath="`pages.deck.legality.${violation.type}`"
                                scope="global"
                                :plural="violation.card_ids.length"
                            >
                                <template #count
                                    ><strong>{{ violation.card_ids.length }}</strong></template
                                >
                            </i18n-t>
                            <span class="legality-panel__names">{{ violationNames(violation.card_ids) }}</span>
                        </template>
                        <template v-else-if="violation.type === 'deck_size_min'">
                            <i18n-t keypath="pages.deck.legality.deck_size_min" scope="global">
                                <template #current
                                    ><strong>{{ violation.current }}</strong></template
                                >
                                <template #min
                                    ><strong>{{ violation.min }}</strong></template
                                >
                            </i18n-t>
                        </template>
                        <template v-else-if="violation.type === 'deck_size_max'">
                            <i18n-t keypath="pages.deck.legality.deck_size_max" scope="global">
                                <template #current
                                    ><strong>{{ violation.current }}</strong></template
                                >
                                <template #max
                                    ><strong>{{ violation.max }}</strong></template
                                >
                            </i18n-t>
                        </template>
                        <template v-else-if="violation.type === 'sideboard_size_max'">
                            <i18n-t keypath="pages.deck.legality.sideboard_size_max" scope="global">
                                <template #current
                                    ><strong>{{ violation.current }}</strong></template
                                >
                                <template #max
                                    ><strong>{{ violation.max }}</strong></template
                                >
                            </i18n-t>
                        </template>
                    </span>
                </li>
            </ul>
        </template>
    </accordion>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.legality-panel {
    border-radius: map.get(s.$pages, "deck", "legality", "radius");

    &__head {
        display: flex;
        align-items: center;

        gap: map.get(s.$pages, "deck", "legality", "row-gap");
    }

    &__list {
        display: flex;
        flex-direction: column;

        padding: 0;
        margin: 0;
        gap: map.get(s.$pages, "deck", "legality", "gap");

        list-style: none;
    }

    &__row {
        display: flex;
        align-items: center;

        gap: map.get(s.$pages, "deck", "legality", "row-gap");

        > span {
            padding-top: map.get(s.$pages, "deck", "legality", "padding");
        }
    }

    &__names {
        opacity: 0.8;

        margin-left: map.get(s.$pages, "deck", "legality", "padding");

        font-style: italic;
    }
}
</style>
