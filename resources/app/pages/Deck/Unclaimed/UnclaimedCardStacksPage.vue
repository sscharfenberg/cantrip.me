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
import CardImagePreview from "Components/Card/CardImagePreview.vue";
import Checkbox from "Components/Form/Checkbox.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import LabelledLink from "Components/UI/LabelledLink.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
interface UnclaimedCard {
    id: string;
    name: string;
    set_code: string | null;
    collector_number: string | null;
    default_card_id: string;
    /** Thumbnail URL for the hover preview; null when the printing has no front image. */
    card_image_0: string | null;
    unclaimed: number;
    zone: string;
    role: string | null;
    /** True when the user already owns at least one stack of any printing of this oracle card — drives the "View your copies" link. */
    has_any_printing: boolean;
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
    { label: props.deck.name, href: `/decks/${props.deck.id}`, icon: "cards" },
    { label: t("pages.deck.unclaimed.menu_link") }
]);
/** Per-row "I just bought this" checkboxes — only meaningful in mode C. */
const form = useForm<{ bought: string[] }>({ bought: [] });
/**
 * Master "I bought all of these" checkbox. Two-way: reading reports
 * "all rows ticked"; writing flips every row at once. Reports unchecked
 * for the empty-list case so it doesn't render as a meaningless tick.
 */
const allChecked = computed({
    get: () => props.cards.length > 0 && form.bought.length === props.cards.length,
    set: (value: boolean) => {
        form.bought = value ? props.cards.map(c => c.id) : [];
    }
});
/** Gates the submit button — at least one row must be ticked. */
const someChecked = computed(() => form.bought.length > 0);
/** Push / pull a deck_card_id into `form.bought` based on the row checkbox state. */
function toggleRow(cardId: string, checked: boolean): void {
    if (checked) {
        if (!form.bought.includes(cardId)) form.bought.push(cardId);
    } else {
        form.bought = form.bought.filter(id => id !== cardId);
    }
}
/** Mirror of the row checkbox state — drives `:checked-initially` after re-renders. */
function isRowChecked(cardId: string): boolean {
    return form.bought.includes(cardId);
}
/** Submit the bought-rows list. No-op if nothing is ticked (button is already disabled). */
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
        <icon name="unclaimed" :size="3" />
        {{ $t("pages.deck.unclaimed.heading", { name: deck.name }) }}
    </headline>

    <p v-if="cards.length === 0" class="unclaimed__empty">
        {{ $t("pages.deck.unclaimed.empty") }}
    </p>

    <template v-else>
        <form-legend :items="[{ slot: 'intro', icon: 'info' }]">
            <template #intro>
                {{ mode === "B" ? $t("pages.deck.unclaimed.intro_mode_b") : $t("pages.deck.unclaimed.intro_mode_c") }}
            </template>
        </form-legend>

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
                        <card-image-preview :src="card.card_image_0" :alt="card.name">
                            {{ card.unclaimed }}× {{ card.name }}
                        </card-image-preview>
                        <span v-if="card.set_code" class="unclaimed__set">
                            {{ card.set_code.toUpperCase() }}:{{ card.collector_number }}
                        </span>
                    </label>
                    <labelled-link
                        v-if="card.has_any_printing"
                        :href="`/collection?search=${encodeURIComponent(card.name)}`"
                        icon="search"
                    >
                        {{ $t("pages.deck.unclaimed.show_in_collection") }}
                    </labelled-link>
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
                <card-image-preview :src="card.card_image_0" :alt="card.name">
                    {{ card.unclaimed }}× {{ card.name }}
                </card-image-preview>
                <span v-if="card.set_code" class="unclaimed__set">
                    {{ card.set_code.toUpperCase() }}:{{ card.collector_number }}
                </span>
                <labelled-link
                    v-if="card.has_any_printing"
                    :href="`/collection?search=${encodeURIComponent(card.name)}`"
                    icon="card"
                >
                    {{ $t("pages.deck.unclaimed.show_in_collection") }}
                </labelled-link>
            </li>
        </ul>
    </template>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.unclaimed {
    &__toolbar {
        display: flex;
        justify-content: flex-end;

        margin: map.get(s.$pages, "unclaimed", "toolbar-margin");
    }

    &__master {
        display: flex;
        align-items: center;

        padding: map.get(s.$pages, "unclaimed", "master", "padding");
        border: map.get(s.$pages, "unclaimed", "master", "border") solid
            map.get(c.$pages, "unclaimed", "master", "border");
        gap: map.get(s.$pages, "unclaimed", "master", "gap");

        background-color: map.get(c.$pages, "unclaimed", "master", "background");
        color: map.get(c.$pages, "unclaimed", "master", "surface");
        border-radius: map.get(s.$pages, "unclaimed", "master", "radius");

        cursor: pointer;
    }

    &__list {
        display: flex;
        flex-direction: column;

        padding: 0;

        margin: map.get(s.$pages, "unclaimed", "list", "margin");
        gap: map.get(s.$pages, "unclaimed", "list", "gap");

        list-style: none;
    }

    &__row {
        display: flex;
        align-items: center;

        padding: map.get(s.$pages, "unclaimed", "row", "padding");
        border: map.get(s.$pages, "unclaimed", "row", "border") solid map.get(c.$pages, "unclaimed", "row", "border");
        gap: map.get(s.$pages, "unclaimed", "row", "gap");

        background-color: map.get(c.$pages, "unclaimed", "row", "background", "even");
        color: map.get(c.$pages, "unclaimed", "row", "surface", "default");
        border-radius: map.get(s.$pages, "unclaimed", "row", "radius");

        transition:
            background-color map.get(ti.$timings, "fast") linear,
            color map.get(ti.$timings, "fast") linear;

        &:nth-child(odd) {
            background-color: map.get(c.$pages, "unclaimed", "row", "background", "odd");
        }

        &:hover {
            background-color: map.get(c.$pages, "unclaimed", "row", "background", "hover");
            color: map.get(c.$pages, "unclaimed", "row", "surface", "hover");
        }

        .text-link {
            margin-left: auto;
        }
    }

    &__row-label {
        display: flex;
        align-items: center;
        flex-grow: 1;

        gap: 0.5rem;

        cursor: pointer;
    }

    &__set {
        opacity: 0.8;

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
