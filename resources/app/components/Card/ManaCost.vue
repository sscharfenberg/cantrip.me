<script setup lang="ts">
/**
 * Renders a Scryfall mana cost string as a row of mana symbol icons.
 *
 * Parses tokens like `{2}{W}{U}` or `{B/G}` from the mana cost string,
 * derives the SVG path for each token using the naming convention
 * `/symbol/<token>.svg` (with `/` replaced by `-`), and renders them
 * as inline images.
 */
import { computed } from "vue";

const props = defineProps<{
    /** Scryfall mana cost string, e.g. "{2}{W}{U}" or "{B/G}{R}". Null renders nothing. */
    manaCost: string | null;
}>();

/** Regex to extract individual mana symbols from a cost string. */
const SYMBOL_REGEX = /\{([^}]+)}/g;

/**
 * Derive the SVG path from a symbol token.
 * Replaces `/` with `-` to match the filename convention (e.g. "B/G" → "B-G").
 */
const toPath = (token: string): string => `/symbol/${token.replace(/\//g, "-")}.svg`;

/** Parsed list of { token, path } pairs to render. */
const symbols = computed(() => {
    if (!props.manaCost) return [];
    const result: { token: string; path: string }[] = [];
    let match: RegExpExecArray | null;
    while ((match = SYMBOL_REGEX.exec(props.manaCost)) !== null) {
        result.push({ token: match[1], path: toPath(match[1]) });
    }
    return result;
});
</script>

<template>
    <span v-if="symbols.length" class="mana-cost">
        <img v-for="(sym, i) in symbols" :key="i" :src="sym.path" :alt="`{${sym.token}}`" class="mana-cost__symbol" />
    </span>
</template>

<style scoped lang="scss">
.mana-cost {
    display: inline-flex;

    gap: 0.125rem;

    vertical-align: -0.125em;

    &__symbol {
        width: 1rem;
        height: 1rem;
    }
}
</style>
