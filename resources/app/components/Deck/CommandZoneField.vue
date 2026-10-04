<script setup lang="ts">
/******************************************************************************
 * The command zone of a deck that is being created: what is selected, and a
 * button that opens the picker to choose or change it.
 *
 * Shared by the create-deck form and the deck list import's review page, so
 * the two show the command zone the same way and cannot drift. Two variants,
 * picked by `withSignatureSpell`:
 *   - commander-family formats: commander + an optional partner-type card
 *     (partner, background, friends forever, Doctor's companion, …);
 *   - Oathbreaker: planeswalker + signature spell.
 *
 * The selection is three `v-model`s; the pickers' own "confirm" overwrites
 * them. Hidden `commander_id` / `companion_id` / `signature_spell_id` inputs
 * carry the selection into an enclosing `<Form>` — the create form submits
 * through them; the import page sends the same ids in its JSON body.
 *****************************************************************************/
import { ref } from "vue";
import CommanderCommandZonePickerModal from "@/pages/Deck/Create/CommanderCommandZonePickerModal.vue";
import OathbreakerCommandZonePickerModal from "@/pages/Deck/Create/OathbreakerCommandZonePickerModal.vue";
import type { CommanderResult } from "Components/Deck/ShowCommanderOverview.vue";
import ShowCommanderOverview from "Components/Deck/ShowCommanderOverview.vue";
import FormGroup from "Components/Form/FormGroup.vue";
import Icon from "Components/UI/Icon.vue";
defineProps<{
    /** The deck's format — the pickers search the cards legal in it. */
    format: string;
    /** Oathbreaker variant (planeswalker + signature spell) instead of commander + partner. */
    withSignatureSpell: boolean;
    /** Server-side errors for the command-zone fields, shown under the button. */
    errors?: { commander_id?: string; signature_spell_id?: string };
}>();
const emit = defineEmits<{
    /** The user confirmed a selection in a picker; carries the new commander / Oathbreaker. */
    confirmed: [commander: CommanderResult];
}>();
/** Commander, or the Oathbreaker planeswalker. */
const commander = defineModel<CommanderResult | null>("commander", { default: null });
/** Partner-type card sharing the zone with the commander. Unused for Oathbreaker. */
const companion = defineModel<CommanderResult | null>("companion", { default: null });
/** Oathbreaker signature spell. Unused for commander-family formats. */
const signatureSpell = defineModel<CommanderResult | null>("signatureSpell", { default: null });
/** Whether the picker modal is open. */
const pickerOpen = ref(false);
/** Store the commander and optional partner from the commander picker. */
const onCommanderConfirmed = (cmd: CommanderResult, comp: CommanderResult | null) => {
    commander.value = cmd;
    companion.value = comp;
    emit("confirmed", cmd);
};
/** Store the planeswalker and signature spell from the Oathbreaker picker. */
const onOathbreakerConfirmed = (pw: CommanderResult, spell: CommanderResult) => {
    commander.value = pw;
    signatureSpell.value = spell;
    emit("confirmed", pw);
};
/** The label key of the partner slot, named after the commander's pairing mechanic. */
const companionLabelKey = (type: string) =>
    `components.commander_picker.${type === "partner_with" || type === "partner_type" ? "partner" : type}_selected`;
</script>

<template>
    <div class="command-zone-field">
        <template v-if="withSignatureSpell">
            <form-group
                v-if="commander"
                :label="$t('components.oathbreaker_picker.selected_planeswalker')"
                :required="true"
                :validated="true"
            >
                <div class="commander-picker__commander commander-picker__commander--selected">
                    <show-commander-overview :card="commander" />
                </div>
                <input type="hidden" name="commander_id" :value="commander.id" />
            </form-group>
            <form-group
                v-if="signatureSpell"
                :label="$t('components.oathbreaker_picker.selected_spell')"
                :required="true"
                :validated="true"
            >
                <div class="commander-picker__commander commander-picker__commander--selected">
                    <show-commander-overview :card="signatureSpell" />
                </div>
                <input type="hidden" name="signature_spell_id" :value="signatureSpell.id" />
            </form-group>
            <form-group
                :error="errors?.commander_id ?? errors?.signature_spell_id ?? ''"
                :invalid="!!errors?.commander_id || !!errors?.signature_spell_id"
            >
                <button type="button" class="btn-default" @click="pickerOpen = true">
                    <icon name="register" />
                    {{
                        $t(commander ? "pages.create_deck.oathbreaker.change" : "pages.create_deck.oathbreaker.choose")
                    }}
                </button>
            </form-group>
        </template>
        <template v-else>
            <form-group v-if="commander" :label="$t('form.fields.commander')" :required="true" :validated="true">
                <div class="commander-picker__commander commander-picker__commander--selected">
                    <show-commander-overview :card="commander" />
                </div>
                <input type="hidden" name="commander_id" :value="commander.id" />
            </form-group>
            <form-group
                v-if="companion && commander?.companion_type"
                :label="$t(companionLabelKey(commander.companion_type))"
                :validated="true"
            >
                <div class="commander-picker__commander commander-picker__commander--selected">
                    <show-commander-overview :card="companion" />
                </div>
                <input type="hidden" name="companion_id" :value="companion.id" />
            </form-group>
            <form-group :error="errors?.commander_id ?? ''" :invalid="!!errors?.commander_id">
                <button type="button" class="btn-default" @click="pickerOpen = true">
                    <icon name="register" />
                    {{ $t(commander ? "pages.create_deck.commander.change" : "pages.create_deck.commander.choose") }}
                </button>
            </form-group>
        </template>
        <oathbreaker-command-zone-picker-modal
            v-if="pickerOpen && withSignatureSpell"
            :format="format"
            @close="pickerOpen = false"
            @confirm="onOathbreakerConfirmed"
        />
        <commander-command-zone-picker-modal
            v-else-if="pickerOpen"
            :format="format"
            @close="pickerOpen = false"
            @confirm="onCommanderConfirmed"
        />
    </div>
</template>

<style lang="scss" scoped>
@use "Abstracts/mixins" as m;

/* Layout-neutral: the form groups sit in the enclosing form as if inline. */
.command-zone-field {
    display: contents;
}

.commander-picker__commander--selected {
    padding-right: calc(0.5rem + 20px + 0.5ch);

    @include m.mq("landscape") {
        padding-right: calc(1rem + 20px + 0.5ch);
    }
}
</style>
