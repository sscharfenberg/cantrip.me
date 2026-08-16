<script setup lang="ts">
import { useI18n } from "vue-i18n";
import ColorIdentity from "Components/Card/ColorIdentity.vue";
import ManaCost from "Components/Card/ManaCost.vue";
import Icon from "Components/UI/Icon.vue";
const { t } = useI18n();
/** Shape of a single face in a commander search result. */
export type CommanderFace = {
    type_line: string;
    mana_cost: string | null;
};
/** Shape of a single commander search result from `/api/commander`. */
export type CommanderResult = {
    id: string;
    name: string;
    color_identity: string | null;
    companion_type:
        "partner" | "partner_with" | "partner_type" | "friends_forever" | "doctors_companion" | "background" | null;
    partner_with_name: string | null;
    faces: CommanderFace[];
    /**
     * Why this card matched when its English name does not contain the query —
     * e.g. searching "Toba" surfaces Mana Flare because the Japanese
     * ほとばしる魔力 transliterates to `hotobashiru mo li`. Null when the
     * English name explains the match, which is almost always.
     */
    matched_translation?: { lang: string; name: string } | null;
};
defineProps<{
    card: CommanderResult;
    /** CSS selector for the FloatingVue tooltip container. Defaults to `body`. */
    tooltipContainer?: string;
}>();
/** Resolve the flag image for a language code, as {@see CardFaceImage} does. */
const flagSrc = (lang: string): string => new URL(`../../assets/flags/${lang}.svg`, import.meta.url).href;
/** Color letter → i18n key mapping in WUBRG order. */
const COLOR_NAMES: Record<string, string> = {
    W: "enums.colors.W",
    U: "enums.colors.U",
    B: "enums.colors.B",
    R: "enums.colors.R",
    G: "enums.colors.G"
};
/**
 * Build a tooltip string like "Color Identity: white, blue and green".
 *
 * @param ci - Color identity string, e.g. "WUG", or null for colorless.
 */
const ciTooltip = (ci: string | null): string => {
    const prefix = t("components.commander_picker.color_identity");
    if (!ci) return `${prefix}: ${t("enums.colors.C")}`;
    const names = ["W", "U", "B", "R", "G"].filter(c => ci.includes(c)).map(c => t(COLOR_NAMES[c]));
    if (names.length <= 1) return `${prefix}: ${names[0] ?? t("enums.colors.C")}`;
    const last = names.pop()!;
    return `${prefix}: ${names.join(", ")} ${t("combinations.and")} ${last}`;
};
</script>

<template>
    <span class="commander-picker__name">{{ card.name }}</span>
    <span v-if="card.matched_translation" class="commander-picker__translation" @click.stop>
        <img
            :src="flagSrc(card.matched_translation.lang)"
            :alt="card.matched_translation.lang.toUpperCase()"
            class="commander-picker__flag"
        />
        {{ card.matched_translation.name }}
    </span>
    <span
        class="commander-picker__ci"
        v-tooltip="{ content: ciTooltip(card.color_identity), container: tooltipContainer ?? false }"
        @click.stop
    >
        <color-identity :color-identity="card.color_identity" />
    </span>
    <span class="commander-picker__faces">
        <span class="commander-picker__type" v-for="(face, i) in card.faces" :key="i">
            <span v-if="i > 0"> // </span>
            {{ face.type_line }} <mana-cost :mana-cost="face.mana_cost" />
        </span>
    </span>
    <span
        class="commander-picker__partner"
        v-if="card.companion_type"
        @click.stop
        v-tooltip="{
            content:
                card.companion_type === 'partner_with'
                    ? $t('components.commander_picker.partner_with_tooltip', { name: card.partner_with_name })
                    : card.companion_type === 'partner_type'
                      ? $t('components.commander_picker.partner_type_tooltip', { type: card.partner_with_name })
                      : $t(`components.commander_picker.${card.companion_type}_tooltip`),
            container: tooltipContainer ?? false
        }"
    >
        <icon :name="card.companion_type === 'background' ? 'background' : 'partner'" />
    </span>
</template>
