<script setup lang="ts">
/******************************************************************************
 * "Edit copies" on a deck card: finish, language and proxy flag of the
 * copies that are not in the collection yet — the deck's plan for them
 * (`DeckCardCopiesService`). "Add all cards to collection" and "bought
 * new" later create the copies exactly this way.
 *
 * "Apply to" re-plans only some of them: those split off into a row of
 * their own, so "2 of my 4 Bolts are foil" is two rows. Copies a stack
 * claims are facts about cards in the collection and are named here, not
 * edited. While the collection master switch is off the dialog, like the
 * deck page, shows every copy as planned and never mentions the collection.
 *
 * The copies come from `GET …/copies` when the dialog opens; saving PATCHes
 * the same URL and reloads the props a re-plan can change — the rows, the
 * deck's worth, which prices each copy by its finish, and the violations,
 * which name rows by id and a split or fold creates and deletes rows.
 *****************************************************************************/
import { router, usePage } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import CardStackFinish from "@/pages/Collection/CardStack/CardStackFinish.vue";
import CardStackLanguage from "@/pages/Collection/CardStack/CardStackLanguage.vue";
import FormGroup from "Components/Form/FormGroup.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import Switch from "Components/Form/Switch.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";

/** One group of copies as the endpoint lists it. */
interface CopyGroup {
    /** Null for the copies no stack claims — the plan. */
    stack_id: string | null;
    count: number;
    /** False when only all of them can change — "Apply to" is then not offered. */
    splittable: boolean;
    finish: string;
    language: string;
    proxy: boolean;
    /** The finishes the printing exists in. */
    finishes: string[];
}

/** `GET /api/decks/{deck}/cards/{deckCard}/copies`. */
interface CopiesResponse {
    /** The collection master switch — while it is off, no copy is said to be in the collection. */
    integration: boolean;
    languages: string[];
    /** Copies a stack claims. */
    claimed: number;
    groups: CopyGroup[];
}

const props = defineProps<{
    deckId: string;
    deckCardId: string;
    /** Card name, interpolated into the title. */
    name: string;
}>();
/** @emits close — Fired when the dialog should be dismissed (cancelled or saved). */
const emit = defineEmits<{ close: [] }>();
const page = usePage();
const { t } = useI18n();

const url = computed(() => `/api/decks/${props.deckId}/cards/${props.deckCardId}/copies`);

const loading = ref(true);
const loadFailed = ref(false);
const integration = ref(true);
const languages = ref<string[]>([]);
const claimed = ref(0);
/** The copies being edited — the plan. Null when every copy is claimed. */
const plan = ref<CopyGroup | null>(null);

const count = ref(1);
const finish = ref("nonfoil");
const language = ref("en");
const proxy = ref(false);

const processing = ref(false);
const saveFailed = ref(false);
const errors = ref<Record<string, string>>({});

let abortController: AbortController | null = null;

onMounted(async () => {
    abortController = new AbortController();
    try {
        const response = await fetch(url.value, {
            headers: { Accept: "application/json" },
            signal: abortController.signal
        });
        if (!response.ok) {
            loadFailed.value = true;
            return;
        }
        const data = (await response.json()) as CopiesResponse;
        integration.value = data.integration;
        languages.value = data.languages;
        claimed.value = data.claimed;
        plan.value = data.groups.find(group => group.stack_id === null) ?? null;
        if (plan.value) {
            count.value = plan.value.count;
            finish.value = plan.value.finish;
            language.value = plan.value.language;
            proxy.value = plan.value.proxy;
        }
    } catch (e) {
        if (e instanceof DOMException && e.name === "AbortError") return;
        loadFailed.value = true;
    } finally {
        loading.value = false;
    }
});
onBeforeUnmount(() => abortController?.abort());

/** Keep "Apply to" inside 1 … the copies on offer. */
const setCount = (value: number): void => {
    const max = plan.value?.count ?? 1;
    count.value = Math.min(Math.max(Math.round(Number(value) || 1), 1), max);
};

/**
 * A typed count, clamped. The field is written back too: typing 9 where 4
 * is the most leaves `count` at 4 — no change, so no re-render — and the
 * field would go on showing the 9.
 */
const onCountChange = (event: Event): void => {
    const input = event.target as HTMLInputElement;
    setCount(Number(input.value));
    input.value = String(count.value);
};

