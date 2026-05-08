<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import { computed, ref, useTemplateRef } from "vue";
import { useI18n } from "vue-i18n";
import DeckFormatFolder from "@/pages/Decks/DeckFormatFolder.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
export interface DeckRow {
    id: string;
    name: string;
    state: string;
    visibility: string;
    colors: string | null;
    /** Commander Bracket (1-5) when set on the deck, otherwise null. */
    bracket: number | null;
    card_count: number;
    /** Sum of (deck_cards.quantity × default_card price) + commanders + companion, in the request user's currency. */
    total_worth: number;
    last_activity: string;
    /** True when the deck has a non-empty description. */
    has_description: boolean;
    /** True when the deck has a custom hero/banner card set. */
    has_image: boolean;
    /** True when a "Companion" keyword card is attached to the deck. */
    has_companion: boolean;
}
const props = defineProps<{
    /** Decks grouped by format value (e.g. { commander: [...], oathbreaker: [...] }). */
    decksByFormat: Record<string, DeckRow[]>;
    /** True when the user has at least one archived deck — drives the "Archived decks" link visibility. */
    hasArchived: boolean;
}>();
const { t } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([{ labelKey: "pages.decks.link" }]);
/** Format keys sorted alphabetically by their translated label. */
const sortedFormats = computed(() =>
    Object.keys(props.decksByFormat).sort((a, b) =>
        t(`enums.card_formats.${a}`).localeCompare(t(`enums.card_formats.${b}`))
    )
);
/**
 * Format key with the most decks. Tiebreakers (in order): largest combined
 * card count, then most-recently-active deck. Used as the default-open
 * folder when the user lands on the page without a `#format` URL hash.
 */
const busiestFormat = computed<string | null>(() => {
    const formats = Object.keys(props.decksByFormat);
    if (formats.length === 0) return null;
    const ranked = formats
        .map(format => {
            const decks = props.decksByFormat[format];
            return {
                format,
                deckCount: decks.length,
                cardSum: decks.reduce((sum, d) => sum + d.card_count, 0),
                latest: decks.reduce((max, d) => (d.last_activity > max ? d.last_activity : max), "")
            };
        })
        .sort((a, b) => {
            if (b.deckCount !== a.deckCount) return b.deckCount - a.deckCount;
            if (b.cardSum !== a.cardSum) return b.cardSum - a.cardSum;
            return b.latest.localeCompare(a.latest);
        });
    return ranked[0].format;
});
/** The format key from the URL hash (e.g. "#commander" → "commander"). */
const initialHash = window.location.hash.replace("#", "");
/** Format folder to open on initial render: URL hash wins, else the busiest format. */
const initialOpenFormat = initialHash || busiestFormat.value || "";
// Reflect the auto-opened folder in the URL so the address bar matches the
// visible state (and a copy/paste of the URL re-opens the same folder).
// Use `replaceState` rather than assigning `location.hash` so a fresh page
// load doesn't push an extra history entry the user didn't ask for.
if (!initialHash && initialOpenFormat) {
    history.replaceState(null, "", `${window.location.pathname}${window.location.search}#${initialOpenFormat}`);
}
/** Which format folder is currently open (null = all closed). */
const openFormat = ref<string | null>(initialOpenFormat || null);
/** Template refs for each folder, keyed by format. */
const folderRefs = useTemplateRef<InstanceType<typeof DeckFormatFolder>[]>("folderRefs");
/**
 * Handle a folder toggle. Close the previously open folder and update state + URL hash.
 */
function onFolderToggle(format: string, isOpen: boolean): void {
    if (isOpen) {
        if (openFormat.value && openFormat.value !== format) {
            const prev = folderRefs.value?.find(ref => ref.$props.format === openFormat.value);
            prev?.close();
        }
        openFormat.value = format;
        window.location.hash = format;
    } else {
        openFormat.value = null;
        history.replaceState(null, "", window.location.pathname + window.location.search);
    }
}
</script>

<template>
    <Head
        ><title>{{ $t("pages.decks.title") }}</title></Head
    >
    <headline>
        <icon name="deck" :size="3" />
        {{ $t("pages.decks.title") }}
    </headline>
    <div class="deck-actions">
        <Link class="btn-primary" href="/decks/add">
            <icon name="add" />
            {{ $t("pages.create_deck.link") }}
        </Link>
        <Link class="btn-primary" href="/decks/import">
            <icon name="upload" />
            {{ $t("pages.deck_import.link") }}
        </Link>
    </div>
    <div v-if="sortedFormats.length" class="deck-folders">
        <deck-format-folder
            v-for="format in sortedFormats"
            :key="format"
            ref="folderRefs"
            :format="format"
            :decks="decksByFormat[format]"
            :initial-open="format === initialOpenFormat"
            @toggle="onFolderToggle"
        />
    </div>
    <paragraph v-else>{{ $t("pages.decks.no_decks") }}</paragraph>
    <div v-if="hasArchived" class="deck-actions deck-actions--archived">
        <Link class="btn-default" href="/decks/archived">
            <icon name="archived" />
            {{ $t("pages.decks.archived_link") }}
        </Link>
    </div>
</template>

<style lang="scss" scoped>
.deck-actions {
    display: flex;
    flex-wrap: wrap;

    gap: 0.5rem;

    &--archived {
        margin-top: 1lh;
    }
}

.deck-folders {
    display: flex;
    flex-direction: column;

    margin-top: 1lh;
    gap: 0.5rem;
}
</style>
