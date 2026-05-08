<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Badge from "Components/UI/Badge.vue";
import Icon from "Components/UI/Icon.vue";
const props = defineProps<{
    /** The deck state value ("planned", "built", or "archived"). */
    state: string;
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
</script>

<template>
    <badge
        v-tooltip="`${t('pages.deck.state_is')} ${t(`enums.deck_state.${state}`)}`"
        class="deck-state"
        :type="badgeType"
    >
        <icon :name="iconName" :size="1" />
        <span>{{ t(`enums.deck_state.${state}`) }}</span>
    </badge>
</template>
