<script setup lang="ts">
import { useI18n } from "vue-i18n";
import Headline from "Components/UI/Headline.vue";
import { useFormatting } from "Composables/useFormatting";
import type { CardRuling } from "Types/cardPreview";
defineProps<{
    rulings: CardRuling[];
}>();
const { t } = useI18n();
const { formatDate } = useFormatting();
</script>

<template>
    <div class="card-rulings">
        <headline :size="4">{{ t("components.rulings.title") }}</headline>
        <ul>
            <li v-for="(ruling, i) in rulings" :key="i" class="card-ruling">
                <span class="card-ruling__ruling">{{ ruling.comment }}</span>
                <span class="card-ruling__source">
                    {{ t("enums.ruling_sources." + ruling.source) }}
                    {{ ruling.published_at ? formatDate(ruling.published_at) : "" }}
                </span>
            </li>
        </ul>
    </div>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/sizes" as s;
@use "Abstracts/colors" as c;
@use "Abstracts/typography" as t;

.card-rulings ul {
    display: flex;
    flex-direction: column;

    padding: 0;
    margin: 0;
    gap: map.get(s.$components, "rulings", "gap");

    list-style: none;
}

.card-ruling {
    padding: map.get(s.$components, "rulings", "padding");
    border: map.get(s.$components, "rulings", "border") solid map.get(c.$components, "rulings", "border");

    background-color: map.get(c.$components, "rulings", "background");
    color: map.get(c.$components, "rulings", "surface");
    border-radius: map.get(s.$components, "rulings", "radius");

    &__source {
        padding: map.get(s.$components, "rulings", "source-padding");
        margin-left: 0.5rem;

        background-color: map.get(c.$components, "rulings", "source-background");
        color: map.get(c.$components, "rulings", "source-surface");
        border-radius: map.get(s.$components, "rulings", "source-radius");

        font-family: map.get(t.$components, "rulings", "source");
        font-style: italic;
    }
}
</style>
