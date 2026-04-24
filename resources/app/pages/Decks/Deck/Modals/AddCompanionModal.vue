<script setup lang="ts">
import { router, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { isSubsetCI } from "@/utils/colorIdentity";
import Modal from "Components/Modal/Modal.vue";
import type { DeckCompanion } from "Types/deckPage";
const emit = defineEmits<{ close: [] }>();
const props = defineProps<{
    deckId: string;
    /** Deck format key (e.g. "commander") — used to name the format in banned tooltips. */
    format: string;
    roster: DeckCompanion[];
    bannedAsCompanion: string[];
    enforcesColorIdentity: boolean;
    commanderColorIdentity: string;
}>();
const { t } = useI18n();
const page = usePage();
const processing = ref(false);
const errorMessage = ref<string | null>(null);
/** Short slug for the i18n key, derived from the first word of the oracle name. */
const slugFor = (name: string): string => name.split(/[\s,]/)[0].toLowerCase();
interface Tile {
    card: DeckCompanion;
    disabledReason: string | null;
}
const tiles = computed<Tile[]>(() =>
    props.roster.map((card) => {
        let reason: string | null = null;
        if (props.bannedAsCompanion.includes(card.name)) {
            reason = t("pages.deck.companion.disabled.banned", {
                format: t(`enums.card_formats.${props.format}`),
            });
        } else if (props.enforcesColorIdentity && !isSubsetCI(card.color_identity, props.commanderColorIdentity)) {
            reason = t("pages.deck.companion.disabled.color_identity");
        }
        return { card, disabledReason: reason };
    })
);
/**
 * Persist the chosen companion on the deck. On 422 the backend returns
 * field errors — the first one becomes an inline error message. On success
 * the Inertia partial reload refreshes deck + companion state before
 * closing the modal.
 */
const pickCompanion = async (card: DeckCompanion): Promise<void> => {
    if (processing.value) return;
    processing.value = true;
    errorMessage.value = null;
    const response = await fetch(`/api/decks/${props.deckId}/companion`, {
        method: "PATCH",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": page.props.csrfToken as string,
            Accept: "application/json",
        },
        body: JSON.stringify({ oracle_card_id: card.oracle_card_id }),
    });
    if (response.status === 422) {
        const data = (await response.json()) as { errors?: Record<string, string[]> };
        const first = Object.values(data.errors ?? {})[0];
        errorMessage.value = Array.isArray(first) ? first[0] : t("pages.deck.companion.generic_error");
        processing.value = false;
        return;
    }
    if (!response.ok) {
        errorMessage.value = t("pages.deck.companion.generic_error");
        processing.value = false;
        return;
    }
    router.reload({
        only: ["deck", "companion"],
        onFinish: () => emit("close"),
    });
};
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ $t("pages.deck.companion.modal.title") }}</template>
        <div class="companion-picker">
            <button
                v-for="tile in tiles"
                :key="tile.card.oracle_card_id"
                type="button"
                class="companion-picker__tile"
                :class="{ 'companion-picker__tile--disabled': tile.disabledReason }"
                :disabled="!!tile.disabledReason || processing"
                v-tooltip="tile.disabledReason ?? ''"
                @click="pickCompanion(tile.card)"
            >
                <img
                    v-if="tile.card.default_card.card_image_0"
                    :src="tile.card.default_card.card_image_0"
                    :alt="tile.card.name"
                    loading="lazy"
                    class="companion-picker__image"
                />
                <span class="companion-picker__name">{{ tile.card.name }}</span>
                <span class="companion-picker__restriction">{{
                    $t("pages.deck.companion.restrictions." + slugFor(tile.card.name))
                }}</span>
            </button>
        </div>
        <p v-if="errorMessage" class="companion-picker__error">{{ errorMessage }}</p>
    </modal>
</template>

<style lang="scss" scoped>
@use "Abstracts/colors" as c;
@use "sass:map";

.companion-picker {
    display: grid;
    grid-template-columns: repeat(2, 1fr);

    gap: 1rem;

    @media (width >= 64rem) {
        grid-template-columns: repeat(5, 1fr);
    }

    &__tile {
        display: flex;
        flex-direction: column;

        padding: 0.5rem;
        border: 1px solid transparent;
        gap: 0.5rem;

        background: transparent;
        border-radius: 0.5rem;

        text-align: left;

        cursor: pointer;

        &--disabled {
            opacity: 0.45;
        }

        &:hover:not(:disabled) {
            border-color: map.get(c.$state, "info", "border");
        }

        &:disabled {
            cursor: not-allowed;
        }
    }

    &__image {
        width: 100%;
        height: auto;
    }

    &__name {
        font-weight: bold;
    }

    &__restriction {
        font-size: 0.85rem;
        line-height: 1.3;
    }

    &__error {
        padding: 0.5rem 0.75rem;
        margin: 1rem 0 0;

        background-color: map.get(c.$state, "error", "background");
        color: map.get(c.$state, "error", "surface");
        border-radius: 0.25rem;
    }
}
</style>