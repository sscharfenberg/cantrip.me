<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import CardFaceImage from "Components/Card/CardFaceImage.vue";
import CardLegalities from "Components/Card/CardLegalities.vue";
import ManaCost from "Components/Card/ManaCost.vue";
import CardStackClaimBadge from "Components/Collection/CardStackClaimBadge.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import { useFormatting } from "Composables/useFormatting";
import type { CardPreview } from "Types/cardPreview";
import type { DefaultCardImage } from "Types/defaultCardImage";
/** @emits close — Fired when the modal should be dismissed. */
const emit = defineEmits<{ close: [] }>();
const props = defineProps<{
    /**
     * Endpoint to fetch the preview payload from. Either the card-stack
     * preview URL (`/collection/cardstack/{id}/preview`, includes
     * stack-only fields) or the deck-card preview URL
     * (`/cards/{defaultCardId}/preview`, card-level fields only).
     */
    previewUrl: string;
}>();
const { t } = useI18n();
const { formatPrice, formatDateTime, formatDecimals } = useFormatting();
/** Resolve the flag image URL for a given language code. */
const flagSrc = (lang: string): string => new URL(`../../assets/flags/${lang}.svg`, import.meta.url).href;
/** True while the preview data is being fetched from the server. */
const loading = ref(true);
/** True when the fetch request failed. */
const error = ref(false);
/** Card preview data returned by the API, or null while loading / on error. */
const card = ref<CardPreview | null>(null);
/** Map the API response to the shape expected by CardFaceImage. */
const faceImage = computed<DefaultCardImage | null>(() => {
    if (!card.value) return null;
    return {
        id: "",
        name: card.value.name,
        card_image_0: card.value.card_image_0,
        card_image_1: card.value.card_image_1,
        artist: card.value.artist,
        cn: card.value.collector_number,
        finishes: card.value.finish ? [card.value.finish] : [],
        set: {
            name: card.value.set_name ?? "",
            code: card.value.set_code ?? "",
            path: card.value.set_path ?? null
        }
    };
});
/**
 * Fetch card preview data from the configured endpoint on mount.
 * Shows a loading spinner until the response arrives; sets the error
 * state if the request fails.
 */
onMounted(async () => {
    try {
        const response = await fetch(props.previewUrl, {
            headers: { Accept: "application/json" }
        });
        if (response.ok) {
            card.value = await response.json();
        } else {
            error.value = true;
        }
    } catch {
        error.value = true;
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ card?.name ?? t("components.card_preview.title") }}</template>
        <div v-if="loading" class="cardstack-preview__loading">
            <loading-spinner :size="4" :branded="true" />
            <p>{{ t("components.card_preview.loading") }}</p>
        </div>
        <div v-else-if="error" class="cardstack-preview__error">
            <icon name="error" :size="2" />
            <p>{{ t("components.card_preview.error") }}</p>
        </div>
        <div v-else-if="card" class="cardstack-preview">
            <card-face-image v-if="faceImage?.card_image_0" :card="faceImage" />
            <div class="col">
                <dl class="cardstack-preview__details">
                    <template v-if="card.amount">
                        <dt>{{ t("form.fields.qty") }}</dt>
                        <dd>{{ formatDecimals(card.amount) }}</dd>
                    </template>
                    <template v-if="card.condition">
                        <dt>{{ t("form.fields.condition") }}</dt>
                        <dd>{{ t("enums.conditions." + card.condition) }}</dd>
                    </template>
                    <template v-if="card.finish">
                        <dt>{{ t("form.fields.finish") }}</dt>
                        <dd>{{ t("enums.finishes." + card.finish) }}</dd>
                    </template>
                    <template v-if="card.language">
                        <dt>{{ t("form.fields.language") }}</dt>
                        <dd>
                            <img
                                :src="flagSrc(card.language)"
                                :alt="t('enums.card_languages.' + card.language)"
                                class="flag"
                                v-tooltip="{
                                    content: t('enums.card_languages.' + card.language),
                                    container: '#modal-body'
                                }"
                            />
                        </dd>
                    </template>
                    <template v-if="card.set_code && card.set_name && card.set_path">
                        <dt>{{ t("form.fields.set_name") }}</dt>
                        <dd class="cardstack-preview__set">
                            [{{ card.set_code.toUpperCase() }}] {{ card.set_name }}
                            <img :src="card.set_path" :alt="`[${card.set_code.toUpperCase()}] ${card.set_name}`" />
                        </dd>
                    </template>
                    <template v-if="card.produced_mana?.length">
                        <dt>{{ t("form.fields.produced_mana") }}</dt>
                        <dd>
                            <mana-cost :mana-cost="card.produced_mana.map(m => `{${m}}`).join('')" />
                        </dd>
                    </template>
                    <template v-if="card.price">
                        <dt>{{ t("form.fields.price") }}</dt>
                        <dd>{{ formatPrice(card.price) }}</dd>
                    </template>
                    <template v-if="card.amount && card.amount > 1 && card.total_price">
                        <dt>{{ t("components.card_preview.total_price", { amount: card.amount }) }}</dt>
                        <dd>{{ formatPrice(card.total_price) }}</dd>
                    </template>
                    <template v-if="card.created_at">
                        <dt>{{ t("form.fields.created_at") }}</dt>
                        <dd>{{ formatDateTime(card.created_at) }}</dd>
                    </template>
                    <template v-if="card.updated_at">
                        <dt>{{ t("form.fields.updated_at") }}</dt>
                        <dd>{{ formatDateTime(card.updated_at) }}</dd>
                    </template>
                    <template v-if="card.claims?.length">
                        <dt>{{ t("form.fields.claimed_by_deck") }}</dt>
                        <dd>
                            <card-stack-claim-badge :claims="card.claims" />
                        </dd>
                    </template>
                </dl>
                <card-legalities v-if="card.legalities.length" :legalities="card.legalities" />
                <br />
                <a
                    v-if="card.scryfall_uri"
                    :href="card.scryfall_uri"
                    target="_blank"
                    rel="noopener"
                    class="btn-default"
                >
                    <icon name="external-link" :size="0" />
                    {{ t("components.card_preview.scryfall") }}
                </a>
            </div>
        </div>
    </modal>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;

:deep(.modal-dialog__content) {
    @include m.mq("landscape") {
        min-width: map.get(s.$components, "modal", "max-width");
    }
}
</style>
