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
import { useFormatting } from "Composables/useFormatting.ts";
import type { Container } from "Types/container.ts";
const { formatDecimals } = useFormatting();
/** @emits close — Fired after successful move or when the user cancels. */
const emit = defineEmits<{ close: [] }>();
const props = defineProps<{
    container: Container;
    containers: ContainerListItem[];
}>();
/** Container options excluding the source, formatted for MonoSelect. */
const containerOptions = computed(() =>
    props.containers.filter(c => c.id !== props.container.id).map(c => ({ value: c.id, label: c.name }))
);
/** Currently selected target container id. */
const selectedContainer = ref("");
/** True while the request is in flight. */
const processing = ref(false);
/** Server-side validation errors. */
const errors = ref<Record<string, string>>({});
/** Update the selected container. */
const onOptionsChange = (value: string) => {
    selectedContainer.value = value;
};
/**
 * Sends a PATCH request via Inertia router to move all card stacks
 * from the source container to the selected target.
 */
const onSubmit = () => {
    processing.value = true;
    router.patch(
        `/containers/${props.container.id}/move-all`,
        {
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
        <template #header>
            <i18n-t keypath="pages.container_page.mass_move.title" scope="global">
                <template #name
                    ><cite>{{ container.name }}</cite></template
                >
            </i18n-t>
        </template>
        <form id="move-all-cardstacks-form" class="form" @submit.prevent="onSubmit">
            <form-legend
                :items="[
                    { slot: 'info', icon: 'info' },
                    { slot: 'unsorted', icon: 'info' }
                ]"
            >
                <template #info>
                    <i18n-t keypath="pages.container_page.mass_move.question" scope="global">
                        <template #amount
                            ><strong>{{ formatDecimals(container.totalCards) }}</strong></template
                        >
                        <template #source
                            ><strong>{{ container.name }}</strong></template
                        >
                    </i18n-t>
                </template>
                <template #unsorted>{{ $t("pages.container_page.mass_move.unsorted") }} </template>
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
            <button type="submit" form="move-all-cardstacks-form" class="btn-primary" :disabled="processing">
                <icon name="move" />
                {{ $t("pages.container_page.mass_move.submit", { number: formatDecimals(container.totalCards) }) }}
                <loading-spinner v-if="processing" :size="2" />
            </button>
        </template>
    </modal>
</template>
