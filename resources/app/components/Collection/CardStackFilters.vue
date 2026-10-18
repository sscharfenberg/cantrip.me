<script setup lang="ts">
/**
 * Filter section of the card-stack data tables (collection page, container
 * page), rendered into the DataTable's `#filters` slot.
 *
 * Owns no filter state of its own: what it shows is `filters`, the set the
 * server applied and echoed back, and every change goes out as a patch of
 * query parameters through `change` — the DataTable turns it into a
 * navigation. The two price fields are the exception: what the user types is
 * held locally and sent debounced, like the table's search input.
 */
import { computed, onBeforeUnmount, ref, useId, watch } from "vue";
import { useI18n } from "vue-i18n";
import {
    COLORLESS,
    COLORS,
    FILTER_KEYS,
    PROXY_EXCLUDING_MAX_PRICE,
    activeFilterCount,
    applyProxyPriceRule,
    parsePrice
} from "@/utils/cardStackFilters.ts";
import Checkbox from "Components/Form/Checkbox.vue";
import RadioButtonGroup from "Components/Form/Radio/RadioButtonGroup.vue";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import Accordion from "Components/UI/Accordion.vue";
import Badge from "Components/UI/Badge.vue";
import Icon from "Components/UI/Icon.vue";
import { useFormatting } from "Composables/useFormatting.ts";
import { usePersistedAccordion } from "Composables/usePersistedAccordion.ts";
import type { CardStackFilterOptions, CardStackFilterPatch } from "Types/cardStackFilters";
const props = defineProps<{
    /** The filters the table applies now, as echoed by the server. */
    filters: Record<string, string | string[]> | null;
    /** Choices for the dropdowns; `containers` and `decks` are optional fields. */
    options: CardStackFilterOptions;
    /** localStorage key remembering whether the section is open. */
    storageKey: string;
}>();
const emit = defineEmits<{
    /** A change to the filters, keyed by query parameter; `null` removes one. */
    change: [patch: CardStackFilterPatch];
}>();
const { t } = useI18n();
const { formatPrice } = useFormatting();
const uid = useId();
const { initialOpen, onToggle } = usePersistedAccordion(props.storageKey, false);
/** How long typing in a price field has to pause before the price is sent. */
const DEBOUNCE_MS = 750;
/** A filter's current value, or undefined when it is not active. */
const value = (key: string): string | undefined => {
    const current = props.filters?.[key];
    return typeof current === "string" ? current : undefined;
};
/** Number of active filters, shown in the accordion head. */
const count = computed(() => activeFilterCount(props.filters));
// ---------------------------------------------------------------------------
// Prices — typed locally, sent debounced
// ---------------------------------------------------------------------------
const minPrice = ref(value("min_price") ?? "");
const maxPrice = ref(value("max_price") ?? "");
const priceInputs = { min_price: minPrice, max_price: maxPrice } as const;
let priceTimer: ReturnType<typeof setTimeout> | null = null;
/** Drop a debounced price change that has not gone out yet. */
const cancelPending = (): void => {
    if (priceTimer) clearTimeout(priceTimer);
    priceTimer = null;
};
onBeforeUnmount(cancelPending);
/** Whether a typed price and an applied one are the same bound ("1.50" and "1.5" are). */
const samePrice = (typed: string | null, applied: string | undefined): boolean =>
    typed === null ? applied === undefined : applied !== undefined && Number(typed) === Number(applied);
/**
 * The price fields whose typed value differs from the applied one and can be
 * sent. A half-typed value ("1,") or a mistyped one is left out until it
 * parses.
 */
const priceChanges = (): CardStackFilterPatch => {
    const patch: CardStackFilterPatch = {};
    for (const [key, input] of Object.entries(priceInputs)) {
        const typed = parsePrice(input.value);
        if (typed !== undefined && !samePrice(typed, value(key))) patch[key] = typed;
    }
    return patch;
};
/**
 * Send a change. Any price typed but not yet sent goes along with it, so a
 * filter picked while the debounce runs cannot be overtaken by the price a
 * moment later; the cheap-cards rule then sees both together.
 */
const change = (patch: CardStackFilterPatch): void => {
    const next = applyProxyPriceRule(props.filters, { ...priceChanges(), ...patch });
    cancelPending();
    if (next.max_price === null) maxPrice.value = "";
    emit("change", next);
};
const onPriceInput = (): void => {
    cancelPending();
    if (Object.keys(priceChanges()).length === 0) return;
    priceTimer = setTimeout(() => change({}), DEBOUNCE_MS);
};
/** Enter sends right away instead of waiting out the debounce. */
const onPriceEnter = (): void => {
    if (Object.keys(priceChanges()).length > 0) change({});
};
/**
 * Bring the fields in line with the applied filters after an outside change
 * (back/forward, "Clear filters", the cheap-cards rule) — but not while the
 * user is typing, and not when the field already says the same bound in
 * other digits.
 */
