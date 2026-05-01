<script setup lang="ts">
import { ref, useTemplateRef, watch } from "vue";
const props = withDefaults(
    defineProps<{
        /** HTML `id` and `name` for the hidden input — auto-generated if omitted. */
        refId: string;
        /** Initial checked state on mount. */
        checkedInitially?: boolean;
        /** Whether the checkbox is disabled. */
        disabled?: boolean;
        /** Whether the checkbox is in indeterminate state (some selected). */
        indeterminate?: boolean;
        /** Visible label text (hidden off-screen, used for accessibility). */
        label?: string;
        /** Form value submitted when checked. */
        value?: string;
    }>(),
    {
        refId: () => Math.random().toString(36).substring(2),
        checkedInitially: false,
        indeterminate: false,
        value: "true"
    }
);
/** Local checked state — initialised from `checkedInitially` and updated on user interaction. */
const checkboxStatus = ref(props.checkedInitially);
watch(
    () => props.checkedInitially,
    value => {
        checkboxStatus.value = value;
    }
);
const inputRef = useTemplateRef<HTMLInputElement>("inputRef");
watch(
    () => props.indeterminate,
    value => {
        if (inputRef.value) inputRef.value.indeterminate = value;
    },
    { flush: "post", immediate: true }
);
/** @emits change — Fired with the new boolean checked state whenever the user toggles the checkbox. */
const emit = defineEmits<{
    change: [checked: boolean];
}>();
/** Syncs local state with the native input and emits the new checked value to the parent. */
const onCheckboxChange = (event: Event) => {
    checkboxStatus.value = (event.target as HTMLInputElement).checked;
    emit("change", checkboxStatus.value);
};
</script>

<template>
    <input
        ref="inputRef"
        :id="refId"
        type="checkbox"
        :name="refId"
        :disabled="disabled"
        @change="onCheckboxChange"
        :value="value"
        :checked="checkboxStatus"
    />
    <label :for="refId">{{ label }}</label>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

input {
    position: absolute;

    opacity: 0;

    width: 0;
    height: 0;

    &:checked + label {
        background-color: map.get(c.$components, "checkbox", "background-checked");
        border-color: map.get(c.$components, "checkbox", "border-checked");

        &::after {
            opacity: 1;

            transform: rotate(45deg) scale(1);
        }
    }

    &:indeterminate + label {
        background-color: map.get(c.$components, "checkbox", "background-checked");
        border-color: map.get(c.$components, "checkbox", "border-checked");

        &::after {
            opacity: 1;

            width: 50%;
            height: 0;
            border-right: 0;
            border-bottom: 2px solid map.get(c.$components, "checkbox", "checkmark");
            margin-bottom: 0;

            transform: rotate(0deg) scale(1);
        }
    }

    // Disabled: dim the box and switch the cursor so the unclickable
    // state reads visually. The native input ignores click events
    // (because of `:disabled`); the label is just a styled proxy and
    // would otherwise stay fully opaque + pointer-cursor.
    &:disabled + label {
        opacity: 0.5;

        cursor: not-allowed;
    }
}

label {
    display: flex;
    position: relative;
    align-items: center;
    justify-content: center;

    width: map.get(s.$components, "checkbox", "size");
    height: map.get(s.$components, "checkbox", "size");
    border: map.get(s.$components, "checkbox", "border") solid map.get(c.$components, "checkbox", "border");

    background-color: map.get(c.$components, "checkbox", "background");
    border-radius: map.get(s.$components, "checkbox", "radius");

    text-indent: -9999px;

    cursor: pointer;

    transition:
        background-color map.get(ti.$timings, "quick") ease,
        border-color map.get(ti.$timings, "quick") ease;

    &::after {
        position: absolute;

        opacity: 0;

        width: 30%;
        height: 55%;
        border-right: 2px solid map.get(c.$components, "checkbox", "checkmark");
        border-bottom: 2px solid map.get(c.$components, "checkbox", "checkmark");
        margin-bottom: 10%;

        transform: rotate(45deg) scale(0.5);

        content: "";

        transition:
            opacity map.get(ti.$timings, "quick") ease,
            transform map.get(ti.$timings, "quick") ease;
    }
}
</style>
