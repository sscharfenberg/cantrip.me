<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import DeleteCardStackModal from "@/pages/Collection/common/DeleteCardStackModal.vue";
import DeleteSelectedCardStacksModal from "@/pages/Collection/common/DeleteSelectedCardStacksModal.vue";
import MoveSelectedCardStacksModal from "@/pages/Collection/common/MoveSelectedCardStacksModal.vue";
import CardImagePreview from "Components/Card/CardImagePreview.vue";
import CardPreviewModal from "Components/Card/CardPreviewModal.vue";
import CardStackClaimBadge from "Components/Collection/CardStackClaimBadge.vue";
import DataTable from "Components/DataTable/DataTable.vue";
import Icon from "Components/UI/Icon.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import PopOver from "Components/UI/PopOver.vue";
import { useFormatting } from "Composables/useFormatting";
import type { CardStackRow } from "Types/cardStackRow";
import type { ContainerListItem } from "Types/containerListItem";
import type { ColumnDef, TableResponse } from "Types/dataTable";
const props = defineProps<{
    table: TableResponse<CardStackRow>;
    baseUrl: string;
    containerName: string;
    containers: ContainerListItem[];
    isOwner: boolean;
}>();
const { t } = useI18n();
const { formatPrice, formatDateTime } = useFormatting();
const columns = computed<ColumnDef<CardStackRow>[]>(() => [
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
        key: "collector_number",
        label: t("form.fields.collector_number"),
        sortable: true,
        width: "5rem",
        align: "right"
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
    // Phase 2.5 — "Reserved for [deck]" badge. Owner-only: a foreign
    // viewer of a public container has no business seeing which decks
    // claim the owner's stacks (the deck list isn't necessarily
    // public, and the deck-show pages would 404 for them anyway). The
    // controller stops shipping `claims` data to non-owners as well —
    // this is the UI half of that gate.
    ...(props.isOwner
        ? [
              {
                  key: "claims" as const,
                  label: t("form.fields.claimed"),
                  sortable: true,
                  visibleInCard: true
              }
          ]
        : [])
]);
/** Resolve the flag image URL for a given language code. */
const flagSrc = (lang: string): string => new URL(`../../../assets/flags/${lang}.svg`, import.meta.url).href;
/** The card stack row currently targeted for deletion, or null when the modal is hidden. */
const deleteTarget = ref<CardStackRow | null>(null);
/** Close the row action popover and open the delete confirmation modal for the given row. */
const openDeleteModal = (row: CardStackRow) => {
    deleteTarget.value = row;
};
/** Selected IDs for the move-selected modal, or null when the modal is hidden. */
const moveSelectedIds = ref<string[] | null>(null);
/** Close the mass-actions popover and open the move-selected modal. */
const openMoveModal = (selectedIds: string[]) => {
    document.getElementById("massActions")?.hidePopover();
    moveSelectedIds.value = [...selectedIds];
};
/** Selected IDs for the delete-selected modal, or null when the modal is hidden. */
const deleteSelectedIds = ref<string[] | null>(null);
/** Close the mass-actions popover and open the delete-selected modal. */
const openDeleteSelectedModal = (selectedIds: string[]) => {
    document.getElementById("massActions")?.hidePopover();
    deleteSelectedIds.value = [...selectedIds];
};
/**
 * Phase 2.7 — fire the collection-side unclaim endpoint. Detaches
 * every deck claim against `row` (atomic for the rare multi-claim
 * case). Server flashes a success message and redirects back to this
 * container. Closes the row-actions popover first; an in-place
 * `router.delete` doesn't light-dismiss it the way a modal-opening
 * or page-navigating action would.
 */
const unclaim = (row: CardStackRow, close: () => void) => {
    close();
    router.delete(`/collection/cardstack/${row.id}/claims?from=container`, {
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
    <data-table
        :columns="columns"
        :response="table"
        :selectable="isOwner"
        :has-actions="isOwner"
        :base-url="baseUrl"
    >
        <template #header-updated_at>
            <icon
                name="calendar"
                :size="1"
                v-tooltip="$t('form.fields.created_at') + ' / ' + $t('form.fields.updated_at')"
            />
        </template>
        <template #toolbar-actions="{ selectedIds }">
            <pop-over
                v-if="selectedIds.length > 0"
                icon="more"
                :aria-label="$t('header.user.label')"
                class-string="popover-button--rounded"
                reference="massActions"
                width="auto"
            >
                <ul class="popover-list popover-list--short">
                    <li>
                        <button type="button" class="popover-list-item" @click="openMoveModal(selectedIds)">
                            <icon name="move" :size="1" />
                            {{ $t("components.datatable.mass_actions.move") }}
                        </button>
                    </li>
                    <li>
                        <button type="button" class="popover-list-item" @click="openDeleteSelectedModal(selectedIds)">
                            <icon name="delete" :size="1" />
                            {{ $t("components.datatable.mass_actions.delete") }}
                        </button>
                    </li>
                </ul>
            </pop-over>
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
        <template v-if="isOwner" #actions="{ row, close }">
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
                    Phase 2.7 — collection-side unclaim, owner-only by
                    virtue of the wrapping `v-if="isOwner"`. The
                    `claims` array is itself owner-only on the
                    container payload (controller strips it for
                    non-owners), so `row.claims.length > 0` only
                    evaluates true for owners. The popover doesn't
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
    <card-preview-modal
        v-if="previewId"
        :preview-url="`/collection/cardstack/${previewId}/preview`"
        @close="previewId = null"
    />
    <template v-if="isOwner">
        <delete-card-stack-modal
            v-if="deleteTarget"
            :card-stack="deleteTarget"
            :container-name="containerName"
            @close="deleteTarget = null"
        />
        <move-selected-card-stacks-modal
            v-if="moveSelectedIds"
            :containers="containers"
            :selected-ids="moveSelectedIds"
            :container-name="containerName"
            @close="moveSelectedIds = null"
        />
        <delete-selected-card-stacks-modal
            v-if="deleteSelectedIds"
            :selected-ids="deleteSelectedIds"
            :container-name="containerName"
            @close="deleteSelectedIds = null"
        />
    </template>
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
