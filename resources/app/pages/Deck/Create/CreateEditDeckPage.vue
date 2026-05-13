<script setup lang="ts">
import { Form, Head } from "@inertiajs/vue3";
import { computed, nextTick, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import type { DeckHeroCardOption } from "@/pages/Deck/Modals/DeckHeroImagePickerModal.vue";
import DeckFormatCapabilities from "Components/Deck/DeckFormatCapabilities.vue";
import type { CommanderResult } from "Components/Deck/ShowCommanderOverview.vue";
import ShowCommanderOverview from "Components/Deck/ShowCommanderOverview.vue";
import FormGroup from "Components/Form/FormGroup.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import RadioButtonGroup from "Components/Form/Radio/RadioButtonGroup.vue";
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
    visibility: "private" | "public";
    /**
     * Currently-set deckbox container id, or null when the deck isn't
     * tied to a container yet. Used to preselect the container picker.
     */
    container_id: string | null;
    /** Currently chosen Commander Bracket (1-5), or null when unset. */
    bracket: number | null;
    /**
     * Auto-suggested minimum bracket based on the deck's contents
     * (game changers + mass land denial). Null when there's nothing to
     * suggest. Surfaced as a hint, never a forced value.
     */
    suggestedBracket: {
        minimum: number;
        reason: "mld" | "game_changers";
        game_changers: number;
        mld: number;
    } | null;
    commander: CommanderResult | null;
    companion: CommanderResult | null;
    signatureSpell: CommanderResult | null;
    /** Distinct printings present in the deck, used by the hero-image picker. */
    cards: DeckHeroCardOption[];
    /** Currently chosen deck hero image (one of `cards`), or null. */
    heroCard: DeckHeroCardOption | null;
}

/** A user-owned container option for the deck-edit container picker. */
export interface ContainerOption {
    id: string;
    name: string;
    type: string;
    /** True when `type === "deckbox"` — drives the recommended-suffix hint and top-of-list pinning. */
    is_deckbox: boolean;
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
        /**
         * User's containers for the deck-edit container picker. Only shipped
         * in edit mode. Empty array when the user has no containers yet.
         */
        containers?: ContainerOption[];
    }>(),
    { mode: "create", existingDeck: null, containers: () => [] }
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
/**
 * Visibility radio state — only rendered in edit mode (decks always start
 * private; you change visibility after creation, either via this form or
 * the quick-toggle in the deck actions menu).
 */
const initialVisibility = props.existingDeck?.visibility ?? "private";
const visibilityOptions = [
    {
        value: "private",
        label: "form.fields.deck_visibility_private",
        checked: initialVisibility === "private",
        icon: "visibility-off"
    },
    {
        value: "public",
        label: "form.fields.deck_visibility_public",
        checked: initialVisibility === "public",
        icon: "visibility-on"
    }
];
const visibility = ref<string>(initialVisibility);
/**
 * Currently picked deckbox container id, edit-only. Initialised from the
 * deck's `container_id` so the select preselects what's already set;
 * empty string === unset (no container). Submitted as a hidden field.
 */
const containerId = ref<string>(props.existingDeck?.container_id ?? "");
/**
 * Currently chosen Commander Bracket — optional, no preselection on
 * create. Stored as a string ("1".."5" or "") so it round-trips through
 * the MonoSelect's string-keyed options and the hidden input cleanly;
 * the controller coerces back to int|null.
 */
const bracket = ref<string>(props.existingDeck?.bracket != null ? String(props.existingDeck.bracket) : "");
const bracketOptions = computed(() =>
    [1, 2, 3, 4, 5].map(n => ({ value: String(n), label: t(`form.fields.deck_bracket_${n}`) }))
);
/**
 * Auto-suggested minimum bracket — server-computed from the deck's
 * cards via {@see BracketSuggestionService}. Only available in edit
 * mode (create mode has no cards to base a suggestion on).
 */
const bracketSuggestion = computed(() => props.existingDeck?.suggestedBracket ?? null);
/**
 * MonoSelect options for the container picker. Server already sorted
 * deckboxes to the top; we only add the recommended-suffix hint to
 * deckbox-typed entries here so non-deckbox containers stay clean.
 */
const containerOptions = computed(() =>
    props.containers.map(c => ({
        value: c.id,
        label: c.is_deckbox ? `${c.name} — ${t("form.fields.deck_container_deckbox_hint")}` : c.name
    }))
);
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
/**
 * Description-textarea character counter. Driven by the textarea's
 * `maxlength` so the displayed remaining count tracks the actual typing
 * cap exactly. State thresholds: 0 → error, ≥95% used → warning, else info.
 */
