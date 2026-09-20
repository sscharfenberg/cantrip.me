<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Badge from "Components/UI/Badge.vue";
import Icon from "Components/UI/Icon.vue";
const props = defineProps<{
    /** The deck state value ("planned", "built", or "archived"). */
    state: string;
    /**
     * Append the per-card badge legend to the tooltip. Deck state is not
     * just a label any more — a planned deck shows collection
     * availability per card, a finished one its tracking-mode status —
     * and this badge is where that gets explained. Owner-only, and only
     * while the collection master switch is on: the legend describes
     * icons that render nowhere else.
     */
    explain?: boolean;
}>();
const { t } = useI18n();
/** Badge type mapped from deck state. */
const badgeType = computed<"info" | "success" | "warning">(() => {
    if (props.state === "built") return "success";
    if (props.state === "archived") return "warning";
    return "info";
});
/** Icon name mapped from deck state ("built" uses the "finished" icon). */
const iconName = computed<string>(() => (props.state === "built" ? "finished" : props.state));
/**
 * Tooltip: the plain "Deck is X" line, plus — when `explain` is set —
 * what the per-card badges of that state mean. HTML tooltip; FloatingVue
 * is configured with `html: true` in main.ts.
 */
const tooltip = computed<string>(() => {
    const lines = [`${t("pages.deck.state_is")} ${t(`enums.deck_state.${props.state}`)}`];
    if (!props.explain) return lines.join("<br />");
    if (props.state === "planned") {
        lines.push(
            t("pages.deck.state_tooltip.planned.intro"),
            t("pages.deck.state_tooltip.planned.available"),
            t("pages.deck.state_tooltip.planned.partial"),
            t("pages.deck.state_tooltip.planned.unavailable"),
            t("pages.deck.state_tooltip.planned.note")
        );
    } else {
        lines.push(t("pages.deck.state_tooltip.tracked"));
    }
    return lines.join("<br />");
});
</script>

<template>
    <badge v-tooltip="tooltip" class="deck-state" :type="badgeType">
        <icon :name="iconName" :size="1" />
        <span>{{ t(`enums.deck_state.${state}`) }}</span>
    </badge>
</template>
