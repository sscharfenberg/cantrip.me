<script setup lang="ts">
/******************************************************************************
 * One pasted line on the deck list import's review page.
 *
 * Shows what the line resolved to — card, printing, category, availability —
 * with the warnings and notices that apply, and offers the only edits the
 * review page allows: leave the line out, or (for a line that did not
 * resolve) search for the card — the search opens pre-filled with the
 * pasted name. Hovering the row previews the card image. Quantities, printings
 * and categories are the deck page's job once the deck exists.
 *****************************************************************************/
import { computed } from "vue";
import type { EffectiveLine, LineChoice, LineWarning } from "@/utils/deckListImport.ts";
import { candidateOf } from "@/utils/deckListImport.ts";
import CardImagePreview from "Components/Card/CardImagePreview.vue";
import CollectionAvailabilityBadge from "Components/Deck/CollectionAvailabilityBadge.vue";
import Checkbox from "Components/Form/Checkbox.vue";
import Icon from "Components/UI/Icon.vue";
import type { DeckListCandidate, DeckListLine } from "Types/deckListImport.ts";
import DeckListCardThumb from "./DeckListCardThumb.vue";
import DeckListLineSearch from "./DeckListLineSearch.vue";
const props = defineProps<{
    line: DeckListLine;
    choice: LineChoice;
    /** The line as it will be imported; undefined while excluded or unresolved. */
    effective?: EffectiveLine;
    warnings: LineWarning[];
    /** The deck's format, for the replacement search. */
    format: string;
}>();
const emit = defineEmits<{
    /** Leave the line out (true) or bring it back (false). */
    exclude: [excluded: boolean];
    /** Use this card for the line. */
    pick: [candidate: DeckListCandidate];
    /** Drop the picked replacement again. */
    undo: [];
}>();
/** The card the line imports, or null while unresolved. */
const candidate = computed(() => candidateOf(props.line, props.choice));
/** Unresolved and still in the import — the user has to act. */
const needsFix = computed(() => candidate.value === null && !props.choice.excluded);
const quantity = computed(() => props.effective?.quantity ?? props.line.quantity);
/** Whether any message pill renders — the list is left out when empty. */
const hasMessages = computed(
    () =>
        (needsFix.value && props.line.reason !== null) ||
        props.choice.replacement !== null ||
        props.line.notices.length > 0 ||
        !!props.effective?.deduped ||
        props.warnings.length > 0
);
</script>

<template>
    <li
        class="review-line"
        :class="{
            'review-line--excluded': choice.excluded,
            'review-line--unresolved': needsFix
        }"
    >
        <card-image-preview :src="candidate?.card.image ?? null" :alt="candidate?.card.name ?? line.raw">
            <div class="review-line__main">
                <checkbox
                    :checked-initially="!choice.excluded"
                    :label="$t('pages.deck_list_import.line.include')"
                    @change="checked => emit('exclude', !checked)"
                />
                <deck-list-card-thumb v-if="candidate" :card="candidate.card" />
                <span v-else class="review-line__thumb-empty" aria-hidden="true" />
                <span class="review-line__quantity">{{ quantity }}×</span>
                <span class="review-line__card">
                    <template v-if="candidate">
                        <span class="review-line__name">{{ candidate.card.name }}</span>
                        <span class="review-line__printing">
                            <img
                                v-if="candidate.card.set_path"
                                :src="candidate.card.set_path"
                                :alt="candidate.card.set_code.toUpperCase()"
                                v-tooltip="candidate.card.set_name"
                                class="review-line__set"
                            />
                            {{ candidate.card.set_code.toUpperCase() }} #{{ candidate.card.collector_number }}
                        </span>
                    </template>
                    <span v-else class="review-line__raw">{{ line.name ?? line.raw }}</span>
                </span>
                <span v-if="effective?.category" class="review-line__category">{{ effective.category }}</span>
                <collection-availability-badge v-if="candidate?.availability" :availability="candidate.availability" />
                <span class="review-line__number">{{
                    $t("pages.deck_list_import.line.number", { line: line.line })
                }}</span>
            </div>
        </card-image-preview>
        <ul v-if="!choice.excluded && hasMessages" class="review-line__messages">
            <li v-if="needsFix && line.reason" class="review-line__message review-line__message--error">
                <icon name="error" :size="1" />
                {{ $t(`pages.deck_list_import.reasons.${line.reason}`, { name: line.name ?? line.raw }) }}
            </li>
            <li v-if="choice.replacement" class="review-line__replaced">
                <span class="review-line__message review-line__message--info">
                    <icon name="info" :size="1" />
                    {{ $t("pages.deck_list_import.line.replaced", { raw: line.raw }) }}
                </span>
                <button type="button" class="btn-default" @click="emit('undo')">
                    <icon name="clear" />
                    {{ $t("pages.deck_list_import.line.undo") }}
                </button>
            </li>
            <template v-if="!choice.replacement">
                <li
                    v-for="notice in line.notices"
                    :key="notice"
                    class="review-line__message review-line__message--info"
                >
                    <icon name="info" :size="1" />
                    {{ $t(`pages.deck_list_import.notices.${notice}`) }}
                </li>
            </template>
            <li v-if="effective?.deduped" class="review-line__message review-line__message--info">
                <icon name="info" :size="1" />
                {{ $t("pages.deck_list_import.line.deduped") }}
            </li>
            <li v-for="warning in warnings" :key="warning" class="review-line__message review-line__message--warning">
                <icon name="warning" :size="1" />
                {{ $t(`pages.deck_list_import.warnings.${warning}`) }}
            </li>
        </ul>
        <div v-if="needsFix" class="review-line__fix">
            <deck-list-line-search
                :format="format"
                :quantity="line.quantity"
                :initial-query="line.name ?? ''"
                @pick="emit('pick', $event)"
            />
        </div>
    </li>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.review-line {
    display: flex;
    flex-direction: column;

    padding: map.get(s.$pages, "deck-list-import", "row", "padding");
    border: map.get(s.$pages, "deck-list-import", "row", "border") solid
        map.get(c.$pages, "deck-list-import", "row", "border");
    gap: 0.75ex;

    background-color: map.get(c.$pages, "deck-list-import", "row", "background", "even");
    color: map.get(c.$pages, "deck-list-import", "row", "surface", "default");
    border-radius: map.get(s.$pages, "deck-list-import", "row", "radius");

    &:nth-child(odd) {
        background-color: map.get(c.$pages, "deck-list-import", "row", "background", "odd");
    }

    &--excluded {
        opacity: 0.55;

        .review-line__name,
        .review-line__raw {
            text-decoration: line-through;
        }
    }

    &--unresolved {
        border-left: 4px solid map.get(c.$state, "error", "border");
    }
}

