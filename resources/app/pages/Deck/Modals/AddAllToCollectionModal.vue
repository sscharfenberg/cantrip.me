<script setup lang="ts">
/******************************************************************************
 * Owner-only "Add all cards to collection" modal — surfaced from
 * `DeckActionsMenu`. Bulk-creates fresh card_stacks for every deck card
 * that has no pivot row yet and attaches them, optionally pinning the
 * deck to Built and/or routing the new stacks into a chosen container.
 *****************************************************************************/
import { useForm } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Checkbox from "Components/Form/Checkbox.vue";
import FormGroup from "Components/Form/FormGroup.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
/** A user-owned container option for the optional container picker. */
interface AddAllContainer {
    id: string;
    name: string;
    type: string;
    /** True when `type === "deckbox"` — drives top-of-list pinning + recommended-suffix hint. */
    is_deckbox: boolean;
}
const emit = defineEmits<{ close: [] }>();
const props = defineProps<{
    /** UUID of the deck — used in the POST endpoint. */
    deckId: string;
    /** Every container the user owns, with deckboxes flagged for sorting. */
    containers: AddAllContainer[];
}>();
const { t } = useI18n();
const form = useForm<{
    container_id: string | null;
    set_built: boolean;
}>({
    container_id: null,
    set_built: false
});
/** Container options for MonoSelect — deckboxes pinned to the top with a hint suffix. */
const containerOptions = computed(() =>
    props.containers.map(c => ({
        value: c.id,
        label: c.is_deckbox ? `${c.name} — ${t("pages.deck.add_all_to_collection.deckbox_hint")}` : c.name
    }))
);
/** Submit the bulk-add — server creates stacks, attaches pivots, redirects to the deck show page with a flash. */
function submit(): void {
    form.post(`/decks/${props.deckId}/add-all-to-collection`, {
        preserveScroll: true,
        onSuccess: () => emit("close")
    });
}
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ $t("pages.deck.add_all_to_collection.title") }}</template>
        <form class="form" @submit.prevent="submit">
            <form-legend :items="[{ slot: 'explanation', icon: 'info' }]">
                <template #explanation>
                    {{ $t("pages.deck.add_all_to_collection.explanation") }}
                </template>
            </form-legend>
            <form-group :label="$t('pages.deck.add_all_to_collection.container_label')">
                <mono-select
                    :options="containerOptions"
                    :selected="form.container_id ?? ''"
                    :placeholder="$t('pages.deck.add_all_to_collection.container_unset')"
                    :sort="false"
                    :disabled="form.processing"
                    addon-icon="container-image"
                    max="100%"
                    @change="form.container_id = $event || null"
                />
                <template #text>
                    {{ $t("pages.deck.add_all_to_collection.container_hint") }}
                </template>
            </form-group>
            <form-group>
                <label class="add-all-modal__set-built">
                    <checkbox
                        ref-id="add-all-set-built"
                        :checked-initially="form.set_built"
                        :disabled="form.processing"
                        @change="form.set_built = $event"
                    />
                    {{ $t("pages.deck.add_all_to_collection.set_built_label") }}
                </label>
            </form-group>
        </form>
        <template #footer>
            <button type="submit" class="btn-primary" :disabled="form.processing" @click="submit">
                <icon name="add" />
                {{ $t("pages.deck.add_all_to_collection.submit") }}
                <loading-spinner v-if="form.processing" :size="2" />
            </button>
        </template>
    </modal>
</template>

<style lang="scss" scoped>
.add-all-modal__set-built {
    display: flex;
    align-items: center;

    gap: 0.5rem;
}
</style>
