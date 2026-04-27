<script setup lang="ts">
/**
 * Renders a Scryfall mana cost string as a row of mana symbol icons.
 *
 * Accepts either a single cost string like `{2}{W}{U}` or an array of
 * per-face costs (e.g. `["{1}{R}", "{1}{U}"]` for split cards like
 * "Fire // Ice"). Multiple faces are joined with a `/` separator.
 *
 * Each token is mapped to the SVG path `/symbol/<token>.svg` (with `/`
 * replaced by `-`) and rendered as an inline image.
 */
import { computed } from "vue";

const props = defineProps<{
    /**
     * Scryfall mana cost. Either a single cost string (e.g. "{2}{W}{U}") or
     * an array of per-face costs for multi-face cards. Null/empty renders nothing.
     */
    manaCost: string | null | (string | null)[];
}>();

/** Regex to extract individual mana symbols from a cost string. */
const SYMBOL_REGEX = /\{([^}]+)}/g;

/**
 * Derive the SVG path from a symbol token.
 * Replaces `/` with `-` to match the filename convention (e.g. "B/G" → "B-G").
 */
const toPath = (token: string): string => `/symbol/${token.replace(/\//g, "-")}.svg`;

/** Parsed faces — each face is a list of symbols. Empty faces are dropped. */
const faces = computed(() => {
    const costs = Array.isArray(props.manaCost) ? props.manaCost : [props.manaCost];
    return costs
        .map(cost => {
            if (!cost) return [];
            return [...cost.matchAll(SYMBOL_REGEX)].map(m => ({ token: m[1], path: toPath(m[1]) }));
        })
        .filter(face => face.length > 0);
});
</script>

<template>
    <span v-if="faces.length" class="mana-cost">
        <template v-for="(face, fi) in faces" :key="fi">
            <span v-if="fi > 0" class="mana-cost__separator">//</span>
            <img
                v-for="(sym, i) in face"
                :key="`${fi}-${i}`"
                :src="sym.path"
                :alt="`{${sym.token}}`"
                class="mana-cost__symbol"
            />
        </template>
    </span>
</template>

<style scoped lang="scss">
.mana-cost {
    display: inline-flex;
    align-items: center;

    gap: 0.125rem;

    vertical-align: -0.125em;

    &__symbol {
        width: 1rem;
        height: 1rem;
    }

    &__separator {
        align-self: center;
    }
}
</style>
