<script setup lang="ts">
/******************************************************************************
 * Inline badge marking a card as a "Game Changer" — a card on the
 * Commander format's Game Changer list. Mirrors `ProxyBadge` and
 * `MassLandDenialBadge`.
 *
 * Use `show-label` to surface the short "Game Changer" label next to
 * the icon (roomy contexts like the card preview modal). When omitted
 * (default), the badge is icon-only and the long-form sentence lives in
 * the tooltip — appropriate in tight contexts.
 *****************************************************************************/
import { useI18n } from "vue-i18n";
import Badge from "Components/UI/Badge.vue";
import Icon from "Components/UI/Icon.vue";
withDefaults(
    defineProps<{
        /** When true, renders the localised "Game Changer" label next to the icon. */
        showLabel?: boolean;
    }>(),
    { showLabel: false }
);
const { t } = useI18n();
</script>

<template>
    <badge class="game-changer-badge" v-tooltip="showLabel ? false : t('pages.deck.game_changer')">
        <icon name="balance" :size="1" :additional-classes="['card__game-changer']" />
        <template v-if="showLabel">{{ t("components.badges.game_changer") }}</template>
    </badge>
</template>

<style scoped lang="scss">
// Shrink to icon width when no label is rendered. See ProxyBadge for
// the why behind the class fallthrough + non-`:deep` selector.
.game-changer-badge {
    display: inline-flex;
}
</style>
