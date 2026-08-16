<script setup lang="ts">
/******************************************************************************
 * Deck-header badge for a Rulebreaker commander, doubling as the picker for
 * the colour one of them lets the pilot nominate.
 *
 * "Rulebreaker" is an ability word on eight legendary creatures that loosen
 * Commander deckbuilding for the deck they lead. Only Tolabow, Loch Rascal asks
 * for a choice — "the color identity of instant and sorcery cards in your deck
 * can include one color of your choice not in your commander's color identity"
 * — so the picker renders only when the backend says `requiresColorChoice`.
 *
 * Two things the backend decides and this component only reflects:
 *
 *  - `enforced` is false while a Rulebreaker is recognised but its rule is not
 *    modelled yet. The set is not out, and the printed text may still be
 *    re-templated, so the badge names the card without claiming the deck is
 *    being validated against it. Saying nothing would be worse: the pilot would
 *    assume the rule was live.
 *
 *  - Colours already in the commander's identity are offered as disabled. The
 *    rule requires a colour NOT in that identity, and hiding them entirely
 *    would leave a mono-blue pilot wondering why four swatches appeared instead
 *    of five.
 *
 * Owner-only; the parent gates rendering.
 *****************************************************************************/
import { router } from "@inertiajs/vue3";
import { computed, ref, useId } from "vue";
import { useI18n } from "vue-i18n";
import ManaCost from "Components/Card/ManaCost.vue";
import Badge from "Components/UI/Badge.vue";
import Icon from "Components/UI/Icon.vue";
import type { DeckRulebreaker } from "Types/deckPage.ts";

const props = defineProps<{
    /** Deck UUID — used in the PATCH endpoint. */
    deckId: string;
    /** Rulebreaker state as shipped by the deck payload. */
    rulebreaker: DeckRulebreaker;
    /**
     * Whether the viewer may change the nomination. Deliberately separate from
     * `requiresColorChoice`: a visitor still needs to SEE which colour was
     * nominated, because it changes what is legal in the 99. Only the picker is
     * owner-gated, not the information.
     */
    canEdit: boolean;
}>();

const { t } = useI18n();
const popoverId = useId();
const reference = ref("--" + popoverId);
/** True while a PATCH is in flight — prevents a double-submit. */
const processing = ref(false);
/** Server-side rejection, surfaced in the popover rather than swallowed. */
const error = ref<string | null>(null);

const COLORS = ["W", "U", "B", "R", "G"] as const;
type Color = (typeof COLORS)[number];

/**
 * Whether to render an interactive trigger at all.
 *
 * A disabled <button> is not an option: browsers suppress mouse events on
 * disabled controls and leave them unfocusable, so the tooltip explaining that
 * this deck's rules are relaxed would never open — which is the one thing a
 * non-owner, or the owner of a Rulebreaker deck that asks for no choice, needs
 * to read. Those cases get a plain informational badge instead.
 */
const interactive = computed(() => props.canEdit && props.rulebreaker.requiresColorChoice);

/** Colours the commander already covers cannot be nominated — see above. */
const unavailable = computed(() => new Set(props.rulebreaker.deckIdentity.split("")));

const label = computed(() => {
    if (props.rulebreaker.color !== null) {
        return t(`pages.deck.rulebreaker.colors.${props.rulebreaker.color}`);
    }

    // Only the owner is invited to pick, so only the owner is told one is
    // missing; to everyone else "no colour chosen" is noise about a decision
    // that is not theirs.
    return props.canEdit && props.rulebreaker.requiresColorChoice
        ? t("pages.deck.rulebreaker.no_color")
        : t("pages.deck.rulebreaker.label");
});

/**
 * The hint above the swatches, keyed to the commander rather than hardcoded.
 * `hint` alone would show Tolabow's "instants and sorceries" wording for the
 * next colour-choosing Rulebreaker that comes along.
 */
const hint = computed(() =>
    props.rulebreaker.messageKey === null ? "" : t(`pages.deck.rulebreaker.rules.${props.rulebreaker.messageKey}`)
);

/**
 * The card's own rule, not a generic line. Seven of the eight Rulebreakers ask
 * for no colour choice and so never open the popover — the tooltip is the only
 * place their rule can be read at all.
 */
const tooltip = computed(() => {
    const name = props.rulebreaker.name ?? "";

    if (!props.rulebreaker.enforced) {
        return t("pages.deck.rulebreaker.tooltip_unenforced", { name });
    }

    return hint.value === "" ? t("pages.deck.rulebreaker.tooltip", { name }) : `${name} — ${hint.value}`;
});

