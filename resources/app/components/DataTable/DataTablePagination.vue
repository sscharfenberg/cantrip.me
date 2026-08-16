<script setup lang="ts">
import { computed, ref } from "vue";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import Icon from "Components/UI/Icon.vue";
const props = defineProps<{
    /** Current page number (1-based). */
    page: number;
    /** Number of rows per page. */
    pageSize: number;
    /** Total number of rows across all pages. */
    total: number;
}>();
const emit = defineEmits<{
    /** Emitted when the user navigates to a different page. */
    navigate: [page: number];
    /** Emitted when the user changes the page size. */
    pageSizeChange: [size: number];
}>();
const totalPages = computed(() => Math.ceil(props.total / props.pageSize));
/** First row number displayed on the current page (1-based). */
const from = computed(() => (props.page - 1) * props.pageSize + 1);
/** Last row number displayed on the current page. */
const to = computed(() => Math.min(props.page * props.pageSize, props.total));
/**
 * Visible page numbers with ellipsis truncation.
 * Shows a sliding window around the current page. First/last page
 * buttons already exist as dedicated icons, so they are not repeated here.
 * Ellipsis indicates more pages exist in that direction.
 * Example for page 5 of 20: ["...", 3, 4, 5, 6, 7, "..."]
 */
const NEIGHBORS = 2;
const visiblePages = computed(() => {
    const pages: (number | "...")[] = [];
    const total = totalPages.value;
    const current = props.page;
    if (total <= 1) return pages;
    const start = Math.max(1, current - NEIGHBORS);
    const end = Math.min(total, current + NEIGHBORS);
    if (start > 1) pages.push("...");
    for (let i = start; i <= end; i++) pages.push(i);
    if (end < total) pages.push("...");
    return pages;
});
/** User-entered page number for the "jump to page" input. */
const jumpToPage = ref(props.page);
/**
 * Clamp user input to the valid range before navigating.
 *
 * `v-model.number` leaves unparseable input as the raw string, so anything
 * non-numeric has to fall back to the current page — clamping it would
 * otherwise produce NaN and navigate nowhere.
 */
function onJumpToPage() {
    const requested = Number(jumpToPage.value);
    const target = Number.isFinite(requested) ? requested : props.page;
    const clamped = Math.max(1, Math.min(totalPages.value, target));
    jumpToPage.value = clamped;
    emit("navigate", clamped);
}
const pageSizeOptions = [25, 50, 100].map(s => ({ value: String(s), label: String(s) }));
</script>

<template>
    <nav class="dt-pagination" :aria-label="$t('components.datatable.pagination')">
        <div v-if="totalPages > 1" class="dt-pagination__col">
            <button
                :disabled="page <= 1"
                @click="emit('navigate', 1)"
                :aria-label="$t('components.datatable.first')"
                class="dt-pagination__page"
            >
                <icon name="first-page" :size="1" />
            </button>
            <button
                :disabled="page <= 1"
                @click="emit('navigate', page - 1)"
                :aria-label="$t('components.datatable.previous')"
                class="dt-pagination__page"
            >
                <icon name="chevron" :size="1" :additional-classes="['left']" />
            </button>
            <template v-for="p in visiblePages" :key="p">
                <span v-if="p === '...'" class="dt-pagination__ellipsis">…</span>
                <button
                    v-else
                    :class="{ 'dt-pagination__current': p === page }"
                    :aria-current="p === page ? 'page' : undefined"
                    @click="emit('navigate', p)"
                    class="dt-pagination__page"
                >
                    {{ p }}
                </button>
            </template>
            <button
                :disabled="page >= totalPages"
                @click="emit('navigate', page + 1)"
                :aria-label="$t('components.datatable.next')"
                class="dt-pagination__page"
            >
                <icon name="chevron" :size="1" :additional-classes="['right']" />
            </button>
            <button
                :disabled="page >= totalPages"
                @click="emit('navigate', totalPages)"
                :aria-label="$t('components.datatable.last')"
                class="dt-pagination__page"
            >
                <icon name="last-page" :size="1" />
            </button>
        </div>
        <div v-if="totalPages > 1" class="dt-pagination__col">
            <label for="jumpToPage">{{ $t("components.datatable.jump_to_page") }}</label>
            <input
                type="text"
                inputmode="numeric"
                :min="1"
                :max="totalPages"
                id="jumpToPage"
                v-model.number="jumpToPage"
                @keydown.enter="onJumpToPage"
                class="form-input dt-pagination__jump"
                :aria-label="$t('components.datatable.jump_to_page')"
            />
        </div>
        <div class="dt-pagination__col">
            <span class="dt-pagination__info"> {{ from }}–{{ to }} / {{ total }} </span>
            <mono-select
                :options="pageSizeOptions"
                :selected="String(pageSize)"
                :sort="false"
                :aria-label="$t('components.datatable.page_size')"
                @change="emit('pageSizeChange', Number($event))"
                :clearable="false"
            />
        </div>
    </nav>
</template>
