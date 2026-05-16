<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { ref, watch } from "vue";
import { VueDraggable } from "vue-draggable-plus";
import { useI18n } from "vue-i18n";
import ContainerMenu from "@/pages/Collection/common/ContainerMenu.vue";
import Icon from "Components/UI/Icon.vue";
import VisibilityBadge from "Components/UI/VisibilityBadge.vue";
import { useFormatting } from "Composables/useFormatting";
import type { Container } from "Types/container";
const { t } = useI18n();
const { formatPrice, formatDecimals } = useFormatting();
const props = defineProps<{ containers: Container[] }>();
/** Emitted after a successful drag-drop; carries the visible rows in their new order. */
const emit = defineEmits<{ reorder: [containers: Container[]] }>();
/**
 * Local writable copy of the containers prop, used as VueDraggable's v-model.
 * A watch keeps it in sync whenever the parent passes a fresh array (e.g. after
 * an Inertia page reload), without breaking any in-progress drag.
 */
const list = ref([...props.containers]);
watch(
    () => props.containers,
    val => {
        list.value = [...val];
    }
);
</script>

<template>
    <VueDraggable
        v-model="list"
        tag="ul"
        class="clist"
        handle=".clist__drag-handle"
        ghost-class="clist__item--ghost"
        @end="emit('reorder', list)"
    >
        <li v-for="container in list" :key="container.id" class="clist__item">
            <span class="clist__drag-handle"><icon name="drag" /></span>
            <Link class="clist__data" :href="`/containers/${container.id}`">
                <img
                    v-if="container.defaultCard"
                    :src="container.defaultCard.art_crop"
                    class="clist__image"
                    :alt="container.name"
                />
                <span v-else class="clist__image" />
                <span class="clist__name">
                    {{ container.name }}
                    <span v-if="container.description" class="clist__description">{{ container.description }}</span>
                </span>
                <span class="clist__type"
                    ><icon name="storage" />
                    {{
                        container.type === "other"
                            ? container.custom_type
                            : $t("enums.container_type." + container.type)
                    }}</span
                >
                <span
                    class="clist__count"
                    v-tooltip="
                        t(
                            'pages.container_page.cards_count',
                            { count: formatDecimals(container.totalCards) },
                            container.totalCards
                        )
                    "
                    ><icon name="deck" />{{ formatDecimals(container.totalCards) }}</span
                >
                <span class="clist__price"><icon name="money" />{{ formatPrice(container.totalPrice) }}</span>
                <visibility-badge class="clist__visibility" :visibility="container.visibility" />
            </Link>
            <ContainerMenu :container="container" :containers="containers" />
        </li>
    </VueDraggable>
</template>

<style lang="scss" scoped>
/**
 * .clist styles live in @/styles/components/_container-list.scss
 * (shared with ContainerQrSheet.vue — both pages render the same
 * container-row grid).
 */
</style>