const descriptionRemaining = computed(() => Math.max(0, props.descriptionMax - deckDescription.value.length));
const descriptionCounterState = computed<"info" | "warning" | "error">(() => {
    if (descriptionRemaining.value === 0) return "error";
    if (deckDescription.value.length >= props.descriptionMax * 0.95) return "warning";
    return "info";
});
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs(
    isEdit.value && props.existingDeck
        ? [
              { labelKey: "pages.decks.link", href: "/decks" },
              { label: props.existingDeck.name, href: `/decks/${props.existingDeck.id}` },
              { labelKey: "pages.create_deck.edit_link" }
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
            <template #text>
                <p class="char-counter" :class="`char-counter--${descriptionCounterState}`">
                    {{
                        descriptionRemaining === 0
                            ? $t("form.hints.chars_full")
                            : $t("form.hints.chars_remaining", { count: descriptionRemaining })
                    }}
                </p>
            </template>
        </form-group>
        <!-- Visibility is edit-only — decks always start private, and the
             choice is only meaningful once a deck exists with content worth
             sharing. The matching quick-toggle lives in DeckActionsMenu. -->
        <form-group v-if="isEdit" :label="$t('form.fields.deck_visibility')" :required="true">
            <radio-button-group
                name="deck_visibility"
                :radio-buttons="visibilityOptions"
                @change="visibility = ($event.target as HTMLInputElement).value"
            />
        </form-group>
        <!-- Commander Bracket — optional, no preselection. Clearable so
             the user can drop the choice after picking. Submitted as a
             hidden input; the controller stores null when empty.
             Only rendered for formats that use the Wizards Game Changer
             list (currently the official Commander format only). -->
        <form-group
            v-if="selectedCapabilities?.usesGameChangerList"
            :label="$t('form.fields.deck_bracket')"
            :error="errors.bracket ?? ''"
            :invalid="!!errors?.bracket"
        >
            <mono-select
                :options="bracketOptions"
                :selected="bracket"
                :placeholder="$t('form.fields.deck_bracket_unset')"
                :sort="false"
                addon-icon="swords"
                max="100%"
                @change="bracket = $event"
            />
            <input type="hidden" name="bracket" :value="bracket" />
            <template #text>
                <form-legend v-if="bracketSuggestion" :items="[{ slot: 'bracket-suggestion', icon: 'info' }]">
                    <template #bracket-suggestion>
                        <span v-if="bracketSuggestion.reason === 'mld'">{{
                            $t("form.fields.deck_bracket_suggested_mld", {
                                minimum: bracketSuggestion.minimum
                            })
                        }}</span>
                        <span v-else>{{
                            $t("form.fields.deck_bracket_suggested_gc", {
                                minimum: bracketSuggestion.minimum,
                                count: bracketSuggestion.game_changers
                            })
                        }}</span>
                    </template>
                </form-legend>
            </template>
        </form-group>
        <!-- Container picker is edit-only and only renders when the user
             actually has containers to pick from. In mode B the picker
             provides the anchor for "in this deckbox" counts; in mode C
             it backs the wizard's "move to deck's deckbox" hint and the
             post-finalize cleanup paths. Submits as a hidden input so
             the wrapping <Form> picks it up alongside the other fields. -->
        <form-group
            v-if="isEdit && containers?.length"
            :label="$t('form.fields.deck_container')"
            :error="errors.container_id ?? ''"
            :invalid="!!errors?.container_id"
        >
            <mono-select
                :options="containerOptions"
                :selected="containerId"
                :placeholder="$t('form.fields.deck_container_unset')"
                :sort="false"
                addon-icon="container-image"
                max="100%"
                @change="containerId = $event"
            />
            <input type="hidden" name="container_id" :value="containerId" />
            <template #text>{{ $t("form.fields.deck_container_hint") }}</template>
        </form-group>
        <!-- Hero image picker is edit-only, and only useful once there are
             at least two cards to choose from — with one card the answer is
             trivial and with zero there's nothing to pick. -->
        <template v-if="isEdit && existingDeck && existingDeck.cards.length >= 2">
            <deck-hero-image-picker v-model="selectedHeroCard" :deck-id="existingDeck.id" :cards="existingDeck.cards" />
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
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;

.commander-picker__commander--selected {
    padding-right: calc(0.5rem + 20px + 0.5ch);

    @include m.mq("landscape") {
        padding-right: calc(1rem + 20px + 0.5ch);
    }
}

.char-counter {
    padding: 0.5ex 1.5ch;
    border: map.get(s.$components, "error", "border") solid transparent;
    margin: 0;

    border-radius: map.get(s.$components, "error", "radius");

    font-size: 0.875rem;

    &--info {
        background-color: map.get(c.$state, "info", "background");
        color: map.get(c.$state, "info", "surface");
        border-color: map.get(c.$state, "info", "border");
    }

    &--warning {
        background-color: map.get(c.$state, "warning", "background");
        color: map.get(c.$state, "warning", "surface");
        border-color: map.get(c.$state, "warning", "border");
    }

    &--error {
        background-color: map.get(c.$state, "error", "background");
        color: map.get(c.$state, "error", "surface");
        border-color: map.get(c.$state, "error", "border");
    }
}
</style>
