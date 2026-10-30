<script setup lang="ts">
/******************************************************************************
 * Pick a new command zone for a deck and save it — the format's own picker
 * (Oathbreaker: planeswalker + signature spell; every other commander
 * format: commander + optional partner), wired to the endpoint that
 * replaces the whole command zone.
 *
 * Opened from a commander's actions menu ("Change commander") and from the
 * deck navigation's "Command zone" button, which is how a deck left
 * without a commander gets one again.
 *
 * The page reloads every prop that depends on the new colour identity:
 * `cards` for the `is_illegal` flags, `violations` for the legality panel,
 * `deck` for the combined colours, `commanders` for the command zone,
 * plus `tokens` and `hasUnclaimedCards`.
 *
 * A card that leaves the command zone frees its claimed copies; while they
 * lie in the deck's deckbox the server asks where they go first, and this
 * stays open — the picker gone — to ask it ({@link useFreedCopiesQuestion}).
 * Cancelling the question leaves the command zone as it was.
 *****************************************************************************/
import { router, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import CommanderCommandZonePickerModal from "@/pages/Deck/Create/CommanderCommandZonePickerModal.vue";
import OathbreakerCommandZonePickerModal from "@/pages/Deck/Create/OathbreakerCommandZonePickerModal.vue";
import type { CommanderResult } from "Components/Deck/ShowCommanderOverview.vue";
import { useFreedCopiesQuestion } from "Composables/useFreedCopiesQuestion";
import DeckCardFreedCopiesModal from "./DeckCardFreedCopiesModal.vue";
const props = defineProps<{
    deckId: string;
    /** Format key (e.g. "commander", "oathbreaker") — selects the picker. */
    format: string;
}>();
/** @emits close — Fired when the picker is dismissed or confirmed. */
const emit = defineEmits<{ close: [] }>();
const page = usePage();
const isOathbreaker = computed(() => props.format === "oathbreaker");
/** True from the pick until the save is done — the picker's own close must not close this yet. */
const saving = ref(false);
const freedCopies = useFreedCopiesQuestion();
/**
 * Replace the deck's command zone. Either picker emits two cards on
 * confirm (commander + partner / planeswalker + signature spell) — both
 * map onto the same payload.
 */
async function save(commander: CommanderResult, second: CommanderResult | null): Promise<void> {
    const body: Record<string, string> = { commander_id: commander.id };
    if (second !== null) {
        body[isOathbreaker.value ? "signature_spell_id" : "companion_id"] = second.id;
    }
    saving.value = true;
    const response = await freedCopies.send(moveTo =>
        fetch(`/api/decks/${props.deckId}/commander`, {
            method: "PATCH",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": page.props.csrfToken as string,
                Accept: "application/json"
            },
            body: JSON.stringify(moveTo === undefined ? body : { ...body, move_to: moveTo })
        })
    );
    if (response?.ok) {
        router.reload({ only: ["deck", "commanders", "cards", "violations", "tokens", "hasUnclaimedCards"] });
    }
    emit("close");
}
/** The picker closes itself on confirm; only a dismissal closes this right away. */
function onPickerClose(): void {
    if (!saving.value) emit("close");
}
</script>

<template>
    <deck-card-freed-copies-modal
        v-if="freedCopies.question.value"
        :name="freedCopies.question.value.name"
        :count="freedCopies.question.value.count"
        :deckbox-id="freedCopies.question.value.container_id"
        @place="freedCopies.answer"
        @close="freedCopies.answer(null)"
    />
    <oathbreaker-command-zone-picker-modal
        v-else-if="!saving && isOathbreaker"
        :format="format"
        @confirm="save"
        @close="onPickerClose"
    />
    <commander-command-zone-picker-modal
        v-else-if="!saving"
        :format="format"
        @confirm="save"
        @close="onPickerClose"
    />
</template>
