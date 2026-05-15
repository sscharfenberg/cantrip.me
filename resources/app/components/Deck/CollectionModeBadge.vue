<script setup lang="ts">
/******************************************************************************
 * Deck-header badge surfacing the current collection-integration mode and
 * doubling as a popover-trigger that lets the owner switch the deck's
 * mode in one click.
 *
 * Three states (A / B / C — see {@see DeckCollectionStatusService}):
 *   A  tracking off    — clear icon
 *   B  implicit        — storage icon (count-based)
 *   C  per-copy        — key icon (each row pinned to a specific stack)
 *
 * Owner-only — the parent already gates rendering on `isOwner` and on the
 * user-level master switch. Switching C → B/A cascade-deletes every
 * `deck_card_card_stack` pivot row on the server side; the transition is
 * silent + immediate (user explicitly opted into this behaviour) with the
 * success flash as the only feedback.
 *****************************************************************************/
import { router } from "@inertiajs/vue3";
import { computed, ref, useId } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";

const props = defineProps<{
    /** Deck UUID — used in the PATCH endpoint. */
    deckId: string;
    /** The deck's currently stored collection mode. */
    mode: "A" | "B" | "C";
}>();

const { t } = useI18n();
const popoverId = useId();
const reference = ref("--" + popoverId);
/** True while a PATCH is in flight — disables the menu to prevent double-submit. */
const processing = ref(false);

const modes = ["A", "B", "C"] as const;
type Mode = (typeof modes)[number];

function iconFor(m: Mode): string {
    switch (m) {
        case "A":
            return "clear";
        case "B":
            return "storage";
        case "C":
            return "key";
    }
}

const tooltip = computed(() => t(`pages.deck.collection_mode.modes.${props.mode}.tooltip`));
const label = computed(() => t(`pages.deck.collection_mode.modes.${props.mode}.label`));

function closePopover(): void {
    const el = document.getElementById(popoverId);
    if (el !== null) el.hidePopover();
}

function onSelect(target: Mode): void {
    closePopover();
    if (target === props.mode || processing.value) return;

    processing.value = true;
    router.patch(
        `/decks/${props.deckId}/collection-mode`,
        { mode: target },
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
    <div class="collection-mode-badge">
        <button
            :popovertarget="popoverId"
            class="badge warning collection-mode-badge__trigger"
            v-tooltip="tooltip"
            :disabled="processing"
        >
            <icon :name="iconFor(mode)" :size="1" />
            {{ label }}
        </button>
        <dialog :id="popoverId" popover class="popover-content collection-mode-badge__menu">
            <ul class="popover-list">
                <li v-for="m in modes" :key="m">
                    <button
                        type="button"
                        class="popover-list-item"
                        :class="{ 'popover-list-item--selected': m === mode }"
                        :disabled="processing"
                        @click.prevent="onSelect(m)"
                    >
                        <icon :name="iconFor(m)" :size="1" />
                        {{ $t(`pages.deck.collection_mode.modes.${m}.label`) }}
                    </button>
                </li>
            </ul>
        </dialog>
    </div>
</template>

<style scoped lang="scss">
.collection-mode-badge {
    &__trigger {
        cursor: pointer;
        anchor-name: v-bind(reference);
    }

    &__menu {
        position-anchor: v-bind(reference);
    }
}
</style>
