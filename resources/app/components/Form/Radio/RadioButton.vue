<script setup lang="ts">
import Icon from "Components/UI/Icon.vue";
defineProps<{
    value: string;
    name: string;
    label?: string;
    checked: boolean;
    icon?: string;
}>();
const emit = defineEmits(["change"]);
/** Forward the native change event to the parent form group. */
const onChange = (ev: Event) => emit("change", ev);
</script>

<template>
    <label :for="`${name}_${value}`" class="form-radio">
        <input
            :id="`${name}_${value}`"
            type="radio"
            :name="name"
            :value="value"
            :checked="checked ?? null"
            @change="onChange"
        />
        <span class="form-radio__button" />
        <span v-if="label?.length" class="form-radio__label">
            <icon v-if="icon?.length" :name="icon" />
            {{ label }}
        </span>
    </label>
</template>
