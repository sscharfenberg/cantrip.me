<script setup lang="ts">
/******************************************************************************
 * Banned & restricted lists, one tab per format — the formats with the most
 * decks on cantrip.me first, then the ones without decks alphabetically.
 * The open tab lives in the URL hash (`/b+r#legacy`); without one, the
 * first tab opens.
 *
 * The tabs follow the WAI-ARIA tabs pattern: arrow keys, Home and End move
 * between them and open the one they land on, and only the open tab is in
 * the Tab order. Only the open tab's panel is rendered — every format at
 * once would be well over a thousand rows.
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { computed, nextTick, ref } from "vue";
import { useI18n } from "vue-i18n";
import BannedRestrictedList from "@/pages/Guest/BannedRestrictedList.vue";
import CardPreviewModal from "Components/Card/CardPreviewModal.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
import { useHashTabs } from "Composables/useHashTabs.ts";
import type { BannedRestrictedCard, BannedRestrictedCards, BannedRestrictedFormat } from "Types/bannedRestricted.ts";
const props = defineProps<{
    /** Every format, each with its deck count. */
    formats: BannedRestrictedFormat[];
    /** The cards the lists refer to, by oracle id. */
    cards: Record<string, BannedRestrictedCard>;
}>();
const { t } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([{ labelKey: "pages.banned_restricted.link" }]);
const formatName = (format: string): string => t(`enums.card_formats.${format}`);
/**
 * Tab order: most decks first; formats with as many decks — every format
 * without one, mostly — alphabetically by the name the tab shows, so the
 * order follows the locale.
 */
const sortedFormats = computed(() =>
    [...props.formats].sort((a, b) => b.decks - a.decks || formatName(a.format).localeCompare(formatName(b.format)))
);
const formatIds = computed(() => sortedFormats.value.map(format => format.format));
const { active, select } = useHashTabs(formatIds);
const current = computed(() => props.formats.find(format => format.format === active.value) ?? null);
const hasCards = (list: BannedRestrictedCards): boolean => list.groups.length > 0 || list.categories.length > 0;
const tabId = (format: string): string => `banned-restricted-tab-${format}`;
/**
 * Keyboard navigation between tabs: the arrow keys wrap around, Home and End
 * jump to the ends. Modified keys are left to the browser — Alt+ArrowLeft is
 * Back on Windows and Linux.
 */
async function onTabKeydown(event: KeyboardEvent): Promise<void> {
    if (event.altKey || event.ctrlKey || event.metaKey) return;
    const ids = formatIds.value;
    const index = ids.indexOf(active.value);
    const target = {
        ArrowRight: ids[(index + 1) % ids.length],
        ArrowLeft: ids[(index - 1 + ids.length) % ids.length],
        Home: ids[0],
        End: ids[ids.length - 1]
    }[event.key];
    if (target === undefined) return;
    event.preventDefault();
    select(target);
    await nextTick();
    document.getElementById(tabId(target))?.focus();
}
/** Preview-modal endpoint, or null while the modal is closed. */
const previewUrl = ref<string | null>(null);
const openPreview = (printingId: string): void => {
    previewUrl.value = `/cards/${printingId}/preview`;
};
</script>

<template>
    <Head
        ><title>{{ t("pages.banned_restricted.title") }}</title></Head
    >
    <headline>
        <icon name="flag" :size="3" />
        {{ t("pages.banned_restricted.title") }}
    </headline>
    <paragraph>{{ t("pages.banned_restricted.intro") }}</paragraph>
    <div class="banned-restricted-tabs" role="tablist" :aria-label="t('pages.banned_restricted.tabs')">
        <button
            v-for="format in sortedFormats"
            :id="tabId(format.format)"
            :key="format.format"
            type="button"
            role="tab"
            class="banned-restricted-tabs__tab"
            :class="{ active: format.format === active }"
            :aria-selected="format.format === active"
            aria-controls="banned-restricted-panel"
            :tabindex="format.format === active ? 0 : -1"
            @click="select(format.format)"
            @keydown="onTabKeydown"
        >
            {{ formatName(format.format) }}
        </button>
    </div>
    <div
        v-if="current"
        id="banned-restricted-panel"
        role="tabpanel"
        :aria-labelledby="tabId(current.format)"
        tabindex="0"
        class="banned-restricted-panel"
    >
        <template v-if="hasCards(current.banned) || hasCards(current.restricted)">
            <banned-restricted-list
                v-if="hasCards(current.banned)"
                :key="`${current.format}-banned`"
                legality="banned"
                :list="current.banned"
                :cards="cards"
                @preview="openPreview"
            />
            <banned-restricted-list
                v-if="hasCards(current.restricted)"
                :key="`${current.format}-restricted`"
                legality="restricted"
                :list="current.restricted"
                :cards="cards"
                @preview="openPreview"
            />
        </template>
        <paragraph v-else>{{ t("pages.banned_restricted.empty", { format: formatName(current.format) }) }}</paragraph>
    </div>
    <card-preview-modal v-if="previewUrl" :preview-url="previewUrl" @close="previewUrl = null" />
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.banned-restricted-tabs {
    display: flex;
    flex-wrap: wrap;

    margin-bottom: map.get(s.$pages, "banned-restricted", "tabs", "margin");
    gap: map.get(s.$pages, "banned-restricted", "tabs", "gap");

    &__tab {
        padding: map.get(s.$pages, "banned-restricted", "tab", "padding");
        border: 0;

        background-color: map.get(c.$pages, "banned-restricted", "tab", "background");
        color: map.get(c.$pages, "banned-restricted", "tab", "surface");
        border-radius: map.get(s.$pages, "banned-restricted", "tab", "radius");

        font: inherit;

        cursor: pointer;

        transition:
            background-color map.get(ti.$timings, "fast") linear,
            color map.get(ti.$timings, "fast") linear;

        &:hover {
            background-color: map.get(c.$pages, "banned-restricted", "tab", "background-hover");
            color: map.get(c.$pages, "banned-restricted", "tab", "surface-hover");
        }

        &.active {
            background-color: map.get(c.$pages, "banned-restricted", "tab", "background-active");
            color: map.get(c.$pages, "banned-restricted", "tab", "surface-active");
        }

        &:focus-visible {
            outline: 2px solid currentcolor;
            outline-offset: 2px;
        }
    }
}

.banned-restricted-panel:focus-visible {
    outline: 2px solid currentcolor;
    outline-offset: 4px;
}
</style>
