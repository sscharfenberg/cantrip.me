<script setup lang="ts">
/******************************************************************************
 * Private / public as an icon badge. Given an `endpoint` — the owner, on
 * the deck page — it is also the control that changes it: a click opens a
 * menu with both, the current one checked and not selectable, and a pick
 * is PATCHed to the endpoint as `{ visibility }`.
 *****************************************************************************/
import { router } from "@inertiajs/vue3";
import { computed, ref, useId } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";

const props = defineProps<{
    /** The visibility value ("private" or "public"). */
    visibility: string;
    /** Where to PATCH a new visibility — makes the badge open the visibility menu. */
    endpoint?: string;
}>();

const { t } = useI18n();
const VISIBILITIES = [
    { value: "private", icon: "visibility-off" },
    { value: "public", icon: "visibility-on" }
] as const;
const currentIcon = computed(() => (props.visibility === "private" ? "visibility-off" : "visibility-on"));
const tooltip = computed(() => `${t("pages.deck.visibility")}: ${t(`enums.visibility.${props.visibility}`)}`);
const popoverId = useId();
const anchor = `--visibility-badge-${popoverId}`;
/** True while a change is on its way — the menu waits for it. */
const processing = ref(false);
function onSelect(target: string): void {
    document.getElementById(popoverId)?.hidePopover();
    if (target === props.visibility || processing.value || props.endpoint === undefined) return;
    processing.value = true;
    router.patch(
        props.endpoint,
        { visibility: target },
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
            }
        }
    );
}
</script>

<template>
    <div v-if="endpoint" class="visibility-picker">
        <button
            type="button"
            :popovertarget="popoverId"
            v-tooltip="tooltip"
            :aria-label="tooltip"
            :class="['visibility-badge', `visibility-badge--${visibility}`, 'visibility-picker__trigger']"
            :disabled="processing"
        >
            <icon :name="currentIcon" :size="1" />
        </button>
        <dialog :id="popoverId" popover class="popover-content visibility-picker__menu">
            <ul class="popover-list">
                <li v-for="option in VISIBILITIES" :key="option.value">
                    <button
                        type="button"
                        class="popover-list-item"
                        :class="{ 'popover-list-item--selected': option.value === visibility }"
                        :disabled="option.value === visibility || processing"
                        :aria-current="option.value === visibility ? 'true' : undefined"
                        @click.prevent="onSelect(option.value)"
                    >
                        <icon :name="option.icon" :size="1" />
                        {{ t(`enums.visibility.${option.value}`) }}
                        <icon
                            v-if="option.value === visibility"
                            name="check"
                            :size="1"
                            :additional-classes="['visibility-picker__check']"
                        />
                    </button>
                </li>
            </ul>
        </dialog>
    </div>
    <span v-else v-tooltip="tooltip" :class="['visibility-badge', `visibility-badge--${visibility}`]">
        <icon :name="currentIcon" :size="1" />
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

.visibility-picker {
    display: inline-flex;

    &__trigger {
        cursor: pointer;
        anchor-name: v-bind(anchor);
    }

    &__menu {
        position-anchor: v-bind(anchor);
    }

    &__check {
        margin-left: auto;
    }
}
</style>
