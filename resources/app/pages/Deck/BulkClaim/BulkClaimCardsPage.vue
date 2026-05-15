<script setup lang="ts">
/******************************************************************************
 * BulkClaim page — replaces the old "finalize deck" wizard.
 *
 * Reached only by mode-C owners (gated by BulkClaimRequest). Cards are
 * partitioned into three sections:
 *
 *   §1 exact    — user owns ≥1 eligible stack of the same printing.
 *   §2 alt      — user owns ≥1 eligible stack of a different printing
 *                 of the same oracle card. Picking one swaps the
 *                 deck_card's printing to match.
 *   §3 missing  — user owns nothing of this oracle card.
 *
 * Eligibility (server-side): owned + no pivot row + not in another
 * deck's deckbox container.
 *
 * Submission payload is identical to the old wizard so the existing
 * DeckFinalizeService persistence path handles it:
 *   { assignments: { deck_card_id: [stack_id] }, buy_new: { ... }, container_id }
 *****************************************************************************/
import { Head, Link, useForm } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Checkbox from "Components/Form/Checkbox.vue";
import FormGroup from "Components/Form/FormGroup.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
/** Container summary for one stack option. */
interface StackContainer {
    id: string;
    name: string;
    type: string;
}
/** §1 stack option — same printing as the deck_card. */
interface ExactStack {
    id: string;
    amount: number;
    container: StackContainer | null;
}
/** §2 stack option — alternate printing of the same oracle card. */
interface AltStack {
    id: string;
    amount: number;
    container: StackContainer | null;
    default_card_id: string;
    set_code: string | null;
    collector_number: string | null;
    /** Thumbnail of the alternate printing — handy hint without a custom select. */
    card_image_0: string | null;
}
interface BulkClaimCard {
    id: string;
    name: string;
    quantity: number;
    set_code: string | null;
    collector_number: string | null;
    card_image_0: string | null;
    section: "exact" | "alt" | "missing";
    exact_stacks: ExactStack[];
    alt_stacks: AltStack[];
}
interface BulkClaimContainer {
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
    cards: BulkClaimCard[];
    containers: BulkClaimContainer[];
}>();
const { t } = useI18n();
useBreadcrumbs().setBreadcrumbs([
    { labelKey: "pages.decks.link", href: "/decks", icon: "deck" },
    { label: props.deck.name, href: `/decks/${props.deck.id}`, icon: "cards" },
    { labelKey: "pages.deck.bulk_claim.title", params: { name: props.deck.name } }
]);
/** Card rows the controller bucketed into §1 (exact-printing available). */
const exactCards = computed(() => props.cards.filter(c => c.section === "exact"));
/** §2 — alternate printing available; picking one swaps deck_card.default_card_id server-side. */
const altCards = computed(() => props.cards.filter(c => c.section === "alt"));
/** §3 — nothing in the user's collection; only buy-new is offered. */
const missingCards = computed(() => props.cards.filter(c => c.section === "missing"));
/** One slot per deck_card_id, empty list. Single-stack-per-row UI so the inner array is at most one element. */
const initialAssignments: Record<string, string[]> = Object.fromEntries(props.cards.map(c => [c.id, []]));
/** Mirror map keyed by deck_card_id — flips to true when the user ticks "I just bought N more copies". */
const initialBuyNew: Record<string, boolean> = Object.fromEntries(props.cards.map(c => [c.id, false]));
const form = useForm<{
    assignments: Record<string, string[]>;
    buy_new: Record<string, boolean>;
    container_id: string | null;
}>({
    assignments: initialAssignments,
    buy_new: initialBuyNew,
    container_id: props.deck.container_id
});
/** Container-picker options. Deckboxes get a " — Recommended" suffix; ordering is server-side. */
const containerOptions = computed(() => [
    ...props.containers.map(c => ({
        value: c.id,
        label: c.is_deckbox ? `${c.name} — ${t("pages.deck.bulk_claim.deckbox_hint")}` : c.name
    }))
]);
/** §1 dropdown labels: "{container-type}: {name} (×{amount})" or "Unsorted (×{amount})". */
function exactStackOptions(card: BulkClaimCard): { value: string; label: string }[] {
    return card.exact_stacks.map(stack => ({
        value: stack.id,
        label: stack.container
            ? t("pages.deck.bulk_claim.stack_label", {
                  type: t(`enums.container_type.${stack.container.type}`),
                  name: stack.container.name,
                  amount: stack.amount
              })
            : t("pages.deck.bulk_claim.stack_unsorted", { amount: stack.amount })
    }));
}
/** §2 dropdown labels — include `SET:collector#` so the user can tell printings apart. */
function altStackOptions(card: BulkClaimCard): { value: string; label: string }[] {
    return card.alt_stacks.map(stack => {
        const printing =
            stack.set_code && stack.collector_number
                ? `${stack.set_code.toUpperCase()}:${stack.collector_number}`
                : t("pages.deck.bulk_claim.printing_unknown");
        const where = stack.container
            ? t(`enums.container_type.${stack.container.type}`) + ` ${stack.container.name}`
            : t("pages.deck.bulk_claim.unsorted");
        return {
            value: stack.id,
            label: t("pages.deck.bulk_claim.alt_stack_label", {
                printing,
                amount: stack.amount,
                where
            })
        };
    });
}
/** Amount of the stack the user picked for this row (across exact + alt buckets). 0 when none picked. */
function pickedStackAmount(card: BulkClaimCard): number {
    const id = form.assignments[card.id]?.[0];
    if (!id) return 0;
    const exact = card.exact_stacks.find(s => s.id === id);
    if (exact) return exact.amount;
    const alt = card.alt_stacks.find(s => s.id === id);
    return alt?.amount ?? 0;
}
/** How many of the deck card's slots are *not* covered by the picked stack — drives the buy-more checkbox. */
function uncoveredFor(card: BulkClaimCard): number {
    return Math.max(0, card.quantity - pickedStackAmount(card));
}
/** Single-stack-per-row replace. Empty value clears the row. */
function onPickStack(cardId: string, value: string): void {
    form.assignments[cardId] = value ? [value] : [];
}
/** Submit the form. Server redirects to `/decks/{id}/unclaimed` so the user sees what's still missing. */
function onSubmit(): void {
    form.post(`/decks/${props.deck.id}/bulk-claim`);
}
</script>

