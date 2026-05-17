<script setup lang="ts">
import { Form, Head, router } from "@inertiajs/vue3";
import { type Ref, computed, nextTick, onMounted, onUnmounted, ref, useTemplateRef, watch } from "vue";
import { useI18n } from "vue-i18n";
import CardStackDefaults from "@/pages/Collection/CardStack/CardStackDefaults.vue";
import CardStackFinish from "@/pages/Collection/CardStack/CardStackFinish.vue";
import CardStackLanguage from "@/pages/Collection/CardStack/CardStackLanguage.vue";
import CardStackSearch from "@/pages/Collection/CardStack/CardStackSearch.vue";
import type { Container } from "@/types/container";
import type { ContainerListItem } from "@/types/containerListItem";
import CardStackClaimBadge from "Components/Collection/CardStackClaimBadge.vue";
import ButtonGroup from "Components/Form/ButtonGroup.vue";
import FormGroup from "Components/Form/FormGroup.vue";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import Switch from "Components/Form/Switch.vue";
import Badge from "Components/UI/Badge.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import { useAddCardsDefaults } from "Composables/useAddCardsDefaults.ts";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
import type { DefaultCardImage } from "Types/defaultCardImage";
/** Shape of the cardStack prop when editing an existing card stack. */
type CardStackEdit = {
    id: string;
    amount: number;
    language: string;
    condition: string;
    finish: string;
    container_id: string | null;
    /** Proxy flag — when true, this stack represents a printout / proxy. */
    proxy: boolean;
    default_card: DefaultCardImage;
    /**
     * Decks claiming this stack (Phase 2.5). When non-empty, the
     * container picker would 422 with "cannot move claimed stack" if
     * the user tries to change it; the badge surfaces *which* deck so
     * the user can navigate over and unclaim before retrying.
     */
    claims: { deck_id: string; deck_name: string }[];
};
/** Shape of one set option shipped from the backend for the set-filter picker. */
type SetRow = {
    code: string;
    name: string;
    /** Set-icon URL (Scryfall SVG path). */
    path: string;
    /** Year extracted from released_at, or null when unknown. */
    year: number | null;
};
const props = defineProps<{
    /** Present when adding cards to a specific container; null for unsorted / collection-level. */
    container: Container | null;
    /** Lightweight list of all user containers for the container dropdown. */
    containers: ContainerListItem[];
    /** CardCondition enum values. */
    conditions: string[];
    /** Finish enum labels. */
    finishes: string[];
    /** CardLanguage enum values. */
    languages: string[];
    /** Present when editing an existing card stack; absent for "add" mode. */
    cardStack?: CardStackEdit;
    /**
     * All sets, server-sorted alphabetically by name. Drives the
     * "Restrict results to set" picker; absent in edit mode is fine
     * (the picker is rendered in both modes but only the search uses it).
     */
    sets?: SetRow[];
}>();

const isEditMode = !!props.cardStack;
const { t } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([
    { labelKey: "pages.collection.link", href: "/collection", icon: "collection" },
    ...(props.container ? [{ labelKey: "pages.containers.link", href: "/containers", icon: "storage" }] : []),
    ...(props.container
        ? [
              {
                  label: props.container.name,
                  href: `/containers/${props.container.id}`,
                  icon: "container-name"
              }
          ]
        : []),
    { labelKey: isEditMode ? "pages.edit_card.title" : "pages.add_cards.title" }
]);
const {
    savedDefaults,
    hasSavedDefaults,
    amount,
    language: selectedLanguage,
    condition: selectedCondition,
    finish: selectedFinish,
    resetKey: searchKey,
    saveDefaults,
    clearDefaults,
    resetToDefaults
} = useAddCardsDefaults();
// In edit mode, override composable defaults with the card stack's current values.
if (isEditMode) {
    amount.value = props.cardStack!.amount;
    selectedLanguage.value = props.cardStack!.language;
    selectedCondition.value = props.cardStack!.condition;
    selectedFinish.value = props.cardStack!.finish;
}
/** Container options formatted for MonoSelect: `{ value, label }` pairs. */
const containerOptions = computed(() =>
    props.containers.map(container => ({
        value: container.id,
        label: container.name
    }))
);
/** Currently selected container id. Initialized from container prop or cardStack in edit mode. */
const selectedContainer = ref((isEditMode ? props.cardStack!.container_id : props.container?.id) as string);
/** Proxy flag. Defaults to false on add; pre-filled from the existing stack on edit. */
const isProxy = ref(isEditMode ? props.cardStack!.proxy : false);
/** CardCondition options formatted for MonoSelect with translated labels. */
const conditionOptions = computed(() =>
    props.conditions.map(condition => ({
        value: condition,
        label: t("enums.conditions." + condition)
    }))
);
/** Maps form field names to their corresponding refs for generic select handling. */
const selectRefs: Record<string, Ref<string>> = {
    container_id: selectedContainer,
    condition: selectedCondition
};
/**
 * Generic change handler for MonoSelect fields. Updates the ref and
 * re-triggers precognitive validation for the given field name.
 */
