<script setup lang="ts">
import { computed, ref, useTemplateRef } from "vue";
import { useI18n } from "vue-i18n";
import DeckListDetailsLink from "@/pages/Decks/DeckListDetailsLink.vue";
import DeckListFilters from "@/pages/Decks/DeckListFilters.vue";
import type { DeckRow } from "@/pages/Decks/DecksPage.vue";
import { type DeckFilters, emptyDeckFilters, filterDecks } from "@/utils/deckListFilters.ts";
// eslint-disable-next-line @typescript-eslint/no-unused-vars -- component used in template
import Accordion from "Components/UI/Accordion.vue";
import Badge from "Components/UI/Badge.vue";

const props = defineProps<{
    /** Format value (e.g. "commander"). */
    format: string;
    /** Decks in this format, already sorted by last_activity desc. */
    decks: DeckRow[];
    /** Whether this folder starts expanded (from URL hash). */
    initialOpen: boolean;
}>();

const emit = defineEmits<{
    /** Emitted when this folder is toggled, with the new open state. */
    toggle: [format: string, isOpen: boolean];
}>();

const { t } = useI18n();
const accordion = useTemplateRef<{ setOpen: (value: boolean) => void }>("accordion");

/** Number of decks in "planned" state. */
const plannedCount = computed(() => props.decks.filter(d => d.state === "planned").length);
/** Number of decks in "built" state. */
const builtCount = computed(() => props.decks.filter(d => d.state === "built").length);

/** The folder's badge filters. Nothing selected shows every deck. */
const filters = ref<DeckFilters>(emptyDeckFilters());
/** The decks the filters let through. The header counts above stay unfiltered. */
const visibleDecks = computed(() => filterDecks(props.decks, filters.value));

/**
 * Handle toggle from user click.
 * Emits to parent so it can close other folders and update the URL hash.
 */
function onToggle(isOpen: boolean): void {
    emit("toggle", props.format, isOpen);
}

/** Programmatically close this folder (called by parent). */
function close(): void {
    accordion.value?.setOpen(false);
}
defineExpose({ close });
</script>

<template>
    <accordion ref="accordion" :initial-open="initialOpen" @toggle="onToggle">
        <template #head>
            <span class="decklist__format">{{ t(`enums.card_formats.${format}`) }}</span>
            <span class="badges">
                <badge
                    v-if="plannedCount"
                    v-tooltip="t('pages.decks.state_planned', { count: plannedCount }, plannedCount)"
                    type="info"
                    >{{ plannedCount }}</badge
                >
                <badge
                    v-if="builtCount"
                    v-tooltip="t('pages.decks.state_built', { count: builtCount }, builtCount)"
                    type="success"
                    >{{ builtCount }}</badge
                >
            </span>
        </template>
        <template #body>
            <deck-list-filters v-model="filters" :decks="decks" />
            <div class="decklist">
                <deck-list-details-link v-for="deck in visibleDecks" :key="deck.id" :deck="deck" />
            </div>
            <p v-if="visibleDecks.length === 0" class="decklist__no-match">{{ t("pages.decks.filters.no_match") }}</p>
        </template>
    </accordion>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/typography" as t;

.decklist__no-match {
    opacity: 0.8;

    margin: 0;
}

:deep(.collapsible__head) {
    display: flex;
    align-items: center;

    gap: 1ch;

    .badges {
        display: flex;

        margin-left: auto;
        gap: 0.5ch;
    }
}
</style>
