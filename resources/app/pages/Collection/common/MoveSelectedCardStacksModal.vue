<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import type { ContainerListItem } from "@/types/containerListItem";
import FormGroup from "Components/Form/FormGroup.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
/** @emits close — Fired when the modal should be dismissed (cancellation or successful move). */
const emit = defineEmits<{ close: [] }>();
const props = defineProps<{
    /** Lightweight list of all user containers for the container dropdown. */
    containers: ContainerListItem[];
    selectedIds: Array<string>;
    containerName: string;
}>();
/** Container options formatted for MonoSelect: `{ value, label }` pairs. */
const containerOptions = computed(() =>
    props.containers.map(container => ({
        value: container.id,
        label: container.name
    }))
);
/** Currently selected container id. */
const selectedContainer = ref("");
/** True while the PATCH request is in flight. */
const processing = ref(false);
/** Server-side validation errors. */
const errors = ref<Record<string, string>>({});
/** Update the selected container. */
const onOptionsChange = (value: string) => {
    selectedContainer.value = value;
};
/**
 * Sends a PATCH request via Inertia router to move the selected card stacks.
 * On success the modal is closed — the controller redirects to the
 * target container or containers list with a flash message.
 */
const onSubmit = () => {
    processing.value = true;
    router.patch(
        "/collection/cardstack/move",
        {
            card_stack_ids: props.selectedIds,
            container_id: selectedContainer.value || null
        },
        {
            onSuccess: () => {
                emit("close");
            },
            onError: responseErrors => {
                errors.value = responseErrors;
            },
            onFinish: () => {
                processing.value = false;
            }
        }
    );
};
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ $t("pages.container_page.move.title") }}</template>
        <form id="move-selected-cardstacks-form" class="form" @submit.prevent="onSubmit">
            <form-legend
                :items="[
                    { slot: 'question', icon: 'question' },
                    { slot: 'unsorted', icon: 'collection' }
                ]"
            >
                <template #question>{{
                    $t("pages.container_page.move.question", {
                        amount: selectedIds.length,
                        container: containerName
                    })
                }}</template>
                <template #unsorted
                    ><strong>{{ $t("pages.container_page.move.unsorted") }}</strong></template
                >
            </form-legend>
            <form-group
                :label="$t('form.fields.container.id')"
                :error="errors.container_id ?? ''"
                :invalid="!!errors?.container_id"
                :required="true"
            >
                <mono-select
                    :options="containerOptions"
                    :selected="selectedContainer"
                    @change="onOptionsChange($event)"
                    addon-icon="storage"
                />
            </form-group>
        </form>
        <template #footer>
            <button type="submit" form="move-selected-cardstacks-form" class="btn-primary" :disabled="processing">
                <icon name="move" />
                {{ $t("pages.container_page.move.submit", { number: selectedIds.length }) }}
                <loading-spinner v-if="processing" :size="2" />
            </button>
        </template>
    </modal>
</template>
