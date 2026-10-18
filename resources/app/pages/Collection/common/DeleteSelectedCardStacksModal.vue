<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { ref } from "vue";
import { withReturn } from "@/utils/returnPath.ts";
import FormLegend from "Components/Form/FormLegend.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
/** @emits close — Fired when the modal should be dismissed. */
const emit = defineEmits<{ close: [] }>();
const props = defineProps<{
    selectedIds: Array<string>;
    containerName: string;
}>();
/** True while the DELETE request is in flight. */
const processing = ref(false);
/**
 * Sends a DELETE request via Inertia router to remove the selected card stacks.
 * On success the modal is closed — the controller redirects back to the page
 * the delete was made from, search, filters and page included, with a flash
 * message.
 */
const onDelete = () => {
    processing.value = true;
    router.delete(withReturn("/collection/cardstack/delete-selected"), {
        data: { card_stack_ids: props.selectedIds },
        onSuccess: () => {
            emit("close");
        },
        onFinish: () => {
            processing.value = false;
        }
    });
};
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ $t("pages.container_page.delete_selected.title") }}</template>
        <form-legend :items="[{ slot: 'question', icon: 'question' }]">
            <template #question>{{
                $t("pages.container_page.delete_selected.question", {
                    amount: selectedIds.length,
                    container: containerName
                })
            }}</template>
        </form-legend>
        <template #footer>
            <button type="button" class="btn-default" :disabled="processing" @click="$emit('close')">
                <icon name="close" />
                {{ $t("pages.container_page.delete_selected.neg") }}
            </button>
            <button type="button" class="btn-primary" :disabled="processing" @click="onDelete">
                <icon name="delete" />
                {{ $t("pages.container_page.delete_selected.aff") }}
                <loading-spinner v-if="processing" :size="2" />
            </button>
        </template>
    </modal>
</template>

<style lang="scss" scoped>
.btn-primary {
    margin-left: auto;
}
</style>
