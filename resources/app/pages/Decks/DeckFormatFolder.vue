<script setup lang="ts">
import { computed, useTemplateRef } from "vue";
import { useI18n } from "vue-i18n";
import DeckListDetailsLink from "@/pages/Decks/DeckListDetailsLink.vue";
import type { DeckRow } from "@/pages/Decks/DecksPage.vue";
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
/** Number of decks in "archived" state. */
const archivedCount = computed(() => props.decks.filter(d => d.state === "archived").length);

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
                <badge
                    v-if="archivedCount"
                    v-tooltip="t('pages.decks.state_archived', { count: archivedCount }, archivedCount)"
                    type="warning"
                    >{{ archivedCount }}</badge
                >
            </span>
        </template>
        <template #body>
            <div class="decklist">
                <deck-list-details-link v-for="deck in decks" :key="deck.id" :deck="deck" />
            </div>
        </template>
    </accordion>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/typography" as t;

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
