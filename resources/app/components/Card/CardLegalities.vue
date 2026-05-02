<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import type { CardLegality } from "Types/cardPreview";
const props = defineProps<{
    legalities: CardLegality[];
}>();
const { t } = useI18n();
const sorted = computed(() =>
    [...props.legalities].sort((a, b) =>
        t("enums.card_formats." + a.format).localeCompare(t("enums.card_formats." + b.format))
    )
);
</script>

<template>
    <div class="card-legalities">
        <headline :size="4">{{ t("form.fields.legalities") }}</headline>
        <ul class="legalities">
            <li v-for="leg in sorted" :key="leg.format" class="legalities__item">
                <span class="legalities__format">{{ t("enums.card_formats." + leg.format) }}</span>
                <span
                    :class="['legalities__status', `legalities__status--${leg.legality.replace('_', '-')}`]"
                    v-tooltip="{
                        content: t('enums.card_legalities.' + leg.legality),
                        container: '#modal-body'
                    }"
                >
                    <icon v-if="leg.legality === 'not_legal'" name="close" :size="1" />
                    <icon v-else-if="leg.legality === 'restricted'" name="warning" :size="1" />
                    <icon v-else-if="leg.legality === 'banned'" name="error" :size="1" />
                    <icon v-else name="check" :size="1" />
                </span>
            </li>
        </ul>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/mixins" as m;

.legalities {
    display: grid;
    grid-template-columns: auto 2rem auto 2rem;

    padding: 0;
    margin: 0;
    gap: map.get(s.$components, "card-legalities", "gap");

    list-style: none;

    @include m.mq("landscape") {
        grid-template-columns: auto 3rem auto 3rem auto 3rem;
    }

    &__item {
        display: grid;
        grid-template-columns: subgrid;
        grid-column: span 2;
    }

    &__format {
        font-size: map.get(s.$components, "card-legalities", "font");
    }

    &__status {
        display: flex;
        align-items: center;
        justify-content: center;

        border-radius: map.get(s.$components, "card-legalities", "radius");

        font-size: map.get(s.$components, "card-legalities", "font");

        &--legal {
            background-color: map.get(c.$state, "success", "background");
            color: map.get(c.$state, "success", "surface");
        }

        &--not-legal,
        &--banned {
            background-color: map.get(c.$state, "error", "background");
            color: map.get(c.$state, "error", "surface");
        }

        &--restricted {
            background-color: map.get(c.$state, "warning", "background");
            color: map.get(c.$state, "warning", "surface");
        }
    }
}
</style>