<template>
    <Head>
        <title>{{ $t("pages.deck.bulk_claim.title", { name: deck.name }) }}</title>
    </Head>
    <headline>
        <icon name="cards" :size="3" />
        {{ $t("pages.deck.bulk_claim.heading") }}
    </headline>
    <form class="form" @submit.prevent="onSubmit">
        <form-legend :items="[{ slot: 'intro', icon: 'info' }]">
            <template #intro>{{ $t("pages.deck.bulk_claim.intro") }}</template>
        </form-legend>
        <p v-if="cards.length === 0" class="bulk-claim__empty">
            {{ $t("pages.deck.bulk_claim.empty") }}
        </p>
        <template v-else>
            <form-group :label="$t('pages.deck.bulk_claim.container_heading')">
                <mono-select
                    :options="containerOptions"
                    :selected="form.container_id ?? ''"
                    :placeholder="$t('pages.deck.bulk_claim.container_unset')"
                    :sort="false"
                    addon-icon="container-image"
                    max="100%"
                    @change="form.container_id = $event || null"
                />
                <template #text>
                    {{ $t("pages.deck.bulk_claim.container_hint") }}
                </template>
            </form-group>

            <section v-if="exactCards.length" class="bulk-claim__section">
                <headline :size="3">{{ $t("pages.deck.bulk_claim.section_exact") }}</headline>
                <ul class="bulk-claim__list">
                    <li v-for="card in exactCards" :key="card.id" class="bulk-claim__row">
                        <div class="bulk-claim__needed">
                            <img
                                v-if="card.card_image_0"
                                :src="card.card_image_0"
                                :alt="card.name"
                                loading="lazy"
                                class="bulk-claim__thumb"
                            />
                            <div class="bulk-claim__needed-text">
                                <strong>{{ card.quantity }}× {{ card.name }}</strong>
                                <span v-if="card.set_code" class="bulk-claim__set">
                                    {{ card.set_code.toUpperCase() }}:{{ card.collector_number }}
                                </span>
                            </div>
                        </div>
                        <div class="bulk-claim__assign">
                            <mono-select
                                :options="exactStackOptions(card)"
                                :selected="form.assignments[card.id]?.[0] ?? ''"
                                :placeholder="$t('pages.deck.bulk_claim.col_assignment')"
                                :sort="false"
                                addon-icon="cards"
                                max="100%"
                                @change="onPickStack(card.id, $event)"
                            />
                            <label
                                v-if="uncoveredFor(card) > 0 && pickedStackAmount(card) > 0"
                                class="bulk-claim__buy-new"
                            >
                                <checkbox
                                    :ref-id="`buy-new-${card.id}`"
                                    :checked-initially="form.buy_new[card.id]"
                                    @change="form.buy_new[card.id] = $event"
                                />
                                {{
                                    $t("pages.deck.bulk_claim.buy_new.label_partial", {
                                        amount: uncoveredFor(card)
                                    })
                                }}
                            </label>
                        </div>
                    </li>
                </ul>
            </section>

            <section v-if="altCards.length" class="bulk-claim__section">
                <headline :size="3">{{ $t("pages.deck.bulk_claim.section_alt") }}</headline>
                <ul class="bulk-claim__list">
                    <li v-for="card in altCards" :key="card.id" class="bulk-claim__row">
                        <div class="bulk-claim__needed">
                            <img
                                v-if="card.card_image_0"
                                :src="card.card_image_0"
                                :alt="card.name"
                                loading="lazy"
                                class="bulk-claim__thumb"
                            />
                            <div class="bulk-claim__needed-text">
                                <strong>{{ card.quantity }}× {{ card.name }}</strong>
                                <span v-if="card.set_code" class="bulk-claim__set">
                                    {{ card.set_code.toUpperCase() }}:{{ card.collector_number }}
                                </span>
                            </div>
                        </div>
                        <div class="bulk-claim__assign">
                            <mono-select
                                :options="altStackOptions(card)"
                                :selected="form.assignments[card.id]?.[0] ?? ''"
                                :placeholder="$t('pages.deck.bulk_claim.col_assignment_alt')"
                                :sort="false"
                                addon-icon="cards"
                                max="100%"
                                @change="onPickStack(card.id, $event)"
                            />
                            <label
                                v-if="uncoveredFor(card) > 0 && pickedStackAmount(card) > 0"
                                class="bulk-claim__buy-new"
                            >
                                <checkbox
                                    :ref-id="`buy-new-${card.id}`"
                                    :checked-initially="form.buy_new[card.id]"
                                    @change="form.buy_new[card.id] = $event"
                                />
                                {{
                                    $t("pages.deck.bulk_claim.buy_new.label_partial", {
                                        amount: uncoveredFor(card)
                                    })
                                }}
                            </label>
                        </div>
                    </li>
                </ul>
            </section>

            <section v-if="missingCards.length" class="bulk-claim__section">
                <headline :size="3">{{ $t("pages.deck.bulk_claim.section_missing") }}</headline>
                <ul class="bulk-claim__list">
                    <li v-for="card in missingCards" :key="card.id" class="bulk-claim__row">
                        <div class="bulk-claim__needed">
                            <img
                                v-if="card.card_image_0"
                                :src="card.card_image_0"
                                :alt="card.name"
                                loading="lazy"
                                class="bulk-claim__thumb"
                            />
                            <div class="bulk-claim__needed-text">
                                <strong>{{ card.quantity }}× {{ card.name }}</strong>
                                <span v-if="card.set_code" class="bulk-claim__set">
                                    {{ card.set_code.toUpperCase() }}:{{ card.collector_number }}
                                </span>
                            </div>
                        </div>
                        <div class="bulk-claim__assign">
                            <label class="bulk-claim__buy-new">
                                <checkbox
                                    :ref-id="`buy-new-${card.id}`"
                                    :checked-initially="form.buy_new[card.id]"
                                    @change="form.buy_new[card.id] = $event"
                                />
                                {{ $t("pages.deck.bulk_claim.buy_new.label_full", { amount: card.quantity }) }}
                            </label>
                        </div>
                    </li>
                </ul>
            </section>
        </template>

        <div class="bulk-claim__actions">
            <Link :href="`/decks/${deck.id}`" class="btn-default">
                {{ $t("pages.deck.bulk_claim.back") }}
            </Link>
            <button type="submit" class="btn-primary" :disabled="form.processing || cards.length === 0">
                <icon name="save" />
                {{ $t("pages.deck.bulk_claim.submit") }}
            </button>
        </div>
    </form>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.bulk-claim {
    &__section {
        margin-top: 1.5rem;
    }

    &__list {
        display: flex;
        flex-direction: column;

        padding: 0;
        margin: 0;
        gap: map.get(s.$pages, "bulk-claim", "list-gap");

        list-style: none;
    }

    &__row {
        display: grid;
        align-items: center;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);

        padding: map.get(s.$pages, "bulk-claim", "row", "padding");
        border: map.get(s.$pages, "bulk-claim", "row", "border") solid map.get(c.$pages, "bulk-claim", "row", "border");
        gap: map.get(s.$pages, "bulk-claim", "row", "gap");

        backdrop-filter: blur(12px);
        border-radius: map.get(s.$pages, "bulk-claim", "row", "radius");

        &:nth-child(even) {
            background-color: map.get(c.$pages, "bulk-claim", "row", "background", "even");
        }

        &:nth-child(odd) {
            background-color: map.get(c.$pages, "bulk-claim", "row", "background", "odd");
        }
    }

    &__needed {
        display: flex;
        align-items: center;

        gap: 0.5rem;
    }

    &__thumb {
        width: map.get(s.$pages, "bulk-claim", "thumbnail", "width");
        flex: 0 0 auto;

        border-radius: map.get(s.$pages, "bulk-claim", "thumbnail", "radius");
    }

    &__needed-text {
        display: flex;
        flex-direction: column;
    }

    &__set {
        opacity: 0.7;

        font-size: 0.875rem;
    }

    &__assign {
        display: flex;
        flex-direction: column;

        gap: map.get(s.$pages, "bulk-claim", "list-gap");
    }

    &__buy-new {
        display: flex;
        align-items: center;

        gap: map.get(s.$pages, "bulk-claim", "list-gap");
    }

    &__actions {
        display: flex;
        justify-content: flex-end;

        margin-top: 1rem;
        gap: map.get(s.$pages, "bulk-claim", "actions-gap");
    }

    &__empty {
        font-style: italic;
    }
}

@media (width <= 40rem) {
    .bulk-claim__row {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