watch(
    () => [value("min_price"), value("max_price")],
    () => {
        if (priceTimer) return;
        for (const [key, input] of Object.entries(priceInputs)) {
            const applied = value(key);
            if (!samePrice(parsePrice(input.value) ?? null, applied)) input.value = applied ?? "";
        }
    }
);
// ---------------------------------------------------------------------------
// Proxies
// ---------------------------------------------------------------------------
const proxyButtons = computed(() =>
    ["all", "only", "none"].map(option => ({
        value: option,
        label: `components.card_stack_filters.proxy.${option}`,
        checked: (value("proxy") ?? "all") === option
    }))
);
const onProxyChange = (event: Event): void => {
    const option = (event.target as HTMLInputElement).value;
    change({ proxy: option === "all" ? null : option });
};
// ---------------------------------------------------------------------------
// Dropdowns
// ---------------------------------------------------------------------------
interface SelectField {
    key: string;
    label: string;
    icon: string;
    options: Array<{ value: string; label: string; imageUrl?: string }>;
    /** Sort the options by label; off where the server's order means something. */
    sort: boolean;
}
const selectFields = computed<SelectField[]>(() => {
    const fields: SelectField[] = [
        {
            key: "lang",
            label: t("form.fields.language"),
            icon: "language",
            options: props.options.languages.map(lang => ({ value: lang, label: t(`enums.card_languages.${lang}`) })),
            sort: true
        },
        {
            key: "condition",
            label: t("form.fields.condition"),
            icon: "cards",
            options: props.options.conditions.map(condition => ({
                value: condition,
                label: t(`enums.conditions.${condition}`)
            })),
            sort: false
        },
        {
            key: "finish",
            label: t("form.fields.finish"),
            icon: "star",
            options: props.options.finishes.map(finish => ({ value: finish, label: t(`enums.finishes.${finish}`) })),
            sort: false
        },
        {
            key: "rarity",
            label: t("components.card_stack_filters.rarity"),
            icon: "card",
            options: props.options.rarities.map(rarity => ({ value: rarity, label: t(`enums.rarities.${rarity}`) })),
            sort: false
        },
        {
            key: "set",
            label: t("form.fields.set_name"),
            icon: "card",
            options: props.options.sets.map(set => ({
                value: set.code,
                label: `${set.name} [${set.code.toUpperCase()}]`,
                imageUrl: set.path ?? undefined
            })),
            sort: false
        },
        {
            key: "type",
            label: t("components.card_stack_filters.type"),
            icon: "cards",
            options: props.options.types.map(type => ({ value: type, label: t(`enums.card_types.${type}`) })),
            sort: true
        }
    ];
    if (props.options.containers) {
        fields.push({
            key: "container",
            label: t("form.fields.container_name"),
            icon: "storage",
            options: [
                { value: "unsorted", label: t("pages.collection.unsorted") },
                ...props.options.containers.map(container => ({ value: container.id, label: container.name }))
            ],
            sort: false
        });
    }
    if (props.options.decks) {
        fields.push({
            key: "claimed",
            label: t("components.card_stack_filters.claimed.label"),
            icon: "claimed",
            options: [
                { value: "any", label: t("components.card_stack_filters.claimed.any") },
                { value: "none", label: t("components.card_stack_filters.claimed.none") },
                ...props.options.decks.map(deck => ({ value: deck.id, label: deck.name }))
            ],
            sort: false
        });
    }
    return fields;
});
/** MonoSelect's clear button selects "" — that removes the filter. */
const onSelectChange = (key: string, selected: string): void => change({ [key]: selected || null });
// ---------------------------------------------------------------------------
// Color identity
// ---------------------------------------------------------------------------
const selectedColors = computed(() => value("colors") ?? "");
const colorButtons = computed(() =>
    [...COLORS, COLORLESS].map(color => ({
        color,
        label: t(`enums.colors.${color}`),
        pressed: color === COLORLESS ? selectedColors.value === COLORLESS : selectedColors.value.includes(color)
    }))
);
/**
 * Toggle one color. Colorless is exclusive: picking it drops the colors,
 * picking a color drops colorless. The match mode goes with the last color.
 */
