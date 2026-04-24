<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useTemplateRef, watch } from "vue";
import { isCompanionCard } from "@/utils/companionNames";
import SearchSyntax from "Components/Card/SearchSyntax.vue";
import type { CommanderResult } from "Components/Deck/ShowCommanderOverview.vue";
import ShowCommanderOverview from "Components/Deck/ShowCommanderOverview.vue";
import Checkbox from "Components/Form/Checkbox.vue";
import FormGroup from "Components/Form/FormGroup.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import Paragraph from "Components/UI/Paragraph.vue";
const props = defineProps<{ format: string }>();
const emit = defineEmits<{
    /** Fired when the modal should be dismissed. */
    close: [];
    /** Fired when the user confirms the command zone selection. */
    confirm: [commander: CommanderResult, companion: CommanderResult | null];
}>();
const searchInput = useTemplateRef<HTMLInputElement>("searchInput");
/** Current search query bound to the input. */
const query = ref("");
/** Commander results returned by the API. */
const results = ref<CommanderResult[]>([]);
/** True while an API request is in flight. */
const loading = ref(false);
/** When true, skip commander-legality filters (Rule 0). */
const rule0 = ref(false);
let debounceTimer: ReturnType<typeof setTimeout> | null = null;
/**
 * Fetch commanders matching the given query string.
 * Skips the request when the query is shorter than 2 characters.
 *
 * @param q - The search query.
 */
const fetchCommanders = async (q: string) => {
    if (q.length < 2) {
        results.value = [];
        return;
    }
    loading.value = true;
    try {
        const params = new URLSearchParams({ q, format: props.format });
        if (rule0.value) params.set("rule0", "1");
        const response = await fetch(`/api/commander?${params}`);
        if (response.ok) {
            results.value = await response.json();
        }
    } finally {
        loading.value = false;
    }
};
/** Debounce search input — waits 500ms after the last keystroke before fetching. */
watch(query, value => {
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => fetchCommanders(value), 750);
});
/** Re-fetch immediately when Rule 0 is toggled (if there is a query). */
watch(rule0, () => fetchCommanders(query.value));
onMounted(() => searchInput.value?.focus());
/** The currently selected commander, or null when nothing is picked yet. */
const selected = ref<CommanderResult | null>(null);
/** Store the picked commander and clear the search state. */
const onCommanderSelected = (card: CommanderResult) => {
    selected.value = card;
    query.value = "";
    results.value = [];
};
/** Clear the selection so the user can search again. */
const clearSelection = async () => {
    selected.value = null;
    selectedCompanion.value = null;
    partnerWithCard.value = null;
    await nextTick();
    searchInput.value?.focus();
};

// ── Partner with (predetermined partner) ────────────────────────────────────

/** The resolved "Partner with X" card, shown as a selectable option. */
const partnerWithCard = ref<CommanderResult | null>(null);

/** When a "Partner with X" commander is selected, fetch the named partner. */
watch(selected, async card => {
    partnerWithCard.value = null;
    if (card?.companion_type !== "partner_with" || !card.partner_with_name) return;
    loading.value = true;
    try {
        const params = new URLSearchParams({ q: card.partner_with_name, format: props.format });
        params.set("exclude", card.id);
        const response = await fetch(`/api/commander?${params}`);
        if (response.ok) {
            const results: CommanderResult[] = await response.json();
            partnerWithCard.value = results.find(r => r.name === card.partner_with_name) ?? null;
        }
    } finally {
        loading.value = false;
    }
});

// ── Companion search (partner or background) ───────────────────────────────

const companionInput = useTemplateRef<HTMLInputElement>("companionInput");
/** Current companion search query bound to the input. */
const companionQuery = ref("");
/** Companion results returned by the API. */
const companionResults = ref<CommanderResult[]>([]);
/** True while a companion API request is in flight. */
const companionLoading = ref(false);
/** The currently selected companion, or null when nothing is picked yet. */
const selectedCompanion = ref<CommanderResult | null>(null);
let companionDebounceTimer: ReturnType<typeof setTimeout> | null = null;

/** Companion types with small enough card pools to list without searching. */
const smallPoolTypes = new Set(["friends_forever", "doctors_companion", "partner_type"]);
/** Whether the selected commander's companion type has a small card pool. */
const isSmallPool = computed(() => smallPoolTypes.has(selected.value?.companion_type ?? ""));

/** Build the companion filter params for the selected commander. */
const appendCompanionParams = (params: URLSearchParams) => {
    if (!selected.value) return;
    params.set("exclude", selected.value.id);
    if (selected.value.companion_type === "partner_type") {
        params.set("partner_type", selected.value.partner_with_name ?? "");
    } else {
        const companionTypeParam: Record<string, string> = {
            background: "background",
            friends_forever: "friends_forever",
            doctors_companion: "doctors_companion"
        };
        params.set(companionTypeParam[selected.value.companion_type ?? ""] ?? "partner", "1");
    }
};

