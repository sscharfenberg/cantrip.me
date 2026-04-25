<script setup lang="ts">
import { useId } from "vue";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";
import { useDeckView } from "Composables/useDeckView.ts";
import type { DeckView } from "Composables/useDeckView.ts";
import type { DeckMeta } from "Types/deckPage.ts";
const popoverId = useId();
const props = defineProps<{
    /** Deck metadata (name, format, state, colors, etc.). */
    deck: DeckMeta;
}>();
/** Current view mode for this deck plus setter (persists to localStorage). */
const { viewMode, setViewMode } = useDeckView(props.deck.id);
/** Close the action popover programmatically. */
function closeViewPopover(): void {
    const dialog = document.getElementById(popoverId);
    if (dialog !== null) dialog.hidePopover();
}
/** Select a view mode and close the popover. */
function selectView(mode: DeckView): void {
    setViewMode(mode);
    closeViewPopover();
}
</script>

<template>
    <pop-over
        icon="visibility-on"
        :aria-label="$t('pages.deck.navigation.view.label')"
        class-string="popover-button--rounded popover-button--padded"
        :reference="popoverId"
        width="10rem"
        :label="$t('pages.deck.navigation.view.label')"
    >
        <ul class="popover-list">
            <li>
                <button
                    class="popover-list-item"
                    :class="{ 'popover-list-item--selected': viewMode === 'text' }"
                    :aria-current="viewMode === 'text' ? 'true' : undefined"
                    @click="selectView('text')"
                >
                    <icon name="text" :size="1" />
                    {{ $t("pages.deck.navigation.view.text") }}
                </button>
            </li>
            <li>
                <button
                    class="popover-list-item"
                    :class="{ 'popover-list-item--selected': viewMode === 'cards' }"
                    :aria-current="viewMode === 'cards' ? 'true' : undefined"
                    @click="selectView('cards')"
                >
                    <icon name="card" :size="1" />
                    {{ $t("pages.deck.navigation.view.cards") }}
                </button>
            </li>
        </ul>
    </pop-over>
</template>

<style scoped lang="scss">
.popover {
    margin-left: auto;
}
</style>
