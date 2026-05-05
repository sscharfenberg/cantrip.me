<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import QrCodePicker from "@/pages/QrCode/QrCodePicker.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
import type { BreadcrumbItem } from "Composables/useBreadcrumbs.ts";

type DeckSummary = { id: string; name: string };

const props = defineProps<{
    deck: DeckSummary | null;
    decks: DeckSummary[];
}>();
const { t } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
const crumbs: BreadcrumbItem[] = [{ labelKey: "pages.decks.link", href: "/decks", icon: "deck" }];
if (props.deck) {
    crumbs.push({
        label: props.deck.name,
        href: `/decks/${props.deck.id}`,
        icon: "deck"
    });
}
crumbs.push({ label: t("pages.deck_qr.link") });
setBreadcrumbs(crumbs);
const headlineName = computed(() => props.deck?.name ?? t("pages.deck_qr.any"));
</script>

<template>
    <Head
        ><title>{{ deck?.name ?? t("pages.deck_qr.any") }}</title></Head
    >
    <headline>
        <icon name="qr-code" :size="3" />
        {{ t("pages.deck_qr.title", { name: headlineName }) }}
    </headline>
    <qr-code-picker
        :selected="deck"
        :options="decks"
        url-base="/decks"
        select-icon="deck"
        :select-label="t('form.fields.deck.id')"
        :select-placeholder="t('pages.deck_qr.select_placeholder')"
        :explanation-text="t('pages.deck_qr.explanation', { name: headlineName })"
        :loading-text="t('pages.deck_qr.loading')"
        :no-selection-text="t('pages.deck_qr.no_selection')"
        :download-svg-label="t('pages.deck_qr.download_svg')"
        :download-png-label="t('pages.deck_qr.download_png')"
        filename-prefix="deck"
    />
</template>
