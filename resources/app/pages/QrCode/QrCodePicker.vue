<script setup lang="ts">
import { router, usePage } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import FormGroup from "Components/Form/FormGroup.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";

export type QrEntity = { id: string; name: string };

const props = defineProps<{
    /** Pre-selected entity, or null when accessed without an ID. */
    selected: QrEntity | null;
    /** Full list of pickable entities for the dropdown. */
    options: QrEntity[];
    /** URL prefix that the picker prepends to `/{id}/qr` for both navigation and SVG fetch. */
    urlBase: string;
    /** Icon shown as the dropdown's leading addon. */
    selectIcon: string;
    /** Already-translated FormGroup label for the dropdown. */
    selectLabel: string;
    /** Already-translated dropdown placeholder. */
    selectPlaceholder: string;
    /** Already-translated explanation text shown above the dropdown. */
    explanationText: string;
    /** Already-translated label shown while the SVG is being fetched. */
    loadingText: string;
    /** Already-translated message shown when nothing is selected yet. */
    noSelectionText: string;
    /** Button label for the SVG download action. */
    downloadSvgLabel: string;
    /** Button label for the PNG download action. */
    downloadPngLabel: string;
    /** Filename prefix and fallback noun, e.g. `container` → `qr-container.svg` when nothing selected. */
    filenamePrefix: string;
}>();

const page = usePage();
const csrfToken = computed(() => page.props.csrfToken as string);

const selectedId = ref(props.selected?.id ?? "");
const qrSvg = ref("");
const loading = ref(false);

const dropdownOptions = computed(() => props.options.map(o => ({ value: o.id, label: o.name })));

/**
 * Navigate to the QR page for the picked entity. Inertia replaces the
 * `selected` prop reactively, which trips the watcher below to fetch the
 * SVG for the new entity.
 */
function onSelectionChange(id: string) {
    selectedId.value = id;
    if (!id) {
        qrSvg.value = "";
        return;
    }
    router.get(`${props.urlBase}/${id}/qr`, {}, { preserveState: true });
}

/** Fetch the QR code SVG from the server for the given entity ID. */
async function fetchQr(id: string) {
    loading.value = true;
    qrSvg.value = "";
    try {
        const response = await fetch(`${props.urlBase}/${id}/qr`, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-TOKEN": csrfToken.value
            }
        });
        if (response.ok) {
            const data = await response.json();
            qrSvg.value = data.svg;
        }
    } finally {
        loading.value = false;
    }
}

/** Compose the download filename from the selected entity (or the type fallback). */
function filename(ext: string): string {
    return `qr-${props.selected?.name ?? props.filenamePrefix}.${ext}`;
}

/** Download the QR code as an SVG file with XML declaration. */
function downloadSvg() {
    const xmlDeclaration = '<?xml version="1.0" encoding="UTF-8"?>\n';
    const blob = new Blob([xmlDeclaration + qrSvg.value], { type: "image/svg+xml" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename("svg");
    a.click();
    URL.revokeObjectURL(url);
}

/** Rasterize the QR SVG to a 1024×1024 PNG via canvas and trigger a download. */
function downloadPng() {
    const size = 1024;
    const svgBlob = new Blob([qrSvg.value], { type: "image/svg+xml;charset=utf-8" });
    const svgUrl = URL.createObjectURL(svgBlob);
    const img = new Image();
    img.onload = () => {
        const canvas = document.createElement("canvas");
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext("2d")!;
        ctx.drawImage(img, 0, 0, size, size);
        URL.revokeObjectURL(svgUrl);
        const a = document.createElement("a");
        a.href = canvas.toDataURL("image/png");
        a.download = filename("png");
        a.click();
    };
    img.src = svgUrl;
}

/** When Inertia delivers a new entity prop after navigation, fetch the QR code. */
watch(
    () => props.selected,
    newSelected => {
        if (newSelected) {
            selectedId.value = newSelected.id;
            fetchQr(newSelected.id);
        } else {
            qrSvg.value = "";
        }
    },
    { immediate: true }
);
</script>

<template>
    <div class="qr-code">
        <form-legend :items="[{ slot: 'info', icon: 'info' }]">
            <template #info>
                {{ explanationText }}
            </template>
        </form-legend>
        <form-group :label="selectLabel" for-id="qr-picker-select" class="qr-code__select">
            <mono-select
                :options="dropdownOptions"
                :selected="selectedId"
                :addon-icon="selectIcon"
                :placeholder="selectPlaceholder"
                :clearable="false"
                @change="onSelectionChange"
            />
        </form-group>
        <form-group v-if="loading">
            <div class="qr-code__loading">
                <loading-spinner :branded="true" :size="4" />
                {{ loadingText }}
            </div>
        </form-group>
        <form-group v-else-if="qrSvg">
            <div class="qr-code__preview" v-html="qrSvg" />
        </form-group>
        <form-group v-if="qrSvg">
            <div class="actions">
                <button type="button" class="btn-default" @click="downloadSvg">
                    <icon name="download" />
                    {{ downloadSvgLabel }}
                </button>
                <button type="button" class="btn-default" @click="downloadPng">
                    <icon name="download" />
                    {{ downloadPngLabel }}
                </button>
            </div>
        </form-group>
        <form-group v-else>
            {{ noSelectionText }}
        </form-group>
    </div>
</template>

<style scoped lang="scss">
.qr-code {
    display: flex;
    flex-direction: column;

    gap: 1rem;
}

.qr-code__preview {
    width: 100%;
    max-width: 20rem;

    :deep(svg) {
        width: 100%;
        height: auto;
    }
}

.actions {
    display: flex;
    flex-wrap: wrap;

    gap: 1ch;
}

.qr-code__loading {
    display: flex;
    align-items: center;

    gap: 1rem;
}
</style>

<style lang="scss">
@use "Abstracts/mixins" as m;

@include m.theme-dark(".qr-code__preview") {
    filter: invert(1);
}
</style>