function closePopover(): void {
    const el = document.getElementById(popoverId);
    if (el !== null) el.hidePopover();
}

function onSelect(target: Color | null): void {
    if (target === props.rulebreaker.color || processing.value) return;
    if (target !== null && unavailable.value.has(target)) return;

    processing.value = true;
    router.patch(
        `/decks/${props.deckId}/rulebreaker-color`,
        { color: target },
        {
            preserveScroll: true,
            // Reachable in practice: swapping the commander away from a
            // Rulebreaker in another tab makes this 422 on `no_choice_to_make`.
            // Without this the click is simply silent and the old value stays.
            onError: errors => {
                error.value = errors.color ?? t("pages.deck.rulebreaker.errors.generic");
            },
            // Closed HERE rather than on click, because the error paragraph
            // lives inside the popover: closing first would dismiss the only
            // surface the message can appear on, and a rejected pick would
            // look exactly like nothing happening.
            onSuccess: () => {
                error.value = null;
                closePopover();
            },
            onFinish: () => {
                processing.value = false;
            }
        }
    );
}
</script>

<template>
    <div class="rulebreaker-badge">
        <!--
            Interactive only when the viewer may actually pick. A disabled
            button would swallow the tooltip — see `interactive` above.
        -->
        <button
            v-if="interactive"
            :popovertarget="popoverId"
            class="badge rulebreaker-badge__trigger"
            v-tooltip="tooltip"
            :disabled="processing"
        >
            <icon name="swords" :size="1" />
            {{ label }}
        </button>
        <badge v-else type="info" v-tooltip="tooltip"> <icon name="swords" :size="1" />{{ label }} </badge>

        <dialog v-if="interactive" :id="popoverId" popover class="popover-content rulebreaker-badge__menu">
            <p v-if="hint !== ''" class="rulebreaker-badge__hint">{{ hint }}</p>
            <p v-if="error !== null" class="rulebreaker-badge__error">{{ error }}</p>
            <ul class="popover-list">
                <li v-for="c in COLORS" :key="c">
                    <button
                        type="button"
                        class="popover-list-item"
                        :class="{ 'popover-list-item--selected': c === rulebreaker.color }"
                        :disabled="processing || unavailable.has(c)"
                        @click.prevent="onSelect(c)"
                    >
                        <mana-cost :mana-cost="`{${c}}`" />
                        {{ $t(`pages.deck.rulebreaker.colors.${c}`) }}
                        <span v-if="unavailable.has(c)" class="rulebreaker-badge__note">
                            {{ $t("pages.deck.rulebreaker.in_identity") }}
                        </span>
                    </button>
                </li>
                <li>
                    <button
                        type="button"
                        class="popover-list-item"
                        :class="{ 'popover-list-item--selected': rulebreaker.color === null }"
                        :disabled="processing"
                        @click.prevent="onSelect(null)"
                    >
                        <icon name="clear" :size="1" />
                        {{ $t("pages.deck.rulebreaker.no_color") }}
                    </button>
                </li>
            </ul>
        </dialog>
    </div>
</template>

<style scoped lang="scss">
.rulebreaker-badge {
    &__trigger {
        cursor: pointer;
        anchor-name: v-bind(reference);
    }

    &__menu {
        /**
         * The shared `.popover-content` switches to `display: flex` when open,
         * and the default row direction lays the explanation out BESIDE the
         * colour list — tolerable on a desktop, unusable on a phone. Every
         * other popover in the app has a single child, so the direction never
         * mattered until this one put a paragraph above its list.
         */
        flex-direction: column;

        /**
         * Wider than the shared `max-width: 50dvw`, which is sized for menus of
         * short labels rather than a sentence of prose — on a 390px phone that
         * cap leaves about 195px to wrap in.
         */
        max-width: min(90dvw, 26rem);

        position-anchor: v-bind(reference);
    }

    &__hint {
        max-width: 22rem;
        padding: 0 0.5rem;
        margin: 0 0 0.5rem;

        font-size: 0.8rem;
    }

    &__error {
        max-width: 22rem;
        padding: 0 0.5rem;
        margin: 0 0 0.5rem;

        color: var(--color-error-text, currentcolor);

        font-size: 0.8rem;
    }

    &__note {
        opacity: 0.7;

        padding-left: 0.5rem;
        margin-left: auto;

        font-size: 0.75rem;
    }
}
</style>
