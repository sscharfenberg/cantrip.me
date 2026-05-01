<script setup lang="ts">
/******************************************************************************
 * Per-stack "Reserved for [deck]" badge surfaced collection-side
 * (Phase 2.5).
 *
 * Single-claim case: renders one small `<Link>` to the deck show page,
 * labelled "Reserved for [deckName]".
 *
 * Multi-claim case (rare — schema allows it, UX assumes one): the
 * primary `<Link>` points at the first deck and the label reads
 * "Reserved for [deckA] +N more". The full list is surfaced via tooltip.
 *
 * Renders nothing (`v-if`) when `claims` is empty — call sites can
 * mount the component unconditionally and let it self-hide.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import LabelledLink from "Components/UI/LabelledLink.vue";
import type { StackClaim } from "Types/cardStackRow";
const props = defineProps<{
    /** Decks claiming this stack — see {@link StackClaim}. */
    claims: StackClaim[];
}>();
const { t } = useI18n();
/** First claim — drives the link target and primary label. */
const primary = computed(() => props.claims[0] ?? null);
/** Number of additional claims beyond the first; gates the multi-label render. */
const extraCount = computed(() => Math.max(0, props.claims.length - 1));
/**
 * Tooltip content for the multi-claim case — comma-joined names of
 * every additional deck. Only used when there's more than one claim.
 */
const tooltipMulti = computed(() =>
    t("pages.collection.claim_badge.tooltip_multi", {
        decks: props.claims
            .slice(1)
            .map(c => c.deck_name)
            .join(", ")
    })
);
</script>

<template>
    <span v-if="primary" class="claim-badge">
        <labelled-link
            v-if="extraCount === 0"
            :href="`/decks/${primary.deck_id}`"
            class="claim-badge__link"
            v-tooltip="$t('pages.collection.claim_badge.label', { deck: primary.deck_name })"
        >
            <icon name="deck" />
            {{ primary.deck_name }}
        </labelled-link>
        <labelled-link v-else :href="`/decks/${primary.deck_id}`" class="claim-badge__link" v-tooltip="tooltipMulti">
            <icon name="deck" />
            {{
                t("pages.collection.claim_badge.multi_label", {
                    deck: primary.deck_name,
                    count: extraCount
                })
            }}
        </labelled-link>
    </span>
</template>
