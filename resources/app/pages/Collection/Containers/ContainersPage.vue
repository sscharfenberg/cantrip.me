<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import { toRef } from "vue";
import { useI18n } from "vue-i18n";
import ContainersResultList from "@/pages/Collection/Containers/ContainersResultList.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import LinkGroup from "Components/UI/LinkGroup.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
import { useContainer } from "Composables/useContainer";
import type { Container } from "Types/container";
const props = defineProps<{
    containers: Container[];
    containerTypes: string[];
    containersMax: number;
    containersAmount: number;
    canCreateNewContainer: boolean;
}>();
const { t } = useI18n();
const { isSaving, handleReorder, activeTypes, search, usedTypes, filteredContainers, toggleType, typeLabel } =
    useContainer(toRef(props, "containers"), props.containerTypes);
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([
    { labelKey: "pages.collection.link", href: "/collection", icon: "collection" },
    { labelKey: "pages.containers.link", href: "/containers" }
]);
</script>

<template>
    <Head
        ><title>{{ $t("pages.containers.title") }}</title></Head
    >
    <headline>
        <icon name="storage" :size="3" />
        {{ $t("pages.containers.title") }}
    </headline>
    <paragraph> {{ $t("pages.containers.explanation") }}<br /> </paragraph>
    <ul class="meta">
        <li class="meta__showing" v-if="containersAmount && filteredContainers.length">
            {{ t("pages.containers.showing", { filtered: filteredContainers.length, total: containersAmount }) }}
        </li>
        <li class="meta__search" v-if="containersAmount">
            <label for="container-search" class="sr-only">{{ $t("pages.containers.search") }}</label>
            <input
                id="container-search"
                type="text"
                :placeholder="$t('pages.containers.search')"
                class="form-input"
                @input="search = ($event.target as HTMLInputElement).value"
            />
        </li>
        <li class="meta__types" v-if="usedTypes.length > 1">
            <ul class="type-filter" role="group" :aria-label="$t('pages.containers.filter_by_type')">
                <li v-for="type in usedTypes" :key="type" class="type-filter__item">
                    <input
                        :id="`type-filter-${type}`"
                        type="checkbox"
                        class="type-filter__checkbox sr-only"
                        :checked="activeTypes.has(type)"
                        @change="toggleType(type)"
                    />
                    <label :for="`type-filter-${type}`" class="type-filter__label">
                        {{ typeLabel(type) }}
                    </label>
                </li>
            </ul>
        </li>
        <li>
            <link-group :label="$t('pages.containers.nav.label')">
                <Link v-if="canCreateNewContainer" href="/containers/new" class="btn-default">
                    <icon name="add" />
                    {{ $t("pages.new_container.link") }}
                </Link>
                <Link v-if="containersAmount > 0" class="btn-default" href="/containers/qr">
                    <icon name="qr-code" />
                    {{ $t("pages.container_qr.link") }}
                </Link>
                <Link v-if="containersAmount > 0" class="btn-default" href="/containers/qr-sheet">
                    <icon name="qr-code" />
                    {{ $t("pages.container_qr_sheet.link") }}
                </Link>
            </link-group>
        </li>
        <li v-if="isSaving" class="meta__saving" aria-live="polite">
            <loading-spinner :size="1.5" />
            {{ $t("pages.containers.sort_saving") }}
        </li>
    </ul>
    <ContainersResultList v-if="filteredContainers.length" :containers="filteredContainers" @reorder="handleReorder" />
    <paragraph v-else>{{ $t("pages.containers.none") }}</paragraph>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;

    padding: 0;
    margin: 0;
    gap: 1ch;

    list-style: none;

    &__saving {
        display: flex;
        align-items: center;
        opacity: 0.7;

        gap: 1ch;

        font-size: 0.875em;
    }
}

.type-filter {
    display: flex;
    flex-wrap: wrap;

    padding: map.get(s.$pages, "containers", "types-padding");
    border: map.get(s.$pages, "containers", "types-border") solid map.get(c.$pages, "containers", "types-border");
    margin: 0;
    gap: 1ch;

    background-color: map.get(c.$pages, "containers", "types-background");
    list-style: none;
    border-radius: map.get(s.$pages, "containers", "types-radius");

    &__label {
        display: block;

        padding: 0.5ex 1ch;

        background-color: map.get(c.$pages, "containers", "type-background");
        border-radius: map.get(s.$pages, "containers", "types-radius");

        cursor: pointer;
    }

    &__item:has(&__checkbox:checked) &__label {
        background-color: map.get(c.$pages, "containers", "type-background-active");
        color: map.get(c.$pages, "containers", "type-surface-active");
    }
}
</style>
