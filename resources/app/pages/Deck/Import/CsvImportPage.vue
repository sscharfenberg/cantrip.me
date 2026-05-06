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
const props = defineProps<{
    /** Maximum upload size in bytes, from config('cantrip.csv_upload.max_bytes'). */
    maxUploadBytes: number;
    /** Allowed file extensions for the upload input (e.g. [".csv"]). */
    allowedTypes: string[];
    /** Available source format identifiers (e.g. ["cantrip", "archidekt"]). */
    sources: string[];
    /** All available CardFormat values, for the format dropdown. */
    formats: string[];
    /** Maximum length of `decks.name` (Deck::NAME_MAX). */
    nameMax: number;
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
const selectedSource = ref<string>(props.sources.includes("cantrip") ? "cantrip" : props.sources[0]);
const selectedFormat = ref<string>("");
const deckName = ref<string>("");
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
/** Submit is enabled when a file is uploaded and no upload is in progress. */
const canSubmit = computed(() => Boolean(uploadedFilename.value) && !isUploading.value);
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
                <form-legend :items="[{ slot: 'line1', icon: 'info' }]">
                    <template #line1>{{ $t("pages.deck_import.explanations.archidekt.line1") }}</template>
                </form-legend>
            </form-group>
            <form-group
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
                for-id="deck_name"
                :label="$t('form.fields.deck_name')"
                :error="errors.deck_name ?? ''"
                :invalid="!!errors.deck_name"
                addon-icon="container-name"
            >
                <input
                    v-model="deckName"
                    type="text"
                    name="deck_name"
                    id="deck_name"
                    class="form-input"
                    :maxlength="nameMax"
                    :placeholder="$t('pages.deck_import.deck_name_placeholder')"
                />
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
