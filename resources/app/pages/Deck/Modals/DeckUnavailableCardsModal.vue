<script setup lang="ts">
/******************************************************************************
 * The shopping list: every card in the deck the collection cannot fully cover.
 *
 * Purely a view over data the deck page already holds — no fetch. The rows are
 * derived by `collectUnavailableCards`, and each keeps its whole availability
 * object so it can render the same badge the deck list uses rather than a
 * re-invented "missing" number.
 *****************************************************************************/
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import type { UnavailableCard } from "@/utils/unavailableCards.ts";
import CollectionAvailabilityBadge from "Components/Deck/CollectionAvailabilityBadge.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import { useClipboard } from "Composables/useClipboard.ts";
defineProps<{
    /** Rows to list, already ordered worst-first by `collectUnavailableCards`. */
    cards: UnavailableCard[];
}>();
/** @emits close — Fired when the modal should be dismissed. */
const emit = defineEmits<{ close: [] }>();
const { t } = useI18n();
const { copy } = useClipboard();
/**
 * Which row was copied last, so the confirmation appears beside the name that
 * was actually copied. The composable's own `copied` flag is per-consumer, not
 * per-row, and one instance per row would mean a component per row for a
 * single button.
 */
const copiedId = ref<string | null>(null);
let copiedTimer: ReturnType<typeof setTimeout> | null = null;
/** Copy one card's name and flash the confirmation next to it. */
const copyName = async (card: UnavailableCard): Promise<void> => {
    await copy(card.name);
    copiedId.value = card.id;
    if (copiedTimer) clearTimeout(copiedTimer);
    copiedTimer = setTimeout(() => {
        copiedId.value = null;
    }, 2000);
};
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ t("pages.deck.unavailable.title") }}</template>
        <paragraph>{{ t("pages.deck.unavailable.intro", { count: cards.length }, cards.length) }}</paragraph>
        <ul class="unavailable-cards">
            <li v-for="card in cards" :key="card.id" class="unavailable-cards__row">
                <img
                    v-if="card.image"
                    :src="card.image"
                    :alt="card.name"
                    class="unavailable-cards__thumb"
                    loading="lazy"
                />
                <span v-else class="unavailable-cards__thumb unavailable-cards__thumb--empty" aria-hidden="true" />
                <span class="unavailable-cards__printing">
                    <img
                        v-if="card.setPath"
                        :src="card.setPath"
                        :alt="`${card.setCode?.toUpperCase()} - ${card.setName}`"
                        class="unavailable-cards__set"
                        v-tooltip="{ content: `${card.setCode?.toUpperCase()} - ${card.setName}`, container: '#modal-body' }"
                    />
                    <span class="unavailable-cards__code">
                        [{{ card.setCode?.toUpperCase() }}] #{{ card.collectorNumber }}
                    </span>
                </span>
                <span class="unavailable-cards__name">
                    <span class="unavailable-cards__qty">{{ card.quantity }}x</span>
                    {{ card.name }}
                    <button
                        type="button"
                        class="unavailable-cards__copy"
                        :title="t('pages.deck.unavailable.copy')"
                        :aria-label="`${t('pages.deck.unavailable.copy')}: ${card.name}`"
                        @click="copyName(card)"
                    >
                        <icon name="copy" :size="1" />
                    </button>
                    <span v-if="copiedId === card.id" class="unavailable-cards__copied" role="status">
                        {{ t("pages.deck.unavailable.copied") }}
                    </span>
                </span>
                <collection-availability-badge :availability="card.availability" variant="inline" />
            </li>
        </ul>
    </modal>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.unavailable-cards {
    display: flex;
    flex-direction: column;

    padding: 0;

    margin: 0;
    gap: 0.5rem;

    list-style: none;

    &__row {
        display: flex;
        align-items: center;

        gap: 0.75rem;
    }

    /* Big enough to tell one printing's art from another's, as the card
       preview's own printing list learned to be. */
    &__thumb {
        width: 3.5rem;
        height: 4.8rem;

        border-radius: 0.25rem;

        object-fit: cover;
        object-position: top center;

        &--empty {
            background-color: map.get(c.$components, "modal", "border");
        }
    }

    &__printing {
        display: flex;
        align-items: center;

        min-width: 9ch;

        gap: 0.5ch;
    }

    &__set {
        width: map.get(s.$components, "face-image", "set");
        height: map.get(s.$components, "face-image", "set");
    }

    &__code {
        white-space: nowrap;
    }

    &__name {
        display: flex;
        align-items: center;

        flex: 1 1 auto;

        gap: 0.5ch;
    }

    &__qty {
        opacity: 0.7;
    }

    &__copy {
        display: flex;
        align-items: center;

        padding: 0.125rem;
        border: 0;

        background: none;
        color: inherit;

        cursor: pointer;

        &:hover {
            color: map.get(c.$state, "success", "border");
        }
    }

    &__copied {
        color: map.get(c.$state, "success", "border");

        font-size: 0.8em;
    }
}
</style>
