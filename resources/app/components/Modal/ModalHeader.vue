<script setup lang="ts">
import Icon from "Components/UI/Icon.vue";
</script>

<template>
    <div class="modal-dialog__header">
        <h3 class="modal-dialog__title"><slot /></h3>
        <button
            type="button"
            class="btn-close"
            @click="$emit('close')"
            :aria-label="$t('modal.close')"
            tabindex="-1"
        >
            <span>{{ $t("modal.close") }}</span>
            <icon name="close" :size="1" />
        </button>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/typography" as t;

.modal-dialog {
    &__header {
        display: flex;
        position: relative;
        align-items: flex-start;

        padding: map.get(s.$components, "modal", "padding");
        gap: 1rem;

        background-color: map.get(c.$components, "modal", "title-background");

        color: map.get(c.$components, "modal", "title-surface");
        border-start-start-radius: calc(
            #{map.get(s.$components, "modal", "radius")} - #{map.get(s.$components, "modal", "border")}
        );
        border-start-end-radius: calc(
            #{map.get(s.$components, "modal", "radius")} - #{map.get(s.$components, "modal", "border")}
        );
    }

    &__title {
        margin: 5px 0 0;

        font-family: map.get(t.$main, "modal-title");
        font-size: 1.3rem;
        font-weight: 500;
        line-height: 1.4;
    }

    .btn-close {
        margin-left: auto;

        > span {
            display: none;

            @include m.mq("portrait") {
                display: block;
            }
        }
    }
}
</style>
