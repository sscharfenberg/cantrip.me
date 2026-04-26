<script setup lang="ts">
import { Form, Head } from "@inertiajs/vue3";
import { computed, nextTick, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import type { DeckHeroCardOption } from "@/pages/Deck/Modals/DeckHeroImagePickerModal.vue";
import DeckFormatCapabilities from "Components/Deck/DeckFormatCapabilities.vue";
import type { CommanderResult } from "Components/Deck/ShowCommanderOverview.vue";
import ShowCommanderOverview from "Components/Deck/ShowCommanderOverview.vue";
import FormGroup from "Components/Form/FormGroup.vue";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
import type { FormatCapabilities } from "Types/formatCapabilities.ts";
import CommanderCommandZonePickerModal from "./CommanderCommandZonePickerModal.vue";
import DeckHeroImagePicker from "./DeckHeroImagePicker.vue";
import OathbreakerCommandZonePickerModal from "./OathbreakerCommandZonePickerModal.vue";
/** Server-rendered shape for "edit existing deck" mode. */
export interface ExistingDeck {
    id: string;
    name: string;
    description: string | null;
    format: string;
    commander: CommanderResult | null;
    companion: CommanderResult | null;
    signatureSpell: CommanderResult | null;
    /** Distinct printings present in the deck, used by the hero-image picker. */
    cards: DeckHeroCardOption[];
    /** Currently chosen deck hero image (one of `cards`), or null. */
    heroCard: DeckHeroCardOption | null;
}
const props = withDefaults(
    defineProps<{
        /**
         * "create" renders an empty form pointed at `POST /decks/add`; "edit"
         * pre-fills from `existingDeck` and (eventually) submits to a PATCH
         * endpoint. The form structure is identical between the two modes.
         */
        mode?: "create" | "edit";
        formats: string[];
        capabilities: Record<string, FormatCapabilities>;
        nameMax: number;
        descriptionMax: number;
        /** Current deck values when `mode === "edit"`. Ignored in create mode. */
        existingDeck?: ExistingDeck | null;
    }>(),
    { mode: "create", existingDeck: null }
);
const { t } = useI18n();
/** True when the page is editing an existing deck rather than creating one. */
const isEdit = computed(() => props.mode === "edit");
/** CardFormat options formatted for MonoSelect: `{ value, label }` pairs with translated labels. */
const formatOptions = computed(() => props.formats.map(value => ({ value, label: t(`enums.card_formats.${value}`) })));
/**
 * Currently selected deck format. Initialised from `existingDeck.format` in
 * edit mode so the watcher below doesn't fire on mount and clear the
 * pre-filled command zone.
 */
const selectedFormat = ref(props.existingDeck?.format ?? "");
/** Capabilities for the currently selected format, or null if none selected. */
const selectedCapabilities = computed<FormatCapabilities | null>(
    () => props.capabilities[selectedFormat.value] ?? null
);
/** Whether the commander picker modal is open. */
const commanderPickerOpen = ref(false);
/** Whether the oathbreaker picker modal is open. */
const oathbreakerPickerOpen = ref(false);
/** Confirmed commander — pre-filled from the existing deck in edit mode. */
const commander = ref<CommanderResult | null>(props.existingDeck?.commander ?? null);
/** Confirmed partner-type companion (partner / background / friends-forever / …). */
const companion = ref<CommanderResult | null>(props.existingDeck?.companion ?? null);
/** Confirmed Oathbreaker signature spell. */
const signatureSpell = ref<CommanderResult | null>(props.existingDeck?.signatureSpell ?? null);
/**
 * Local refs back the name + description inputs so they survive Inertia
 * re-renders. Without this, `:value="existingDeck?.name"` is a one-way
 * bind that snaps the DOM back to the prop after every precognition
 * validate, silently reverting whatever the user just typed.
 */
const deckName = ref(props.existingDeck?.name ?? "");
const deckDescription = ref(props.existingDeck?.description ?? "");
/**
 * The deck card the user picked as the deck's hero image. Pre-filled from
 * the server-rendered `existingDeck.heroCard` so the current banner shows
 * up immediately when entering edit mode. Submitted to the backend as a
 * hidden `default_card_id` field on the form.
 */
const selectedHeroCard = ref<DeckHeroCardOption | null>(props.existingDeck?.heroCard ?? null);
/** Pre-fill the deck name with the commander's name when the field is empty. */
const prefillDeckName = (name: string) => {
    if (!deckName.value.trim()) {
        deckName.value = name;
    }
};
/** Store the confirmed commander and optional companion from the picker modal. */
const onCommandZoneConfirmed = (cmd: CommanderResult, comp: CommanderResult | null) => {
    commander.value = cmd;
    companion.value = comp;
    prefillDeckName(cmd.name);
};
/** Store the confirmed oathbreaker (planeswalker) and signature spell from the picker modal. */
const onOathbreakerConfirmed = (pw: CommanderResult, spell: CommanderResult) => {
    commander.value = pw;
    signatureSpell.value = spell;
    prefillDeckName(pw.name);
};
/**
 * Clear command zone when format changes — legality may differ. In edit
 * mode the format select is rendered as disabled, so this watcher won't
 * fire from user interaction; it's still wired up to keep the create flow
 * (where the format genuinely changes) working.
 */
watch(selectedFormat, () => {
    commander.value = null;
    companion.value = null;
    signatureSpell.value = null;
});
/**
 * True when the selected format requires a commander / Oathbreaker but the
 * user hasn't picked one yet. Used to disable the submit button and (once
 * the server has validated) to surface the error inline.
 */
const commanderMissing = computed(() => !!selectedCapabilities.value?.requiresCommander && !commander.value);
const signatureSpellMissing = computed(() => !!selectedCapabilities.value?.hasSignatureSpell && !signatureSpell.value);
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs(
    isEdit.value && props.existingDeck
        ? [
              { labelKey: "pages.decks.link", href: "/decks" },
              { label: props.existingDeck.name, href: `/decks/${props.existingDeck.id}` },
              { labelKey: "pages.create_deck.edit_link" },
          ]
        : [{ labelKey: "pages.decks.link", href: "/decks" }, { labelKey: "pages.create_deck.link" }]
);
</script>

<template>
    <Head>
        <title>{{ isEdit ? $t("pages.create_deck.edit_title") : $t("pages.create_deck.title") }}</title>
    </Head>
    <headline>
        <icon name="deck" :size="3" />
        {{ isEdit ? $t("pages.create_deck.edit_title") : $t("pages.create_deck.title") }}
    </headline>
    <Form
        class="form"
        :action="isEdit && existingDeck ? `/decks/${existingDeck.id}` : '/decks/add'"
        :method="isEdit ? 'patch' : 'post'"
        #default="{ errors, processing, validating, valid, validate }"
    >
        <form-group
            :label="$t('form.fields.format')"
            :required="true"
            :error="errors.format ?? ''"
            :invalid="!!errors?.format"
            :validated="valid('format')"
            :validating="validating"
        >
            <mono-select
                :options="formatOptions"
                :selected="selectedFormat"
                :disabled="isEdit"
                @change="
                    selectedFormat = $event;
                    nextTick(() => validate('format'));
                "
                addon-icon="card"
                max="100%"
            />
            <input type="hidden" name="format" :value="selectedFormat" />
            <template v-if="selectedCapabilities" #text>
                <deck-format-capabilities :capabilities="selectedCapabilities" />
            </template>
        </form-group>
        <!-- Oathbreaker: planeswalker + signature spell -->
        <template v-if="selectedCapabilities?.requiresCommander && selectedCapabilities?.hasSignatureSpell">
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
                :error="errors.commander_id ?? errors.signature_spell_id ?? ''"
                :invalid="!!errors.commander_id || !!errors.signature_spell_id"
            >
                <button type="button" class="btn-default" @click="oathbreakerPickerOpen = true">
                    <icon name="register" />
                    {{
                        $t(commander ? "pages.create_deck.oathbreaker.change" : "pages.create_deck.oathbreaker.choose")
                    }}
                </button>
            </form-group>
        </template>
        <!-- Commander-family formats: commander + optional companion -->
        <template v-else-if="selectedCapabilities?.requiresCommander">
            <form-group v-if="commander" :label="$t('form.fields.commander')" :required="true" :validated="true">
                <div class="commander-picker__commander commander-picker__commander--selected">
                    <show-commander-overview :card="commander" />
                </div>
                <input type="hidden" name="commander_id" :value="commander.id" />
            </form-group>
            <form-group
                v-if="companion && commander?.companion_type"
                :label="
                    $t(
                        `components.commander_picker.${commander.companion_type === 'partner_with' || commander.companion_type === 'partner_type' ? 'partner' : commander.companion_type}_selected`
                    )
                "
                :validated="true"
            >
                <div class="commander-picker__commander commander-picker__commander--selected">
                    <show-commander-overview :card="companion" />
                </div>
                <input type="hidden" name="companion_id" :value="companion.id" />
            </form-group>
            <form-group :error="errors.commander_id ?? ''" :invalid="!!errors.commander_id">
                <button type="button" class="btn-default" @click="commanderPickerOpen = true">
                    <icon name="register" />
                    {{ $t(commander ? "pages.create_deck.commander.change" : "pages.create_deck.commander.choose") }}
                </button>
            </form-group>
        </template>
        <form-group
            for-id="deck_name"
            :label="$t('form.fields.deck_name')"
            :error="errors.deck_name ?? ''"
            :invalid="!!errors?.deck_name"
            :validated="valid('deck_name')"
            :validating="validating"
            :required="true"
            addon-icon="container-name"
        >
            <input
                v-model="deckName"
                type="text"
                name="deck_name"
                id="deck_name"
                class="form-input"
                :maxlength="nameMax"
                @change="validate('deck_name')"
            />
        </form-group>
        <form-group
            for-id="deck_description"
            :label="$t('form.fields.container.description')"
            :error="errors.deck_description ?? ''"
            :invalid="!!errors?.deck_description"
            :validated="valid('deck_description')"
            :validating="validating"
        >
            <div class="form-input__textarea-addon"><icon name="text" /></div>
            <div class="form-input form-input__textarea">
                <textarea
                    v-model="deckDescription"
                    name="deck_description"
                    id="deck_description"
                    :maxlength="props.descriptionMax"
                    @change="validate('deck_description')"
                />
            </div>
        </form-group>
        <!-- Hero image picker is edit-only, and only useful once there are
             at least two cards to choose from — with one card the answer is
             trivial and with zero there's nothing to pick. -->
        <template v-if="isEdit && existingDeck && existingDeck.cards.length >= 2">
            <deck-hero-image-picker
                v-model="selectedHeroCard"
                :deck-id="existingDeck.id"
                :cards="existingDeck.cards"
            />
            <input type="hidden" name="default_card_id" :value="selectedHeroCard?.id ?? ''" />
        </template>
        <form-group>
            <button
                type="submit"
                class="btn-primary"
                :disabled="processing || commanderMissing || signatureSpellMissing"
            >
                <icon name="save" />
                {{ isEdit ? $t("pages.create_deck.edit_submit") : $t("pages.create_deck.submit") }}
            </button>
        </form-group>
    </Form>
    <commander-command-zone-picker-modal
        v-if="commanderPickerOpen"
        :format="selectedFormat"
        @close="commanderPickerOpen = false"
        @confirm="onCommandZoneConfirmed"
    />
    <oathbreaker-command-zone-picker-modal
        v-if="oathbreakerPickerOpen"
        :format="selectedFormat"
        @close="oathbreakerPickerOpen = false"
        @confirm="onOathbreakerConfirmed"
    />
</template>

<style lang="scss" scoped>
@use "Abstracts/mixins" as m;

.commander-picker__commander--selected {
    padding-right: calc(0.5rem + 20px + 0.5ch);

    @include m.mq("landscape") {
        padding-right: calc(1rem + 20px + 0.5ch);
    }
}
</style>
