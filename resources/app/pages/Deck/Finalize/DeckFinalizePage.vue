<script setup lang="ts">
/******************************************************************************
 * Finalize-deck wizard (planned → built transition).
 *
 * Reached only by mode-B / mode-C owners — mode A patches the state
 * directly from the actions menu without ever opening this page. The
 * payload shape mirrors what `DeckFinalizeService::persistAssignments`
 * expects: `assignments[deckCardId][] = stackId`, plus an optional
 * `container_id` for the deck's deckbox.
 *****************************************************************************/
import { Head, Link, useForm } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import FaceImageLazy from "@/pages/Deck/Cards/FaceImageLazy.vue";
import FormGroup from "Components/Form/FormGroup.vue";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";

interface FinalizeStackOption {
    id: string;
    amount: number;
    container: { id: string; name: string; type: string } | null;
}
interface FinalizeCard {
    id: string;
    name: string;
    quantity: number;
    set_code: string | null;
    collector_number: string | null;
    card_image_0: string | null;
    card_image_1: string | null;
    available: FinalizeStackOption[];
}
interface FinalizeContainer {
    id: string;
    name: string;
    type: string;
    is_deckbox: boolean;
}
interface DeckSnapshot {
    id: string;
    name: string;
    container_id: string | null;
}

const props = defineProps<{
    deck: DeckSnapshot;
    cards: FinalizeCard[];
    containers: FinalizeContainer[];
}>();

const { t } = useI18n();

useBreadcrumbs().setBreadcrumbs([
    { labelKey: "pages.decks.link", href: "/decks", icon: "deck" },
    { label: props.deck.name, href: `/decks/${props.deck.id}` },
    { labelKey: "pages.deck.finalize.title", params: { name: props.deck.name } }
]);

/**
 * Form state. `assignments` is keyed by deck_card_id with an array of
 * picked stack ids. Initialised empty — every row defaults to "skip".
 */
const initialAssignments: Record<string, string[]> = Object.fromEntries(
    props.cards.map(card => [card.id, []])
);

const form = useForm<{
    assignments: Record<string, string[]>;
    container_id: string | null;
}>({
    assignments: initialAssignments,
    container_id: props.deck.container_id
});

/** Container options for MonoSelect, deckboxes pinned to the top with a hint suffix. */
const containerOptions = computed(() => [
    ...props.containers.map(c => ({
        value: c.id,
        label: c.is_deckbox ? `${c.name} — ${t("pages.deck.finalize.deckbox_hint")}` : c.name
    }))
]);

/** Build the option list for one card's available-stacks select. */
function stackOptions(card: FinalizeCard): { value: string; label: string }[] {
    return card.available.map(stack => {
        if (stack.container === null) {
            return {
                value: stack.id,
                label: t("pages.deck.finalize.stack_unsorted", { amount: stack.amount })
            };
        }
        return {
            value: stack.id,
            label: t("pages.deck.finalize.stack_label", {
                type: t(`enums.container_type.${stack.container.type}`),
                name: stack.container.name,
                amount: stack.amount
            })
        };
    });
}

/** Sum of `amount` across the user's currently picked stacks for one row. */
function claimedFor(card: FinalizeCard): number {
    const ids = form.assignments[card.id] ?? [];
    return card.available
        .filter(stack => ids.includes(stack.id))
        .reduce((sum, stack) => sum + stack.amount, 0);
}

function onPickStack(cardId: string, value: string): void {
    if (!value) {
        form.assignments[cardId] = [];
        return;
    }
    form.assignments[cardId] = [value];
}

function onSubmit(): void {
    form.post(`/decks/${props.deck.id}/finalize`);
}

/** Skip path — submit an empty payload, server transitions state without writing pivot rows. */
function onSkip(): void {
    form.transform(() => ({ assignments: {}, container_id: null }))
        .post(`/decks/${props.deck.id}/finalize`);
}
</script>

