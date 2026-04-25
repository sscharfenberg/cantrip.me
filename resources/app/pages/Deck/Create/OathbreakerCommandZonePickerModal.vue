<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useTemplateRef, watch } from "vue";
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
    confirm: [planeswalker: CommanderResult, signatureSpell: CommanderResult];
}>();

// ── Shared state ────────────────────────────────────────────────────────────

const planeswalkerInput = useTemplateRef<HTMLInputElement>("planeswalkerInput");
const spellInput = useTemplateRef<HTMLInputElement>("spellInput");
/** True while a planeswalker API request is in flight. */
const loading = ref(false);
/** When true, skip legality filters (Rule 0). */
const rule0 = ref(false);
let debounceTimer: ReturnType<typeof setTimeout> | null = null;

// ── Step 1: Planeswalker search ─────────────────────────────────────────────

/** Current planeswalker search query bound to the input. */
const pwQuery = ref("");
/** Planeswalker results returned by the API. */
const pwResults = ref<CommanderResult[]>([]);
/** The currently selected planeswalker, or null when nothing is picked yet. */
const selectedPW = ref<CommanderResult | null>(null);

/**
 * Fetch planeswalkers matching the given query string.
 * Skips the request when the query is shorter than 2 characters.
 *
 * @param q - The search query.
 */
const fetchPlaneswalkers = async (q: string) => {
    if (q.length < 2) {
        pwResults.value = [];
        return;
    }
    loading.value = true;
    try {
        const params = new URLSearchParams({ q, format: props.format, type: "planeswalker" });
        if (rule0.value) params.set("rule0", "1");
        const response = await fetch(`/api/oathbreaker?${params}`);
        if (response.ok) {
            pwResults.value = await response.json();
        }
    } finally {
        loading.value = false;
    }
};

/** Debounce planeswalker search input — waits 750ms after the last keystroke before fetching. */
watch(pwQuery, value => {
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => fetchPlaneswalkers(value), 750);
});

/** Re-fetch immediately when Rule 0 is toggled (if there is a query). */
watch(rule0, () => fetchPlaneswalkers(pwQuery.value));

onMounted(() => planeswalkerInput.value?.focus());

/** Store the picked planeswalker and clear the search state. */
const onPWSelected = (card: CommanderResult) => {
    selectedPW.value = card;
    pwQuery.value = "";
    pwResults.value = [];
};

/** Clear the planeswalker (and signature spell) so the user can search again. */
const clearPW = async () => {
    selectedPW.value = null;
    selectedSpell.value = null;
    spellResults.value = [];
    await nextTick();
    planeswalkerInput.value?.focus();
};

// ── Step 2: Signature spell search ──────────────────────────────────────────

/** Current signature spell search query bound to the input. */
const spellQuery = ref("");
/** Signature spell results returned by the API. */
const spellResults = ref<CommanderResult[]>([]);
/** True while a signature spell API request is in flight. */
const spellLoading = ref(false);
/** The currently selected signature spell, or null when nothing is picked yet. */
const selectedSpell = ref<CommanderResult | null>(null);
let spellDebounceTimer: ReturnType<typeof setTimeout> | null = null;

/**
 * Fetch instants/sorceries matching the query, filtered by the selected planeswalker's color identity.
 * Skips the request when the query is shorter than 2 characters or no planeswalker is selected.
 *
 * @param q - The search query.
 */
const fetchSpells = async (q: string) => {
    if (q.length < 2 || !selectedPW.value) {
        spellResults.value = [];
        return;
    }
    spellLoading.value = true;
    try {
        const params = new URLSearchParams({
            q,
            format: props.format,
            type: "spell",
            ci: selectedPW.value.color_identity ?? "",
        });
        if (rule0.value) params.set("rule0", "1");
        params.set("exclude", selectedPW.value.id);
        const response = await fetch(`/api/oathbreaker?${params}`);
        if (response.ok) {
            spellResults.value = await response.json();
        }
    } finally {
        spellLoading.value = false;
    }
};

/** Debounce signature spell search input — waits 750ms after the last keystroke before fetching. */
watch(spellQuery, value => {
    if (spellDebounceTimer) clearTimeout(spellDebounceTimer);
    spellDebounceTimer = setTimeout(() => fetchSpells(value), 750);
});

/** Focus the spell input when a planeswalker is selected. */
watch(selectedPW, async card => {
    if (card) {
        await nextTick();
        spellInput.value?.focus();
    }
});

/** Store the picked signature spell and clear the spell search state. */
const onSpellSelected = (card: CommanderResult) => {
    selectedSpell.value = card;
    spellQuery.value = "";
    spellResults.value = [];
};

