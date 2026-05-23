<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import { ref, useId } from "vue";
import CollectionCardStacks from "@/pages/Collection/CollectionCardStacks.vue";
import CollectionPageStats from "@/pages/Collection/CollectionPageStats.vue";
import DeleteCollectionModal from "@/pages/Collection/DeleteCollectionModal.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
import type { CollectionCardStackRow } from "Types/collectionCardStackRow";
import type { TableResponse } from "Types/dataTable";

defineProps<{
    stats: {
        totalCards: number;
        uniqueCards: number;
        containers: number;
        totalPrice: number;
        containerTypes: Record<string, number>;
        rarities: Record<"common" | "uncommon" | "rare" | "mythic", number>;
        topSets: Array<{ code: string; name: string; count: number }>;
        mostValuableCard: {
            name: string;
            price: number;
            printingsOwned: number;
        } | null;
        mostOwnedCard: {
            name: string;
            owned: number;
            printingsOwned: number;
        } | null;
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
                    <li v-if="canCreateNewContainer">
                        <Link href="/containers/new" class="popover-list-item" @click="closePopover">
                            <icon name="add" :size="1" />
                            {{ $t("pages.new_container.link") }}
                        </Link>
                    </li>
                    <li>
                        <Link href="/containers/qr-sheet" class="popover-list-item" @click="closePopover">
                            <icon name="qr-code" :size="1" />
                            {{ $t("pages.container_qr_sheet.link") }}
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
    <collection-page-stats v-if="stats.totalCards > 0" :stats="stats" />
    <nav class="links" :aria-label="$t('pages.collection.nav.label')">
        <Link v-if="stats.containers > 0" href="/containers" class="btn-primary">
            <icon name="storage" />
            {{ $t("pages.containers.link") }}
        </Link>
        <Link v-if="canCreateNewContainer" href="/containers/new" class="btn-default">
            <icon name="add" />
            {{ $t("pages.new_container.link") }}
        </Link>
        <Link href="/collection/add" class="btn-default">
            <icon name="add" />
            {{ $t("pages.add_cards.link") }}
        </Link>
    </nav>
    <collection-card-stacks v-if="stats.totalCards > 0" :table="table" />
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
