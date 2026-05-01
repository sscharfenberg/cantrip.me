<script setup lang="ts">
withDefaults(
    defineProps<{
        type?: "success" | "warning" | "error" | "info" | "caution";
    }>(),
    {
        type: "info"
    }
);
</script>

<template>
    <span class="badge" :class="type"><slot /></span>
</template>
