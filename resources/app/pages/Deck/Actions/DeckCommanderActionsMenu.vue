<script setup lang="ts">
import { Link, router, usePage } from "@inertiajs/vue3";
import { computed, ref, useId } from "vue";
import CommanderCommandZonePickerModal from "@/pages/Deck/Create/CommanderCommandZonePickerModal.vue";
import OathbreakerCommandZonePickerModal from "@/pages/Deck/Create/OathbreakerCommandZonePickerModal.vue";
import type { CommanderResult } from "Components/Deck/ShowCommanderOverview.vue";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";
import type { DeckPrinting } from "Types/defaultCardImage.ts";
import DeckCardAssignStackModal from "../Modals/DeckCardAssignStackModal.vue";
import DeckCardSwitchPrintingModal from "../Modals/DeckCardSwitchPrintingModal.vue";
const props = defineProps<{
    /** UUID of the deck. */
    deckId: string;
    /** UUID of the commander's deck_card row — used for the unified hero-image endpoint. */
    deckCardId: string;
    /** UUID of the commander's oracle card — picks which deck_card is updated for printing swaps. */
    oracleCardId: string;
    /** Commander name — interpolated into the switch-printing modal title. */
    commanderName: string;
    /** Format key (e.g. "commander", "oathbreaker") — selects the right picker modal. */
    format: string;
    /** This commander's current printing id — compared against `heroCardId`. */
    defaultCardId: string;
    /** Current deck hero printing id, or null. Hides "Use as hero image" when this commander already is the hero. */
    heroCardId: string | null;
    /**
     * Effective collection-integration mode. The "Assign physical copy"
     * entry is only rendered in mode C — modes A and B are silent (mode
     * A means no collection at all; mode B is the implicit-deckbox
     * mode). Same gating that mainboard rows use.
     */
    collectionMode: "A" | "B" | "C";
    /** Whether this sits on top of a card image (tweaks PopOver trigger size). */
    isMediumButton?: boolean;
}>();
const popoverId = useId();
const page = usePage();
const showSwitchPrintingModal = ref(false);
const showChangeCommanderModal = ref(false);
const showAssignStackModal = ref(false);
/** Oathbreaker has its own picker (planeswalker + signature spell) — every other commander-family format uses the standard picker. */
const isOathbreaker = computed(() => props.format === "oathbreaker");
/** Dismiss the popover menu via the native popover API. */
function closePopover(): void {
    const el = document.getElementById(popoverId);
    if (el !== null) el.hidePopover();
}
/** Close the menu and open the switch-printing modal. */
function openSwitchPrinting(): void {
    closePopover();
    showSwitchPrintingModal.value = true;
}
/** Close the menu and open the format-appropriate command-zone picker. */
function openChangeCommander(): void {
    closePopover();
    showChangeCommanderModal.value = true;
}
/** Close the menu and open the assign-stack picker. */
function openAssignStack(): void {
    closePopover();
    showAssignStackModal.value = true;
}
/**
 * PATCH the chosen stack id (or null to clear) and refresh the
 * `commanders` payload so the per-row collection-status badge reflects
 * the new pivot state. Reuses the deck_card-keyed endpoints because
 * commanders are deck_cards too post-consolidation.
 */
async function assignStack(stackId: string | null): Promise<void> {
    const response = await fetch(`/api/decks/${props.deckId}/cards/${props.deckCardId}/assigned-stacks`, {
        method: "PATCH",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": page.props.csrfToken as string,
            Accept: "application/json"
        },
        body: JSON.stringify({ card_stack_id: stackId })
    });
    if (!response.ok) return;
    router.reload({ only: ["commanders"] });
}
/**
 * Swap this commander's display printing (the `commanders` pivot row's
 * `default_card_id`). Reload only `deck` and `commanders` so the new image
 * shows without dragging the rest of the page through a full refresh.
 */
