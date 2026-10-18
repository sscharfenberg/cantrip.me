<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { withReturn } from "@/utils/returnPath.ts";
import FormLegend from "Components/Form/FormLegend.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import type { CardStackRow } from "Types/cardStackRow.ts";

/** @emits close — Fired when the modal should be dismissed (cancellation or successful deletion). */
const emit = defineEmits<{ close: [] }>();
const props = defineProps<{
    cardStack: CardStackRow;
    containerName: string;
}>();
const { t } = useI18n();
/** True while the DELETE request is in flight — disables both buttons to prevent double submission. */
const processing = ref(false);

/** Human-readable description of the card stack, e.g. "3x Akron Hoplite (THS Theros 185) Near Mint Foil". */
const description = computed(() => {
    const parts: string[] = [
        `${props.cardStack.amount}x ${props.cardStack.name}`,
        `(${props.cardStack.set_code.toUpperCase()} ${props.cardStack.set_name} #${props.cardStack.collector_number})`
    ];
    if (props.cardStack.condition) {
        parts.push(t("enums.conditions." + props.cardStack.condition));
    }
    if (props.cardStack.finish) {
        parts.push(t("enums.finishes." + props.cardStack.finish));
    }
    return parts.join(" ");
});

/**
 * Sends a DELETE request via Inertia router to remove the card stack.
 * On success the modal is closed — the controller redirects back to the
 * page the delete was made from, search, filters and page included, with
 * a flash message.
 */
const onDelete = () => {
    processing.value = true;
    router.delete(withReturn(`/collection/cardstack/${props.cardStack.id}`), {
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
        <template #header>{{ $t("pages.container_page.delete.title") }}</template>
        <form-legend :items="[{ slot: 'question', icon: 'question' }]">
            <template #question>{{
                $t("pages.container_page.delete.question", {
                    description,
                    container: containerName
                })
            }}</template>
        </form-legend>
        <template #footer>
            <button type="button" class="btn-default" :disabled="processing" @click="$emit('close')">
                <icon name="close" />
                {{ $t("pages.container_page.delete.neg") }}
            </button>
            <button type="button" class="btn-primary" :disabled="processing" @click="onDelete">
                <icon name="delete" />
                {{ $t("pages.container_page.delete.aff") }}
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
