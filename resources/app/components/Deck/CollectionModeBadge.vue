<script setup lang="ts">
/******************************************************************************
 * Deck-header badge surfacing the current collection-integration mode.
 *
 * Three states (A / B / C — see `DeckCollectionStatusService`):
 *   A  tracking off    — clear icon
 *   B  implicit        — storage icon (count-based)
 *   C  per-copy        — key icon (each row pinned to a specific stack)
 *
 * Owner-only — the modal behind the click is the actionable surface.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
const props = defineProps<{
    /** Effective mode resolved by the controller. */
    mode: "A" | "B" | "C";
}>();
const emit = defineEmits<{ click: [] }>();
const { t } = useI18n();
const iconName = computed(() => {
    switch (props.mode) {
        case "A":
            return "clear";
        case "B":
            return "storage";
        case "C":
        default:
            return "key";
    }
});
const tooltip = computed(() => t(`pages.deck.collection_mode.modes.${props.mode}.tooltip`));
const label = computed(() => t(`pages.deck.collection_mode.modes.${props.mode}.label`));
</script>

<template>
    <button type="button" class="badge warning" v-tooltip="tooltip" @click="emit('click')">
        <icon :name="iconName" :size="1" />
        {{ label }}
    </button>
</template>

<style scoped lang="scss">
button {
    cursor: pointer;
}
</style>
