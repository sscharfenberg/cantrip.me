<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import DeleteCardStackModal from "@/pages/Collection/common/DeleteCardStackModal.vue";
import CardImagePreview from "Components/Card/CardImagePreview.vue";
import CardStackPreviewModal from "Components/Card/CardStackPreviewModal.vue";
import CardStackClaimBadge from "Components/Collection/CardStackClaimBadge.vue";
import DataTable from "Components/DataTable/DataTable.vue";
import Icon from "Components/UI/Icon.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import { useFormatting } from "Composables/useFormatting";
import type { CollectionCardStackRow } from "Types/collectionCardStackRow";
import type { ColumnDef, TableResponse } from "Types/dataTable";
defineProps<{
    table: TableResponse<CollectionCardStackRow>;
}>();
const { t } = useI18n();
const { formatPrice, formatDateTime } = useFormatting();
const columns = computed<ColumnDef<CollectionCardStackRow>[]>(() => [
    {
        key: "name",
        label: t("form.fields.name"),
        sortable: true,
        visibleInCard: true,
        cardPrimary: true,
        cellClass: "no-padding"
    },
    {
        key: "set_name",
        label: t("form.fields.set_name"),
        sortable: true,
        visibleInCard: true
    },
    {
        key: "container_name",
        label: t("form.fields.container_name"),
        sortable: true,
        visibleInCard: true
    },
    {
        key: "language",
        label: t("form.fields.language"),
        sortable: true,
        visibleInCard: true
    },
    {
        key: "amount",
        label: t("form.fields.qty"),
        sortable: true,
        width: "5rem",
        align: "right",
        visibleInCard: true
    },
    {
        key: "condition",
        label: t("form.fields.condition"),
        sortable: true,
        visibleInCard: true
    },
    {
        key: "finish",
        label: t("form.fields.finish"),
        sortable: true
    },
    {
        key: "price",
        label: t("form.fields.price"),
        sortable: true,
        align: "right"
    },
    {
        key: "total_price",
        label: t("form.fields.total_price"),
        sortable: true,
        align: "right",
        visibleInCard: true
    },
    {
        key: "updated_at",
        label: t("form.fields.updated_at"),
        sortable: true
    },
    {
        // Phase 2.5 — "Reserved for [deck]" badge. Server ships
        // `claims: StackClaim[]` per row. Column key matches the row
        // field (`claims`) so `ColumnDef`'s keyof-T constraint is
        // satisfied. Sorting maps to a `claim_count` correlated
        // subquery server-side — DESC clusters claimed rows at the
        // top (multi-claims first), ASC clusters unclaimed at the top.
        key: "claims",
        label: t("form.fields.claimed"),
        sortable: true,
        visibleInCard: true
    }
]);
/** Resolve the flag image URL for a given language code. */
const flagSrc = (lang: string): string => new URL(`../../assets/flags/${lang}.svg`, import.meta.url).href;
/** The card stack row currently targeted for deletion, or null when the modal is hidden. */
const deleteTarget = ref<CollectionCardStackRow | null>(null);
/** Open the delete confirmation modal for the given row. */
const openDeleteModal = (row: CollectionCardStackRow) => {
    deleteTarget.value = row;
};
/**
 * Phase 2.7 — fire the collection-side unclaim endpoint. Detaches
 * every deck claim against `row` (atomic for the rare multi-claim
 * case). Server flashes a success message and redirects back here.
 * Closes the row-actions popover first; an in-place `router.delete`
 * doesn't light-dismiss it the way a modal-opening or page-navigating
 * action would.
 */
const unclaim = (row: CollectionCardStackRow, close: () => void) => {
    close();
    router.delete(`/collection/cardstack/${row.id}/claims?from=collection`, {
        preserveScroll: true
    });
};
/** The card stack ID currently shown in the preview modal, or null when hidden. */
const previewId = ref<string | null>(null);
/** helper function for created/updated at dates */
const getTimeStamps = (created: string, updated?: string | null) => {
    let timestamp = formatDateTime(created);
    if (updated && updated !== created) {
        timestamp = `${t("form.fields.created_at")}: ${timestamp}<br />${t("form.fields.updated_at")}: ${formatDateTime(updated)}`;
    }
    return timestamp;
};
</script>

