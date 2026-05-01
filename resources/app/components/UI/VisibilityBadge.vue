<script setup lang="ts">
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";

defineProps<{
    /** The visibility value ("private" or "public"). */
    visibility: string;
}>();

const { t } = useI18n();
</script>

<template>
    <span
        v-tooltip="t('pages.deck.visibility') + ': ' + t(`enums.visibility.${visibility}`)"
        :class="['visibility-badge', `visibility-badge--${visibility}`]"
    >
        <icon :name="visibility === 'private' ? 'visibility-off' : 'visibility-on'" :size="1" />
    </span>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.visibility-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    width: map.get(s.$components, "visibility-badge", "size");
    height: map.get(s.$components, "visibility-badge", "size");
    padding: map.get(s.$components, "visibility-badge", "padding");
    border: map.get(s.$components, "visibility-badge", "border") solid transparent;

    border-radius: map.get(s.$components, "visibility-badge", "radius");

    &--public {
        background-color: map.get(c.$state, "error", "background");
        color: map.get(c.$state, "error", "surface");
        border-color: map.get(c.$state, "error", "border");
    }

    &--private {
        background-color: map.get(c.$state, "success", "background");
        color: map.get(c.$state, "success", "surface");
        border-color: map.get(c.$state, "success", "border");
    }
}
</style>