async function switchPrinting(printing: DeckPrinting): Promise<void> {
    const response = await fetch(`/api/decks/${props.deckId}/commander/${props.oracleCardId}/printing`, {
        method: "PATCH",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": page.props.csrfToken as string,
            Accept: "application/json"
        },
        body: JSON.stringify({ default_card_id: printing.id })
    });
    if (response.ok) {
        router.reload({ only: ["deck", "commanders", "tokens"] });
    }
}
/**
 * Replace the deck's command zone. Either picker emits two cards on confirm
 * (commander + companion / planeswalker + signature spell) — both shapes
 * map onto the same backend payload. The deck page is reloaded with the
 * full set of props that depend on the new color identity (`cards` for
 * `is_illegal` flags, `violations` for the legality panel, `deck` for the
 * combined colors badge, `commanders` for the command zone display).
 */
async function changeCommander(commander: CommanderResult, second: CommanderResult | null): Promise<void> {
    const body: Record<string, string> = { commander_id: commander.id };
    if (second !== null) {
        body[isOathbreaker.value ? "signature_spell_id" : "companion_id"] = second.id;
    }
    const response = await fetch(`/api/decks/${props.deckId}/commander`, {
        method: "PATCH",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": page.props.csrfToken as string,
            Accept: "application/json"
        },
        body: JSON.stringify(body)
    });
    if (response.ok) {
        router.reload({ only: ["deck", "commanders", "cards", "violations", "tokens"] });
    }
}
</script>

<template>
    <pop-over
        icon="more"
        aria-label="Commander actions"
        :class-string="`popover-button--rounded${props.isMediumButton ? '' : ' popover-button--tiny'}`"
        :reference="popoverId"
        width="14rem"
    >
        <ul class="popover-list">
            <li>
                <button type="button" class="popover-list-item" @click="openSwitchPrinting">
                    <icon name="card" :size="1" />
                    {{ $t("pages.deck.switch_printing.link") }}
                </button>
            </li>
            <li>
                <button type="button" class="popover-list-item" @click="openChangeCommander">
                    <icon name="register" :size="1" />
                    {{ $t("pages.deck.change_commander.link") }}
                </button>
            </li>
            <li v-if="props.collectionMode === 'C'">
                <button type="button" class="popover-list-item" @click="openAssignStack">
                    <icon name="storage" :size="1" />
                    {{ $t("pages.deck.assign_stack.link") }}
                </button>
            </li>
            <li v-if="props.defaultCardId !== props.heroCardId">
                <Link
                    :href="`/decks/${props.deckId}/cards/${props.deckCardId}/use-as-hero`"
                    method="patch"
                    as="button"
                    class="popover-list-item"
                    @click="closePopover"
                >
                    <icon name="container-image" :size="1" />
                    {{ $t("pages.deck.use_as_hero.link") }}
                </Link>
            </li>
        </ul>
    </pop-over>
    <deck-card-switch-printing-modal
        v-if="showSwitchPrintingModal"
        :printings-url="`/api/decks/${props.deckId}/commander/${props.oracleCardId}/printings`"
        :name="props.commanderName"
        @select="switchPrinting"
        @close="showSwitchPrintingModal = false"
    />
    <oathbreaker-command-zone-picker-modal
        v-if="showChangeCommanderModal && isOathbreaker"
        :format="props.format"
        @confirm="changeCommander"
        @close="showChangeCommanderModal = false"
    />
    <commander-command-zone-picker-modal
        v-else-if="showChangeCommanderModal"
        :format="props.format"
        @confirm="changeCommander"
        @close="showChangeCommanderModal = false"
    />
    <deck-card-assign-stack-modal
        v-if="showAssignStackModal"
        :stacks-url="`/api/decks/${props.deckId}/cards/${props.deckCardId}/assignable-stacks`"
        :name="props.commanderName"
        @select="assignStack"
        @close="showAssignStackModal = false"
    />
</template>
