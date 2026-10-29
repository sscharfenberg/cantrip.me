<script setup lang="ts">
/******************************************************************************
 * "Edit copies" on a deck card: finish, language, proxy flag — and for cards
 * in the collection, condition — of the card's copies, each edited where its
 * values live ("one owner at a time", `DeckCardCopiesService`):
 *
 *  - the copies no stack claims are the deck's **plan**; "Add all cards to
 *    collection" and "bought new" later create them exactly this way;
 *  - a copy a stack claims is a **card in the collection**; editing it
 *    edits that stack — and every deck claiming it — not the deck card.
 *
 * The dialog lists the groups — one per claimed stack, then the plan — and
 * edits the one picked; with a single group it opens on it directly.
 * "Apply to" changes only some copies of a group: planned ones split off
 * into a row of their own, a stack's split off into a stack of their own
 * that keeps every claim. While the collection master switch is off the
 * dialog, like the deck page, shows every copy as planned and never
 * mentions the collection.
 *
 * The copies come from `GET …/copies` when the dialog opens; saving PATCHes
 * the same URL and reloads the props an edit can change — the rows, the
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
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import Switch from "Components/Form/Switch.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";

/** One group of copies as the endpoint lists it. */
interface CopyGroup {
    /** The claimed stack, or null for the copies no stack claims — the plan. */
    stack_id: string | null;
    count: number;
    /** False when only all of them can change — "Apply to" is then not offered. */
    splittable: boolean;
    finish: string;
    language: string;
    proxy: boolean;
    /** A stack's condition; always null for the plan. */
    condition: string | null;
    /** The finishes the group's printing exists in. */
    finishes: string[];
    /** Where a stack's cards lie; null when unsorted, and for the plan. */
    container: { id: string; name: string } | null;
    /** Other decks claiming the stack — an edit changes those too. */
    other_decks: string[];
}

/** `GET /api/decks/{deck}/cards/{deckCard}/copies`. */
interface CopiesResponse {
    /** The collection master switch — while it is off, no copy is said to be in the collection. */
    integration: boolean;
    languages: string[];
    conditions: string[];
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
const conditions = ref<string[]>([]);
const claimed = ref(0);
const groups = ref<CopyGroup[]>([]);
/** Index of the group being edited. */
const selected = ref(0);

const group = computed<CopyGroup | null>(() => groups.value[selected.value] ?? null);
const plan = computed<CopyGroup | null>(() => groups.value.find(entry => entry.stack_id === null) ?? null);
const isStack = computed(() => group.value?.stack_id != null);

const count = ref(1);
const finish = ref("nonfoil");
const language = ref("en");
const proxy = ref(false);
const condition = ref<string | null>(null);

const processing = ref(false);
const saveFailed = ref(false);
const errors = ref<Record<string, string>>({});

/** Edit the group at `index`: the form starts on its values. */
const select = (index: number): void => {
    selected.value = index;
    const picked = groups.value[index];
    if (!picked) return;
    count.value = picked.count;
    finish.value = picked.finish;
    language.value = picked.language;
    proxy.value = picked.proxy;
    condition.value = picked.condition;
    errors.value = {};
    saveFailed.value = false;
};

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
        conditions.value = data.conditions;
        claimed.value = data.claimed;
        groups.value = data.groups;
        // The plan is what a deck is built around; open on it when there is one.
        const planIndex = data.groups.findIndex(entry => entry.stack_id === null);
        select(planIndex === -1 ? 0 : planIndex);
    } catch (e) {
        if (e instanceof DOMException && e.name === "AbortError") return;
        loadFailed.value = true;
    } finally {
        loading.value = false;
    }
});
onBeforeUnmount(() => abortController?.abort());

/** "2× Foil · Deutsch · Proxy · Near Mint — in Binder": one group at a glance. */
const summary = (entry: CopyGroup): string => {
    const parts = [
        t(`enums.finishes.${entry.finish}`),
        t(`enums.card_languages.${entry.language}`),
        ...(entry.proxy ? [t("form.fields.proxy.label")] : []),
        ...(entry.condition ? [t(`enums.conditions.${entry.condition}`)] : [])
    ];
    const where =
        entry.stack_id === null
            ? t("pages.deck.copies.where_planned")
            : (entry.container?.name ?? t("pages.collection.unsorted"));
    return `${entry.count}× ${parts.join(" · ")} — ${where}`;
};