/** Fetch all companions for small-pool types (no search query needed). */
const fetchAllCompanions = async () => {
    companionLoading.value = true;
    try {
        const params = new URLSearchParams({ format: props.format });
        appendCompanionParams(params);
        const response = await fetch(`/api/commander?${params}`);
        if (response.ok) {
            companionResults.value = await response.json();
        }
    } finally {
        companionLoading.value = false;
    }
};

/** Auto-fetch all companions when a small-pool commander is selected. */
watch(selected, card => {
    companionResults.value = [];
    if (card && smallPoolTypes.has(card.companion_type ?? "")) {
        fetchAllCompanions();
    }
});

/** Fetch companion cards matching the query, excluding the selected commander. */
const fetchCompanions = async (q: string) => {
    if (q.length < 2) {
        companionResults.value = [];
        return;
    }
    companionLoading.value = true;
    try {
        const params = new URLSearchParams({ q, format: props.format });
        appendCompanionParams(params);
        const response = await fetch(`/api/commander?${params}`);
        if (response.ok) {
            companionResults.value = await response.json();
        }
    } finally {
        companionLoading.value = false;
    }
};
/** Debounce companion search input — waits 750ms after the last keystroke before fetching. */
watch(companionQuery, value => {
    if (companionDebounceTimer) clearTimeout(companionDebounceTimer);
    companionDebounceTimer = setTimeout(() => fetchCompanions(value), 750);
});

/** Store the picked companion and clear the companion search state. */
const onCompanionSelected = (card: CommanderResult) => {
    selectedCompanion.value = card;
    companionQuery.value = "";
    companionResults.value = [];
};
/** Clear the companion selection so the user can search again. */
const clearCompanion = async () => {
    selectedCompanion.value = null;
    if (isSmallPool.value) {
        fetchAllCompanions();
    } else {
        await nextTick();
        companionInput.value?.focus();
    }
};
/** True while any API request (commander or companion) is in flight. */
const processing = computed(() => loading.value || companionLoading.value);
/**
 * True when the picked commander is one of the ten "Companion" keyword cards.
 * Used to surface a helper note clarifying that using one as commander does
 * NOT make it your companion — a separate companion may still be attached.
 */
const commanderIsCompanionCard = computed(
    () => selected.value !== null && isCompanionCard(selected.value.name)
);
/** True when a commander has been selected. Partner/background/partner-with are all optional. */
const canSubmit = computed(() => !!selected.value);
/** Emit the confirmed command zone and close the modal. */
const onConfirm = () => {
    if (!selected.value) return;
    emit("confirm", selected.value, selectedCompanion.value);
    emit("close");
};
</script>

