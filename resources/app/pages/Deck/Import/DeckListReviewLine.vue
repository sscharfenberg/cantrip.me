<script setup lang="ts">
/******************************************************************************
 * One pasted line on the deck list import's review page.
 *
 * Shows what the line resolved to — card, printing, category, availability —
 * with the warnings and notices that apply, and offers the only edits the
 * review page allows: leave the line out, or (for a line that did not
 * resolve) pick a suggestion or search for the card. Quantities, printings
 * and categories are the deck page's job once the deck exists.
 *****************************************************************************/
import { computed } from "vue";
import type { EffectiveLine, LineChoice, LineWarning } from "@/utils/deckListImport.ts";
import { candidateOf } from "@/utils/deckListImport.ts";
import CollectionAvailabilityBadge from "Components/Deck/CollectionAvailabilityBadge.vue";
import Checkbox from "Components/Form/Checkbox.vue";
import Icon from "Components/UI/Icon.vue";
import type { DeckListCandidate, DeckListLine } from "Types/deckListImport.ts";
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
</script>

<template>
    <li
        class="review-line"
        :class="{
            'review-line--excluded': choice.excluded,
            'review-line--unresolved': needsFix
        }"
    >
        <div class="review-line__main">
            <checkbox
                :checked-initially="!choice.excluded"
                :label="$t('pages.deck_list_import.line.include')"
                @change="checked => emit('exclude', !checked)"
            />
            <span class="review-line__quantity">{{ quantity }}</span>
            <template v-if="candidate">
                <span class="review-line__name">{{ candidate.card.name }}</span>
                <span class="review-line__printing">
                    ({{ candidate.card.set_code.toUpperCase() }}) {{ candidate.card.collector_number }}
                </span>
                <span v-if="effective?.category" class="review-line__category">{{ effective.category }}</span>
                <collection-availability-badge v-if="candidate.availability" :availability="candidate.availability" />
                <button v-if="choice.replacement" type="button" class="btn-default" @click="emit('undo')">
                    <icon name="clear" />
                    {{ $t("pages.deck_list_import.line.undo") }}
                </button>
            </template>
            <span v-else class="review-line__raw">{{ line.raw }}</span>
            <span class="review-line__number">{{ $t("pages.deck_list_import.line.number", { line: line.line }) }}</span>
        </div>
        <ul v-if="!choice.excluded" class="review-line__messages">
            <li v-if="needsFix && line.reason" class="review-line__message review-line__message--error">
                <icon name="error" :size="1" />
                {{ $t(`pages.deck_list_import.reasons.${line.reason}`, { name: line.name ?? line.raw }) }}
            </li>
            <li v-if="choice.replacement" class="review-line__message">
                <icon name="info" :size="1" />
                {{ $t("pages.deck_list_import.line.replaced", { raw: line.raw }) }}
            </li>
            <template v-if="!choice.replacement">
                <li v-for="notice in line.notices" :key="notice" class="review-line__message">
                    <icon name="info" :size="1" />
                    {{ $t(`pages.deck_list_import.notices.${notice}`) }}
                </li>
            </template>
            <li v-if="effective?.deduped" class="review-line__message">
                <icon name="info" :size="1" />
                {{ $t("pages.deck_list_import.line.deduped") }}
            </li>
            <li v-for="warning in warnings" :key="warning" class="review-line__message review-line__message--warning">
                <icon name="warning" :size="1" />
                {{ $t(`pages.deck_list_import.warnings.${warning}`) }}
            </li>
        </ul>
        <div v-if="needsFix" class="review-line__fix">
            <div v-if="line.suggestions.length" class="review-line__suggestions">
                <span>{{ $t("pages.deck_list_import.line.suggestions") }}</span>
                <button
                    v-for="suggestion in line.suggestions"
                    :key="suggestion.card.oracle_card_id"
                    type="button"
                    class="btn-default"
                    @click="emit('pick', suggestion)"
                >
                    {{ suggestion.card.name }}
                </button>
            </div>
            <deck-list-line-search :format="format" :quantity="line.quantity" @pick="emit('pick', $event)" />
        </div>
    </li>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;

.review-line {
    display: flex;
    flex-direction: column;

    padding: 0.75ex 1ch;
    border-left: 3px solid transparent;
    gap: 0.5ex;

    &--excluded {
        opacity: 0.55;

        .review-line__name,
        .review-line__raw {
            text-decoration: line-through;
        }
    }

    &--unresolved {
        border-left-color: map.get(c.$state, "error", "border");
    }
}

.review-line__main {
    display: flex;
    align-items: center;
    flex-wrap: wrap;

    gap: 0.5ch 1ch;
}

.review-line__quantity {
    min-width: 3ch;

    font-variant-numeric: tabular-nums;
    text-align: right;
}

.review-line__name {
    font-weight: 600;
}

.review-line__printing,
.review-line__number {
    opacity: 0.7;

    font-size: 0.875em;
}

.review-line__number {
    margin-left: auto;
}

.review-line__category {
    padding: 0 0.75ch;
    border: 1px solid currentcolor;

    border-radius: 1em;

    font-size: 0.875em;
}

.review-line__messages {
    display: flex;
    flex-direction: column;

    padding: 0;
    margin: 0;
    gap: 0.25ex;

    list-style: none;

    font-size: 0.875em;
}

.review-line__message {
    display: flex;
    align-items: center;

    gap: 0.5ch;

    &--warning {
        color: map.get(c.$state, "warning", "surface");
    }

    &--error {
        color: map.get(c.$state, "error", "surface");
    }
}

.review-line__fix {
    display: flex;
    flex-direction: column;

    gap: 0.75ex;
}

.review-line__suggestions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;

    gap: 0.5ex 0.5ch;
}
</style>
