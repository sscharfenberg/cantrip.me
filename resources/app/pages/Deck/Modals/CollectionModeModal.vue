<script setup lang="ts">
/******************************************************************************
 * Modal opened by `CollectionModeBadge`. Explains the deck's current
 * collection-integration mode, recaps the rule that put it there, and
 * surfaces the actionable transitions:
 *
 *   In A — link out to settings (master switch off) or `/collection/add`
 *          (no stacks); read-only otherwise.
 *   In B — "Switch to per-copy tracking" (PATCH promote, no claims).
 *   In C — "Clear all collection assignments" (DELETE clear, two-step
 *          in-modal confirm — destructive).
 *
 * The badge is owner-only, so this modal does not re-check ownership.
 *****************************************************************************/
import { Link, router } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import Modal from "Components/Modal/Modal.vue";
import Badge from "Components/UI/Badge.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import Paragraph from "Components/UI/Paragraph.vue";
/** Live context the modal phrases its "why" recap from. */
interface CollectionModeContext {
    master_switch_enabled: boolean;
    has_stacks: boolean;
    has_container: boolean;
    /** Total pivot rows attached to this deck — sizes the C→B confirm copy. */
    claimed_count: number;
}
const emit = defineEmits<{ close: [] }>();
const props = defineProps<{
    /** UUID of the deck — used in the PATCH/DELETE endpoints. */
    deckId: string;
    /**
     * Effective mode — drives the why-recap and the action set so the
     * "B but no container" case still gets the mode-B promote button +
     * "Edit deck" link instead of mode-A's settings/collection links.
     */
    mode: "A" | "B" | "C";
    /**
     * Badge-presentation mode — drives the heading label + description
     * so the modal's "Current mode" matches what the badge in the deck
     * header showed. Differs from {@see mode} only when effective mode
     * is B but the deck has no `container_id`, in which case both badge
     * and modal heading present as A.
     */
    badgeMode: "A" | "B" | "C";
    /** Context for "why" recap and confirm copy. */
    context: CollectionModeContext;
}>();
const { t } = useI18n();
/** True while a PATCH/DELETE is in flight. */
const processing = ref(false);
/** Two-step confirm for destructive C→B. False = primary state, true = confirm copy visible. */
const confirmingClear = ref(false);
/**
 * Pick the rule that put the deck in its current mode. Order matters —
 * earlier rules dominate (e.g. an opted-out user with stacks still
 * shows the master-switch reason first).
 */
function whyKey(): string {
    if (!props.context.master_switch_enabled) return "master_switch_off";
    if (!props.context.has_stacks) return "no_stacks";
    if (props.mode === "B" && !props.context.has_container) return "no_container";
    if (props.mode === "B") return "implicit";
    return "explicit_pinned";
}
/** Promote B → C (no claims, just pin). */
function onPromote(): void {
    processing.value = true;
    router.patch(
        `/decks/${props.deckId}/collection-mode/promote`,
        {},
        {
            onSuccess: () => emit("close"),
            onFinish: () => {
                processing.value = false;
            }
        }
    );
}
/** Show the second-step confirm copy. */
function onClearRequest(): void {
    confirmingClear.value = true;
}
/** Step back from the confirm. */
function onClearCancel(): void {
    confirmingClear.value = false;
}
/** Final confirm — fire the DELETE. */
function onClearConfirm(): void {
    processing.value = true;
    router.delete(`/decks/${props.deckId}/collection-mode/assignments`, {
        onSuccess: () => emit("close"),
        onFinish: () => {
            processing.value = false;
        }
    });
}
const iconName = computed(() => {
    switch (props.badgeMode) {
        case "A":
            return "clear";
        case "B":
            return "storage";
        case "C":
        default:
            return "key";
    }
});
</script>

