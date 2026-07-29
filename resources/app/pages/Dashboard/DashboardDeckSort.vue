<script setup lang="ts">
import { Form, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import FormGroup from "Components/Form/FormGroup.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import RadioButtonGroup from "Components/Form/Radio/RadioButtonGroup.vue";
import Switch from "Components/Form/Switch.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import { clearAllDeckSortOverrides } from "Composables/useDeckSort.ts";
import type { DeckSort } from "Composables/useDeckSort.ts";

/** Current user default pulled from the Inertia shared `auth.user` prop. */
const userDefault = (usePage().props.auth.user?.deck_sort_default ?? "mana") as DeckSort;
/** Locally tracked radio selection — starts at the current server value. */
const selectedSort = ref<DeckSort>(userDefault);
/** Whether the switch "also apply to existing decks" is on. */
const applyToExisting = ref(false);

/** Radio options fed to {@link RadioButtonGroup}, reactive to the current selection. */
const sortOptions = computed(() => [
    {
        value: "mana",
        label: "enums.deck_sort.mana",
        checked: selectedSort.value === "mana",
        icon: "spell"
    },
    {
        value: "name",
        label: "enums.deck_sort.name",
        checked: selectedSort.value === "name",
        icon: "alphabetically"
    }
]);

/**
 * Handle a successful form submit.
 *
 * If the user turned on the "apply to existing decks" switch, wipe every
 * per-deck localStorage override so existing decks pick up the new default
 * on their next visit.
 */
function onSuccess(): void {
    if (applyToExisting.value) {
        clearAllDeckSortOverrides();
    }
}
</script>

<template>
    <headline :size="3" anchor-id="deckSortSection"
        ><icon name="alphabetically" />{{ $t("pages.dashboard.deck_sort.headline") }}</headline
    >
    <Form
        action="/deck-sort-default"
        method="post"
        class="form"
        :options="{ preserveScroll: true }"
        @success="onSuccess"
        #default="{ processing }"
    >
        <form-legend :items="[{ slot: 'intro', icon: 'info' }]">
            <template #intro>{{ $t("pages.dashboard.deck_sort.intro") }}</template>
        </form-legend>
        <form-group :label="$t('pages.dashboard.deck_sort.radio_label')" :required="true">
            <radio-button-group
                name="deck_sort_default"
                :radio-buttons="sortOptions"
                layout="row"
                @change="selectedSort = ($event.target as HTMLInputElement).value as DeckSort"
            />
        </form-group>
        <form-group for-id="apply_existing_sort" :label="$t('pages.dashboard.deck_sort.apply_existing')">
            <template #addon>
                <Switch
                    ref-id="apply_existing_sort"
                    :label="$t('pages.dashboard.deck_sort.apply_existing')"
                    :checked-initially="false"
                    @change="applyToExisting = $event"
                />
            </template>
            <template #text
                ><paragraph style="margin: 0">{{
                    $t("pages.dashboard.deck_sort.apply_existing_hint")
                }}</paragraph></template
            >
        </form-group>
        <form-group>
            <button type="submit" class="btn-default" :disabled="processing">
                <icon name="save" />
                {{ $t("pages.dashboard.deck_sort.submit") }}
                <loading-spinner v-if="processing" :size="2" />
            </button>
        </form-group>
    </Form>
</template>

<style scoped lang="scss">
.form {
    margin: 1em 0;
}
</style>