const onSelectChange = (field: string, value: string, validate: (field: string) => void) => {
    selectRefs[field].value = value;
    nextTick(() => validate(field));
};
/** Available finishes for the currently selected card. All finishes when no card is selected. */
const availableFinishes = ref<string[]>(isEditMode ? props.cardStack!.default_card.finishes : [...props.finishes]);
const cardSearch = useTemplateRef<{ focus: () => void }>("cardSearch");
/**
 * Set-filter dropdown state — persists across page reloads / Inertia
 * navigations within the same tab via sessionStorage so a typical
 * "set up filter, then bulk-add 50 cards from one set" workflow
 * doesn't reset on every redirect back to this page.
 *
 * The "All sets" option uses a sentinel string (not "") so the option's
 * value stays truthy in places like MonoSelect's clear-button guard
 * (`v-if="selectedValue && clearable"`) — every other MonoSelect caller
 * treats empty-string as "no selection", and we don't want to fight
 * that convention. The composable + plumbing translate the sentinel
 * back to "no filter" before hitting the server.
 *
 * The stored value is validated against the current `sets` list to
 * guard against stale codes after a Scryfall sync drops a set.
 */
const ALL_SETS = "__all__";
const SET_FILTER_STORAGE_KEY = "cardStack:setFilter";
function readStoredSetCode(): string {
    try {
        const stored = sessionStorage.getItem(SET_FILTER_STORAGE_KEY) ?? "";
        if (!stored || !props.sets) return ALL_SETS;
        return props.sets.some(s => s.code === stored) ? stored : ALL_SETS;
    } catch {
        return ALL_SETS;
    }
}
const selectedSetCode = ref(readStoredSetCode());
/** Listbox options: pinned "All sets" first, then one entry per set. */
const setOptions = computed(() => {
    const all: { value: string; label: string; imageUrl?: string }[] = [
        { value: ALL_SETS, label: t("pages.add_cards.all_sets") }
    ];
    if (!props.sets) return all;
    return all.concat(
        props.sets.map(s => ({
            value: s.code,
            // Name-first so MonoSelect's typeahead (jump to first option
            // whose label starts with typed letters) is intuitive — the
            // user types the set's first letter to land on its block.
            // Year goes into `meta` so it renders right-aligned and
            // muted, out of the way of the primary label.
            label: `${s.name} [${s.code.toUpperCase()}]`,
            imageUrl: s.path,
            meta: s.year !== null ? String(s.year) : undefined
        }))
    );
});
/** True when no set filter is active. Drives both the search wiring and the clear-button disabled state. */
const isAllSetsSelected = computed(() => selectedSetCode.value === ALL_SETS);
/** Set-code value handed to the search composable: empty string when "All sets". */
const activeSetCode = computed(() => (isAllSetsSelected.value ? "" : selectedSetCode.value));
/** Persist (or clear) the selection in sessionStorage on every change. */
watch(selectedSetCode, value => {
    try {
        if (value && value !== ALL_SETS) {
            sessionStorage.setItem(SET_FILTER_STORAGE_KEY, value);
        } else {
            sessionStorage.removeItem(SET_FILTER_STORAGE_KEY);
        }
    } catch {
        // sessionStorage can throw in private mode / when full — ignore.
    }
});
/** Reset the set filter back to "All sets" (and drop the sessionStorage entry). */
function clearSetFilter(): void {
    selectedSetCode.value = ALL_SETS;
}
/**
 * Global Enter-to-submit shortcut.
 *
 * The page has no textareas (where Enter means newline) and no other
 * legitimate Enter semantics — so a plain Enter anywhere on the page
 * fires a submit. Preference order:
 *   1. `add_more` (add mode — keeps the user on the form for rapid entry)
 *   2. `back` (add mode "Save" button, or the edit-mode submit which
 *      carries the same `redirect=back` marker for selector parity)
 *
 * Skipped when:
 *   - any modifier key is held (Cmd/Ctrl/Alt/Shift+Enter is preserved
 *     for browser/OS shortcuts)
 *   - a child handler already called event.preventDefault() (e.g. an
 *     autocomplete that uses Enter to commit a selection — we shouldn't
 *     submit on top of that)
 */