<template>
    <modal @close="emit('close')">
        <template #header>
            {{ t("pages.deck.collection_mode.modal_title") }}
        </template>
        <section class="mode-modal__section">
            <headline :size="3">
                {{ t("pages.deck.collection_mode.current_mode_heading") }}
            </headline>
            <badge type="warning">
                <icon :name="iconName" />
                <strong>{{ t(`pages.deck.collection_mode.modes.${badgeMode}.label`) }}</strong>
            </badge>
            <paragraph>{{ t(`pages.deck.collection_mode.modes.${badgeMode}.description`) }}</paragraph>
        </section>
        <section class="mode-modal__section">
            <headline :size="4">
                {{ t("pages.deck.collection_mode.why_heading") }}
            </headline>
            <paragraph>{{ t(`pages.deck.collection_mode.why.${whyKey()}`) }}</paragraph>
        </section>
        <section class="mode-modal__section">
            <headline :size="3">
                {{ t("pages.deck.collection_mode.actions_heading") }}
            </headline>
            <!-- Mode A: contextual links out, never a destructive action. -->
            <template v-if="mode === 'A'">
                <template v-if="!context.master_switch_enabled">
                    <Link href="/dashboard" class="btn-primary" @click="emit('close')">
                        <icon name="key" />
                        {{ t("pages.deck.collection_mode.action.open_settings") }}
                    </Link>
                    <paragraph>
                        {{ t("pages.deck.collection_mode.action.open_settings_hint") }}
                    </paragraph>
                </template>
                <template v-else-if="!context.has_stacks">
                    <Link href="/collection/add" class="btn-primary" @click="emit('close')">
                        <icon name="add" />
                        {{ t("pages.deck.collection_mode.action.add_to_collection") }}
                    </Link>
                    <paragraph>
                        {{ t("pages.deck.collection_mode.action.add_to_collection_hint") }}
                    </paragraph>
                </template>
            </template>
            <!-- Mode B: optional set-container link + promote button. -->
            <template v-else-if="mode === 'B'">
                <template v-if="!context.has_container">
                    <Link :href="`/decks/${deckId}/edit`" class="btn-default" @click="emit('close')">
                        <icon name="edit" />
                        {{ t("pages.deck.collection_mode.action.set_container") }}
                    </Link>
                    <paragraph>
                        {{ t("pages.deck.collection_mode.action.set_container_hint") }}
                    </paragraph>
                </template>
                <button type="button" class="btn-primary" :disabled="processing" @click="onPromote">
                    <icon name="key" />
                    {{ t("pages.deck.collection_mode.action.promote") }}
                    <loading-spinner v-if="processing" :size="2" />
                </button>
                <paragraph>
                    {{ t("pages.deck.collection_mode.action.promote_hint") }}
                </paragraph>
            </template>
            <!-- Mode C: destructive clear-all behind a two-step confirm. -->
            <template v-else>
                <template v-if="!confirmingClear">
                    <button type="button" class="btn-primary" :disabled="processing" @click="onClearRequest">
                        <icon name="delete" />
                        {{ t("pages.deck.collection_mode.action.clear") }}
                    </button>
                    <paragraph>
                        {{ t("pages.deck.collection_mode.action.clear_hint") }}
                    </paragraph>
                </template>
                <template v-else>
                    <headline :size="4">
                        {{ t("pages.deck.collection_mode.action.clear_confirm_heading") }}
                    </headline>
                    <paragraph>
                        {{
                            t(
                                "pages.deck.collection_mode.action.clear_confirm_body",
                                { count: context.claimed_count },
                                context.claimed_count
                            )
                        }}
                    </paragraph>
                    <div class="mode-modal__confirm-buttons">
                        <button type="button" class="btn-default" :disabled="processing" @click="onClearCancel">
                            <icon name="close" />
                            {{ t("pages.deck.collection_mode.action.clear_cancel") }}
                        </button>
                        <button
                            type="button"
                            class="btn-primary mode-modal__danger"
                            :disabled="processing"
                            @click="onClearConfirm"
                        >
                            <icon name="delete" />
                            {{ t("pages.deck.collection_mode.action.clear_confirm_button") }}
                            <loading-spinner v-if="processing" :size="2" />
                        </button>
                    </div>
                </template>
            </template>
        </section>
    </modal>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/sizes" as s;

:deep(.badge) {
    display: inline-flex;
    align-self: flex-start;
}

.mode-modal {
    &__section {
        display: flex;
        flex-direction: column;

        gap: map.get(s.$pages, "deck", "mode-modal", "section-gap");

        &:not(:last-child) {
            margin-bottom: map.get(s.$pages, "deck", "mode-modal", "section-gap");
        }
    }

    &__headline {
        margin: 0;
    }

    &__confirm-buttons {
        display: flex;

        gap: map.get(s.$pages, "deck", "mode-modal", "section-gap");
    }
}

:deep(h3),
:deep(h4) {
    margin: 0;
}

:deep(.btn-primary),
:deep(.btn-default) {
    align-self: flex-start;
}
</style>
