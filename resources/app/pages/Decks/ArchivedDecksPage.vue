<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import { computed, ref, useTemplateRef } from "vue";
import { useI18n } from "vue-i18n";
import DeckFormatFolder from "@/pages/Decks/DeckFormatFolder.vue";
import type { DeckRow } from "@/pages/Decks/DecksPage.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
const props = defineProps<{
    /** Archived decks grouped by format value, same shape as DecksPage. */
    decksByFormat: Record<string, DeckRow[]>;
}>();
const { t } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([
    { labelKey: "pages.decks.link", href: "/decks", icon: "deck" },
    { labelKey: "pages.decks.archived_link" }
]);
/** Format keys sorted alphabetically by their translated label. */
const sortedFormats = computed(() =>
    Object.keys(props.decksByFormat).sort((a, b) =>
        t(`enums.card_formats.${a}`).localeCompare(t(`enums.card_formats.${b}`))
    )
);
const initialHash = window.location.hash.replace("#", "");
const initialOpenFormat = initialHash || sortedFormats.value[0] || "";
if (!initialHash && initialOpenFormat) {
    history.replaceState(null, "", `${window.location.pathname}${window.location.search}#${initialOpenFormat}`);
}
const openFormat = ref<string | null>(initialOpenFormat || null);
const folderRefs = useTemplateRef<InstanceType<typeof DeckFormatFolder>[]>("folderRefs");
function onFolderToggle(format: string, isOpen: boolean): void {
    if (isOpen) {
        if (openFormat.value && openFormat.value !== format) {
            const prev = folderRefs.value?.find(ref => ref.$props.format === openFormat.value);
            prev?.close();
        }
        openFormat.value = format;
        window.location.hash = format;
    } else {
        openFormat.value = null;
        history.replaceState(null, "", window.location.pathname + window.location.search);
    }
}
</script>

<template>
    <Head
        ><title>{{ $t("pages.archived_decks.title") }}</title></Head
    >
    <headline>
        <icon name="archived" :size="3" />
        {{ $t("pages.archived_decks.title") }}
    </headline>
    <div v-if="sortedFormats.length" class="deck-folders">
        <deck-format-folder
            v-for="format in sortedFormats"
            :key="format"
            ref="folderRefs"
            :format="format"
            :decks="decksByFormat[format]"
            :initial-open="format === initialOpenFormat"
            @toggle="onFolderToggle"
        />
    </div>
    <paragraph v-else>{{ $t("pages.archived_decks.no_decks") }}</paragraph>
</template>

<style lang="scss" scoped>
.deck-folders {
    display: flex;
    flex-direction: column;

    margin-top: 1lh;
    gap: 0.5rem;
}
</style>