<template>
    <modal @close="$emit('close')">
        <template #header>{{ $t("components.commander_picker.title") }}</template>
        <div class="form">
            <template v-if="selected">
                <form-group :label="$t('components.commander_picker.selected')">
                    <div class="commander-picker__commander commander-picker__commander--selected">
                        <show-commander-overview tooltip-container="#modal" :card="selected" />
                    </div>
                </form-group>
                <paragraph v-if="commanderIsCompanionCard">
                    {{ $t("components.commander_picker.is_also_companion_hint", { name: selected.name }) }}
                </paragraph>
                <form-group>
                    <button type="button" class="btn-default" @click="clearSelection">
                        <icon name="register" />
                        {{
                            $t(
                                selectedCompanion && selected.companion_type
                                    ? `components.commander_picker.change_commander_and_${selected.companion_type === "partner_with" || selected.companion_type === "partner_type" ? "partner" : selected.companion_type}`
                                    : "components.commander_picker.change"
                            )
                        }}
                    </button>
                </form-group>
                <!-- Partner with X: show the predetermined partner as a selectable option -->
                <template v-if="selected.companion_type === 'partner_with'">
                    <template v-if="selectedCompanion">
                        <form-group :label="$t('components.commander_picker.partner_selected')">
                            <div class="commander-picker__commander commander-picker__commander--selected">
                                <show-commander-overview tooltip-container="#modal" :card="selectedCompanion" />
                            </div>
                        </form-group>
                        <form-group>
                            <button type="button" class="btn-default" @click="clearCompanion">
                                <icon name="partner" />
                                {{ $t("components.commander_picker.partner_change") }}
                            </button>
                        </form-group>
                    </template>
                    <template v-else-if="partnerWithCard">
                        <form-group :label="$t('components.commander_picker.partner_selected')">
                            <button type="button" class="btn-default" @click="onCompanionSelected(partnerWithCard)">
                                <icon name="partner" />
                                {{
                                    $t("components.commander_picker.partner_with_label", {
                                        name: selected.partner_with_name
                                    })
                                }}
                            </button>
                        </form-group>
                    </template>
                </template>
                <!-- Partner / Partner—Type / Friends forever / Doctor's companion / Background: search for a companion -->
                <template v-else-if="selected.companion_type">
                    <template v-if="selectedCompanion">
                        <form-group :label="$t(`components.commander_picker.${selected.companion_type}_selected`)">
                            <div class="commander-picker__commander commander-picker__commander--selected">
                                <show-commander-overview tooltip-container="#modal" :card="selectedCompanion" />
                            </div>
                        </form-group>
                        <form-group>
                            <button type="button" class="btn-default" @click="clearCompanion">
                                <icon :name="selected.companion_type === 'background' ? 'spell' : 'partner'" />
                                {{ $t(`components.commander_picker.${selected.companion_type}_change`) }}
                            </button>
                        </form-group>
                    </template>
                    <template v-else-if="isSmallPool">
                        <nav v-if="companionResults.length" class="commander-picker__wrapper">
                            <div class="commander-picker__heading">
                                <span>{{ $t(`components.commander_picker.${selected.companion_type}_select`) }}</span>
                                <span
                                    >{{ $t("components.commander_picker.color_identity") }}<br />{{
                                        $t("components.commander_picker.partner_type")
                                    }}</span
                                >
                            </div>
                            <button
                                class="commander-picker__commander"
                                v-for="card in companionResults"
                                :key="card.id"
                                @click="onCompanionSelected(card)"
                            >
                                <show-commander-overview tooltip-container="#modal" :card="card" />
                            </button>
                        </nav>
                        <paragraph v-else-if="!companionLoading">
                            {{ $t(`components.commander_picker.${selected.companion_type}_no_results`) }}
                        </paragraph>
                    </template>
                    <template v-else>
                        <form-group
                            :label="$t(`components.commander_picker.${selected.companion_type}_search`)"
                            :validating="processing"
                            :addon-icon="selected.companion_type === 'background' ? 'background' : 'partner'"
                            for-id="companion_id"
                        >
                            <input
                                ref="companionInput"
                                v-model="companionQuery"
                                type="text"
                                name="companion_id"
                                id="companion_id"
                                class="form-input"
                                autocomplete="off"
                            />
                        </form-group>
                        <nav v-if="companionResults.length" class="commander-picker__wrapper">
                            <button
                                class="commander-picker__commander"
                                v-for="card in companionResults"
                                :key="card.id"
                                @click="onCompanionSelected(card)"
                            >
                                <show-commander-overview tooltip-container="#modal" :card="card" />
                            </button>
                        </nav>
                        <paragraph v-else-if="companionQuery.length >= 2 && !companionLoading">
                            {{ $t(`components.commander_picker.${selected.companion_type}_no_results`) }}
                        </paragraph>
                    </template>
                </template>
            </template>
            <template v-else>
                <form-group
                    :label="$t('pages.create_deck.commander.search')"
                    :required="true"
                    :validating="processing"
                    addon-icon="register"
                    for-id="commander_id"
                >
                    <input
                        ref="searchInput"
                        v-model="query"
                        type="text"
                        name="commander_id"
                        id="commander_id"
                        class="form-input"
                        autocomplete="off"
                    />
                </form-group>
                <form-group>
                    <label class="rule0" for="include_rule0">
                        <checkbox ref-id="include_rule0" @change="rule0 = $event" />
                        {{ $t("components.commander_picker.include_rule0") }}
                    </label>
                </form-group>
                <search-syntax v-if="!results.length" />
                <nav v-if="results.length" class="commander-picker__wrapper">
                    <button
                        class="commander-picker__commander"
                        v-for="card in results"
                        :key="card.id"
                        @click="onCommanderSelected(card)"
                    >
                        <show-commander-overview tooltip-container="#modal" :card="card" />
                    </button>
                </nav>
                <paragraph v-else-if="query.length >= 2 && !loading">{{
                    $t("components.commander_picker.no_results")
                }}</paragraph>
            </template>
        </div>
        <template #footer>
            <button type="button" class="btn-primary" :disabled="!canSubmit" @click="onConfirm">
                <icon name="save" />
                {{ $t("components.commander_picker.confirm") }}
            </button>
        </template>
    </modal>
</template>

<style lang="scss" scoped>
.rule0 {
    display: flex;
    align-items: center;

    gap: 1rem;
}
</style>
