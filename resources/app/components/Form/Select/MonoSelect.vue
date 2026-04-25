<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, useId, useTemplateRef, watch } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
const { t } = useI18n();
interface SelectOption {
    value: string;
    label: string;
}
const props = withDefaults(
    defineProps<{
        options: SelectOption[];
        selected?: string;
        placeholder?: string;
        addonIcon?: string;
        sort?: boolean;
        max?: string;
        clearable?: boolean;
        /** When true, the trigger button + clear button are disabled. */
        disabled?: boolean;
    }>(),
    { sort: true, max: "100%", clearable: true, disabled: false }
);
// Falls back to the i18n default when no placeholder prop is provided.
const effectivePlaceholder = computed(() => props.placeholder ?? t("components.select.placeholder"));
/**
 * Returns the options to render in the listbox.
 * When `sort` is true, options are sorted alphabetically by label.
 * The "other" option is always pinned to the bottom regardless of its label,
 * because it represents a catch-all choice that should not compete with named options.
 */
const effectiveOptions = computed(() =>
    props.sort
        ? [...props.options].sort((a, b) => {
              if (a.value === "other") return 1; // "other" sinks to the bottom
              if (b.value === "other") return -1; // everything else floats above it
              return a.label.localeCompare(b.label);
          })
        : props.options
);
const emit = defineEmits(["change"]);
// Unique IDs tie the trigger button, clear button, and listbox together for ARIA.
const uid = useId();
const anchorName = `--select-${uid}`;
const buttonAnchorName = `--select-button-${uid}`;
const buttonId = `select-button-${uid}`;
const listboxId = `select-listbox-${uid}`;
const menuOpen = ref(false);
const selectedValue = ref(props.selected);
// Used for click-outside detection to close the dropdown.
const dropdown = useTemplateRef<HTMLDivElement>("dropdown");
// Template ref to the listbox element — used to promote it into the top layer
// via the HTML Popover API so it escapes any ancestor overflow/clipping
// (e.g. when MonoSelect is rendered inside a modal dialog).
const listbox = useTemplateRef<HTMLDivElement>("listbox");
// Resolves the human-readable label for the currently selected value.
const selectedLabel = computed(() => props.options.find(o => o.value === selectedValue.value)?.label);
/**
 * Sets the selected value and emits a change event if the value changed.
 * Always closes the menu afterwards.
 *
 * @param value - The value of the selected option.
 */
const select = (value: string) => {
    if (value !== selectedValue.value) {
        selectedValue.value = value;
        emit("change", value);
    }
    menuOpen.value = false;
};
/**
 * Toggles the dropdown menu open or closed.
 */
const toggleMenu = () => {
    menuOpen.value = !menuOpen.value;
};
/**
 * Scrolls the selected option into view after the enter transition completes.
 *
 * @param el - The transitioned element.
 */
const onAfterEnter = (el: Element) => {
    const _option = el.querySelector(`button[data-value="${selectedValue.value}"]`);
    _option?.scrollIntoView();
};
/**
 * Closes the dropdown when a click occurs outside the component.
 *
 * @param ev - The native click event.
 */
const onClickOutSide = (ev: MouseEvent) => {
    if (!(dropdown.value === ev.target || dropdown.value?.contains(ev.target as Node))) {
        menuOpen.value = false;
    }
};
// Keep internal state in sync when the parent updates the selected prop externally.
watch(
    () => props.selected,
    value => {
        selectedValue.value = value;
    },
    { immediate: true }
);
// Promote the listbox into the browser's top layer via the HTML Popover API
// whenever it opens. This escapes any ancestor overflow/clipping/stacking context
// (e.g. when MonoSelect is rendered inside a modal dialog) so the dropdown is
// never visually cut off. v-if removes the element on close, so no explicit
// hidePopover() call is needed.
watch(menuOpen, async open => {
    if (open) {
        await nextTick();
        listbox.value?.showPopover?.();
    }
});
onMounted(() => {
    document.addEventListener("click", onClickOutSide);
});
onUnmounted(() => {
    document.removeEventListener("click", onClickOutSide);
});
</script>

<template>
    <div class="form-select" ref="dropdown" :style="{ 'max-width': max, 'anchor-name': anchorName }">
        <span v-if="addonIcon" class="form-select__addon"><icon :name="addonIcon" /></span>
        <button
            type="button"
            :id="buttonId"
            class="form-select__button"
            :class="{ open: menuOpen }"
            :style="{ 'anchor-name': buttonAnchorName }"
            :aria-expanded="menuOpen"
            :aria-controls="listboxId"
            aria-haspopup="listbox"
            :disabled="disabled"
            @click.prevent="toggleMenu"
        >
            <span v-if="selectedValue">{{ selectedLabel }}</span>
            <span v-else>{{ effectivePlaceholder }}</span>
            <span class="form-select__caret" aria-hidden="true" />
        </button>
        <button
            v-if="selectedValue && clearable && !disabled"
            type="button"
            class="form-select__clear"
            :style="{ 'position-anchor': buttonAnchorName }"
            @click.prevent="select('')"
            :aria-label="$t('components.select.clear')"
        >
            <icon name="clear" aria-hidden="true" />
        </button>
        <Transition name="slide-down" @after-enter="onAfterEnter">
            <div
                v-if="menuOpen"
                ref="listbox"
                :id="listboxId"
                role="listbox"
                :aria-labelledby="buttonId"
                popover="manual"
                class="form-select__options"
                :style="{ 'position-anchor': anchorName }"
            >
                <div class="form-select__scroll">
                    <button
                        v-for="option in effectiveOptions"
                        :key="option.value"
                        type="button"
                        role="option"
                        :data-value="option.value"
                        :aria-selected="selectedValue === option.value"
                        :class="{
                            'form-select__option--selected': selectedValue === option.value
                        }"
                        class="form-select__option"
                        @click.prevent="select(option.value)"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </div>
        </Transition>
    </div>
</template>

<style scoped lang="scss">
.slide-down-enter-active,
.slide-down-leave-active {
    transition: clip-path 0.15s ease;
}

.slide-down-enter-from,
.slide-down-leave-to {
    clip-path: inset(0 0 100% 0);
}

.slide-down-enter-to,
.slide-down-leave-from {
    clip-path: inset(0 0 0% 0);
}
</style>
