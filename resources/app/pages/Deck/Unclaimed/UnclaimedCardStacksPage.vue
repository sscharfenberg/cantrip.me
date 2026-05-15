<script setup lang="ts">
/******************************************************************************
 * UnclaimedCardStacks page (route `/decks/{deck}/unclaimed`).
 *
 * Lists every deck slot not yet covered for the request user. Mode B
 * counts in-deckbox stacks against the deck card's quantity; mode C
 * counts pivot coverage. Mode A is rejected by the FormRequest.
 *
 * Mode B: read-only. The user is expected to physically buy / move
 *         cards into the deck's container.
 * Mode C: per-row "I just bought this" checkbox + master toggle +
 *         submit. Submission posts to {@see DecksController::buyUnclaimed}
 *         which mints + claims a stack of the row's `unclaimed` size.
 *
 * Both modes show a "Download CSV" link pointing at the same-page
 * streaming export endpoint.
 *****************************************************************************/
import { Head, Link, useForm } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Checkbox from "Components/Form/Checkbox.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";

interface UnclaimedCard {
    id: string;
    name: string;
    set_code: string | null;
    collector_number: string | null;
    default_card_id: string;
    unclaimed: number;
    zone: string;
    role: string | null;
}

interface DeckSnapshot {
    id: string;
    name: string;
    container_id: string | null;
}

const props = defineProps<{
    deck: DeckSnapshot;
    mode: "A" | "B" | "C";
    cards: UnclaimedCard[];
}>();

const { t } = useI18n();
useBreadcrumbs().setBreadcrumbs([
    { labelKey: "pages.decks.link", href: "/decks", icon: "deck" },
    { label: props.deck.name, href: `/decks/${props.deck.id}` },
    { label: t("pages.deck.unclaimed.menu_link") }
]);

/** Per-row "I just bought this" checkboxes — only meaningful in mode C. */
const form = useForm<{ bought: string[] }>({ bought: [] });

const allChecked = computed({
    get: () => props.cards.length > 0 && form.bought.length === props.cards.length,
    set: (value: boolean) => {
        form.bought = value ? props.cards.map(c => c.id) : [];
    }
});

const someChecked = computed(() => form.bought.length > 0);

function toggleRow(cardId: string, checked: boolean): void {
    if (checked) {
        if (!form.bought.includes(cardId)) form.bought.push(cardId);
    } else {
        form.bought = form.bought.filter(id => id !== cardId);
    }
}

function isRowChecked(cardId: string): boolean {
    return form.bought.includes(cardId);
}

function onSubmit(): void {
    if (!someChecked.value) return;
    form.post(`/decks/${props.deck.id}/unclaimed/buy`);
}
</script>

<template>
    <Head>
        <title>{{ $t("pages.deck.unclaimed.title", { name: deck.name }) }}</title>
    </Head>
    <headline>
        <icon name="cards" :size="3" />
        {{ $t("pages.deck.unclaimed.heading", { name: deck.name }) }}
    </headline>

    <p v-if="cards.length === 0" class="unclaimed__empty">
        {{ $t("pages.deck.unclaimed.empty") }}
    </p>

    <template v-else>
        <p class="unclaimed__intro">
            {{
                mode === "B"
                    ? $t("pages.deck.unclaimed.intro_mode_b")
                    : $t("pages.deck.unclaimed.intro_mode_c")
            }}
        </p>

        <div class="unclaimed__toolbar">
            <a :href="`/decks/${deck.id}/unclaimed/export`" class="btn-default">
                <icon name="download" :size="1" />
                {{ $t("pages.deck.unclaimed.csv_link") }}
            </a>
        </div>

        <form v-if="mode === 'C'" @submit.prevent="onSubmit">
            <label class="unclaimed__master">
                <checkbox ref-id="bought-all" :checked-initially="allChecked" @change="allChecked = $event" />
                {{ $t("pages.deck.unclaimed.master_label") }}
            </label>

            <ul class="unclaimed__list">
                <li v-for="card in cards" :key="card.id" class="unclaimed__row">
                    <label class="unclaimed__row-label">
                        <checkbox
                            :ref-id="`bought-${card.id}`"
                            :checked-initially="isRowChecked(card.id)"
                            @change="toggleRow(card.id, $event)"
                        />
                        <strong>{{ card.unclaimed }}× {{ card.name }}</strong>
                        <span v-if="card.set_code" class="unclaimed__set">
                            {{ card.set_code.toUpperCase() }}:{{ card.collector_number }}
                        </span>
                    </label>
                </li>
            </ul>

            <div class="unclaimed__actions">
                <Link :href="`/decks/${deck.id}`" class="btn-default">
                    {{ $t("pages.deck.unclaimed.back") }}
                </Link>
                <button type="submit" class="btn-primary" :disabled="form.processing || !someChecked">
                    {{ $t("pages.deck.unclaimed.submit") }}
                </button>
            </div>
        </form>

        <ul v-else class="unclaimed__list unclaimed__list--readonly">
            <li v-for="card in cards" :key="card.id" class="unclaimed__row">
                <strong>{{ card.unclaimed }}× {{ card.name }}</strong>
                <span v-if="card.set_code" class="unclaimed__set">
                    {{ card.set_code.toUpperCase() }}:{{ card.collector_number }}
                </span>
            </li>
        </ul>
    </template>
</template>

<style scoped lang="scss">
.unclaimed {
    &__intro {
        margin: 0 0 1rem;
    }

    &__toolbar {
        display: flex;
        justify-content: flex-end;

        margin-bottom: 0.5rem;
    }

    &__master {
        display: flex;
        align-items: center;

        gap: 0.5rem;

        font-weight: bold;
    }

    &__list {
        display: flex;
        flex-direction: column;

        padding: 0;

        margin: 0.5rem 0;
        gap: 0.25rem;

        list-style: none;
    }

    &__row {
        display: flex;
        align-items: center;

        gap: 0.5rem;
    }

    &__row-label {
        display: flex;
        align-items: center;

        gap: 0.5rem;

        cursor: pointer;
    }

    &__set {
        opacity: 0.7;

        font-size: 0.875rem;
    }

    &__actions {
        display: flex;
        justify-content: flex-end;

        margin-top: 1rem;
        gap: 0.5rem;
    }

    &__empty {
        font-style: italic;
    }
}
</style>
