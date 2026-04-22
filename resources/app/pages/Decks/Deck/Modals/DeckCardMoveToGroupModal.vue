<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import DeckAddGroupModal from "@/pages/Decks/Deck/Modals/DeckAddGroupModal.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import { resolveGroup } from "Composables/useDeckGrouping.ts";
import type { DeckCardRow, DeckCategoryRow } from "Types/deckPage";
const emit = defineEmits<{ close: [] }>();
const props = defineProps<{
    /** UUID of the deck this card belongs to. */
    deckId: string;
    /** The deck card entry being moved — needed by the nested create-group modal. */
    card: DeckCardRow;
    /** All user-defined categories for this deck. */
    categories: DeckCategoryRow[];
    /** Maximum length for a category name — forwarded to the create-group modal. */
    categoryNameMax: number;
}>();
const { t } = useI18n();
/** Default type group derived from the card's type line — the target for "remove from category". */
const defaultGroup = computed(() => resolveGroup(props.card.type_line));
/** Label for the card's current group, shown in the header paragraph. */
const currentGroupLabel = computed((): string => {
    if (props.card.category_id === null) return t(`pages.deck.groups.${defaultGroup.value}`);
    const category = props.categories.find(c => c.id === props.card.category_id);
    return category?.name ?? "";
});
/** True while the PATCH is in flight — disables buttons to prevent double-submit. */
const submitting = ref(false);
/** Controls visibility of the nested DeckAddGroupModal. */
const showCreateModal = ref(false);
/** Persist the category change and close on success. */
function moveTo(categoryId: string | null): void {
    if (submitting.value) return;
    submitting.value = true;
    router.patch(
        `/api/decks/${props.deckId}/cards/${props.card.id}/category`,
        { category_id: categoryId },
        {
            preserveScroll: true,
            onSuccess: () => emit("close"),
            onFinish: () => {
                submitting.value = false;
            }
        }
    );
}
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ $t("pages.deck.move_to_group.title", { card: props.card.name }) }}</template>
        <paragraph>
            {{ $t("pages.deck.move_to_group.current_label") }} <strong>{{ currentGroupLabel }}</strong>
        </paragraph>
        <div class="targets">
            <template v-if="props.card.category_id !== null">
                <button type="button" class="btn-default" :disabled="submitting" @click="moveTo(null)">
                    <icon name="card" />
                    {{ t(`pages.deck.groups.${defaultGroup}`) }}
                </button>
            </template>
            <template v-for="category in props.categories" :key="category.id">
                <button
                    v-if="category.id !== props.card.category_id"
                    type="button"
                    class="btn-default"
                    :disabled="submitting"
                    @click="moveTo(category.id)"
                >
                    <icon name="cards" />
                    {{ category.name }}
                </button>
            </template>
        </div>
        <template #footer>
            <button type="button" class="btn-primary" :disabled="submitting" @click="showCreateModal = true">
                <icon name="add" />
                {{ $t("pages.deck.create_group.link") }}
            </button>
        </template>
    </modal>
    <deck-add-group-modal
        v-if="showCreateModal"
        :deck-id="props.deckId"
        :card="props.card"
        :category-name-max="props.categoryNameMax"
        @close="
            showCreateModal = false;
            emit('close');
        "
    />
</template>

<style lang="scss" scoped>
.targets {
    display: flex;
    flex-wrap: wrap;

    margin-bottom: 1rem;

    gap: 0.5rem;
}
</style>
