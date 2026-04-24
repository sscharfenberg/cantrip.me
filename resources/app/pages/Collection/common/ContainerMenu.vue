<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { ref, useId } from "vue";
import MoveAllCardStacksModal from "@/pages/Collection/common/MoveAllCardStacksModal.vue";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";
import type { Container } from "Types/container.ts";
import type { ContainerListItem } from "Types/containerListItem.ts";
import DeleteContainerModal from "./DeleteContainerModal.vue";
import PruneContainerModal from "./PruneContainerModal.vue";
/** Dismiss the container-menu popover after an action is chosen. */
const closePopover = () => {
    const dialog = document.getElementById(refId);
    if (dialog !== null) dialog.hidePopover();
};
const refId = useId();
defineProps<{ container: Container; containers: ContainerListItem[] }>();
const showDeleteModal = ref(false);
const showPruneModal = ref(false);
const showMoveModal = ref(false);
</script>

<template>
    <pop-over
        icon="more"
        :aria-label="$t('header.user.label')"
        class-string="popover-button--rounded container-context-menu"
        :reference="refId"
        width="14rem"
    >
        <ul class="popover-list">
            <li>
                <Link class="popover-list-item" :href="`/containers/${container.id}/edit`" @click="closePopover">
                    <icon name="edit" :size="1" />
                    {{ $t("pages.edit_container.link") }}
                </Link>
            </li>
            <li>
                <Link class="popover-list-item" :href="`/containers/${container.id}/add`" @click="closePopover">
                    <icon name="add" :size="1" />
                    {{ $t("pages.add_cards.link") }}
                </Link>
            </li>
            <li>
                <button
                    class="popover-list-item"
                    @click="
                        showMoveModal = true;
                        closePopover;
                    "
                >
                    <icon name="move" :size="1" />
                    {{ $t("pages.container_page.mass_move.link") }}
                </button>
            </li>
            <li>
                <Link class="popover-list-item" :href="`/containers/${container.id}/qr`" @click="closePopover">
                    <icon name="qr-code" :size="1" />
                    {{ $t("pages.container_qr.link") }}
                </Link>
            </li>
            <li>
                <a class="popover-list-item" :href="`/containers/${container.id}/export`" @click="closePopover">
                    <icon name="download" :size="1" />
                    {{ $t("pages.containers.export_csv") }}
                </a>
            </li>
            <li>
                <Link class="popover-list-item" :href="`/containers/${container.id}/import`" @click="closePopover">
                    <icon name="upload" :size="1" />
                    {{ $t("pages.import.link") }}
                </Link>
            </li>
            <li>
                <button
                    class="popover-list-item popover-list-item--caution"
                    @click="
                        showPruneModal = true;
                        closePopover;
                    "
                >
                    <icon name="delete" :size="1" />
                    {{ $t("pages.containers.prune.link") }}
                </button>
            </li>
            <li>
                <button
                    class="popover-list-item popover-list-item--error"
                    @click="
                        showDeleteModal = true;
                        closePopover;
                    "
                >
                    <icon name="delete" :size="1" />
                    {{ $t("pages.containers.delete.link") }}
                </button>
            </li>
        </ul>
    </pop-over>
    <delete-container-modal v-if="showDeleteModal" @close="showDeleteModal = false" :container="container" />
    <prune-container-modal v-if="showPruneModal" @close="showPruneModal = false" :container="container" />
    <move-all-card-stacks-modal
        v-if="showMoveModal"
        @close="showMoveModal = false"
        :container="container"
        :containers="containers"
    />
</template>
