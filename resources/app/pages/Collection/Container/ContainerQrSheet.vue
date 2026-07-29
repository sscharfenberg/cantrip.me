<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import Checkbox from "Components/Form/Checkbox.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import LinkGroup from "Components/UI/LinkGroup.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import VisibilityBadge from "Components/UI/VisibilityBadge.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
import { useFormatting } from "Composables/useFormatting";
import type { Container } from "Types/container";
const props = defineProps<{ containers: Container[] }>();
const { t } = useI18n();
const { formatPrice, formatDecimals } = useFormatting();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([
    { labelKey: "pages.collection.link", href: "/collection", icon: "collection" },
    { labelKey: "pages.containers.link", href: "/containers", icon: "storage" },
    { labelKey: "pages.container_qr_sheet.link" }
]);
/**
 * Selected container ids. Defaults to every binder the user owns — the
 * most common print case — and falls back to all containers if they
 * don't have any binders.
 */
const initialSelection = (): string[] => {
    const binders = props.containers.filter(c => c.type === "binder").map(c => c.id);
    return binders.length > 0 ? binders : props.containers.map(c => c.id);
};
const selectedIds = ref<Set<string>>(new Set(initialSelection()));
const isSelected = (id: string): boolean => selectedIds.value.has(id);
function toggle(id: string): void {
    if (selectedIds.value.has(id)) {
        selectedIds.value.delete(id);
    } else {
        selectedIds.value.add(id);
    }
    // Force reactivity — Set mutation alone doesn't trigger refs.
    selectedIds.value = new Set(selectedIds.value);
}
function selectAll(): void {
    selectedIds.value = new Set(props.containers.map(c => c.id));
}
function selectNone(): void {
    selectedIds.value = new Set();
}
const selectedCount = computed(() => selectedIds.value.size);
const canSubmit = computed(() => selectedCount.value > 0);
const pageCount = computed(() => Math.ceil(selectedCount.value / 9));
/**
 * Trigger the PDF download by navigating to the streaming endpoint.
 * The server returns Content-Disposition: attachment so the browser
 * starts a download in place instead of replacing the current page.
 */
function generateSheet(): void {
    if (!canSubmit.value) return;
    const ids = [...selectedIds.value].join(",");
    window.location.href = `/containers/qr-sheet/pdf?ids=${encodeURIComponent(ids)}`;
}
/** Human-readable type label, honouring custom types ("Other → free text"). */
function typeLabelFor(c: Container): string {
    if (c.type === "other" && c.custom_type) return c.custom_type;
    return t("enums.container_type." + c.type);
}
</script>

<template>
    <Head
        ><title>{{ t("pages.container_qr_sheet.title") }}</title></Head
    >
    <headline>
        <icon name="qr-code" :size="3" />
        {{ t("pages.container_qr_sheet.title") }}
    </headline>
    <paragraph>{{ t("pages.container_qr_sheet.explanation") }}</paragraph>

    <div v-if="containers.length === 0" class="qrsheet__empty">
        <paragraph>{{ t("pages.container_qr_sheet.none") }}</paragraph>
        <Link href="/containers/new" class="btn-default">
            <icon name="add" />
            {{ t("pages.new_container.link") }}
        </Link>
    </div>

    <template v-else>
        <ul class="qrsheet__meta">
            <li class="qrsheet__count" aria-live="polite">
                {{
                    t("pages.container_qr_sheet.selected", {
                        count: selectedCount,
                        total: containers.length,
                        pages: pageCount
                    })
                }}
            </li>
            <li>
                <link-group :label="t('pages.container_qr_sheet.nav.label')">
                    <button type="button" class="btn-default" @click="selectAll">
                        <icon name="check" />
                        {{ t("pages.container_qr_sheet.select_all") }}
                    </button>
                    <button type="button" class="btn-default" @click="selectNone">
                        <icon name="close" />
                        {{ t("pages.container_qr_sheet.select_none") }}
                    </button>
                </link-group>
            </li>
        </ul>

        <ul class="clist clist--selectable">
            <li v-for="container in containers" :key="container.id" class="clist__item" @click="toggle(container.id)">
                <!-- Col 1 is a checkbox here (drag handle in the
                     sortable variant). @click.stop on the cell so the
                     Checkbox's own change event drives the toggle
                     without the row's @click double-firing. -->
                <span class="clist__check" @click.stop>
                    <checkbox
                        :ref-id="`qrsheet-${container.id}`"
                        :checked-initially="isSelected(container.id)"
                        :label="t('pages.container_qr_sheet.toggle', { name: container.name })"
                        @change="toggle(container.id)"
                    />
                </span>
                <!-- --no-menu: data subgrid extends to the last column
                     (no trailing ContainerMenu cell to leave room for). -->
                <div class="clist__data clist__data--no-menu">
                    <img
                        v-if="container.defaultCard"
                        :src="container.defaultCard.art_crop"
                        class="clist__image"
                        :alt="container.name"
                    />
                    <span v-else class="clist__image" />
                    <span class="clist__name">
                        {{ container.name }}
                        <span v-if="container.description" class="clist__description">{{ container.description }}</span>
                    </span>
                    <span class="clist__type">
                        <icon name="storage" />
                        {{ typeLabelFor(container) }}
                    </span>
                    <span class="clist__count">
                        <icon name="deck" />
                        {{ formatDecimals(container.totalCards) }}
                    </span>
                    <span class="clist__price">
                        <icon name="money" />
                        {{ formatPrice(container.totalPrice) }}
                    </span>
                    <visibility-badge class="clist__visibility" :visibility="container.visibility" />
                </div>
            </li>
        </ul>

        <div class="qrsheet__submit">
            <button type="button" class="btn-primary" :disabled="!canSubmit" @click="generateSheet">
                <icon name="download" />
                {{ t("pages.container_qr_sheet.submit") }}
            </button>
        </div>
    </template>
</template>

<style scoped lang="scss">
/**
 * Container-row grid (.clist) lives in
 * @/styles/components/_container-list.scss — shared with
 * ContainersResultList.vue. This block only carries the page-local
 * chrome around the list: meta bar, submit button, empty state.
 */
.qrsheet {
    &__meta {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;

        padding: 0;
        margin: 1lh 0 0;
        gap: 1ch;

        list-style: none;
    }

    &__submit {
        margin-top: 1lh;

        text-align: right;
    }

    &__empty {
        margin-top: 1lh;
    }
}
</style>
