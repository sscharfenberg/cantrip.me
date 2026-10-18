<script setup lang="ts">
/******************************************************************************
 * Unclaim a card stack from the decks reserving it, from the collection and
 * container tables' row actions — and, in the same step, put it somewhere
 * else: a claimed stack is locked to its container (usually the deck's
 * deckbox), so freeing it is the moment to move it back into a binder.
 *
 * No choice keeps the stack where it is. "Unsorted" takes it out of every
 * container. The stack's own container is not offered — choosing it would
 * keep it there anyway — and neither is "Unsorted" for an unsorted stack.
 * With nowhere else to go, the dialog only asks about the unclaim.
 *
 * "Remove from the deck list as well" (off by default) also takes the
 * stack's copies out of the decks claiming it — with a warning when the
 * stack is a deck's commander, which that would take out too.
 *****************************************************************************/
import { router } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { withReturn } from "@/utils/returnPath.ts";
import Checkbox from "Components/Form/Checkbox.vue";
import FormGroup from "Components/Form/FormGroup.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import type { StackClaim } from "Types/cardStackRow.ts";
/** `move_to` value for "no container" — `UnclaimCardStackRequest::UNSORTED`. */
const UNSORTED = "unsorted";
/** @emits close — Fired when the modal should be dismissed (cancelled or done). */
const emit = defineEmits<{ close: [] }>();
const props = defineProps<{
    cardStack: {
        id: string;
        name: string;
        amount: number;
        set_code: string;
        collector_number: string;
        claims: StackClaim[];
    };
    /** The user's containers, in the order the page lists them. */
    containers: { id: string; name: string }[];
    /** The container the stack is in, or null when it is unsorted. */
    currentContainerId: string | null;
    /** That container's name, or null when unsorted. */
    currentContainerName: string | null;
}>();
const { t } = useI18n();
const description = computed(
    () =>
        `${props.cardStack.amount}× ${props.cardStack.name} (${props.cardStack.set_code.toUpperCase()} #${props.cardStack.collector_number})`
);
const decks = computed(() => props.cardStack.claims.map(claim => claim.deck_name).join(", "));
/** The decks this stack is the commander of, or "" when none. */
const commanderOf = computed(() =>
    props.cardStack.claims
        .filter(claim => claim.is_commander)
        .map(claim => claim.deck_name)
        .join(", ")
);
/** Unsorted first, then every container but the stack's own. */
const options = computed(() => [
    ...(props.currentContainerId === null ? [] : [{ value: UNSORTED, label: t("pages.collection.unsorted") }]),
    ...props.containers
        .filter(container => container.id !== props.currentContainerId)
        .map(container => ({ value: container.id, label: container.name }))
]);
const placeholder = computed(() =>
    props.currentContainerName === null
        ? t("pages.collection.unclaim.keep_unsorted")
        : t("pages.collection.unclaim.keep", { container: props.currentContainerName })
);
/** The chosen target, or "" to keep the stack where it is. */
const moveTo = ref("");
/** Whether the stack's copies leave the claiming decks too. */
const removeFromDecks = ref(false);
const processing = ref(false);
const errors = ref<Record<string, string>>({});
const onSubmit = (): void => {
    if (processing.value) return;
    processing.value = true;
    router.delete(withReturn(`/collection/cardstack/${props.cardStack.id}/claims`), {
        data: {
            ...(moveTo.value === "" ? {} : { move_to: moveTo.value }),
            ...(removeFromDecks.value ? { remove_from_decks: true } : {})
        },
        preserveScroll: true,
        onSuccess: () => emit("close"),
        onError: responseErrors => {
            errors.value = responseErrors;
        },
        onFinish: () => {
            processing.value = false;
        }
    });
};
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ t("pages.collection.unclaim.title") }}</template>
        <form id="unclaim-cardstack-form" class="form" @submit.prevent="onSubmit">
            <form-legend
                :items="[
                    { slot: 'question', icon: 'question' },
                    ...(options.length ? [{ slot: 'move', icon: 'move' }] : [])
                ]"
            >
                <template #question>{{ t("pages.collection.unclaim.question", { description, decks }) }}</template>
                <template #move>{{ t("pages.collection.unclaim.move_hint") }}</template>
            </form-legend>
            <form-group
                v-if="options.length"
                :label="t('pages.collection.unclaim.label')"
                :error="errors.move_to ?? ''"
                :invalid="!!errors.move_to"
            >
                <mono-select
                    :options="options"
                    :selected="moveTo"
                    :sort="false"
                    :placeholder="placeholder"
                    addon-icon="storage"
                    @change="moveTo = $event ?? ''"
                />
            </form-group>
            <form-legend v-if="commanderOf" :items="[{ slot: 'commander', icon: 'warning', modifier: 'warning' }]">
                <template #commander>{{
                    t("pages.collection.unclaim.commander_warning", { name: cardStack.name, decks: commanderOf })
                }}</template>
            </form-legend>
            <form-group
                for-id="unclaim-remove-from-decks"
                :label="t('pages.collection.unclaim.remove_from_decks', { decks })"
            >
                <template #addon>
                    <checkbox
                        ref-id="unclaim-remove-from-decks"
                        :label="t('pages.collection.unclaim.remove_from_decks', { decks })"
                        @change="removeFromDecks = $event"
                    />
                </template>
            </form-group>
        </form>
        <template #footer>
            <button type="button" class="btn-default" :disabled="processing" @click="emit('close')">
                <icon name="close" />
                {{ t("pages.collection.unclaim.cancel") }}
            </button>
            <button type="submit" form="unclaim-cardstack-form" class="btn-primary" :disabled="processing">
                <icon name="unclaimed" />
                {{ t("pages.collection.unclaim.submit") }}
                <loading-spinner v-if="processing" :size="2" />
            </button>
        </template>
    </modal>
</template>
