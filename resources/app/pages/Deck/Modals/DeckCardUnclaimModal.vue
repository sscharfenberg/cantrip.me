<script setup lang="ts">
/******************************************************************************
 * Unclaim a card on the deck page: free the collection stacks claimed for
 * it, so other decks can have them — the deck-side twin of the collection
 * tables' unclaim dialog.
 *
 * The freed cards can be put somewhere else in the same step: the picker
 * starts on the container they lie in now (normally the deck's deckbox),
 * offers "Unsorted" and every other container, and only a changed choice
 * moves them. "Remove the card from the deck as well" (off by default)
 * takes the card out of the deck list too.
 *
 * The containers are the page's own `containers` prop — the deck page
 * ships the owner's containers for its collection dialogs.
 *****************************************************************************/
import { router, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import Checkbox from "Components/Form/Checkbox.vue";
import FormGroup from "Components/Form/FormGroup.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import type { DeckCardRow } from "Types/deckPage.ts";
/** `move_to` value for "no container" — `MovesFreedStacks::UNSORTED`. */
const UNSORTED = "unsorted";
const props = defineProps<{
    deckId: string;
    card: Pick<DeckCardRow, "id" | "name" | "quantity" | "claimed_stacks">;
}>();
/** @emits close — Fired when the dialog should be dismissed (cancelled or done). */
const emit = defineEmits<{ close: [] }>();
const page = usePage();
const { t } = useI18n();
const containers = computed(() => (page.props.containers as { id: string; name: string }[] | undefined) ?? []);
/** Where the claimed copies lie now — the first stack's place; they normally lie together. */
const initial = props.card.claimed_stacks[0]?.container_id ?? UNSORTED;
const options = computed(() => [
    { value: UNSORTED, label: t("pages.collection.unsorted") },
    ...containers.value.map(container => ({ value: container.id, label: container.name }))
]);
const moveTo = ref<string>(initial);
const removeFromDeck = ref(false);
const processing = ref(false);
const failed = ref(false);
async function onSubmit(): Promise<void> {
    if (processing.value) return;
    processing.value = true;
    failed.value = false;
    try {
        const response = await fetch(`/api/decks/${props.deckId}/cards/${props.card.id}/claims`, {
            method: "DELETE",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": page.props.csrfToken as string,
                Accept: "application/json"
            },
            body: JSON.stringify({
                ...(moveTo.value === initial ? {} : { move_to: moveTo.value }),
                ...(removeFromDeck.value ? { remove_from_deck: true } : {})
            })
        });
        if (!response.ok) {
            failed.value = true;
            return;
        }
        router.reload({ only: ["cards", "deck", "violations", "tokens", "hasUnclaimedCards"] });
        emit("close");
    } catch {
        failed.value = true;
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ t("pages.deck.unclaim.title") }}</template>
        <form id="deck-card-unclaim-form" class="form" @submit.prevent="onSubmit">
            <form-legend :items="[{ slot: 'question', icon: 'question' }]">
                <template #question>{{
                    t("pages.deck.unclaim.question", { quantity: card.quantity, name: card.name })
                }}</template>
            </form-legend>
            <form-group
                :label="t('pages.deck.unclaim.label')"
                :error="failed ? t('pages.deck.unclaim.error') : ''"
                :invalid="failed"
            >
                <mono-select
                    :options="options"
                    :selected="moveTo"
                    :sort="false"
                    :clearable="false"
                    addon-icon="storage"
                    @change="moveTo = $event || initial"
                />
            </form-group>
            <form-group for-id="deck-card-unclaim-remove" :label="t('pages.deck.unclaim.remove_from_deck')">
                <template #addon>
                    <checkbox
                        ref-id="deck-card-unclaim-remove"
                        :label="t('pages.deck.unclaim.remove_from_deck')"
                        @change="removeFromDeck = $event"
                    />
                </template>
            </form-group>
        </form>
        <template #footer>
            <button type="button" class="btn-default" :disabled="processing" @click="emit('close')">
                <icon name="close" />
                {{ t("pages.deck.unclaim.cancel") }}
            </button>
            <button type="submit" form="deck-card-unclaim-form" class="btn-primary" :disabled="processing">
                <icon name="unclaimed" />
                {{ t("pages.deck.unclaim.submit") }}
                <loading-spinner v-if="processing" :size="2" />
            </button>
        </template>
    </modal>
</template>