function onGlobalEnter(event: KeyboardEvent): void {
    if (event.key !== "Enter") return;
    if (event.metaKey || event.ctrlKey || event.altKey || event.shiftKey) return;
    if (event.defaultPrevented) return;
    const target =
        document.querySelector<HTMLButtonElement>('button[name="redirect"][value="add_more"]:not([disabled])') ??
        document.querySelector<HTMLButtonElement>('button[name="redirect"][value="back"]:not([disabled])');
    if (target !== null) {
        event.preventDefault();
        target.click();
    }
}
onMounted(() => {
    if (!isEditMode) cardSearch.value?.focus();
    window.addEventListener("keydown", onGlobalEnter);
});
onUnmounted(() => {
    window.removeEventListener("keydown", onGlobalEnter);
});
/**
 * "Save and add more" success handler. The Form is keyed on
 * `searchKey`; bumping it via resetToDefaults() remounts the form and
 * its CardStackSearch child, which drops focus. Re-focus after the
 * remount so the next card name can be typed without grabbing the mouse.
 */
function onAddMoreSuccess() {
    resetToDefaults();
    nextTick(() => cardSearch.value?.focus());
}
/** Called when the user selects a card from search results. */
function onCardSelected(card: DefaultCardImage) {
    availableFinishes.value = card.finishes;
    if (!card.finishes.includes(selectedFinish.value)) {
        selectedFinish.value = card.finishes[0];
    }
}
/** Called when the user clears the card selection. */
function onCardCleared() {
    availableFinishes.value = [...props.finishes];
}
/**
 * collection-side unclaim from the edit form. Detaches
 * every deck claim against this stack atomically (multi-claim case
 * included). Spins the button while the request is in-flight; the
 * controller redirects back to this same edit page on success and the
 * claim form-group disappears via its `claims.length > 0` gate.
 */
const processingUnclaim = ref(false);
const unclaim = () => {
    if (!props.cardStack) return;
    processingUnclaim.value = true;
    router.delete(`/collection/cardstack/${props.cardStack.id}/claims`, {
        preserveScroll: true,
        onFinish: () => {
            processingUnclaim.value = false;
        }
    });
};
</script>

