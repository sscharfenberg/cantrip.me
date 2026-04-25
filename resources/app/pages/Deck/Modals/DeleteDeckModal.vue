<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import type { DeleteDeckTarget } from "@/utils/deleteDeck.ts";
import FormLegend from "Components/Form/FormLegend.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
/** @emits close — Fired after successful deletion or when the user cancels. */
const emit = defineEmits<{ close: [] }>();
/** @props target — Minimal deck info: id + name for the request and heading. */
const props = defineProps<{ target: DeleteDeckTarget }>();
const { t } = useI18n();
/** True while the DELETE request is in flight. */
const processing = ref(false);
/**
 * Issue the DELETE. The controller flashes a success message and redirects
 * to the deck-list page, so no explicit reload is needed here — the Inertia
 * response already contains the updated page props.
 */
const onDelete = () => {
    processing.value = true;
    router.delete(`/decks/${props.target.id}`, {
        onSuccess: () => emit("close"),
        onFinish: () => {
            processing.value = false;
        }
    });
};
</script>

<template>
    <modal @close="emit('close')">
        <template #header>
            <i18n-t keypath="pages.decks.delete.title" scope="global">
                <template #name
                    ><cite>{{ target.name }}</cite></template
                >
            </i18n-t>
        </template>
        <form-legend
            :items="[
                { slot: 'warning', icon: 'warning', modifier: 'warning' },
                { slot: 'question', icon: 'question', modifier: 'error' }
            ]"
        >
            <template #warning>
                {{ t("pages.decks.delete.warning") }}
            </template>
            <template #question
                ><span>{{ t("pages.decks.delete.question") }}</span></template
            >
        </form-legend>
        <template #footer>
            <button type="button" class="btn-default" :disabled="processing" @click="emit('close')">
                <icon name="close" />
                {{ t("pages.decks.delete.neg") }}
            </button>
            <button type="button" class="btn-primary" :disabled="processing" @click="onDelete">
                <icon name="delete" />
                {{ t("pages.decks.delete.aff") }}
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
