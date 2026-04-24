<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import { ref, useId } from "vue";
import CollectionCardStacks from "@/pages/Collection/CollectionCardStacks.vue";
import DeleteCollectionModal from "@/pages/Collection/DeleteCollectionModal.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";
import Stats from "Components/UI/Stats/Stats.vue";
import StatsItem from "Components/UI/Stats/StatsItem.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
import { useFormatting } from "Composables/useFormatting.ts";
const { formatDecimals, formatPrice } = useFormatting();
import type { CollectionCardStackRow } from "Types/collectionCardStackRow";
import type { TableResponse } from "Types/dataTable";
defineProps<{
    stats: {
        totalCards: number;
        totalStacks: number;
        totalPrice: number;
        containers: number;
        commons: number;
        uncommons: number;
        rares: number;
        mythics: number;
    };
    table: TableResponse<CollectionCardStackRow>;
    canCreateNewContainer: boolean;
}>();
/** Dismiss the container-actions popover. */
const closePopover = () => {
    const dialog = document.getElementById(refId);
    if (dialog !== null) dialog.hidePopover();
};
const refId = useId();
const showNukeModal = ref(false);
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([{ labelKey: "pages.collection.link" }]);
</script>

<template>
    <Head
        ><title>{{ $t("pages.collection.title") }}</title></Head
    >
    <headline>
        <icon name="collection" :size="3" />
        {{ $t("pages.collection.title") }}
        <template v-if="stats.totalCards > 0" #right>
            <pop-over
                icon="more"
                :aria-label="$t('pages.collection.nav.label')"
                class-string="popover-button--rounded"
                :reference="refId"
                width="14rem"
            >
                <ul class="popover-list">
                    <li>
                        <Link href="/containers" class="popover-list-item" @click="closePopover">
                            <icon name="storage" :size="1" />
                            {{ $t("pages.containers.link") }}
                        </Link>
                    </li>
                    <li>
                        <Link class="popover-list-item" href="/collection/add" @click="closePopover">
                            <icon name="add" :size="1" />
                            {{ $t("pages.add_cards.link") }}
                        </Link>
                    </li>
                    <li>
                        <a class="popover-list-item" href="/collection/export" @click="closePopover">
                            <icon name="download" :size="1" />
                            {{ $t("pages.collection.export_csv") }}
                        </a>
                    </li>
                    <li>
                        <Link href="/collection/import" class="popover-list-item" @click="closePopover">
                            <icon name="upload" :size="1" />
                            {{ $t("pages.import.link") }}
                        </Link>
                    </li>
                    <li>
                        <button
                            class="popover-list-item popover-list-item--error"
                            @click="
                                showNukeModal = true;
                                closePopover();
                            "
                        >
                            <icon name="delete" :size="1" />
                            {{ $t("pages.collection.nuke.link") }}
                        </button>
                    </li>
                </ul>
            </pop-over>
        </template>
    </headline>
    <stats v-if="stats.totalCards > 0">
        <stats-item>
            <template #title>{{ $t("pages.collection.stats.totalPrice.title") }}</template>
            <template #value>{{ formatPrice(stats.totalPrice) }}</template>
            <template #explanation>{{ $t("pages.collection.stats.totalPrice.explanation") }}</template>
        </stats-item>
        <stats-item>
            <template #title>{{ $t("pages.collection.stats.totalCards.title") }}</template>
            <template #value>{{ formatDecimals(stats.totalCards) }}</template>
            <template #explanation>{{ $t("pages.collection.stats.totalCards.explanation") }}</template>
        </stats-item>
        <stats-item>
            <template #title>{{ $t("pages.collection.stats.totalStacks.title") }}</template>
            <template #value>{{ formatDecimals(stats.totalStacks) }}</template>
            <template #explanation>{{ $t("pages.collection.stats.totalStacks.explanation") }}</template>
        </stats-item>
        <stats-item>
            <template #title>{{ $t("pages.collection.stats.containers.title") }}</template>
            <template #value>{{ formatDecimals(stats.containers) }}</template>
            <template #explanation>{{ $t("pages.collection.stats.containers.explanation") }}</template>
        </stats-item>
        <stats-item>
            <template #title>{{ $t("pages.collection.stats.commons.title") }}</template>
            <template #value>{{ formatDecimals(stats.commons) }}</template>
            <template #explanation>{{ $t("pages.collection.stats.commons.explanation") }}</template>
        </stats-item>
        <stats-item>
            <template #title>{{ $t("pages.collection.stats.uncommons.title") }}</template>
            <template #value>{{ formatDecimals(stats.uncommons) }}</template>
            <template #explanation>{{ $t("pages.collection.stats.uncommons.explanation") }}</template>
        </stats-item>
        <stats-item>
            <template #title>{{ $t("pages.collection.stats.rares.title") }}</template>
            <template #value>{{ formatDecimals(stats.rares) }}</template>
            <template #explanation>{{ $t("pages.collection.stats.rares.explanation") }}</template>
        </stats-item>
        <stats-item>
            <template #title>{{ $t("pages.collection.stats.mythics.title") }}</template>
            <template #value>{{ formatDecimals(stats.mythics) }}</template>
            <template #explanation>{{ $t("pages.collection.stats.mythics.explanation") }}</template>
        </stats-item>
    </stats>
    <nav class="links" :aria-label="$t('pages.collection.nav.label')">
        <Link v-if="stats.containers > 0" href="/containers" class="btn-primary">
            <icon name="storage" />
            {{ $t("pages.containers.link") }}
        </Link>
        <Link v-if="stats.containers === 0 && canCreateNewContainer" href="/containers/new" class="btn-default">
            <icon name="add" />
            {{ $t("pages.new_container.link") }}
        </Link>
        <Link v-if="stats.containers === 0" href="/collection/add" class="btn-default">
            <icon name="add" />
            {{ $t("pages.add_cards.link") }}
        </Link>
    </nav>
    <collection-card-stacks v-if="table.rows.length > 0" :table="table" />
    <delete-collection-modal
        v-if="showNukeModal"
        :total-cards="stats.totalCards"
        :total-price="stats.totalPrice"
        :containers="stats.containers"
        @close="showNukeModal = false"
    />
</template>

<style lang="scss" scoped>
.links {
    display: flex;
    flex-wrap: wrap;

    margin: 1rem 0;
    gap: 1rem;
}
</style>
