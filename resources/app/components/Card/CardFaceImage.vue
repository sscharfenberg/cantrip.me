<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import type { DefaultCardImage, OwnedCopies } from "Types/defaultCardImage.ts";
const props = defineProps<{
    card: DefaultCardImage;
    /** When true, shows a zoom effect on hover. Use in clickable contexts (e.g. results grid). */
    interactive?: boolean;
    /** CSS selector for the FloatingVue tooltip container. Defaults to `body`. */
    tooltipContainer?: string;
    /**
     * Foreign-language printed_name to render in the panel under cn/artist.
     * Caller-controlled: search-result grids pass this from the API's
     * `matched_translation` so users see "Ätherblitz" next to Aether Flash
     * when their query matched the DE name. Renders nothing unless both
     * this and `translatedLang` are non-empty.
     */
    translatedName?: string | null;
    /**
     * Lang code paired with `translatedName`. Must match a file in
     * `resources/app/assets/flags/<lang>.svg`.
     */
    translatedLang?: string | null;
}>();
const { t } = useI18n();
/** Resolve the flag image URL for a given language code. */
const flagSrc = (lang: string): string => new URL(`../../assets/flags/${lang}.svg`, import.meta.url).href;
/**
 * Copies of this printing in the viewer's collection, per finish, or null
 * when there is nothing worth showing. The backend leaves `owned` null for a
 * guest, while the collection-integration master switch is off, and for a
 * printing the viewer owns none of — so the badge as a whole is a positive
 * signal only.
 *
 * Inside it, one count per finish the printing exists in — a foil and a
 * nonfoil are different cards to own, and a deck planned foil needs the
 * foil — so a dual-finish printing can show "★0" next to its nonfoils, but
 * a nonfoil-only one never offers a foil count it could not have. A finish
 * owned beyond what the printing data lists still shows. Foil wears the
 * collection's `star`, etched its `texture`; nonfoil goes bare.
 */
const owned = computed<OwnedCopies | null>(() => {
    const copies = props.card.owned;
    if (!copies) return null;
    return copies.nonfoil + copies.foil + copies.etched > 0 ? copies : null;
});
const FINISHES = [
    { key: "nonfoil", icon: null },
    { key: "foil", icon: "star" },
    { key: "etched", icon: "texture" }
] as const;
/** The counts the badge shows, in finish order. */
const ownedCounts = computed(() => {
    const copies = owned.value;
    if (copies === null) return [];
    return FINISHES.filter(finish => copies[finish.key] > 0 || props.card.finishes.includes(finish.key)).map(
        finish => ({ ...finish, count: copies[finish.key] })
    );
});
/** "2× Nonfoil", one per finish owned — the tooltip's lines and the screen-reader text. */
const ownedLines = computed(() => {
    const copies = owned.value;
    if (copies === null) return [];
    return FINISHES.filter(finish => copies[finish.key] > 0).map(finish =>
        t("components.card_face_image.owned_finish", {
            count: copies[finish.key],
            finish: t(`enums.finishes.${finish.key}`)
        })
    );
});
/** HTML tooltip — FloatingVue runs with `html: true` (main.ts) — one line per finish owned. */
const ownedTooltip = computed(() => [t("components.card_face_image.owned_title"), ...ownedLines.value].join("<br />"));
/** The same for a screen reader, which gets nothing from bare digits beside icons. */
const ownedLabel = computed(() => `${t("components.card_face_image.owned_title")} ${ownedLines.value.join(", ")}`);
/** True when the back face is showing. */
const flipped = ref(false);
/** True while the flip animation is running (prevents rapid double-clicks). */
const animating = ref(false);
/**
 * Toggle between the front and back face. Guarded by `animating` so rapid
 * clicks during the CSS flip transition don't stack state changes.
 */
function onFlip() {
    if (animating.value) return;
    animating.value = true;
    flipped.value = !flipped.value;
}
</script>

<template>
    <div
        class="face-image"
        :class="{ 'face-image--interactive': interactive, 'face-image--flipped': flipped }"
        @transitionend="animating = false"
    >
        <img :src="card.card_image_0 ?? undefined" :alt="card.name" loading="lazy" class="face-image__front" />
        <img
            v-if="card.card_image_1"
            :src="card.card_image_1"
            :alt="card.name"
            loading="lazy"
            class="face-image__back"
        />
        <button type="button" class="face-image__flip" v-if="card.card_image_1" @click.stop="onFlip">
            <icon name="flip" />
        </button>
        <span v-if="translatedName && translatedLang" class="face-image__translation">
            <img :src="flagSrc(translatedLang)" :alt="translatedLang.toUpperCase()" class="face-image__flag" />
            {{ translatedName }}
        </span>
        <div class="face-image__panel">
            <span class="face-image__panel-line">
                #{{ card.cn }}
                <img
                    v-if="card.set.path"
                    :src="card.set.path"
                    class="face-image__set"
                    :alt="`${card.set.code.toUpperCase()} - ${card.set.name}`"
                    :title="`${card.set.code.toUpperCase()} - ${card.set.name}`"
                    v-tooltip="{
                        content: `${card.set.code.toUpperCase()} - ${card.set.name}`,
                        container: tooltipContainer ?? 'body'
                    }"
                />
            </span>
            <span v-if="card.artist || owned !== null" class="face-image__panel-line">
                <span v-if="card.artist" class="face-image__panel-artist">
                    <icon name="brush" :size="0" />
                    {{ card.artist }}
                </span>
                <span
                    v-if="owned !== null"
                    class="face-image__panel-owned"
                    v-tooltip="{
                        content: ownedTooltip,
                        container: tooltipContainer ?? 'body'
                    }"
                >
                    <icon name="storage" :size="0" />
                    <span
                        v-for="entry in ownedCounts"
                        :key="entry.key"
                        class="face-image__panel-count"
                        :class="`face-image__panel-count--${entry.key}`"
                        aria-hidden="true"
                    >
                        <icon v-if="entry.icon" :name="entry.icon" :size="0" />{{ entry.count }}
                    </span>
                    <span class="sr-only">{{ ownedLabel }}</span>
                </span>
            </span>
        </div>
    </div>
</template>

<style lang="scss">
// doesn't work scoped.
@use "Abstracts/mixins" as m;

@include m.theme-dark(".face-image__set") {
    filter: none;
}
</style>
