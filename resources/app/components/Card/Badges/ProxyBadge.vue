<script setup lang="ts">
/******************************************************************************
 * Inline badge marking a card_stack as a proxy. Wraps a `<badge>` around
 * the `print` icon (and an optional "Proxy" label) so the visual is
 * consistent with the rest of the badge set (game-changer, MLD,
 * collection-status). Caller-side: render anywhere a stack-level proxy
 * indicator is appropriate.
 *
 * Use `show-label` to surface the localised text next to the icon —
 * appropriate in roomy contexts like the card preview modal. When
 * omitted (default), the badge is icon-only and the localised text
 * lives in the tooltip — appropriate in tight contexts like a
 * collection-table cell.
 *****************************************************************************/
import { useI18n } from "vue-i18n";
import Badge from "Components/UI/Badge.vue";
import Icon from "Components/UI/Icon.vue";
withDefaults(
    defineProps<{
        /** When true, renders the localised "Proxy" label next to the icon. */
        showLabel?: boolean;
    }>(),
    { showLabel: false }
);
const { t } = useI18n();
</script>

<template>
    <badge class="proxy-badge" v-tooltip="showLabel ? false : t('form.fields.proxy.label')">
        <icon name="print" :size="1" />
        <template v-if="showLabel">{{ t("form.fields.proxy.label") }}</template>
    </badge>
</template>

<style scoped lang="scss">
.proxy-badge {
    display: inline-flex;
}
</style>