<template>
    <Head>
        <title>{{ $t("pages.deck.finalize.title", { name: deck.name }) }}</title>
    </Head>
    <section class="finalize">
        <headline>
            <icon name="finished" :size="3" />
            {{ $t("pages.deck.finalize.heading") }}
        </headline>
        <paragraph>{{ $t("pages.deck.finalize.intro") }}</paragraph>
        <form class="finalize__form" @submit.prevent="onSubmit">
            <ul class="finalize__list">
                <li v-for="card in cards" :key="card.id" class="finalize__row">
                    <div class="finalize__needed">
                        <face-image-lazy
                            :card-image0="card.card_image_0"
                            :card-image1="card.card_image_1"
                            :name="card.name"
                            class="finalize__thumb"
                        />
                        <div class="finalize__needed-text">
                            <strong>{{ card.quantity }}× {{ card.name }}</strong>
                            <span v-if="card.set_code" class="finalize__set">
                                {{ card.set_code.toUpperCase() }}:{{ card.collector_number }}
                            </span>
                        </div>
                    </div>
                    <div class="finalize__assign">
                        <span v-if="card.available.length === 0" class="finalize__no-match">
                            {{ $t("pages.deck.finalize.no_match") }}
                        </span>
                        <mono-select
                            v-else
                            :options="stackOptions(card)"
                            :selected="form.assignments[card.id]?.[0] ?? ''"
                            :placeholder="$t('pages.deck.finalize.col_assignment')"
                            :sort="false"
                            addon-icon="cards"
                            max="100%"
                            @change="onPickStack(card.id, $event)"
                        />
                        <span
                            v-if="claimedFor(card) > 0 && claimedFor(card) < card.quantity"
                            class="finalize__partial"
                        >
                            {{
                                $t("pages.deck.finalize.partial_coverage", {
                                    claimed: claimedFor(card),
                                    needed: card.quantity
                                })
                            }}
                        </span>
                    </div>
                </li>
            </ul>
            <form-group :label="$t('pages.deck.finalize.container_heading')">
                <mono-select
                    :options="containerOptions"
                    :selected="form.container_id ?? ''"
                    :placeholder="$t('pages.deck.finalize.container_unset')"
                    :sort="false"
                    addon-icon="container-image"
                    max="100%"
                    @change="form.container_id = $event || null"
                />
                <template #text>
                    {{ $t("pages.deck.finalize.container_hint") }}
                </template>
            </form-group>
            <div class="finalize__actions">
                <Link :href="`/decks/${deck.id}`" class="button button--secondary">
                    {{ $t("pages.deck.finalize.back") }}
                </Link>
                <button type="button" class="button button--secondary" :disabled="form.processing" @click="onSkip">
                    {{ $t("pages.deck.finalize.skip") }}
                </button>
                <button type="submit" class="button button--primary" :disabled="form.processing">
                    {{ $t("pages.deck.finalize.submit") }}
                </button>
            </div>
        </form>
    </section>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.finalize {
    display: flex;
    flex-direction: column;

    max-width: 60rem;
    padding: 1rem;

    margin: 0 auto;
    gap: 1rem;
}

.finalize__form {
    display: flex;
    flex-direction: column;

    gap: 1.5rem;
}

.finalize__list {
    display: flex;
    flex-direction: column;

    padding: 0;

    margin: 0;
    gap: 0.75rem;

    list-style: none;
}

.finalize__row {
    display: grid;
    align-items: center;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);

    padding: 0.75rem;
    border: 1px solid map.get(c.$pages, "deck", "card", "border");
    gap: 1rem;

    background-color: map.get(c.$pages, "deck", "card", "background");
    border-radius: 0.5rem;
}

.finalize__needed {
    display: flex;
    align-items: center;

    gap: 0.75rem;
}

.finalize__thumb {
    width: 3rem;
    flex: 0 0 auto;
}

.finalize__needed-text {
    display: flex;
    flex-direction: column;
}

.finalize__set {
    opacity: 0.7;

    font-size: 0.875rem;
}

.finalize__assign {
    display: flex;
    flex-direction: column;

    gap: 0.25rem;
}

.finalize__no-match {
    color: map.get(c.$state, "warning", "border");

    font-style: italic;
}

.finalize__partial {
    color: map.get(c.$state, "info", "border");

    font-size: 0.875rem;
}

.finalize__actions {
    display: flex;
    justify-content: flex-end;

    gap: 0.5rem;
}

@media (width <= 40rem) {
    .finalize__row {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
