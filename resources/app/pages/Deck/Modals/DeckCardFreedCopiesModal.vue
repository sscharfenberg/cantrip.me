<script setup lang="ts">
/******************************************************************************
 * Where should the cards go? Asked when "−" or "Remove" takes claimed copies
 * out of a deck while they lie in its deckbox: they leave the deck, so they
 * can't stay in its box. The picker offers "Unsorted" (the default) and
 * every other container; the deckbox itself is left out.
 *
 * Only picks the place — the removal itself is sent by `useDeckCardActions`,
 * which opened this dialog. Closing it keeps the cards in the deck.
 *
 * The containers are the page's own `containers` prop — the deck page ships
 * the owner's containers for its collection dialogs.
 *****************************************************************************/
import { usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import FormGroup from "Components/Form/FormGroup.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
/** `move_to` value for "no container" — `MovesFreedStacks::UNSORTED`. */
const UNSORTED = "unsorted";
const props = defineProps<{
    /** The card's name. */
    name: string;
    /** How many claimed copies leave the deck. */
    count: number;
    /** The deck's deckbox, where the copies lie now. */
    deckboxId: string;
}>();
/**
 * @emits place — The chosen `move_to`: a container id, or `"unsorted"`.
 * @emits close — Dismissed without a choice; the cards stay in the deck.
 */
const emit = defineEmits<{ place: [moveTo: string]; close: [] }>();
const page = usePage();
const { t } = useI18n();
const containers = computed(() => (page.props.containers as { id: string; name: string }[] | undefined) ?? []);
const deckboxName = computed(() => containers.value.find(container => container.id === props.deckboxId)?.name ?? "");
const options = computed(() => [
    { value: UNSORTED, label: t("pages.collection.unsorted") },
    ...containers.value
        .filter(container => container.id !== props.deckboxId)
        .map(container => ({ value: container.id, label: container.name }))
]);
const moveTo = ref<string>(UNSORTED);
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ t("pages.deck.freed_copies.title") }}</template>
        <form id="deck-card-freed-copies-form" class="form" @submit.prevent="emit('place', moveTo)">
            <form-legend :items="[{ slot: 'question', icon: 'question' }]">
                <template #question>{{
                    t("pages.deck.freed_copies.question", { quantity: count, name, container: deckboxName })
                }}</template>
            </form-legend>
            <form-group :label="t('pages.deck.freed_copies.label')">
                <mono-select
                    :options="options"
                    :selected="moveTo"
                    :sort="false"
                    :clearable="false"
                    addon-icon="storage"
                    @change="moveTo = $event || UNSORTED"
                />
            </form-group>
        </form>
        <template #footer>
            <button type="button" class="btn-default" @click="emit('close')">
                <icon name="close" />
                {{ t("pages.deck.freed_copies.cancel") }}
            </button>
            <button type="submit" form="deck-card-freed-copies-form" class="btn-primary">
                <icon name="delete" />
                {{ t("pages.deck.freed_copies.submit") }}
            </button>
        </template>
    </modal>
</template>
