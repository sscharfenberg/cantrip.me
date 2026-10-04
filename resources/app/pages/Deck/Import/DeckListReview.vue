<script setup lang="ts">
/******************************************************************************
 * Review step of the deck list import: what the paste resolved to, the few
 * edits allowed before the deck exists, and the confirm that creates it.
 *
 * Editable here: leave a line out, fix an unresolved line (suggestion or
 * search), the zone of a section whose zone the parser guessed, and the
 * command zone. Everything else — quantities, printings, categories, more
 * cards — is the deck page's job afterwards. All of it is client-side state;
 * nothing is written until confirm, so leaving the page abandons the import.
 *****************************************************************************/
import { router, usePage } from "@inertiajs/vue3";
import { computed, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";
import type { LineChoice } from "@/utils/deckListImport.ts";
import {
    blockingLines,
    commandZonePrintings,
    effectiveLines,
    lineWarnings,
    submissionRows,
    zoneTotals
} from "@/utils/deckListImport.ts";
import CommandZoneField from "Components/Deck/CommandZoneField.vue";
import type { CommanderResult } from "Components/Deck/ShowCommanderOverview.vue";
import FormGroup from "Components/Form/FormGroup.vue";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import type { DeckListCandidate, DeckListParseResult, DeckListZone } from "Types/deckListImport.ts";
import DeckListReviewLine from "./DeckListReviewLine.vue";
const props = defineProps<{
    result: DeckListParseResult;
    format: string;
    /** Maximum length of `decks.name`. */
    nameMax: number;
}>();
const emit = defineEmits<{
    /** Back to the paste form. */
    back: [];
}>();
/** Deck name — shared with the paste form, editable here too. */
const deckName = defineModel<string>("deckName", { required: true });
const { t } = useI18n();
const page = usePage();

const commander = ref<CommanderResult | null>(props.result.command_zone.commander);
const partner = ref<CommanderResult | null>(props.result.command_zone.partner);
const signatureSpell = ref<CommanderResult | null>(props.result.command_zone.signature_spell);
const commandZone = computed(() => ({
    commander: commander.value,
    partner: partner.value,
    signatureSpell: signatureSpell.value
}));

/** Per-line edits, keyed by line number. */
const choices = reactive<Record<number, LineChoice>>(
    Object.fromEntries(props.result.lines.map(line => [line.line, { excluded: false, replacement: null }]))
);
/** Zones the user chose for sections the parser guessed. */
const sectionZones = reactive<Record<number, DeckListZone>>(
    Object.fromEntries(
        props.result.sections
            .filter(section => section.zone_guessed)
            .map(section => [section.index, section.zone as DeckListZone])
    )
);
const zoneOptions = computed(() =>
    (["main", "side"] as const).map(zone => ({ value: zone, label: t(`pages.deck_list_import.zones.${zone}`) }))
);

const effective = computed(() => effectiveLines(props.result.lines, choices, sectionZones, commandZone.value));
const effectiveByLine = computed(() => Object.fromEntries(effective.value.map(line => [line.line, line])));
const warnings = computed(() =>
    lineWarnings(effective.value, commandZone.value, props.result.rules.enforcesColorIdentity)
);
const totals = computed(() => zoneTotals(effective.value, commandZone.value));
const blocking = computed(() => blockingLines(props.result.lines, choices));
const commandZoneMissing = computed(
    () =>
        props.result.rules.requiresCommander &&
        (commander.value === null || (props.result.rules.hasSignatureSpell && signatureSpell.value === null))
);
const droppedText = computed(() =>
    t("pages.deck_list_import.review.dropped", { count: props.result.dropped }, props.result.dropped)
);
const unresolvedText = computed(() =>
    t("pages.deck_list_import.review.unresolved", { count: blocking.value.length }, blocking.value.length)
);
/** A confirm request is in flight. */
const submitting = ref(false);
const canConfirm = computed(
    () => deckName.value.trim() !== "" && blocking.value.length === 0 && !commandZoneMissing.value && !submitting.value
);
/** Sections that still have lines, in paste order. */
const sections = computed(() =>
    props.result.sections
        .map(section => ({ section, lines: props.result.lines.filter(line => line.section === section.index) }))
        .filter(group => group.lines.length > 0)
);

/** Exclude a line, or bring it back. */
const setExcluded = (line: number, excluded: boolean) => {
    choices[line].excluded = excluded;
};
/** Use a picked card for a line. */
const pick = (line: number, candidate: DeckListCandidate) => {
    choices[line].replacement = candidate;
};
/** Drop a line's replacement again. */
const undo = (line: number) => {
    choices[line].replacement = null;
};
/** Name the deck after its commander when the user has not named it. */
const prefillDeckName = (card: CommanderResult) => {
    if (deckName.value.trim() === "") deckName.value = card.name;
};

/** Validation errors from the confirm request, flattened to field → message. */
const errors = ref<Record<string, string>>({});
/** Create the deck. On success the server answers with the deck's URL. */
const confirm = async (): Promise<void> => {
    if (!canConfirm.value) return;
    submitting.value = true;
    errors.value = {};
    try {
        const response = await fetch("/decks/import-list", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": page.props.csrfToken as string,
                Accept: "application/json"
            },
            body: JSON.stringify({
                format: props.format,
                deck_name: deckName.value.trim(),
                commander_id: commander.value?.id ?? null,
                companion_id: props.result.rules.hasSignatureSpell ? null : (partner.value?.id ?? null),
                signature_spell_id: props.result.rules.hasSignatureSpell ? (signatureSpell.value?.id ?? null) : null,
                command_zone_printings: commandZonePrintings(commandZone.value, props.result.command_zone.printings),
                rows: submissionRows(effective.value)
            })
        });
        if (response.status === 422) {
            const data = (await response.json()) as { errors?: Record<string, string[]> };
            errors.value = Object.fromEntries(
                Object.entries(data.errors ?? {}).map(([field, messages]) => [field, messages[0]])
            );
            return;
        }
        if (!response.ok) {
            errors.value = { general: t("pages.deck_list_import.review.failed") };
            return;
        }
        const data = (await response.json()) as { redirect: string };
        router.visit(data.redirect);
    } catch {
        errors.value = { general: t("pages.deck_list_import.review.failed") };
    } finally {
        submitting.value = false;
    }
};
</script>