<template>
    <Head
        ><title>{{ isEditMode ? $t("pages.edit_card.title") : $t("pages.add_cards.title") }}</title></Head
    >
    <headline>
        <icon :name="isEditMode ? 'edit' : 'add'" :size="3" />
        {{ isEditMode ? $t("pages.edit_card.title") : $t("pages.add_cards.title") }}
        <badge type="info" v-if="container">
            <icon name="storage" />
            {{ container?.type === "other" ? container?.custom_type : $t("enums.container_type." + container?.type) }}:
            {{ container.name }}
        </badge>
        <badge v-else-if="!isEditMode">
            <icon name="collection" />
            {{ $t("pages.add_cards.to_collection") }}
        </badge>
    </headline>
    <Form
        :key="searchKey"
        :action="isEditMode ? `/collection/cardstack/${cardStack!.id}` : '/collection/add'"
        :method="isEditMode ? 'patch' : 'post'"
        class="form"
        @success="isEditMode ? undefined : onAddMoreSuccess()"
        #default="{ validate, processing, validating, errors, valid }"
    >
        <card-stack-defaults
            v-if="!isEditMode"
            :amount="amount"
            :language="selectedLanguage"
            :condition="selectedCondition"
            :finish="selectedFinish"
            :saved-defaults="savedDefaults"
            :has-saved-defaults="hasSavedDefaults"
            @save="saveDefaults"
            @clear="clearDefaults"
        />
        <!-- Duplicate of the bottom submit row, mirrored at the top of
             the form so the user can submit without scrolling all the
             way down on long forms. Both groups submit the same form
             and react to the same `processing` flag. -->
        <form-group class="button-group">
            <button-group>
                <template v-if="isEditMode">
                    <!-- name="redirect" value="back": ignored by the
                         update controller (only the add flow reads
                         $request->redirect), present purely so the
                         global Enter-to-submit selector can match this
                         button in edit mode the same way it matches
                         the back button in add mode. -->
                    <button type="submit" name="redirect" value="back" class="btn-primary" :disabled="processing">
                        <icon name="save" />
                        {{ $t("pages.edit_card.submit") }}
                        <loading-spinner v-if="processing" :size="2" />
                    </button>
                </template>
                <template v-else>
                    <button type="submit" name="redirect" value="back" class="btn-default" :disabled="processing">
                        <icon name="save" />
                        {{ $t("pages.add_cards.submit") }}
                        <loading-spinner v-if="processing" :size="2" />
                    </button>
                    <button type="submit" name="redirect" value="add_more" class="btn-primary" :disabled="processing">
                        <icon name="add" />
                        {{ $t("pages.add_cards.submit_and_add_more") }}
                        <loading-spinner v-if="processing" :size="2" />
                    </button>
                </template>
            </button-group>
        </form-group>
        <!-- Set filter: restrict card-search results to a single MTG
             set. Selection persists across page navigations via
             sessionStorage. Edit mode skips the row (the card is
             locked, so filtering has no effect). -->
        <form-group v-if="!isEditMode && sets" :label="$t('form.fields.restrict_to_set')">
            <!-- :clearable="false" hides MonoSelect's inline X — clearing
                 it would set the value to "" (placeholder shown), but the
                 picker's "no filter" state is the sentinel `__all__`
                 ("All sets") instead. The external "Clear set" button
                 resets to that sentinel correctly. -->
            <mono-select
                :options="setOptions"
                :selected="selectedSetCode"
                :sort="false"
                :clearable="false"
                addon-icon="card"
                @change="selectedSetCode = $event"
            />
            <template v-if="!isAllSetsSelected" #text>
                <button type="button" class="btn-default" @click="clearSetFilter">
                    <icon name="clear" />
                    {{ $t("pages.add_cards.clear_set") }}
                </button>
            </template>
        </form-group>
        <card-stack-search
            ref="cardSearch"
            :error="errors.default_card_id ?? ''"
            :invalid="!!errors?.default_card_id"
            :card="isEditMode ? cardStack!.default_card : null"
            :locked="isEditMode"
            :set-code="activeSetCode"
            @selected="onCardSelected"
            @cleared="onCardCleared"
        />
        <form-group
            for-id="amount"
            :label="$t('form.fields.amount')"
            :error="errors.amount ?? ''"
            :invalid="!!errors?.amount"
            :validated="valid('amount')"
            :validating="validating"
            :required="true"
            addon-icon="deck"
        >
            <input
                type="text"
                name="amount"
                class="form-input"
                inputmode="numeric"
                v-model="amount"
                @change="validate('amount')"
            />
            <template #button>
                <button type="button" @mousedown.prevent @click="amount++" tabindex="-1">
                    <icon name="add" />
                </button>
                <button type="button" @mousedown.prevent @click="amount--" :disabled="amount <= 1" tabindex="-1">
                    <icon name="subtract" />
                </button>
            </template>
        </form-group>
        <card-stack-language
            v-model="selectedLanguage"
            :languages="languages"
            :error="errors.language ?? ''"
            :invalid="!!errors?.language"
        />
        <card-stack-finish
            v-model="selectedFinish"
            :finishes="availableFinishes"
            :error="errors.finish ?? ''"
            :invalid="!!errors?.finish"
        />
        <form-group
            v-if="containerOptions.length > 0"
            :label="$t('form.fields.container.id')"
            :error="errors.container_id ?? ''"
            :invalid="!!errors?.container_id"
            :validated="valid('container_id')"
            :validating="validating"
        >
            <mono-select
                :options="containerOptions"
                :selected="selectedContainer"
                @change="onSelectChange('container_id', $event, validate)"
                addon-icon="storage"
            />
            <input type="hidden" name="container_id" :value="selectedContainer" />
        </form-group>
        <!-- separate (non-required) form-group surfacing the
             deck(s) currently claiming this stack. Read-only — the
             container picker above would 422 with "cannot move claimed
             stack" if the user tries to move the stack while it's
             pivoted to a deck; this group answers "which deck?" so the
             user can navigate over and unclaim before retrying. -->
        <form-group
            v-if="isEditMode && cardStack && cardStack.claims.length > 0"
            :label="$t('form.fields.claimed_by_deck')"
            class="claim-group"
        >
            <card-stack-claim-badge :claims="cardStack.claims" />
            <!-- in-form unclaim. Lands the user here after
                 the lifecycle 422 from a container move attempt; this
                 button closes the loop without leaving the page.
                 Detaches every claim atomically (multi-claim case
                 included). After success the controller redirects back
                 to this same edit page (default `from` branch) and
                 flashes a count, so the form-group disappears via the
                 `claims.length > 0` gate. -->
            <button type="button" class="btn-default" :disabled="processingUnclaim" @click="unclaim">
                <icon name="delete" :size="1" />
                {{ $t("pages.collection.claim_badge.unclaim") }}
                <loading-spinner v-if="processingUnclaim" :size="2" />
            </button>
        </form-group>
        <form-group
            :label="$t('form.fields.condition')"
            :error="errors.condition ?? ''"
            :invalid="!!errors?.condition"
            :validated="valid('condition')"
            :validating="validating"
        >
            <mono-select
                :options="conditionOptions"
                :selected="selectedCondition"
                @change="onSelectChange('condition', $event, validate)"
                :sort="false"
                addon-icon="cards"
            />
            <input type="hidden" name="condition" :value="selectedCondition" />
        </form-group>
        <form-group :label="$t('form.fields.proxy.label')" :error="errors.proxy ?? ''" :invalid="!!errors?.proxy">
            <div class="proxy-label">
                <Switch
                    ref-id="proxy"
                    value="1"
                    :label="$t('form.fields.proxy.toggle_label')"
                    :checked-initially="isProxy"
                    @change="isProxy = $event"
                />
                {{ $t("form.fields.proxy.hint") }}
            </div>
        </form-group>
        <form-group class="button-group">
            <button-group>
                <template v-if="isEditMode">
                    <!-- name="redirect" value="back": ignored by the
                         update controller (only the add flow reads
                         $request->redirect), present purely so the
                         global Enter-to-submit selector can match this
                         button in edit mode the same way it matches
                         the back button in add mode. -->
                    <button type="submit" name="redirect" value="back" class="btn-primary" :disabled="processing">
                        <icon name="save" />
                        {{ $t("pages.edit_card.submit") }}
                        <loading-spinner v-if="processing" :size="2" />
                    </button>
                </template>
                <template v-else>
                    <button type="submit" name="redirect" value="back" class="btn-default" :disabled="processing">
                        <icon name="save" />
                        {{ $t("pages.add_cards.submit") }}
                        <loading-spinner v-if="processing" :size="2" />
                    </button>
                    <button type="submit" name="redirect" value="add_more" class="btn-primary" :disabled="processing">
                        <icon name="add" />
                        {{ $t("pages.add_cards.submit_and_add_more") }}
                        <loading-spinner v-if="processing" :size="2" />
                    </button>
                </template>
            </button-group>
        </form-group>
    </Form>
</template>

<style lang="scss" scoped>
.badge {
    margin-left: auto;
}

:deep(.claim-badge) {
    padding: 0.75ex 0;
}

:deep(.claim-group .form-group__field) {
    display: flex;
    align-items: center;

    gap: 2ch;
}

.proxy-label {
    display: flex;
    align-items: center;
    opacity: 0.8;

    gap: 1rem;

    font-size: 0.875rem;
}
</style>
