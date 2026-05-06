<script setup lang="ts">
import { Form, Head, router } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import FileUpload from "Components/Form/FileUpload/FileUpload.vue";
import FormGroup from "Components/Form/FormGroup.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import type { BreadcrumbItem } from "Composables/useBreadcrumbs.ts";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
import { useFormatting } from "Composables/useFormatting";
interface DeckOption {
    id: string;
    name: string;
    format: string;
    card_count: number;
}
const props = defineProps<{
    /** All decks belonging to the user. The Archidekt path filters this to empty decks. */
    decks: DeckOption[];
    /** Maximum upload size in bytes, from config('cantrip.csv_upload.max_bytes'). */
    maxUploadBytes: number;
    /** Allowed file extensions for the upload input (e.g. [".csv"]). */
    allowedTypes: string[];
    /** Available source format identifiers (e.g. ["cantrip", "archidekt"]). */
    sources: string[];
    /** All available CardFormat values, for the cantrip-path format dropdown. */
    formats: string[];
    /** Import results from the controller, null on initial GET. */
    results?: {
        imported: number;
        commanders: number;
        companion: number;
        skipped: number;
        skipped_rows: Array<{ row: number; name: string; reason: string }>;
        deck: { id: string; name: string };
    } | null;
}>();
const { t } = useI18n();
const { formatDecimals } = useFormatting();
const { setBreadcrumbs } = useBreadcrumbs();
const crumbs: BreadcrumbItem[] = [
    { labelKey: "pages.decks.link", href: "/decks", icon: "deck" },
    { labelKey: "pages.deck_import.link" }
];
setBreadcrumbs(crumbs);
const sourceOptions = computed(() =>
    props.sources.map(s => ({
        value: s,
        label: t(`pages.deck_import.sources.${s}`)
    }))
);
const formatOptions = computed(() =>
    props.formats
        .map(f => ({ value: f, label: t(`enums.card_formats.${f}`) }))
        .sort((a, b) => a.label.localeCompare(b.label))
);
/**
 * Every deck the user owns, formatted for the "Import to" dropdown.
 * Already sorted server-side by card_count asc then name asc, so empty
 * decks (the natural Archidekt targets) surface first.
 */
const deckOptions = computed(() =>
    props.decks.map(d => ({
        value: d.id,
        label: `${d.name} (${t(`enums.card_formats.${d.format}`)} · ${formatDecimals(d.card_count)} ${t("pages.decks.card_count_noun", d.card_count)})`
    }))
);
/** True when the user has no empty deck — drives a warning legend. */
const hasEmptyDeck = computed(() => props.decks.some(d => d.card_count === 0));
const selectedSource = ref<string>(props.sources.includes("cantrip") ? "cantrip" : props.sources[0]);
const selectedFormat = ref<string>("");
const selectedDeck = ref<string>(deckOptions.value[0]?.value ?? "");
/** Server-generated tmp filename, set after the XHR upload succeeds. */
const uploadedFilename = ref("");
/** XHR upload error (file too large, not parseable, etc.). */
const uploadError = ref("");
const onUploadSuccess = (filename: string) => {
    uploadedFilename.value = filename;
    uploadError.value = "";
};
const onUploadError = (message: string) => {
    uploadError.value = message;
    uploadedFilename.value = "";
};
const onUploadClear = () => {
    uploadedFilename.value = "";
    uploadError.value = "";
};
const isUploading = ref(false);
/**
 * Submit is enabled when a file is uploaded, no upload is in progress,
 * and — for the Archidekt path — the user has at least one deck to
 * import into.
 */
const canSubmit = computed(() => {
    if (!uploadedFilename.value || isUploading.value) return false;
    return !(selectedSource.value === "archidekt" && deckOptions.value.length === 0);
});
</script>