const conditionOptions = computed(() =>
    conditions.value.map(value => ({ value, label: t(`enums.conditions.${value}`) }))
);

/** Keep "Apply to" inside 1 … the copies of the group. */
const setCount = (value: number): void => {
    const max = group.value?.count ?? 1;
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
    if (processing.value || group.value === null) return;
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
                ...(isStack.value ? { stack_id: group.value.stack_id, condition: condition.value } : {}),
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
                    t(
                        groups.length > 1 ? "pages.deck.copies.claimed" : "pages.deck.copies.claimed_here",
                        { count: claimed },
                        claimed
                    )
                }}</template>
            </form-legend>
            <form-group v-if="groups.length > 1" :label="t('pages.deck.copies.choose_label')">
                <ul
                    class="deck-copies-modal__groups"
                    role="radiogroup"
                    :aria-label="t('pages.deck.copies.choose_label')"
                >
                    <li v-for="(entry, index) in groups" :key="entry.stack_id ?? 'plan'">
                        <button
                            type="button"
                            role="radio"
                            class="deck-copies-modal__group"
                            :class="{ 'deck-copies-modal__group--selected': index === selected }"
                            :aria-checked="index === selected"
                            :disabled="processing"
                            @click="select(index)"
                        >
                            <icon :name="entry.stack_id === null ? 'planned' : 'storage'" :size="1" />
                            {{ summary(entry) }}
                        </button>
                    </li>
                </ul>
            </form-group>
            <div v-if="group" :key="selected" class="deck-copies-modal__fields">
                <form-legend
                    v-if="isStack"
                    :items="[
                        { slot: 'stack', icon: 'storage' },
                        ...(group.other_decks.length ? [{ slot: 'decks', icon: 'deck' }] : [])
                    ]"
                >
                    <template #stack>{{ t("pages.deck.copies.stack_note") }}</template>
                    <template #decks>{{
                        t("pages.deck.copies.other_decks", { decks: group.other_decks.join(", ") })
                    }}</template>
                </form-legend>
                <form-group
                    v-if="group.count > 1 && group.splittable"
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
                            :disabled="count >= group.count"
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
                    <template #text>{{
                        t(isStack ? "pages.deck.copies.count_hint_stack" : "pages.deck.copies.count_hint", {
                            total: group.count
                        })
                    }}</template>
                </form-group>
                <card-stack-finish
                    v-model="finish"
                    :finishes="group.finishes"
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
                    v-if="isStack"
                    :label="t('form.fields.condition')"
                    :error="errors.condition ?? errors.stack_id ?? ''"
                    :invalid="!!(errors.condition ?? errors.stack_id)"
                >
                    <mono-select
                        :options="conditionOptions"
                        :selected="condition ?? ''"
                        :placeholder="t('pages.deck.copies.condition_unset')"
                        :sort="false"
                        addon-icon="card"
                        max="100%"
                        @change="condition = $event || null"
                    />
                </form-group>
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
            </div>
        </form>
        <template #footer>
            <button type="button" class="btn-default" :disabled="processing" @click="emit('close')">
                <icon name="close" />
                {{ t("pages.deck.copies.cancel") }}
            </button>
            <button
                v-if="group"
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
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.deck-copies-modal {
    &__error {
        margin: 0;
    }

    &__groups {
        display: flex;
        flex-direction: column;

        padding: 0;
        margin: 0;
        gap: 0.5ex;

        list-style: none;
    }

    &__group {
        display: flex;
        align-items: center;

        width: 100%;
        padding: map.get(s.$components, "finish", "padding");
        border: map.get(s.$components, "finish", "border") solid map.get(c.$components, "finish", "border");
        gap: 1ch;

        background-color: map.get(c.$components, "finish", "background");
        color: inherit;
        border-radius: map.get(s.$components, "finish", "radius");

        text-align: start;

        cursor: pointer;

        &:hover {
            background-color: map.get(c.$components, "finish", "background-hover");
        }

        &--selected {
            background-color: map.get(c.$components, "finish", "background-checked");
            border-color: map.get(c.$components, "finish", "border-checked");
        }
    }

    &__fields {
        display: contents;
    }
}
</style>
