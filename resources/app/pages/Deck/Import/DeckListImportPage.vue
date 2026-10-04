<script setup lang="ts">
/******************************************************************************
 * Import a deck by pasting its list — the paste form, then the review step.
 *
 * The paste is parsed and resolved by `POST /api/decks/import-list/parse`,
 * which writes nothing; the review step ({@see DeckListReview}) creates the
 * deck on confirm. Going back keeps the text, so a typo can be fixed in the
 * paste and parsed again.
 *****************************************************************************/
import { Head, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import FormGroup from "Components/Form/FormGroup.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import type { BreadcrumbItem } from "Composables/useBreadcrumbs.ts";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
import type { DeckListParseResult } from "Types/deckListImport.ts";
import DeckListReview from "./DeckListReview.vue";
const props = defineProps<{
    /** All available CardFormat values, for the format dropdown. */
    formats: string[];
    /** Maximum length of `decks.name` (Deck::NAME_MAX). */
    nameMax: number;
    /** Maximum characters of the paste. */
    maxChars: number;
    /** Maximum non-blank lines of the paste. */
    maxLines: number;
}>();
const { t } = useI18n();
const page = usePage();
const { setBreadcrumbs } = useBreadcrumbs();
const crumbs: BreadcrumbItem[] = [
    { labelKey: "pages.decks.link", href: "/decks", icon: "deck" },
    { labelKey: "pages.deck_list_import.link" }
];
setBreadcrumbs(crumbs);
const formatOptions = computed(() =>
    props.formats
        .map(f => ({ value: f, label: t(`enums.card_formats.${f}`) }))
        .sort((a, b) => a.label.localeCompare(b.label))
);
const format = ref("");
const deckName = ref("");
const text = ref("");
/** Non-blank lines of the paste, for the counter. */
const lineCount = computed(() => text.value.split(/\r\n|\r|\n/).filter(line => line.trim() !== "").length);
const tooLong = computed(() => text.value.length > props.maxChars || lineCount.value > props.maxLines);
/** A parse request is in flight. */
const parsing = ref(false);
const canParse = computed(() => format.value !== "" && text.value.trim() !== "" && !tooLong.value && !parsing.value);

/** Validation errors from the parse request, flattened to field → message. */
const errors = ref<Record<string, string>>({});
/** The resolved paste; the review step shows while it is set. */
const result = ref<DeckListParseResult | null>(null);
/** The format the shown result was resolved against. */
const reviewedFormat = ref("");

/** Parse and resolve the paste, then switch to the review step. */
const parse = async (): Promise<void> => {
    if (!canParse.value) return;
    parsing.value = true;
    errors.value = {};
    try {
        const response = await fetch("/api/decks/import-list/parse", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": page.props.csrfToken as string,
                Accept: "application/json"
            },
            body: JSON.stringify({ format: format.value, text: text.value })
        });
        if (response.status === 422) {
            const data = (await response.json()) as { errors?: Record<string, string[]> };
            errors.value = Object.fromEntries(
                Object.entries(data.errors ?? {}).map(([field, messages]) => [field, messages[0]])
            );
            return;
        }
        if (!response.ok) {
            errors.value = { text: t("pages.deck_list_import.paste.failed") };
            return;
        }
        const data = (await response.json()) as DeckListParseResult;
        if (deckName.value.trim() === "" && data.command_zone.commander) {
            deckName.value = data.command_zone.commander.name;
        }
        reviewedFormat.value = format.value;
        result.value = data;
    } catch {
        errors.value = { text: t("pages.deck_list_import.paste.failed") };
    } finally {
        parsing.value = false;
    }
};
</script>

<template>
    <Head
        ><title>{{ $t("pages.deck_list_import.title") }}</title></Head
    >
    <headline>
        <icon name="text" :size="3" />
        {{ $t("pages.deck_list_import.title") }}
    </headline>
    <deck-list-review
        v-if="result"
        v-model:deck-name="deckName"
        :result="result"
        :format="reviewedFormat"
        :name-max="nameMax"
        @back="result = null"
    />
    <form v-else class="form" @submit.prevent="parse">
        <form-group>
            <form-legend
                :items="[
                    { slot: 'line1', icon: 'info' },
                    { slot: 'line2', icon: 'info' }
                ]"
            >
                <template #line1>{{ $t("pages.deck_list_import.paste.explanation") }}</template>
                <template #line2>{{ $t("pages.deck_list_import.paste.explanation_review") }}</template>
            </form-legend>
        </form-group>
        <form-group
            :label="$t('pages.deck_list_import.paste.format')"
            :error="errors.format ?? ''"
            :invalid="!!errors.format"
            :required="true"
            for-id="format"
        >
            <mono-select
                :options="formatOptions"
                :selected="format"
                :clearable="false"
                addon-icon="spell"
                @change="format = $event"
            />
        </form-group>
        <form-group
            for-id="deck_name"
            :label="$t('form.fields.deck_name')"
            :error="errors.deck_name ?? ''"
            :invalid="!!errors.deck_name"
            addon-icon="container-name"
        >
            <input
                id="deck_name"
                v-model="deckName"
                type="text"
                name="deck_name"
                class="form-input"
                :maxlength="nameMax"
                :placeholder="$t('pages.deck_list_import.paste.deck_name_placeholder')"
            />
        </form-group>
        <form-group
            for-id="deck_list"
            :label="$t('pages.deck_list_import.paste.text')"
            :error="errors.text ?? ''"
            :invalid="!!errors.text || tooLong"
            :required="true"
        >
            <div class="form-input__textarea-addon"><icon name="text" /></div>
            <div class="form-input form-input__textarea">
                <textarea
                    id="deck_list"
                    v-model="text"
                    name="text"
                    class="deck-list-input"
                    rows="16"
                    spellcheck="false"
                    :placeholder="$t('pages.deck_list_import.paste.placeholder')"
                />
            </div>
            <template #text>
                <p class="deck-list-counter" :class="{ 'deck-list-counter--over': tooLong }">
                    {{
                        $t("pages.deck_list_import.paste.counter", {
                            lines: lineCount,
                            maxLines,
                            chars: text.length,
                            maxChars
                        })
                    }}
                </p>
            </template>
        </form-group>
        <form-group>
            <button type="submit" class="btn-primary" :disabled="!canParse">
                <icon name="search" />
                {{ $t(parsing ? "pages.deck_list_import.paste.parsing" : "pages.deck_list_import.paste.submit") }}
            </button>
        </form-group>
    </form>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;

.deck-list-input {
    font-family: monospace;
}

.deck-list-counter {
    margin: 0;

    font-size: 0.875rem;

    &--over {
        color: map.get(c.$state, "error", "surface");
    }
}
</style>
