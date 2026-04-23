<script setup lang="ts">
import { computed } from "vue";
import Accordion from "Components/UI/Accordion.vue";
import Icon from "Components/UI/Icon.vue";
import type { DeckViolation } from "Types/deckPage";
const props = defineProps<{
    /** All legality violations for the deck. */
    violations: DeckViolation[];
}>();
/** Total number of violations — used in the panel header. */
const count = computed(() => props.violations.length);
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
                        <template v-if="'card_ids' in violation">
                            <i18n-t :keypath="`pages.deck.legality.${violation.type}`" scope="global">
                                <template #count
                                    ><strong>{{ violation.card_ids.length }}</strong></template
                                >
                            </i18n-t>
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
    &__head {
        display: flex;
        align-items: center;

        gap: 0.5rem;
    }

    &__list {
        display: flex;
        flex-direction: column;

        padding: 0;
        margin: 0;
        gap: 0.25rem;

        list-style: none;
    }

    &__row {
        display: flex;
        align-items: center;

        gap: 0.5rem;

        > span {
            padding-top: 0.25em;
        }
    }
}
</style>