<template>
    <Head
        ><title>{{ $t("pages.deck_import.title") }}</title></Head
    >
    <headline>
        <icon name="upload" :size="3" />
        {{ $t("pages.deck_import.title") }}
    </headline>
    <template v-if="!results">
        <Form class="form" action="/decks/import" method="post" #default="{ errors }">
            <form-group :label="$t('pages.deck_import.source')" for-id="source">
                <mono-select
                    :options="sourceOptions"
                    :selected="selectedSource"
                    :clearable="false"
                    @change="selectedSource = $event"
                />
                <input type="hidden" name="source" v-model="selectedSource" />
            </form-group>

            <form-group v-if="selectedSource === 'cantrip'">
                <form-legend :items="[{ slot: 'line1', icon: 'info' }]">
                    <template #line1>{{ $t("pages.deck_import.explanations.cantrip.line1") }}</template>
                </form-legend>
            </form-group>
            <form-group v-else-if="selectedSource === 'archidekt'">
                <form-legend
                    :items="[
                        { slot: 'line1', icon: 'info' },
                        ...(!hasEmptyDeck ? [{ slot: 'no_empty', icon: 'warning', modifier: 'warning' }] : [])
                    ]"
                >
                    <template #line1>{{ $t("pages.deck_import.explanations.archidekt.line1") }}</template>
                    <template #no_empty>{{ $t("pages.deck_import.no_empty_decks") }}</template>
                </form-legend>
            </form-group>
            <form-group
                v-if="selectedSource === 'cantrip'"
                :label="$t('pages.deck_import.format')"
                :error="errors.format"
                :invalid="!!errors.format"
                for-id="format"
            >
                <mono-select
                    :options="formatOptions"
                    :selected="selectedFormat"
                    :clearable="false"
                    addon-icon="spell"
                    @change="selectedFormat = $event"
                />
                <input type="hidden" name="format" v-model="selectedFormat" />
            </form-group>

            <form-group
                v-else-if="selectedSource === 'archidekt' && deckOptions.length > 0"
                :label="$t('pages.deck_import.target')"
                :error="errors.deck"
                :invalid="!!errors.deck"
                for-id="deck"
            >
                <mono-select
                    :options="deckOptions"
                    :selected="selectedDeck"
                    :clearable="false"
                    addon-icon="deck"
                    @change="selectedDeck = $event"
                />
                <input type="hidden" name="deck" v-model="selectedDeck" />
            </form-group>

            <form-group
                :label="$t('pages.deck_import.file')"
                :error="uploadError || errors.filename"
                :invalid="!!uploadError || !!errors.filename"
            >
                <file-upload
                    action="/collection/import/upload"
                    :allowed-types="allowedTypes"
                    :max-bytes="maxUploadBytes"
                    @success="onUploadSuccess"
                    @error="onUploadError"
                    @clear="onUploadClear"
                    @uploading="isUploading = $event"
                />
            </form-group>
            <input type="hidden" name="filename" v-model="uploadedFilename" />
            <form-group>
                <button class="btn-primary" :disabled="!canSubmit">
                    <icon name="save" />
                    {{ $t("pages.deck_import.submit") }}
                </button>
            </form-group>
        </Form>
    </template>
    <template v-else>
        <headline :size="3">{{ $t("pages.deck_import.results.title") }}</headline>
        <paragraph>
            {{ $t("pages.deck_import.results.imported", { count: formatDecimals(results.imported) }) }}<br />
            <span v-if="results.commanders > 0">
                {{ $t("pages.deck_import.results.commanders", { count: formatDecimals(results.commanders) }) }}<br />
            </span>
            <span v-if="results.companion > 0"> {{ $t("pages.deck_import.results.companion") }}<br /> </span>
            <span v-if="results.skipped > 0">
                {{ $t("pages.deck_import.results.skipped", { count: formatDecimals(results.skipped) }) }}
            </span>
        </paragraph>
        <table v-if="results.skipped_rows.length > 0" class="dt__table">
            <thead class="dt-head">
                <tr>
                    <th>{{ $t("pages.deck_import.results.skipped_table.row") }}</th>
                    <th>{{ $t("pages.deck_import.results.skipped_table.name") }}</th>
                    <th>{{ $t("pages.deck_import.results.skipped_table.reason") }}</th>
                </tr>
            </thead>
            <tbody class="dt-body">
                <tr v-for="row in results.skipped_rows" :key="row.row">
                    <td>{{ row.row }}</td>
                    <td>{{ row.name }}</td>
                    <td>{{ $t(`pages.deck_import.results.reasons.${row.reason}`) }}</td>
                </tr>
            </tbody>
        </table>
        <div class="actions">
            <button class="btn-primary" @click="router.visit(`/decks/${results.deck.id}`)">
                <icon name="deck" />
                {{ $t("pages.deck_import.results.go_to_deck") }}
            </button>
            <button class="btn-default" @click="router.visit('/decks/import')">
                <icon name="upload" />
                {{ $t("pages.deck_import.results.import_another") }}
            </button>
        </div>
    </template>
</template>

<style lang="scss" scoped>
.dt__table {
    margin: 1lh 0;
}

.actions {
    display: flex;

    gap: 0.5rem;
}
</style>
