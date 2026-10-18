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
 *****************************************************************************/
import { router, usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import CommanderCommandZonePickerModal from "@/pages/Deck/Create/CommanderCommandZonePickerModal.vue";
import OathbreakerCommandZonePickerModal from "@/pages/Deck/Create/OathbreakerCommandZonePickerModal.vue";
import type { CommanderResult } from "Components/Deck/ShowCommanderOverview.vue";
const props = defineProps<{
    deckId: string;
    /** Format key (e.g. "commander", "oathbreaker") — selects the picker. */
    format: string;
}>();
/** @emits close — Fired when the picker is dismissed or confirmed. */
const emit = defineEmits<{ close: [] }>();
const page = usePage();
const isOathbreaker = computed(() => props.format === "oathbreaker");
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
        router.reload({ only: ["deck", "commanders", "cards", "violations", "tokens", "hasUnclaimedCards"] });
    }
}
</script>

<template>
    <oathbreaker-command-zone-picker-modal
        v-if="isOathbreaker"
        :format="format"
        @confirm="save"
        @close="emit('close')"
    />
    <commander-command-zone-picker-modal v-else :format="format" @confirm="save" @close="emit('close')" />
</template>