const toggleColor = (color: string): void => {
    const colors = selectedColors.value === COLORLESS ? "" : selectedColors.value;
    const next =
        color === COLORLESS
            ? selectedColors.value === COLORLESS
                ? ""
                : COLORLESS
            : COLORS.filter(candidate => (candidate === color) !== colors.includes(candidate)).join("");
    change(next === "" || next === COLORLESS ? { colors: next || null, color_mode: null } : { colors: next });
};
const colorModeOptions = computed(() =>
    ["include", "exact", "within"].map(mode => ({
        value: mode,
        label: t(`components.card_stack_filters.color_mode.${mode}`)
    }))
);
/** The match mode only means something for actual colors. */
const colorModeDisabled = computed(() => selectedColors.value === "" || selectedColors.value === COLORLESS);
const onColorModeChange = (mode: string): void => change({ color_mode: mode === "include" ? null : mode });
// ---------------------------------------------------------------------------
// Clear
// ---------------------------------------------------------------------------
const canClear = computed(() => count.value > 0 || minPrice.value !== "" || maxPrice.value !== "");
const clear = (): void => {
    cancelPending();
    minPrice.value = "";
    maxPrice.value = "";
    emit("change", Object.fromEntries(FILTER_KEYS.map(key => [key, null])));
};
</script>

