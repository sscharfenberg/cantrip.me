<script setup lang="ts">
/******************************************************************************
 * The deck's lifecycle state as a badge. Given a `deckId` — the owner, on
 * the deck page — it is also the control that changes it: a click opens
 * a menu with every state, the current one checked and not selectable.
 *****************************************************************************/
import { router } from "@inertiajs/vue3";
import { computed, ref, useId } from "vue";
import { useI18n } from "vue-i18n";
import Badge from "Components/UI/Badge.vue";
import Icon from "Components/UI/Icon.vue";
const props = defineProps<{
    /** The deck state value ("planned", "built", or "archived"). */
    state: string;
    /**
     * Append the per-card badge legend to the tooltip. Deck state is not
     * just a label any more — a planned deck shows collection
     * availability per card, a finished one its tracking-mode status —
     * and this badge is where that gets explained. Owner-only, and only
     * while the collection master switch is on: the legend describes
     * icons that render nowhere else.
     */
    explain?: boolean;
    /** The deck's id when the viewer may change its state — makes the badge open the state menu. */
    deckId?: string;
}>();
const { t } = useI18n();
/** Every state, with the icon the deck's actions used for it. */
const STATES = [
    { value: "planned", icon: "planned" },
    { value: "built", icon: "finished" },
    { value: "archived", icon: "archived" }
] as const;
/** Badge type mapped from deck state. */
const badgeType = computed<"info" | "success" | "warning">(() => {
    if (props.state === "built") return "success";
    if (props.state === "archived") return "warning";
    return "info";
});
/** Icon name mapped from deck state ("built" uses the "finished" icon). */
const iconName = computed<string>(() => (props.state === "built" ? "finished" : props.state));
/**
 * Tooltip: the plain "Deck is X" line, plus — when `explain` is set —
 * what the per-card badges of that state mean. HTML tooltip; FloatingVue
 * is configured with `html: true` in main.ts.
 */
const tooltip = computed<string>(() => {
    const lines = [`${t("pages.deck.state_is")} ${t(`enums.deck_state.${props.state}`)}`];
    if (!props.explain) return lines.join("<br />");
    if (props.state === "planned") {
        lines.push(
            t("pages.deck.state_tooltip.planned.intro"),
            t("pages.deck.state_tooltip.planned.available"),
            t("pages.deck.state_tooltip.planned.partial"),
            t("pages.deck.state_tooltip.planned.unavailable"),
            t("pages.deck.state_tooltip.planned.note")
        );
    } else {
        lines.push(t("pages.deck.state_tooltip.tracked"));
    }
    return lines.join("<br />");
});
const popoverId = useId();
const anchor = `--deck-state-${popoverId}`;
/** True while a change is on its way — the menu waits for it. */
const processing = ref(false);
function onSelect(target: string): void {
    document.getElementById(popoverId)?.hidePopover();
    if (target === props.state || processing.value || props.deckId === undefined) return;
    processing.value = true;
    router.patch(
        `/decks/${props.deckId}/state`,
        { state: target },
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
            }
        }
    );
}
</script>

<template>
    <div v-if="deckId" class="deck-state-picker">
        <button
            type="button"
            :popovertarget="popoverId"
            v-tooltip="tooltip"
            class="badge deck-state deck-state-picker__trigger"
            :class="badgeType"
            :disabled="processing"
        >
            <icon :name="iconName" :size="1" />
            <span>{{ t(`enums.deck_state.${state}`) }}</span>
        </button>
        <dialog :id="popoverId" popover class="popover-content deck-state-picker__menu">
            <ul class="popover-list">
                <li v-for="option in STATES" :key="option.value">
                    <button
                        type="button"
                        class="popover-list-item"
                        :class="{ 'popover-list-item--selected': option.value === state }"
                        :disabled="option.value === state || processing"
                        :aria-current="option.value === state ? 'true' : undefined"
                        @click.prevent="onSelect(option.value)"
                    >
                        <icon :name="option.icon" :size="1" />
                        {{ t(`enums.deck_state.${option.value}`) }}
                        <icon
                            v-if="option.value === state"
                            name="check"
                            :size="1"
                            :additional-classes="['deck-state-picker__check']"
                        />
                    </button>
                </li>
            </ul>
        </dialog>
    </div>
    <badge v-else v-tooltip="tooltip" class="deck-state" :type="badgeType">
        <icon :name="iconName" :size="1" />
        <span>{{ t(`enums.deck_state.${state}`) }}</span>
    </badge>
</template>

<style scoped lang="scss">
.deck-state-picker {
    display: inline-flex;

    &__trigger {
        cursor: pointer;
        anchor-name: v-bind(anchor);
    }

    &__menu {
        position-anchor: v-bind(anchor);
    }

    &__check {
        margin-left: auto;
    }
}
</style>
