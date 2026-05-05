<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import DeckActionsMenu from "@/pages/Deck/Actions/DeckActionsMenu.vue";
import type { DeckRow } from "@/pages/Decks/Decks.vue";
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
const { formatDateTime } = useFormatting();
</script>

<template>
    <Link class="decklist__link" :href="`/decks/${deck.id}`">
        <color-identity :color-identity="deck.colors" />
        <span class="decklist__name">{{ deck.name }}</span>
        <deck-state :state="deck.state" />
        <span class="decklist__cards">
            <icon name="deck" />
            {{ deck.card_count }}
            <span>{{ t("pages.decks.card_count_noun", deck.card_count) }}</span>
        </span>
        <badge
            v-tooltip="formatDateTime(deck.last_activity)"
            class="decklist__timestamp"
            type="info"
        >
            <icon name="calendar" :size="1" />
        </badge>
        <badge
            v-if="deck.bracket"
            v-tooltip="t('form.fields.deck_bracket_hint')"
            class="deck-bracket"
            type="info"
        >
            <icon name="swords" :size="1" />
            <span>{{ deck.bracket }}</span>
        </badge>
        <visibility-badge :visibility="deck.visibility" />
        <deck-actions-menu :deck="deck" />
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
    font-size: 0.8em;

    span {
        display: none;

        @include m.mq("landscape") {
            display: inline-flex;
        }
    }
}
</style>
