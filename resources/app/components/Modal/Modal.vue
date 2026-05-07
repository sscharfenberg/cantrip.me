<script setup lang="ts">
import { onMounted, ref } from "vue";
import ModalBody from "./ModalBody.vue";
import ModalFooter from "./ModalFooter.vue";
import ModalHeader from "./ModalHeader.vue";
const emit = defineEmits<{ close: [] }>();
const modalRef = ref<HTMLDialogElement | null>(null);
const contentRef = ref<HTMLDivElement | null>(null);
const isClosing = ref(false);
/**
 * Open the native dialog via `showModal()`.
 *
 * Resets the local closing flag first so that any previous close animation
 * state does not leak into the next open cycle.
 */
const openModal = () => {
    const modal = modalRef.value;
    if (!modal) return;
    isClosing.value = false;
    modal.showModal();
};
/**
 * Close the dialog with an optional exit animation.
 *
 * If reduced motion is enabled (or the content node is unavailable), the
 * dialog closes immediately. Otherwise, it applies the `is-closing` state
 * and waits for the content animation to finish before calling `close()`.
 */
const closeModal = () => {
    const modal = modalRef.value;
    const content = contentRef.value;
    if (!modal?.open || isClosing.value) return;
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches || !content) {
        modal.close();
        emit("close");
        return;
    }
    isClosing.value = true;
    const handleAnimationEnd = (event: AnimationEvent) => {
        if (event.target !== content) return;
        isClosing.value = false;
        modal.close();
        emit("close");
    };
    content.addEventListener("animationend", handleAnimationEnd, { once: true });
};
/**
 * Intercept native cancel requests (for example Escape key presses) so the
 * modal can run its exit animation before the dialog is actually closed.
 *
 * @param event - Native dialog cancel event.
 */
const onDialogCancel = (event: Event) => {
    event.preventDefault();
    closeModal();
};
/**
 * Close the modal when users click outside the content area on the dialog
 * backdrop itself.
 *
 * @param event - Mouse click event emitted by the dialog element.
 */
const onDialogClick = (event: MouseEvent) => {
    if (event.target === event.currentTarget) {
        closeModal();
    }
};
/**
 * Open the dialog immediately after mount so rendering this component
 * behaves like "show this modal now" for consumers.
 */
onMounted(() => {
    openModal();
});
</script>

<template>
    <!--
        Teleport to <body> so the modal's DOM lives outside whatever
        parent rendered <Modal>. Without this, mounting the modal inside
        a clickable ancestor (e.g. an Inertia <Link> on the deck list)
        means a click on the close button is still a click "inside" the
        anchor — the browser's default-navigation action for <a> kicks
        in and a stray `stopPropagation` won't stop it (only
        `preventDefault` would, but that would also block legitimate
        links inside modals).
    -->
    <Teleport to="body">
        <dialog
            id="modal"
            ref="modalRef"
            class="modal-dialog"
            :class="{ 'is-closing': isClosing }"
            closedby="closerequest"
            @cancel="onDialogCancel"
            @click="onDialogClick"
        >
            <div ref="contentRef" class="modal-dialog__content">
                <modal-header @close="closeModal"><slot name="header" /></modal-header>
                <modal-body :has-footer="!!$slots.footer"><slot /></modal-body>
                <modal-footer v-if="$slots.footer"><slot name="footer" /></modal-footer>
            </div>
        </dialog>
    </Teleport>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/typography" as t;
@forward "modal-animations";

.modal-dialog {
    position: fixed;

    overflow-y: hidden;
    width: 100dvw;
    height: 100dvh;
    max-height: 100dvh;
    padding: 0;
    border: 0;
    margin: 0 auto;

    background: transparent;
    outline: 0;

    font-style: normal;

    &::backdrop {
        opacity: 0;

        background: map.get(c.$components, "modal", "backdrop");
        backdrop-filter: blur(10px);
    }

    &__content {
        display: flex;
        flex-direction: column;

        // Bounded by viewport (minus the vertical margin below) but free to
        // shrink for small content — the body flex-grows to fill whatever
        // space is available inside this cap.
        overflow: hidden;
        width: 100%;
        max-width: map.get(s.$components, "modal", "max-width");
        max-height: calc(100dvh - 2rem);
        border: map.get(s.$components, "modal", "border") solid map.get(c.$components, "modal", "border");
        margin: 1rem auto;

        background-color: map.get(c.$components, "modal", "background");
        color: map.get(c.$components, "modal", "surface");
        border-radius: map.get(s.$components, "modal", "radius");

        // reset modal styles, since we might open the modal from within an
        // element with different values, and we don't want to inherit them.
        font-family: map.get(t.$main, "modal-content");
        font-size: 1rem;
        font-weight: normal;
        text-shadow: none;
        letter-spacing: 0;
    }
}
</style>