<template>
    <div class="deck-list-review">
        <div class="deck-list-review__actions">
            <button type="button" class="btn-default" @click="emit('back')">
                <icon name="edit" />
                {{ $t("pages.deck_list_import.review.back") }}
            </button>
        </div>
        <paragraph>
            {{
                $t("pages.deck_list_import.review.summary", {
                    main: totals.main,
                    side: totals.side
                })
            }}
            <template v-if="result.dropped > 0"> <br />{{ droppedText }} </template>
            <br />{{ $t("pages.deck_list_import.review.printing_hint") }}
        </paragraph>
        <form class="form" @submit.prevent="confirm">
            <form-group
                for-id="deck_list_name"
                :label="$t('form.fields.deck_name')"
                :error="errors.deck_name ?? ''"
                :invalid="!!errors.deck_name"
                :required="true"
                addon-icon="container-name"
            >
                <input
                    id="deck_list_name"
                    v-model="deckName"
                    type="text"
                    name="deck_name"
                    class="form-input"
                    :maxlength="nameMax"
                />
            </form-group>
            <command-zone-field
                v-if="result.rules.requiresCommander"
                v-model:commander="commander"
                v-model:companion="partner"
                v-model:signature-spell="signatureSpell"
                :format="format"
                :with-signature-spell="result.rules.hasSignatureSpell"
                :errors="errors"
                @confirmed="prefillDeckName"
            />
        </form>
        <section v-for="group in sections" :key="group.section.index" class="deck-list-review__section">
            <div class="deck-list-review__section-head">
                <headline :size="4">
                    {{ group.section.label ?? $t("pages.deck_list_import.review.untitled_section") }}
                </headline>
                <div v-if="group.section.zone_guessed" class="deck-list-review__zone">
                    <span>{{ $t("pages.deck_list_import.review.zone_guessed") }}</span>
                    <mono-select
                        :options="zoneOptions"
                        :selected="sectionZones[group.section.index]"
                        :clearable="false"
                        @change="sectionZones[group.section.index] = $event as DeckListZone"
                    />
                </div>
            </div>
            <ul class="deck-list-review__lines">
                <deck-list-review-line
                    v-for="line in group.lines"
                    :key="line.line"
                    :line="line"
                    :choice="choices[line.line]"
                    :effective="effectiveByLine[line.line]"
                    :warnings="warnings[line.line] ?? []"
                    :format="format"
                    @exclude="setExcluded(line.line, $event)"
                    @pick="pick(line.line, $event)"
                    @undo="undo(line.line)"
                />
            </ul>
        </section>
        <paragraph v-if="result.lines.length === 0">{{ $t("pages.deck_list_import.review.no_lines") }}</paragraph>
        <ul v-if="Object.keys(errors).length" class="deck-list-review__errors">
            <li v-for="(message, field) in errors" :key="field">{{ message }}</li>
        </ul>
        <div class="deck-list-review__confirm">
            <p v-if="blocking.length" class="deck-list-review__blocker">
                <icon name="error" :size="1" />
                {{ unresolvedText }}
            </p>
            <p v-if="commandZoneMissing" class="deck-list-review__blocker">
                <icon name="error" :size="1" />
                {{ $t("pages.deck_list_import.review.command_zone_missing") }}
            </p>
            <button type="button" class="btn-primary" :disabled="!canConfirm" @click="confirm">
                <icon name="save" />
                {{ $t("pages.deck_list_import.review.confirm") }}
            </button>
        </div>
    </div>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;

.deck-list-review {
    display: flex;
    flex-direction: column;

    gap: 1lh;
}

.deck-list-review__actions,
.deck-list-review__confirm {
    display: flex;
    align-items: center;
    flex-wrap: wrap;

    gap: 0.5rem 1rem;
}

.deck-list-review__section-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;

    gap: 0.5rem 1rem;
}

.deck-list-review__zone {
    display: flex;
    align-items: center;

    gap: 1ch;
}

.deck-list-review__lines {
    display: flex;
    flex-direction: column;

    padding: 0;
    margin: 0;
    gap: 0.25ex;

    list-style: none;
}

.deck-list-review__errors,
.deck-list-review__blocker {
    display: flex;
    align-items: center;

    margin: 0;
    gap: 0.5ch;

    color: map.get(c.$state, "error", "surface");
}

.deck-list-review__errors {
    align-items: flex-start;
    flex-direction: column;
}
</style>
