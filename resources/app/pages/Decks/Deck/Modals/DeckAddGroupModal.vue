<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import { nextTick, onMounted, ref } from "vue";
import FormGroup from "Components/Form/FormGroup.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import type { DeckCardRow } from "Types/deckPage.ts";
const emit = defineEmits<{ close: [] }>();
const props = defineProps<{
    /** UUID of the deck this category belongs to. */
    deckId: string;
    /** The card that was dragged to trigger group creation, if any. */
    card?: DeckCardRow;
    /** Maximum length for a category name — passed from the backend model constant. */
    categoryNameMax: number;
}>();
const form = useForm({
    group_name: "",
    card_id: props.card?.id ?? null
});
const inputRef = ref<HTMLInputElement | null>(null);
onMounted(() => {
    nextTick(() => inputRef.value?.focus());
});
/** POST the new category name and close the modal on success. */
const submit = () => {
    form.post(`/decks/${props.deckId}/categories`, {
        onSuccess: () => emit("close")
    });
};
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ $t("pages.deck.create_group.title") }}</template>
        <form class="form" @submit.prevent="submit">
            <form-legend :items="[{ slot: 'explanation', icon: 'info' }]">
                <template #explanation>{{
                    props.card
                        ? $t("pages.deck.create_group.explanation", { card: props.card.name })
                        : $t("pages.deck.create_group.explanation_empty")
                }}</template>
            </form-legend>
            <form-group
                for-id="group_name"
                :label="$t('form.fields.group_name')"
                :error="form.errors.group_name ?? ''"
                :invalid="!!form.errors.group_name"
                :required="true"
                addon-icon="text"
            >
                <input
                    ref="inputRef"
                    v-model="form.group_name"
                    type="text"
                    name="group_name"
                    id="group_name"
                    class="form-input"
                    :maxlength="props.categoryNameMax"
                />
            </form-group>
        </form>
        <template #footer>
            <button type="submit" class="btn-primary" :disabled="form.processing" @click="submit">
                <icon name="save" />
                {{ $t("pages.deck.create_group.submit") }}
            </button>
        </template>
    </modal>
</template>
