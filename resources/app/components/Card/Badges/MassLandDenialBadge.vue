<script setup lang="ts">
/******************************************************************************
 * Inline badge marking a card as Mass Land Denial — Scryfall's
 * `otag:mass-land-denial` overlay used by the Commander bracket
 * suggestion (bracket 4+). Mirrors `ProxyBadge` and `GameChangerBadge`.
 *
 * Use `show-label` to surface the short "Mass Land Denial" label next
 * to the icon (roomy contexts like the card preview modal). When
 * omitted (default), the badge is icon-only and the long-form sentence
 * lives in the tooltip — appropriate in tight contexts.
 *****************************************************************************/
import { useI18n } from "vue-i18n";
import Badge from "Components/UI/Badge.vue";
import Icon from "Components/UI/Icon.vue";
withDefaults(
    defineProps<{
        /** When true, renders the localised "Mass Land Denial" label next to the icon. */
        showLabel?: boolean;
    }>(),
    { showLabel: false }
);
const { t } = useI18n();
</script>

<template>
    <badge class="mld-badge" v-tooltip="showLabel ? false : t('pages.deck.mld')">
        <icon name="landslide" :size="1" :additional-classes="['card__mld']" />
        <template v-if="showLabel">{{ t("components.badges.mass_land_denial") }}</template>
    </badge>
</template>

<style scoped lang="scss">
// Shrink to icon width when no label is rendered. See ProxyBadge for
// the why behind the class fallthrough + non-`:deep` selector.
.mld-badge {
    display: inline-flex;
}
</style>
