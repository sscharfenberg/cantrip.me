<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import DeckActionsMenu from "@/pages/Deck/Actions/DeckActionsMenu.vue";
import type { DeckRow } from "@/pages/Decks/DecksPage.vue";
import ColorIdentity from "Components/Card/ColorIdentity.vue";
import DeckState from "Components/Deck/DeckState.vue";
import Badge from "Components/UI/Badge.vue";
import Icon from "Components/UI/Icon.vue";
import VisibilityBadge from "Components/UI/VisibilityBadge.vue";
import { useFormatting } from "Composables/useFormatting.ts";
defineProps<{
    /** A single deck row from the controller. */
    deck: DeckRow;
}>();
const { t } = useI18n();
const { formatDateTime, formatPrice } = useFormatting();
</script>

<template>
    <Link class="decklist__link" :href="`/decks/${deck.id}`">
        <color-identity :color-identity="deck.colors" />
        <span class="decklist__name">{{ deck.name }}</span>
        <deck-state :state="deck.state" />
        <badge class="decklist__cards">
            <icon name="deck" :size="1" />
            {{ deck.card_count }}
            <span>{{ t("pages.decks.card_count_noun", deck.card_count) }}</span>
        </badge>
        <badge v-tooltip="formatDateTime(deck.last_activity)" class="decklist__timestamp" type="info">
            <icon name="calendar" :size="1" />
        </badge>
        <badge v-if="deck.bracket" v-tooltip="t('form.fields.deck_bracket_hint')" class="deck-bracket" type="info">
            <icon name="swords" :size="1" />
            <span>{{ deck.bracket }}</span>
        </badge>
        <badge v-tooltip="t('pages.deck.total_worth')" class="decklist__worth" type="info">
            <icon name="money" :size="1" />
            <span>{{ formatPrice(deck.total_worth) }}</span>
        </badge>
        <visibility-badge :visibility="deck.visibility" />
        <deck-actions-menu :deck="deck" :is-archived="deck.state === 'archived'" />
    </Link>
</template>

<style lang="scss" scoped>
/** styles can be found in
 * resources/app/styles/components/deck/_decklist.scss
 */
@use "Abstracts/mixins" as m;

:deep(.visibility-badge) {
    display: none;

    @include m.mq("landscape") {
        display: inline-flex;

        width: calc(24px + 0.7rem);
        height: calc(24px + 0.7rem);
    }
}

.deck-bracket {
    display: none;

    @include m.mq("landscape") {
        display: inline-flex;
    }
}

:deep(.popover) {
    justify-self: end;
}

:deep(.deck-state) {
    display: none;

    // font-size: 0.8em;

    @include m.mq("landscape") {
        display: inline-flex;
    }
}

.decklist__timestamp {
    padding: 0.4rem 0.5rem;
}
</style>
