<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import ContainerMenu from "@/pages/Collection/common/ContainerMenu.vue";
import ContainerCardStacks from "@/pages/Collection/Container/ContainerCardStacks.vue";
import ArtCropImage from "Components/Card/ArtCropImage.vue";
import Badge from "Components/UI/Badge.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import { useFormatting } from "Composables/useFormatting";
import type { CardStackRow } from "Types/cardStackRow";
import type { Container } from "Types/container";
import type { ContainerListItem } from "Types/containerListItem";
import type { TableResponse } from "Types/dataTable";

const props = defineProps<{
    container: Container;
    table: TableResponse<CardStackRow>;
    isOwner: boolean;
    /** Only present when isOwner is true. */
    containers?: ContainerListItem[];
}>();

const { t } = useI18n();
const { formatPrice, formatDecimals } = useFormatting();
const { setBreadcrumbs } = useBreadcrumbs();

const cardsCountLabel = computed(() => {
    const count = props.container.totalCards;
    return t("pages.container_page.cards_count", { count: formatDecimals(count) }, count);
});
setBreadcrumbs(
    props.isOwner
        ? [
              { labelKey: "pages.collection.link", href: "/collection", icon: "collection" },
              { labelKey: "pages.containers.link", href: "/containers", icon: "storage" },
              { label: props.container.name }
          ]
        : [{ label: props.container.name }]
);
</script>

<template>
    <Head
        ><title>{{ container.name }}</title></Head
    >
    <headline>
        <icon name="container-name" :size="3" />
        {{ container.name }}
        <badge type="info" class="type">
            <icon name="storage" />
            {{
                container.type === "other" ? container.custom_type : $t("enums.container_type." + container.type)
            }}</badge
        >
        <badge v-if="isOwner" :type="container.visibility === 'private' ? 'success' : 'error'" class="visibility">
            <icon :name="container.visibility === 'private' ? 'visibility-off' : 'visibility-on'" />
            {{ $t("enums.visibility." + container.visibility) }}
        </badge>
    </headline>
    <ul class="container-meta">
        <li class="container-meta__name">
            {{ container.name }}
            <ContainerMenu v-if="isOwner && containers" :container="container" :containers="containers" />
        </li>
        <li v-if="container.description">{{ container.description }}</li>
        <li v-if="container.defaultCard"><art-crop-image :card="container.defaultCard" /></li>
        <li>
            <icon name="storage" />{{
                container.type === "other" ? container.custom_type : $t("enums.container_type." + container.type)
            }}
        </li>
        <li><icon name="deck" />{{ cardsCountLabel }}</li>
        <li><icon name="money" />{{ formatPrice(container.totalPrice) }}</li>
        <li v-if="isOwner">
            <Link class="btn-default" :href="`/containers/${container.id}/add`">
                <icon name="add" :size="1" />
                {{ $t("pages.add_cards.link") }}
            </Link>
        </li>
    </ul>
    <container-card-stacks
        v-if="container.totalCards > 0"
        :table="table"
        :base-url="`/containers/${container.id}`"
        :container-name="container.name"
        :containers="containers ?? []"
        :is-owner="isOwner"
    />
    <paragraph v-else>{{ $t("pages.container_page.empty") }}</paragraph>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/shadows" as sh;
@use "Abstracts/sizes" as s;
@use "Abstracts/mixins" as m;
@use "Abstracts/z-indexes" as z;

.container-meta {
    display: flex;
    position: relative;
    flex-direction: column;

    padding: map.get(s.$pages, "container", "meta", "padding");
    margin: 0 0 1rem;
    gap: 1ch;

    background-color: map.get(c.$pages, "container", "meta", "background");
    color: map.get(c.$pages, "container", "meta", "surface");
    list-style: none;
    border-radius: map.get(s.$pages, "container", "meta", "radius");

    &::before {
        position: absolute;
        inset: 0;
        z-index: map.get(z.$index, "background");

        border: map.get(s.$pages, "container", "meta", "border") solid transparent;

        background: linear-gradient(
                to bottom right,
                map.get(c.$pages, "container", "meta", "border-from"),
                map.get(c.$pages, "container", "meta", "border-to")
            )
            border-box;

        border-radius: inherit;
        mask:
            linear-gradient(black, black) border-box,
            linear-gradient(black, black) padding-box;
        mask-composite: subtract;

        content: "";
    }

    li:not(:has(.art-crop)) {
        display: flex;
        align-items: center;
        grid-column: 1;

        gap: 1ch;
    }

    @include m.mq("desktop") {
        &:has(.art-crop) {
            display: grid;
            grid-template-columns: 1fr minmax(auto, map.get(s.$components, "art-crop", "card-image-max"));

            li:has(.art-crop) {
                display: flex;
                justify-content: flex-end;
                grid-column: 2;
                grid-row: 1 / span 10;
            }
        }
    }

    .art-crop {
        z-index: map.get(z.$index, "default");
    }

    &__name {
        display: flex;
        align-items: center;
        justify-content: space-between;

        gap: 1rem;

        color: map.get(c.$pages, "container", "meta", "surface-name");

        font-size: 2rem;
        font-weight: 300;
        text-shadow: map.get(sh.$main, "container-name");
    }
}

.type {
    display: none;

    @include m.mq("portrait") {
        display: block;

        margin-left: auto;
    }
}

.visibility {
    margin-left: auto;

    @include m.mq("portrait") {
        margin-left: 0;
    }
}
</style>
