<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import type { DeckCardGroup } from "@/utils/deckGrouping.ts";
import { buildPrintableDeck } from "@/utils/printableDeck.ts";
import type { PrintableCard } from "@/utils/printableDeck.ts";
import ButtonGroup from "Components/Form/ButtonGroup.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
import { useDeckSections } from "Composables/useDeckSections.ts";
import { useDeckSort } from "Composables/useDeckSort.ts";
import type { DeckCardCount, DeckCardRow, DeckCategoryRow } from "Types/deckPage.ts";

/** A non-command-zone deck row — the fields the text needs plus what grouping reads. */
type PrintDeckCard = PrintableCard & Pick<DeckCardRow, "id" | "cmc" | "type_line" | "zone" | "category_id">;

const props = defineProps<{
    /** True when the request user owns the deck — drives the breadcrumbs only. */
    isOwner: boolean;
    deck: {
        id: string;
        name: string;
        description: string | null;
        format: string;
        state: string;
        bracket: number | null;
        card_count: DeckCardCount;
        max_sideboard_size: number;
    };
    /** Command zone rows, primary commander first. */
    commanders: PrintableCard[];
    companion: PrintableCard | null;
    /** Mainboard / sideboard / maybeboard rows. */
    cards: PrintDeckCard[];
    categories: DeckCategoryRow[];
}>();
const { t } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([
    props.isOwner
        ? { labelKey: "pages.decks.link", href: "/decks", icon: "deck" }
        : { labelKey: "pages.decks.link", icon: "deck" },
    { label: props.deck.name, href: `/decks/${props.deck.id}`, icon: "deck" },
    { label: t("pages.deck_print.link") }
]);
/**
 * Group exactly like the deck page does — same sections composable, same
 * per-deck sort mode — so the text lists the groups the user sees there.
 * Command zone and companion are passed separately below, and nothing is
 * ever dragged here.
 */
const { sortMode } = useDeckSort(props.deck.id);
const { allGroups } = useDeckSections<PrintDeckCard>(
    () => props.cards,
    [],
    null,
    () => props.categories,
    sortMode,
    () => props.deck.max_sideboard_size > 0,
    t,
    ref<DeckCardGroup | null>(null)
);
/** Metadata block: format, bracket (commander decks only, as in the header), state, card count. */
const meta = computed<string[]>(() => {
    const { deck } = props;
    const lines = [`${t("pages.deck.format")}: ${t(`enums.card_formats.${deck.format}`)}`];
    if (props.commanders.length > 0 && deck.bracket) {
        lines.push(`${t("pages.deck.bracket")}: ${deck.bracket} – ${t(`enums.bracket.${deck.bracket}`)}`);
    }
    lines.push(`${t("pages.deck.state")}: ${t(`enums.deck_state.${deck.state}`)}`);
    const counts = [t("pages.deck.card_count_tooltip.main", { count: deck.card_count.main }, deck.card_count.main)];
    if (deck.card_count.companion > 0) {
        counts.push(t("pages.deck.card_count_tooltip.companion", { count: deck.card_count.companion }));
    }
    if (deck.card_count.side > 0) {
        counts.push(t("pages.deck.card_count_tooltip.side", { count: deck.card_count.side }, deck.card_count.side));
    }
    lines.push(`${t("pages.deck.card_count")}: ${counts.join(", ")}`);
    return lines;
});
const generatedText = computed(() => {
    const mainGroups = allGroups.value.filter(group => group.zone === "main");
    const side = allGroups.value.find(group => group.zone === "side");
    return buildPrintableDeck({
        name: props.deck.name,
        description: props.deck.description,
        meta: meta.value,
        groups: [
            { label: t("pages.deck.commanders"), cards: props.commanders },
            { label: t("pages.deck.companion.heading"), cards: props.companion ? [props.companion] : [] },
            ...mainGroups
        ],
        sideboard: side ?? null
    });
});
/**
 * The textarea's content. Editable so the user can tweak the list before
 * printing — print and download both use what is in the box, and the
 * edit is thrown away with the page. Re-seeded if the generated text
 * changes (e.g. the locale switches).
 */
const text = ref(generatedText.value);
watch(generatedText, value => (text.value = value));

/**
 * Print just the text, not the page: write it into a throwaway iframe and
 * print that. Built with DOM APIs rather than `document.write` so no
 * markup or inline script is parsed — nothing for the CSP to object to.
 */
function onPrint(): void {
    const frame = document.createElement("iframe");
    frame.setAttribute("aria-hidden", "true");
    frame.tabIndex = -1;
    Object.assign(frame.style, { position: "fixed", width: "0", height: "0", border: "0", visibility: "hidden" });
    document.body.append(frame);
    const frameWindow = frame.contentWindow;
    const frameDocument = frame.contentDocument;
    if (frameWindow === null || frameDocument === null) {
        frame.remove();
        return;
    }
    frameDocument.title = props.deck.name;
    const pre = frameDocument.createElement("pre");
    Object.assign(pre.style, { margin: "0", fontFamily: "monospace", fontSize: "10pt", whiteSpace: "pre-wrap" });
    pre.textContent = text.value;
    frameDocument.body.append(pre);
    frameWindow.addEventListener("afterprint", () => frame.remove());
    frameWindow.focus();
    frameWindow.print();
}

/** Download the text as `<deck name>.txt`, with filename-hostile characters replaced. */
function onDownload(): void {
    const blob = new Blob([text.value], { type: "text/plain;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `${props.deck.name.replace(/[\\/:*?"<>|]+/g, "_").trim() || "deck"}.txt`;
    a.click();
    URL.revokeObjectURL(url);
}
</script>

<template>
    <Head
        ><title>{{ t("pages.deck_print.title", { name: deck.name }) }}</title></Head
    >
    <headline>
        <icon name="print" :size="3" />
        {{ t("pages.deck_print.title", { name: deck.name }) }}
    </headline>
    <paragraph>{{ t("pages.deck_print.explanation") }}</paragraph>
    <div class="form-input form-input__textarea deck-print">
        <textarea
            v-model="text"
            class="deck-print__text"
            :aria-label="t('pages.deck_print.textarea')"
            spellcheck="false"
        />
    </div>
    <button-group class="deck-print__actions">
        <button type="button" class="btn-primary" @click="onPrint">
            <icon name="print" />
            {{ t("pages.deck_print.print") }}
        </button>
        <button type="button" class="btn-default" @click="onDownload">
            <icon name="download" />
            {{ t("pages.deck_print.download") }}
        </button>
    </button-group>
</template>

<style lang="scss" scoped>
.deck-print {
    margin: 1lh 0;
}

// The shared textarea caps at 10lh; a deck list wants to be read in full.
.form-input__textarea .deck-print__text {
    min-height: 10lh;
    max-height: none;

    font-family: monospace;
}

.deck-print__actions {
    margin-bottom: 1lh;
}
</style>