<template>
    <data-table :columns="columns" :response="table" :selectable="false" base-url="/collection">
        <template #header-updated_at>
            <icon
                name="calendar"
                :size="1"
                v-tooltip="$t('form.fields.created_at') + ' / ' + $t('form.fields.updated_at')"
            />
        </template>
        <template #cell-name="{ row }">
            <card-image-preview :src="row.card_image_0" :alt="row.name" @preview="previewId = row.id">
                {{ row.name }}
            </card-image-preview>
        </template>
        <template #cell-set_name="{ row }">
            <img
                v-if="row.set_path"
                :src="row.set_path"
                :alt="row.set_code"
                class="set"
                v-tooltip="`[${row.set_code.toUpperCase()}] ${row.set_name}`"
            />
        </template>
        <template #cell-container_name="{ row }">
            <template v-if="row.container_name">
                {{ row.container_name }}
            </template>
            <em v-else>{{ $t("pages.collection.unsorted") }}</em>
        </template>
        <template #cell-condition="{ row }">
            <template v-if="row.condition">{{ $t("enums.conditions." + row.condition) }}</template>
        </template>
        <template #cell-finish="{ row }">
            <icon
                v-if="row.finish === 'foil' || row.finish === 'etched'"
                :name="row.finish === 'foil' ? 'star' : 'texture'"
                :size="2"
                v-tooltip="$t('enums.finishes.' + row.finish)"
            />
        </template>
        <template #cell-language="{ row }">
            <img
                v-if="row.language"
                :src="flagSrc(row.language)"
                :alt="t('enums.card_languages.' + row.language)"
                class="flag small"
                v-tooltip="t('enums.card_languages.' + row.language)"
            />
        </template>
        <template #cell-price="{ row }">{{ row.price ? formatPrice(row.price) : "" }}</template>
        <template #cell-total_price="{ row }">{{ row.total_price ? formatPrice(row.total_price) : "" }}</template>
        <template #cell-updated_at="{ row }">
            <icon name="calendar" :size="1" v-tooltip="`${getTimeStamps(row.created_at, row.updated_at)}`" />
        </template>
        <template #cell-claims="{ row }">
            <card-stack-claim-badge :claims="row.claims" />
        </template>
        <template #actions="{ row, close }">
            <li>
                <button
                    type="button"
                    class="popover-list-item"
                    @click="router.visit(`/collection/cardstack/${row.id}/edit`)"
                >
                    <icon name="edit" :size="1" /> {{ $t("pages.edit_card.link") }}
                </button>
            </li>
            <li v-if="row.claims.length > 0">
                <!--
                    Phase 2.7 — collection-side unclaim. Detaches every
                    deck claim against the stack in one shot (rare
                    multi-claim case included). Only renders when the
                    stack is actually claimed; matches the badge column
                    above. `?from=collection` tells the controller to
                    redirect back here on success. The popover doesn't
                    light-dismiss for an in-place `router.delete`, so
                    we explicitly `close()` first.
                -->
                <button type="button" class="popover-list-item" @click="unclaim(row, close)">
                    <icon name="delete" :size="1" />
                    {{ $t("pages.collection.claim_badge.unclaim") }}
                </button>
            </li>
            <li>
                <button
                    type="button"
                    class="popover-list-item popover-list-item--caution"
                    @click="openDeleteModal(row)"
                >
                    <icon name="delete" :size="1" />
                    {{ $t("pages.container_page.delete.link") }}
                </button>
            </li>
        </template>
        <template #empty>
            <paragraph>{{ $t("components.datatable.no_results") }}</paragraph>
        </template>
    </data-table>
    <card-stack-preview-modal v-if="previewId" :card-stack-id="previewId" @close="previewId = null" />
    <delete-card-stack-modal
        v-if="deleteTarget"
        :card-stack="deleteTarget"
        :container-name="deleteTarget.container_name ?? $t('pages.collection.unsorted')"
        @close="deleteTarget = null"
    />
</template>

<style lang="scss" scoped>
.set {
    width: 1.5rem;
    height: 1.5rem;

    vertical-align: middle;
}

.flag {
    vertical-align: middle;
}
</style>

<style lang="scss">
// doesn't work scoped.
@use "Abstracts/mixins" as m;

@include m.theme-dark(".set") {
    filter: invert(1);
}
</style>
