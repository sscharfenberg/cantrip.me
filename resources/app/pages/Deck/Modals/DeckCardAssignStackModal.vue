<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from "vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";

/** A row returned by the assignable-stacks endpoint. */
interface AssignableStack {
    id: string;
    amount: number;
    finish: string;
    language: string;
    condition: string | null;
    container: { id: string; name: string; type: string } | null;
    /** True when this stack is already pivoted to the current deck card. */
    currently_assigned: boolean;
    /**
     * Non-null when the stack is pivoted somewhere. The picker uses this
     * to disable rows claimed by a different deck (the FormRequest also
     * rejects them server-side as belt-and-suspenders).
     */
    claim: { deck_id: string; deck_name: string; is_this_deck_card: boolean } | null;
}
const emit = defineEmits<{
    close: [];
    /** Fired when the user picks a stack — the caller owns the PATCH and reload. */
    select: [stackId: string | null];
}>();
const props = defineProps<{
    /** GET endpoint that returns `AssignableStack[]`. */
    stacksUrl: string;
    /** Card name, interpolated into the modal title. */
    name: string;
}>();
/** True while the stacks XHR is in flight. */
const loading = ref(true);
/** True when the stacks fetch failed. */
const error = ref(false);
/** Stacks returned by the API. */
const stacks = ref<AssignableStack[]>([]);
/** Whether at least one stack is currently assigned to this deck card. */
const hasCurrentAssignment = ref(false);
/** AbortController so the in-flight request is cancelled if the modal is unmounted. */
let abortController: AbortController | null = null;
onMounted(async () => {
    abortController = new AbortController();
    try {
        const response = await fetch(props.stacksUrl, {
            headers: { Accept: "application/json" },
            signal: abortController.signal
        });
        if (response.ok) {
            const data = (await response.json()) as AssignableStack[];
            // Pin the currently-assigned stack to the top, then unclaimed stacks,
            // then stacks claimed elsewhere. Frozen for the lifetime of the modal.
            data.sort((a, b) => {
                const score = (s: AssignableStack): number => {
                    if (s.currently_assigned) return 0;
                    if (s.claim === null) return 1;
                    return 2;
                };
                return score(a) - score(b);
            });
            stacks.value = data;
            hasCurrentAssignment.value = data.some(s => s.currently_assigned);
        } else {
            error.value = true;
        }
    } catch (e) {
        if (e instanceof DOMException && e.name === "AbortError") return;
        error.value = true;
    } finally {
        loading.value = false;
    }
});
onBeforeUnmount(() => {
    if (abortController) abortController.abort();
});
/**
 * Bubble the selected stack up to the caller and close. The caller owns
 * the PATCH and reload — the modal is a pure picker.
 *
 * No-op when the user re-clicks the already-assigned row, mirroring the
 * switch-printing modal.
 */
function pickStack(stack: AssignableStack): void {
    if (stack.currently_assigned) return;
    if (stack.claim !== null && !stack.claim.is_this_deck_card) return;
    emit("select", stack.id);
    emit("close");
}
/** Clear the assignment entirely (detach without picking a replacement). */
function clearAssignment(): void {
    emit("select", null);
    emit("close");
}
</script>

<template>
    <modal @close="emit('close')">
        <template #header>
            <i18n-t keypath="pages.deck.assign_stack.title" scope="global">
                <template #card>
                    <cite>{{ name }}</cite>
                </template>
            </i18n-t>
        </template>
        <div v-if="loading" class="assign-stack__loading">
            <loading-spinner :size="4" :branded="true" />
            <p>{{ $t("pages.deck.assign_stack.loading") }}</p>
        </div>
        <div v-else-if="error" class="assign-stack__error">
            <icon name="error" :size="2" />
            <p>{{ $t("pages.deck.assign_stack.load_error") }}</p>
        </div>
        <div v-else-if="stacks.length === 0" class="assign-stack__empty">
            <p>{{ $t("pages.deck.assign_stack.no_stacks") }}</p>
        </div>
        <ul v-else class="assign-stack__list">
            <li
                v-for="stack in stacks"
                :key="stack.id"
                class="assign-stack__item"
                :class="{
                    'assign-stack__item--current': stack.currently_assigned,
                    'assign-stack__item--locked': stack.claim !== null && !stack.claim.is_this_deck_card
                }"
            >
                <button
                    type="button"
                    class="assign-stack__button"
                    :disabled="stack.claim !== null && !stack.claim.is_this_deck_card"
                    @click="pickStack(stack)"
                >
                    <span class="assign-stack__container">
                        <template v-if="stack.container !== null">
                            <icon name="storage" />
                            {{
                                $t("pages.deck.assign_stack.stack_label_with_container", {
                                    container_type: $t(`enums.container_type.${stack.container.type}`),
                                    container_name: stack.container.name
                                })
                            }}
                        </template>
                        <template v-else>
                            {{ $t("pages.deck.assign_stack.stack_label_unsorted") }}
                        </template>
                    </span>
                    <span v-if="stack.currently_assigned" class="assign-stack__badge assign-stack__badge--current">
                        <icon name="check" :size="1" />
                        {{ $t("pages.deck.assign_stack.currently_assigned") }}
                    </span>
                    <span class="assign-stack__amount">
                        {{ $t("pages.deck.assign_stack.amount", { amount: stack.amount }) }}
                    </span>
                    <span
                        v-if="!stack.currently_assigned && stack.claim !== null && !stack.claim.is_this_deck_card"
                        class="assign-stack__badge assign-stack__badge--locked"
                    >
                        {{
                            $t("pages.deck.assign_stack.claimed_by_other_deck", {
                                deck: stack.claim.deck_name
                            })
                        }}
                    </span>
                </button>
            </li>
        </ul>
        <template v-if="!loading && !error && hasCurrentAssignment" #footer>
            <button type="button" class="btn-default" @click="clearAssignment">
                <icon name="delete" />
                {{ $t("pages.deck.assign_stack.clear") }}
            </button>
        </template>
    </modal>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.assign-stack__loading,
.assign-stack__error,
.assign-stack__empty {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;

    padding: 2rem;
    gap: 1rem;
}

.assign-stack__list {
    display: flex;
    flex-direction: column;

    padding: 0;

    margin: 0;
    gap: map.get(s.$pages, "deck", "assign-stack", "list-gap");

    list-style: none;
}

.assign-stack__button {
    display: flex;
    align-items: center;
    flex-wrap: wrap;

    width: 100%;
    padding: map.get(s.$pages, "deck", "assign-stack", "button-padding");

    border: map.get(s.$pages, "deck", "assign-stack", "border") solid currentcolor;
    gap: 0.75rem;

    border-radius: 0.25rem;

    text-align: left;

    cursor: pointer;

    &:disabled {
        opacity: 0.5;

        cursor: not-allowed;
    }
}

.assign-stack__item--current .assign-stack__button {
    background-color: map.get(c.$pages, "deck", "assign-stack", "current-background");
    color: map.get(c.$pages, "deck", "assign-stack", "current-surface");
}

.assign-stack__container {
    display: flex;
    align-items: center;

    flex: 1 1 auto;
    gap: map.get(s.$pages, "deck", "assign-stack", "list-gap");
}

.assign-stack__amount {
    flex: 0 0 auto;

    font-weight: 600;
}

.assign-stack__badge {
    display: inline-flex;
    align-items: center;

    flex: 0 0 auto;

    padding: 0.125rem 0.5rem;

    border: 1px solid currentcolor;
    gap: 0.25rem;

    border-radius: 0.25rem;

    font-size: 0.85em;
}
</style>
