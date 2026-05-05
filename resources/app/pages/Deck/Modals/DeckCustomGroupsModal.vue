<script setup lang="ts">
import { router, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import FormLegend from "Components/Form/FormLegend.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import type { DeckCardRow, DeckCategoryRow } from "Types/deckPage.ts";
import DeckAddGroupModal from "./DeckAddGroupModal.vue";
const emit = defineEmits<{ close: [] }>();
const props = defineProps<{
    /** UUID of the deck these categories belong to. */
    deckId: string;
    /** All cards in the deck — used to compute per-category card counts. */
    cards: DeckCardRow[];
    /** User-defined categories for this deck. */
    categories: DeckCategoryRow[];
    /** Maximum length for a category name. */
    categoryNameMax: number;
}>();
const page = usePage();
/** Temporary feedback message shown after a successful delete. */
const feedback = ref<string | null>(null);
/** ID of the category currently being deleted (disables its button). */
const deleting = ref<string | null>(null);
/** Controls visibility of the DeckAddGroupModal. */
const showCreateModal = ref(false);
/** Map of category ID → number of cards assigned to it. */
const cardCounts = computed(() => {
    const counts = new Map<string, number>();
    for (const card of props.cards) {
        if (card.category_id === null) continue;
        counts.set(card.category_id, (counts.get(card.category_id) ?? 0) + card.quantity);
    }
    return counts;
});
/** Delete a category via XHR, then reload page props so the list updates. */
async function deleteCategory(categoryId: string): Promise<void> {
    deleting.value = categoryId;
    try {
        const response = await fetch(`/api/decks/${props.deckId}/categories/${categoryId}`, {
            method: "DELETE",
            headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-TOKEN": page.props.csrfToken as string
            }
        });
        if (response.ok) {
            feedback.value = "deleted";
            setTimeout(() => {
                feedback.value = null;
            }, 5000);
            router.reload({ only: ["cards", "categories"] });
        }
    } finally {
        deleting.value = null;
    }
}
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ $t("pages.deck.custom_groups.title") }}</template>
        <div class="form">
            <form-legend :items="[{ slot: 'delete', icon: 'delete', modifier: 'warning' }]">
                <template #delete>{{ $t("pages.deck.custom_groups.delete.explanation") }}</template>
            </form-legend>
            <paragraph v-if="feedback">{{ $t("pages.deck.custom_groups.delete.success") }}</paragraph>
            <ul v-if="categories.length" class="groups">
                <li v-for="category in categories" :key="category.id" class="group">
                    {{ category.name }} ({{ cardCounts.get(category.id) ?? 0 }})
                    <button
                        type="button"
                        class="btn-default"
                        :disabled="deleting === category.id"
                        @click="deleteCategory(category.id)"
                    >
                        <icon name="delete" />
                        {{ $t("pages.deck.custom_groups.delete.link") }}
                    </button>
                </li>
            </ul>
            <paragraph v-if="!categories.length">{{ $t("pages.deck.no_categories") }}</paragraph>
        </div>
        <template #footer>
            <button type="button" class="btn-primary" @click="showCreateModal = true">
                <icon name="add" />
                {{ $t("pages.deck.create_group.link") }}
            </button>
        </template>
    </modal>
    <deck-add-group-modal
        v-if="showCreateModal"
        :deck-id="props.deckId"
        :category-name-max="props.categoryNameMax"
        @close="showCreateModal = false"
    />
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.groups {
    display: flex;
    flex-direction: column;

    padding: 0;
    margin: 0;
    gap: map.get(s.$pages, "deck", "manage_groups", "gap");

    list-style: none;
}

.group {
    display: flex;
    align-items: center;

    padding: map.get(s.$pages, "deck", "manage_groups", "padding");
    border: map.get(s.$pages, "deck", "manage_groups", "border") solid
        map.get(c.$pages, "deck", "manage_groups", "border");
    gap: map.get(s.$pages, "deck", "manage_groups", "group-gap");

    border-radius: map.get(s.$pages, "deck", "manage_groups", "radius");

    .btn-default {
        margin-left: auto;
    }
}
</style>