/** Clear the signature spell selection so the user can search again. */
const clearSpell = async () => {
    selectedSpell.value = null;
    await nextTick();
    spellInput.value?.focus();
};

// ── Confirm ─────────────────────────────────────────────────────────────────

/** True while any API request (planeswalker or spell) is in flight. */
const processing = computed(() => loading.value || spellLoading.value);
/** True when both a planeswalker and a signature spell have been selected. */
const canSubmit = computed(() => !!selectedPW.value && !!selectedSpell.value);

/** Emit the confirmed command zone and close the modal. */
const onConfirm = () => {
    if (!selectedPW.value || !selectedSpell.value) return;
    emit("confirm", selectedPW.value, selectedSpell.value);
    emit("close");
};
</script>

<template>
    <modal @close="$emit('close')">
        <template #header>{{ $t("components.oathbreaker_picker.title") }}</template>
        <div class="form">
            <!-- Step 1: Planeswalker (oathbreaker) -->
            <template v-if="selectedPW">
                <form-group :label="$t('components.oathbreaker_picker.selected_planeswalker')">
                    <div class="commander-picker__commander commander-picker__commander--selected">
                        <show-commander-overview tooltip-container="#modal" :card="selectedPW" />
                    </div>
                </form-group>
                <form-group>
                    <button type="button" class="btn-default" @click="clearPW">
                        <icon name="register" />
                        {{ $t("components.oathbreaker_picker.change_planeswalker") }}
                    </button>
                </form-group>
            </template>
            <template v-else>
                <form-group
                    :label="$t('components.oathbreaker_picker.search_planeswalker')"
                    :required="true"
                    :validating="processing"
                    addon-icon="register"
                    for-id="oathbreaker_pw"
                >
                    <input
                        ref="planeswalkerInput"
                        v-model="pwQuery"
                        type="text"
                        name="oathbreaker_pw"
                        id="oathbreaker_pw"
                        class="form-input"
                        autocomplete="off"
                    />
                </form-group>
                <form-group>
                    <label class="rule0" for="include_rule0">
                        <checkbox ref-id="include_rule0" @change="rule0 = $event" />
                        {{ $t("components.oathbreaker_picker.include_rule0") }}
                    </label>
                </form-group>
                <search-syntax v-if="!pwResults.length" />
                <nav v-if="pwResults.length" class="commander-picker__wrapper">
                    <button
                        class="commander-picker__commander"
                        v-for="card in pwResults"
                        :key="card.id"
                        @click="onPWSelected(card)"
                    >
                        <show-commander-overview tooltip-container="#modal" :card="card" />
                    </button>
                </nav>
                <paragraph v-else-if="pwQuery.length >= 2 && !loading">
                    {{ $t("components.oathbreaker_picker.no_planeswalkers") }}
                </paragraph>
            </template>

            <!-- Step 2: Signature spell (only after planeswalker is selected) -->
            <template v-if="selectedPW">
                <template v-if="selectedSpell">
                    <form-group :label="$t('components.oathbreaker_picker.selected_spell')">
                        <div class="commander-picker__commander commander-picker__commander--selected">
                            <show-commander-overview tooltip-container="#modal" :card="selectedSpell" />
                        </div>
                    </form-group>
                    <form-group>
                        <button type="button" class="btn-default" @click="clearSpell">
                            <icon name="spell" />
                            {{ $t("components.oathbreaker_picker.change_spell") }}
                        </button>
                    </form-group>
                </template>
                <template v-else>
                    <form-group
                        :label="$t('components.oathbreaker_picker.search_spell')"
                        :required="true"
                        :validating="spellLoading"
                        addon-icon="spell"
                        for-id="oathbreaker_spell"
                    >
                        <input
                            ref="spellInput"
                            v-model="spellQuery"
                            type="text"
                            name="oathbreaker_spell"
                            id="oathbreaker_spell"
                            class="form-input"
                            autocomplete="off"
                        />
                    </form-group>
                    <nav v-if="spellResults.length" class="commander-picker__wrapper">
                        <button
                            class="commander-picker__commander"
                            v-for="card in spellResults"
                            :key="card.id"
                            @click="onSpellSelected(card)"
                        >
                            <show-commander-overview tooltip-container="#modal" :card="card" />
                        </button>
                    </nav>
                    <paragraph v-else-if="spellQuery.length >= 2 && !spellLoading">
                        {{ $t("components.oathbreaker_picker.no_spells") }}
                    </paragraph>
                </template>
            </template>
        </div>
        <template #footer>
            <button type="button" class="btn-primary" :disabled="!canSubmit" @click="onConfirm">
                <icon name="save" />
                {{ $t("components.oathbreaker_picker.confirm") }}
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