/* The whole row is the hover-preview trigger; it keeps the row's own spacing. */
:deep(.card-preview__trigger) {
    padding: 0;

    cursor: default;
}

.review-line__main {
    display: flex;
    align-items: center;
    flex-wrap: wrap;

    gap: map.get(s.$pages, "deck-list-import", "row", "gap");
}

.review-line__thumb-empty {
    width: map.get(s.$pages, "deck-list-import", "thumb", "width");
}

.review-line__quantity {
    min-width: 4ch;

    font-variant-numeric: tabular-nums;
    text-align: right;
}

.review-line__card {
    display: flex;
    flex-direction: column;

    flex: 1 1 12rem;

    gap: 0.25ex;
}

.review-line__name {
    font-weight: 600;
}

.review-line__printing {
    display: flex;
    align-items: center;

    opacity: 0.75;

    gap: 0.5ch;

    font-size: 0.875em;
}

.review-line__set {
    width: 1.1em;
    height: 1.1em;
}

.review-line__number {
    opacity: 0.6;

    font-size: 0.8em;
}

.review-line__category {
    padding: 0 0.75ch;
    border: 1px solid currentcolor;

    border-radius: 1em;

    font-size: 0.875em;
}

.review-line__messages {
    display: flex;
    align-items: flex-start;
    flex-direction: column;

    padding: 0;
    margin: 0;
    gap: 0.5ex;

    list-style: none;

    font-size: 0.875em;
}

/* Sized to its text, not the row: a pill per message, coloured by kind. */
.review-line__message {
    display: inline-flex;
    align-items: center;

    padding: map.get(s.$pages, "deck-list-import", "message", "padding");
    border: map.get(s.$pages, "deck-list-import", "message", "border") solid transparent;
    gap: 0.5ch;

    border-radius: map.get(s.$pages, "deck-list-import", "message", "radius");

    @each $kind in ("error", "warning", "info") {
        &--#{$kind} {
            background-color: map.get(c.$state, $kind, "background");
            color: map.get(c.$state, $kind, "surface");
            border-color: map.get(c.$state, $kind, "border");
        }
    }
}

/* The replacement notice with its undo right beside it. */
.review-line__replaced {
    display: flex;
    align-items: center;
    flex-wrap: wrap;

    gap: 0.5ex 1ch;
}

.review-line__fix {
    display: flex;
    flex-direction: column;

    gap: 0.75ex;
}

.review-line__fix-label {
    font-size: 0.875em;
}
</style>

<style lang="scss">
// Set symbols are black-filled SVGs; dark mode inverts them to stay visible,
// as the card preview does.
@use "Abstracts/mixins" as m;

@include m.theme-dark(".review-line__set") {
    filter: invert(1);
}
</style>