<template>
    <accordion class="card-stack-filters" :initial-open="initialOpen" @toggle="onToggle">
        <template #head>
            <span class="card-stack-filters__title">
                <icon name="filter" />
                {{ $t("components.card_stack_filters.title") }}
                <badge v-if="count > 0" type="info">{{ count }}</badge>
            </span>
        </template>
        <template #body>
            <div class="card-stack-filters__grid">
                <div class="card-stack-filters__field">
                    <label :for="`${uid}-min_price`" class="card-stack-filters__label">
                        {{ $t("components.card_stack_filters.min_price") }}
                    </label>
                    <div class="form-group card-stack-filters__price">
                        <div class="form-group__addon"><icon name="money" /></div>
                        <input
                            :id="`${uid}-min_price`"
                            v-model="minPrice"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            class="form-input"
                            @input="onPriceInput"
                            @keydown.enter.prevent="onPriceEnter"
                        />
                    </div>
                </div>
                <div class="card-stack-filters__field">
                    <label :for="`${uid}-max_price`" class="card-stack-filters__label">
                        {{ $t("components.card_stack_filters.max_price") }}
                    </label>
                    <div class="form-group card-stack-filters__price">
                        <div class="form-group__addon"><icon name="money" /></div>
                        <input
                            :id="`${uid}-max_price`"
                            v-model="maxPrice"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            class="form-input"
                            @input="onPriceInput"
                            @keydown.enter.prevent="onPriceEnter"
                        />
                    </div>
                </div>
                <div class="card-stack-filters__field" role="group" :aria-labelledby="`${uid}-proxy`">
                    <span :id="`${uid}-proxy`" class="card-stack-filters__label">
                        {{ $t("components.card_stack_filters.proxy.label") }}
                    </span>
                    <radio-button-group
                        class="card-stack-filters__proxies"
                        :name="`${uid}-proxy`"
                        :radio-buttons="proxyButtons"
                        layout="row"
                        @change="onProxyChange"
                    />
                    <span class="card-stack-filters__hint">
                        {{
                            $t("components.card_stack_filters.proxy.hint", {
                                price: formatPrice(PROXY_EXCLUDING_MAX_PRICE)
                            })
                        }}
                    </span>
                </div>
                <div
                    v-for="field in selectFields"
                    :key="field.key"
                    class="card-stack-filters__field"
                    :class="`card-stack-filters__field--${field.key}`"
                    role="group"
                    :aria-labelledby="`${uid}-${field.key}`"
                >
                    <span :id="`${uid}-${field.key}`" class="card-stack-filters__label">{{ field.label }}</span>
                    <mono-select
                        :options="field.options"
                        :selected="value(field.key)"
                        :sort="field.sort"
                        :addon-icon="field.icon"
                        :placeholder="$t('components.card_stack_filters.any')"
                        @change="onSelectChange(field.key, $event)"
                    />
                </div>
                <div class="card-stack-filters__field" role="group" :aria-labelledby="`${uid}-colors`">
                    <span :id="`${uid}-colors`" class="card-stack-filters__label">
                        {{ $t("components.card_stack_filters.colors") }}
                    </span>
                    <div class="card-stack-filters__colors">
                        <button
                            v-for="button in colorButtons"
                            :key="button.color"
                            type="button"
                            class="card-stack-filters__color"
                            :aria-pressed="button.pressed"
                            :aria-label="button.label"
                            v-tooltip="button.label"
                            @click="toggleColor(button.color)"
                        >
                            <img :src="`/symbol/${button.color}.svg`" alt="" />
                        </button>
                    </div>
                </div>
                <div class="card-stack-filters__field" role="group" :aria-labelledby="`${uid}-color_mode`">
                    <span :id="`${uid}-color_mode`" class="card-stack-filters__label">
                        {{ $t("components.card_stack_filters.color_mode.label") }}
                    </span>
                    <mono-select
                        :options="colorModeOptions"
                        :selected="value('color_mode') ?? 'include'"
                        :sort="false"
                        :clearable="false"
                        :disabled="colorModeDisabled"
                        addon-icon="color"
                        @change="onColorModeChange"
                    />
                </div>
                <div class="card-stack-filters__field">
                    <span class="card-stack-filters__label">
                        {{ $t("components.card_stack_filters.reserved.label") }}
                    </span>
                    <div class="card-stack-filters__toggle">
                        <!-- No `label` on the checkbox itself: its own label is the
                             styled box, and the visible one below names the input. -->
                        <checkbox
                            :ref-id="`${uid}-reserved`"
                            value="1"
                            :checked-initially="value('reserved') === '1'"
                            @change="change({ reserved: $event ? '1' : null })"
                        />
                        <label :for="`${uid}-reserved`">{{ $t("components.card_stack_filters.reserved.toggle") }}</label>
                    </div>
                </div>
            </div>
            <div class="card-stack-filters__actions">
                <button type="button" class="btn-default" :disabled="!canClear" @click="clear">
                    <icon name="filter-off" />
                    {{ $t("components.card_stack_filters.clear") }}
                </button>
            </div>
        </template>
    </accordion>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.card-stack-filters {
    margin-bottom: 1lh;

    &__title {
        display: flex;
        align-items: center;

        gap: 0.5rem;

        font-weight: 600;
    }

    // Intrinsically responsive: as many columns as fit, one on a phone. The
    // minimum is what the three proxy options need side by side.
    &__grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 18.5rem), 1fr));

        gap: 2ex 2ch;
    }

    // A grid, not a column flexbox: the global .form-select has flex-grow: 1,
    // which in a column would stretch the dropdown to the row's full height
    // whenever a taller field (Proxies) shares the row. minmax(0, 1fr), and a
    // dropdown button allowed below its text width, so a
    // long label ("Contains these colors", a deck name) ends in an ellipsis
    // instead of pushing the dropdown into the next column — where that
    // column's field painted over it and swallowed its clicks.
    &__field {
        display: grid;
        align-content: start;
        grid-template-columns: minmax(0, 1fr);

        min-width: 0;
        gap: 0.75ex;

        :deep(.form-select__button) {
            min-width: 0;
        }

        // A disabled dropdown otherwise only loses its pointer cursor.
        :deep(.form-select:has(> .form-select__button:disabled)) {
            opacity: 0.5;
        }
    }

    &__label {
        font-weight: 600;
    }

    // Always one line: short labels, no wrapping, and a little less padding
    // around each label than the global pill has.
    &__proxies {
        flex-wrap: nowrap;

        :deep(.form-radio__label) {
            padding-inline: 1ch;
        }
    }

    &__hint {
        opacity: 0.7;

        font-size: 0.875rem;
    }

    // The global .form-group is a label | input row; here it only pairs the
    // addon with its input.
    &__price {
        flex-wrap: nowrap;

        gap: 0;

        .form-input {
            flex-grow: 1;

            min-width: 0;
        }
    }

    &__colors {
        display: flex;
        flex-wrap: wrap;

        gap: 1ch;
    }

    &__color {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        opacity: 0.45;

        padding: 0.5ex;
        border: map.get(s.$components, "input", "border") solid map.get(c.$components, "input", "border");

        background-color: map.get(c.$components, "input", "background");

        border-radius: 50%;

        cursor: pointer;

        img {
            width: 1.75rem;
            height: 1.75rem;
        }

        &[aria-pressed="true"] {
            opacity: 1;

            border-color: map.get(c.$components, "input", "border-focus");
        }

        &:hover,
        &:focus-visible {
            opacity: 1;
        }
    }

    &__toggle {
        display: flex;
        align-items: center;

        min-height: 100%;
        gap: 1ch;
    }

    &__actions {
        display: flex;
        justify-content: flex-end;

        margin-top: 2ex;
    }
}
</style>

<style lang="scss">
// Set icons are black-filled SVGs; dark mode needs them inverted. Unscoped,
// like the set column's own rule, because the theme-dark mixin roots the
// selector at :root.
@use "Abstracts/mixins" as m;

@include m.theme-dark(".card-stack-filters__field--set .form-select__option-image") {
    filter: invert(1);
}
</style>
