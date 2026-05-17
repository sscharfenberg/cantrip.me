<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, useId, useTemplateRef, watch } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
const { t } = useI18n();
interface SelectOption {
    value: string;
    label: string;
    /** Optional thumbnail rendered before the label (e.g. set icon). */
    imageUrl?: string;
    /**
     * Optional secondary text rendered right-aligned at the end of the
     * option button — used for low-priority metadata that shouldn't
     * compete with the primary label (e.g. a set's release year).
     */
    meta?: string;
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
// Image URL (if any) for the currently selected value — rendered as a
// thumbnail next to the label inside the trigger button.
const selectedImageUrl = computed(() => props.options.find(o => o.value === selectedValue.value)?.imageUrl);
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
/**
 * Multi-letter typeahead — while the menu is open, typing successive
 * printable keys within a 500ms idle window builds up a buffer and
 * jumps to the first option whose (case-folded) label starts with it.
 * Useful for long lists like the set picker (1k+ entries) where
 * scrolling to find a specific entry is slow.
 *
 * After 500ms of idle, the buffer resets to the next key alone — so
 * typing "Fo" + (pause) + "B" jumps from "Foundations" to "Battle…".
 */
let typeaheadBuffer = "";
let typeaheadTimer: ReturnType<typeof setTimeout> | null = null;
const onListboxKeydown = (event: KeyboardEvent) => {
    if (!menuOpen.value) return;
    if (event.key.length !== 1) return; // skip Tab, Enter, Arrow*, etc.
    if (event.metaKey || event.ctrlKey || event.altKey) return;
    typeaheadBuffer += event.key.toLowerCase();
    if (typeaheadTimer !== null) clearTimeout(typeaheadTimer);
    typeaheadTimer = setTimeout(() => {
        typeaheadBuffer = "";
    }, 500);
    const match = effectiveOptions.value.find(o => o.label.toLowerCase().startsWith(typeaheadBuffer));
    if (match === undefined) return;
    event.preventDefault();
    const buttons = listbox.value?.querySelectorAll<HTMLButtonElement>("button[data-value]");
    for (const btn of buttons ?? []) {
        if (btn.dataset.value === match.value) {
            btn.scrollIntoView({ block: "nearest" });
            btn.focus(); // gives a visible focus indicator; Enter also activates the button
            break;
        }
    }
};
// Promote the listbox into the browser's top layer via the HTML Popover API
// whenever it opens. This escapes any ancestor overflow/clipping/stacking context
// (e.g. when MonoSelect is rendered inside a modal dialog) so the dropdown is
// never visually cut off. v-if removes the element on close, so no explicit
// hidePopover() call is needed. Also gates the document-level typeahead
// listener — only active while the menu is visible.
watch(menuOpen, async open => {
    if (open) {
        await nextTick();
        listbox.value?.showPopover?.();
        document.addEventListener("keydown", onListboxKeydown);
    } else {
        document.removeEventListener("keydown", onListboxKeydown);
        typeaheadBuffer = "";
        if (typeaheadTimer !== null) {
            clearTimeout(typeaheadTimer);
            typeaheadTimer = null;
        }
    }
});
onMounted(() => {
    document.addEventListener("click", onClickOutSide);
});
onUnmounted(() => {
    document.removeEventListener("click", onClickOutSide);
    document.removeEventListener("keydown", onListboxKeydown);
    if (typeaheadTimer !== null) clearTimeout(typeaheadTimer);
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
            <span v-if="selectedValue" class="form-select__selected">
                <img
                    v-if="selectedImageUrl"
                    :src="selectedImageUrl"
                    class="form-select__option-image"
                    alt=""
                />
                {{ selectedLabel }}
            </span>
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
                        <img
                            v-if="option.imageUrl"
                            :src="option.imageUrl"
                            class="form-select__option-image"
                            alt=""
                        />
                        <span class="form-select__option-label">{{ option.label }}</span>
                        <span v-if="option.meta" class="form-select__option-meta">{{ option.meta }}</span>
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
