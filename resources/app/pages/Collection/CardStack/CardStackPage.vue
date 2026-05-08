<script setup lang="ts">
import { Form, Head, router } from "@inertiajs/vue3";
import { type Ref, computed, nextTick, ref } from "vue";
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
 * Phase 2.7 — collection-side unclaim from the edit form. Detaches
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
        @success="isEditMode ? undefined : resetToDefaults()"
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
        <card-stack-search
            :error="errors.default_card_id ?? ''"
            :invalid="!!errors?.default_card_id"
            :card="isEditMode ? cardStack!.default_card : null"
            :locked="isEditMode"
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
        <!-- Phase 2.5: separate (non-required) form-group surfacing the
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
            <!-- Phase 2.7: in-form unclaim. Lands the user here after
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
        <form-group
            :label="$t('form.fields.proxy.label')"
            :error="errors.proxy ?? ''"
            :invalid="!!errors?.proxy"
        >
            <Switch
                ref-id="proxy"
                value="1"
                :label="$t('form.fields.proxy.toggle_label')"
                :checked-initially="isProxy"
                @change="isProxy = $event"
            />
            <template #text>{{ $t("form.fields.proxy.hint") }}</template>
        </form-group>
        <form-group class="button-group">
            <button-group>
                <template v-if="isEditMode">
                    <button type="submit" class="btn-primary" :disabled="processing">
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
</style>
