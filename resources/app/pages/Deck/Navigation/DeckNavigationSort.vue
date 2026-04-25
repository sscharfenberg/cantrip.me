<script setup lang="ts">
import { useId } from "vue";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";
import { useDeckSort } from "Composables/useDeckSort.ts";
import type { DeckSort } from "Composables/useDeckSort.ts";
import type { DeckMeta } from "Types/deckPage.ts";
const popoverId = useId();
const props = defineProps<{
    /** Deck metadata (name, format, state, colors, etc.). */
    deck: DeckMeta;
}>();
/** Current sort mode for this deck plus setter (persists to localStorage). */
const { sortMode, setSortMode } = useDeckSort(props.deck.id);
/** Close the action popover programmatically. */
function closeSortPopover(): void {
    const dialog = document.getElementById(popoverId);
    if (dialog !== null) dialog.hidePopover();
}
/** Select a sort mode and close the popover. */
function selectSort(mode: DeckSort): void {
    setSortMode(mode);
    closeSortPopover();
}
</script>

<template>
    <pop-over
        icon="alphabetically"
        :aria-label="$t('pages.deck.navigation.sort.label')"
        :label="$t('pages.deck.navigation.sort.label')"
        class-string="popover-button--rounded popover-button--padded"
        :reference="popoverId"
        width="10rem"
    >
        <ul class="popover-list">
            <li>
                <button
                    class="popover-list-item"
                    :class="{ 'popover-list-item--selected': sortMode === 'mana' }"
                    :aria-current="sortMode === 'mana' ? 'true' : undefined"
                    @click="selectSort('mana')"
                >
                    <icon name="spell" :size="1" />
                    {{ $t("pages.deck.navigation.sort.mana") }}
                </button>
            </li>
            <li>
                <button
                    class="popover-list-item"
                    :class="{ 'popover-list-item--selected': sortMode === 'name' }"
                    :aria-current="sortMode === 'name' ? 'true' : undefined"
                    @click="selectSort('name')"
                >
                    <icon name="alphabetically" :size="1" />
                    {{ $t("pages.deck.navigation.sort.name") }}
                </button>
            </li>
        </ul>
    </pop-over>
</template>