async function onSubmit(): Promise<void> {
    if (processing.value || plan.value === null) return;
    processing.value = true;
    saveFailed.value = false;
    errors.value = {};
    try {
        const response = await fetch(url.value, {
            method: "PATCH",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": page.props.csrfToken as string,
                Accept: "application/json"
            },
            body: JSON.stringify({
                count: count.value,
                finish: finish.value,
                language: language.value,
                proxy: proxy.value
            })
        });
        if (response.status === 422) {
            const body = (await response.json()) as { errors?: Record<string, string[]> };
            errors.value = Object.fromEntries(
                Object.entries(body.errors ?? {}).map(([field, messages]) => [field, messages[0]])
            );
            return;
        }
        if (!response.ok) {
            saveFailed.value = true;
            return;
        }
        router.reload({ only: ["cards", "commanders", "companion", "deck", "violations"] });
        emit("close");
    } catch {
        saveFailed.value = true;
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ t("pages.deck.copies.title", { name }) }}</template>
        <loading-spinner v-if="loading" :size="3" />
        <p v-else-if="loadFailed" class="deck-copies-modal__error">{{ t("pages.deck.copies.load_error") }}</p>
        <form v-else id="deck-card-copies-form" class="form" @submit.prevent="onSubmit">
            <form-legend
                :items="[
                    ...(plan ? [{ slot: 'planned', icon: 'planned' }] : []),
                    ...(claimed > 0 ? [{ slot: 'claimed', icon: 'storage' }] : [])
                ]"
            >
                <template #planned>{{
                    integration
                        ? t("pages.deck.copies.planned", { count: plan?.count ?? 0 }, plan?.count ?? 0)
                        : t("pages.deck.copies.planned_plain")
                }}</template>
                <template #claimed>{{
                    plan
                        ? t("pages.deck.copies.claimed", { count: claimed }, claimed)
                        : t("pages.deck.copies.all_claimed")
                }}</template>
            </form-legend>
            <template v-if="plan">
                <form-group
                    v-if="plan.count > 1 && plan.splittable"
                    for-id="deck-card-copies-count"
                    :label="t('pages.deck.copies.count_label')"
                    :error="errors.count ?? ''"
                    :invalid="!!errors.count"
                    addon-icon="deck"
                >
                    <input
                        id="deck-card-copies-count"
                        type="text"
                        class="form-input"
                        inputmode="numeric"
                        :value="count"
                        @change="onCountChange"
                    />
                    <template #button>
                        <button
                            type="button"
                            :disabled="count >= plan.count"
                            tabindex="-1"
                            @mousedown.prevent
                            @click="setCount(count + 1)"
                        >
                            <icon name="add" />
                        </button>
                        <button
                            type="button"
                            :disabled="count <= 1"
                            tabindex="-1"
                            @mousedown.prevent
                            @click="setCount(count - 1)"
                        >
                            <icon name="subtract" />
                        </button>
                    </template>
                    <template #text>{{ t("pages.deck.copies.count_hint", { total: plan.count }) }}</template>
                </form-group>
                <card-stack-finish
                    v-model="finish"
                    :finishes="plan.finishes"
                    :error="errors.finish ?? ''"
                    :invalid="!!errors.finish"
                />
                <card-stack-language
                    v-model="language"
                    :languages="languages"
                    :error="errors.language ?? ''"
                    :invalid="!!errors.language"
                />
                <form-group
                    :label="t('form.fields.proxy.label')"
                    :error="saveFailed ? t('pages.deck.copies.error') : ''"
                    :invalid="saveFailed"
                >
                    <Switch
                        ref-id="deck-card-copies-proxy"
                        value="1"
                        :label="t('pages.deck.copies.proxy_toggle')"
                        :checked-initially="proxy"
                        @change="proxy = $event"
                    />
                </form-group>
            </template>
        </form>
        <template #footer>
            <button type="button" class="btn-default" :disabled="processing" @click="emit('close')">
                <icon name="close" />
                {{ t("pages.deck.copies.cancel") }}
            </button>
            <button
                v-if="plan"
                type="submit"
                form="deck-card-copies-form"
                class="btn-primary"
                :disabled="processing || loading"
            >
                <icon name="save" />
                {{ t("pages.deck.copies.submit") }}
                <loading-spinner v-if="processing" :size="2" />
            </button>
        </template>
    </modal>
</template>

<style lang="scss" scoped>
.deck-copies-modal__error {
    margin: 0;
}
</style>
