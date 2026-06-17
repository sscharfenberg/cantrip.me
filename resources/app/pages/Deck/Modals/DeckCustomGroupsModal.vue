<script setup lang="ts">
import { router, usePage } from "@inertiajs/vue3";
import { computed, nextTick, ref } from "vue";
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
/** ID of the category currently being renamed (toggles its inline edit form). */
const editingId = ref<string | null>(null);
/** Working copy of the name shown in the inline edit input. */
const editName = ref("");
/** Validation error for the current rename, mapped from the 422 response. */
const editError = ref<string | null>(null);
/** True while a rename request is in flight (disables the save button). */
const saving = ref(false);
/** The inline rename input, focused when edit mode opens. */
const editInputRef = ref<HTMLInputElement | null>(null);
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
/** Open the inline rename form for a category and focus its input. */
function startEdit(category: DeckCategoryRow): void {
    editingId.value = category.id;
    editName.value = category.name;
    editError.value = null;
    nextTick(() => {
        editInputRef.value?.focus();
        editInputRef.value?.select();
    });
}
/** Close the inline rename form without saving. */
function cancelEdit(): void {
    editingId.value = null;
    editError.value = null;
}
/** Persist the renamed category via XHR, then reload props on success. */
async function saveEdit(categoryId: string): Promise<void> {
    saving.value = true;
    editError.value = null;
    try {
        const response = await fetch(`/api/decks/${props.deckId}/categories/${categoryId}`, {
            method: "PATCH",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-TOKEN": page.props.csrfToken as string
            },
            body: JSON.stringify({ name: editName.value })
        });
        if (response.ok) {
            editingId.value = null;
            router.reload({ only: ["categories"] });
            return;
        }
        if (response.status === 422) {
            const data = (await response.json()) as { errors?: Record<string, string[]> };
            editError.value = data.errors?.name?.[0] ?? null;
        }
    } finally {
        saving.value = false;
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
                    <template v-if="editingId === category.id">
                        <div class="group__edit">
                            <div class="group__edit-row">
                                <input
                                    ref="editInputRef"
                                    v-model="editName"
                                    type="text"
                                    class="form-input"
                                    :maxlength="props.categoryNameMax"
                                    @keyup.enter="saveEdit(category.id)"
                                />
                                <button
                                    type="button"
                                    class="btn-primary"
                                    :aria-label="$t('pages.deck.custom_groups.edit.save')"
                                    :disabled="saving"
                                    @click="saveEdit(category.id)"
                                >
                                    <icon name="save" />
                                </button>
                                <button
                                    type="button"
                                    class="btn-default"
                                    :aria-label="$t('pages.deck.custom_groups.edit.abort')"
                                    @click="cancelEdit"
                                >
                                    <icon name="close" />
                                </button>
                            </div>
                            <div v-if="editError" class="form-group__error">
                                <icon name="error" />
                                {{ editError }}
                            </div>
                        </div>
                    </template>
                    <template v-else>
                        {{ category.name }} ({{ cardCounts.get(category.id) ?? 0 }})
                        <button
                            type="button"
                            class="btn-default"
                            :aria-label="$t('pages.deck.custom_groups.edit.aria')"
                            @click="startEdit(category)"
                        >
                            <icon name="edit" />
                        </button>
                        <button
                            type="button"
                            class="btn-default"
                            :aria-label="$t('pages.deck.custom_groups.delete.aria')"
                            :disabled="deleting === category.id"
                            @click="deleteCategory(category.id)"
                        >
                            <icon name="delete" />
                        </button>
                    </template>
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

    .btn-default:first-of-type {
        margin-left: auto;
    }
}

.group__edit {
    display: flex;
    flex-direction: column;

    width: 100%;
    gap: map.get(s.$pages, "deck", "manage_groups", "group-gap");
}

.group__edit-row {
    display: flex;
    align-items: center;

    gap: map.get(s.$pages, "deck", "manage_groups", "group-gap");

    .form-input {
        flex: 1 1 auto;
    }

    .btn-default,
    .btn-primary {
        margin-left: 0;
    }
}
</style>
