<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import QrCodePicker from "@/pages/QrCode/QrCodePicker.vue";
import type { ContainerListItem } from "@/types/containerListItem";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
import type { BreadcrumbItem } from "Composables/useBreadcrumbs.ts";
import type { Container } from "Types/container.ts";
const props = defineProps<{
    container: Container | null;
    containers: ContainerListItem[];
}>();
const { t } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
const crumbs: BreadcrumbItem[] = [
    { labelKey: "pages.collection.link", href: "/collection", icon: "collection" },
    { labelKey: "pages.containers.link", href: "/containers", icon: "storage" }
];
if (props.container) {
    crumbs.push({
        label: props.container.name,
        href: `/containers/${props.container.id}`,
        icon: "container-name"
    });
}
crumbs.push({ label: t("pages.container_qr.link") });
setBreadcrumbs(crumbs);
const headlineName = computed(() => props.container?.name ?? t("pages.container_qr.any"));
const selectedEntity = computed(() =>
    props.container ? { id: props.container.id, name: props.container.name } : null
);
</script>

<template>
    <Head
        ><title>{{ container?.name ?? t("pages.container_qr.any") }}</title></Head
    >
    <headline>
        <icon name="qr-code" :size="3" />
        {{ t("pages.container_qr.title", { name: headlineName }) }}
    </headline>
    <qr-code-picker
        :selected="selectedEntity"
        :options="containers"
        url-base="/containers"
        select-icon="storage"
        :select-label="t('form.fields.container.id')"
        :select-placeholder="t('pages.container_qr.select_placeholder')"
        :explanation-text="t('pages.container_qr.explanation', { name: headlineName })"
        :loading-text="t('pages.container_qr.loading')"
        :no-selection-text="t('pages.container_qr.no_selection')"
        :download-svg-label="t('pages.container_qr.download_svg')"
        :download-png-label="t('pages.container_qr.download_png')"
        filename-prefix="container"
    />
</template>
