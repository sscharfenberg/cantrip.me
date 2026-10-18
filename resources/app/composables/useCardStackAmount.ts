import { router, usePage } from "@inertiajs/vue3";
import type { ComputedRef, Ref } from "vue";
import { computed, onBeforeUnmount, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useToast } from "Composables/useToast.ts";

/** The largest amount a stack can hold — `CardStack::MAX_AMOUNT`. */
export const MAX_STACK_AMOUNT = 65535;

/** Milliseconds to wait after the last click before the net change is sent. */
const DEBOUNCE_MS = 500;

/** The table-row fields the +/- buttons read and update. */
export type AmountRow = {
    id: string;
    amount: number;
    /** Unit price — `total_price` is shown as `price × amount`. */
    price: number;
    total_price: number;
};

/** Return type of {@link useCardStackAmount}. */
export type UseCardStackAmountReturn = {
    /** The amount the clicks so far add up to. */
    amount: ComputedRef<number>;
    /** False at {@link MAX_STACK_AMOUNT}. */
    canIncrement: ComputedRef<boolean>;
    /** Add one card. */
    increment: () => void;
    /**
     * Remove one card. Returns false, changing nothing, when only one is
     * left: taking the last card is a delete, which the caller confirms.
     */
    decrement: () => boolean;
};

/** One stack's counting, shared by every menu opened for it until it is saved. */
type StackState = {
    /** The amount the clicks so far add up to. */
    effective: Ref<number>;
    /** The amount the requests sent so far ask for — the base of the next delta. */
    requested: number;
    /** The last amount the server confirmed. */
    confirmed: number;
    timer: ReturnType<typeof setTimeout> | null;
    /** The requests sent so far, one after another. */
    chain: Promise<void>;
    /** Menus open for this stack right now. */
    mounted: number;
    /** Whether something was saved since the table last reloaded. */
    saved: boolean;
    /** Send the waiting clicks now; resolves once every request so far is done. */
    flush: () => Promise<void>;
};

const states = new Map<string, StackState>();

/**
 * Send every stack's waiting clicks now and wait until the server has them
 * — before anything that reads the amount back: the edit form, a delete.
 */
export async function settleAmountChanges(): Promise<void> {
    await Promise.all([...states.values()].map(state => state.flush()));
}

/**
 * The +/- buttons of a card stack in the collection and container tables'
 * row actions — the deck page's quantity buttons, collection-side.
 *
 * Each click shows at once: the table's row gets its new amount and total.
 * The clicks are collected for {@link DEBOUNCE_MS} and sent as one PATCH
 * carrying their net change; requests go out one after another, so they
 * add up on the server. After each, the props in `totals` reload — the
 * page's sums, not the table: the tables sort by last change by default,
 * so a reloaded table would move the row out from under the open menu.
 * The table reloads once the menu has closed and everything is saved.
 *
 * A failed request takes the amount back to the last one the server
 * confirmed and says so. The counting lives per stack, outside the menu,
 * so a menu reopened while a request is still out continues from it.
 */
export function useCardStackAmount(params: {
    row: AmountRow;
    rows: () => AmountRow[];
    totals: string[];
}): UseCardStackAmountReturn {
    const page = usePage();
    const { t } = useI18n();
    const { addToast } = useToast();
    const id = params.row.id;
    /** The page the menu opened on — reloads are for it only. */
    const component = page.component;
    const onThisPage = (): boolean => page.component === component;

    /** Show `amount` in the table right away. */
    function show(amount: number): void {
        const row = params.rows().find(r => r.id === id);
        if (!row) return;
        row.amount = amount;
        row.total_price = Math.round(row.price * amount * 100) / 100;
    }

    async function send(state: StackState): Promise<void> {
        const delta = state.effective.value - state.requested;
        if (delta === 0) return;
        state.requested += delta;
        try {
            const response = await fetch(`/api/collection/cardstack/${id}/amount`, {
                method: "PATCH",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": page.props.csrfToken as string,
                    Accept: "application/json"
                },
                body: JSON.stringify({ delta })
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = (await response.json()) as { amount: number };
            // The server's word wins — another tab may have changed the stack.
            const drift = data.amount - state.requested;
            state.requested = data.amount;
            state.confirmed = data.amount;
            state.saved = true;
            if (drift !== 0) {
                state.effective.value += drift;
                show(state.effective.value);
            }
            if (state.mounted > 0 && onThisPage()) router.reload({ only: params.totals });
        } catch {
            if (state.timer !== null) clearTimeout(state.timer);
            state.timer = null;
            state.requested = state.confirmed;
            state.effective.value = state.confirmed;
            show(state.confirmed);
            addToast(t("pages.collection.amount.error"), "error");
        }
    }

    let state = states.get(id);
    if (state === undefined) {
        const created: StackState = {
            effective: ref(params.row.amount),
            requested: params.row.amount,
            confirmed: params.row.amount,
            timer: null,
            chain: Promise.resolve(),
            mounted: 0,
            saved: false,
            flush: () => {
                if (created.timer !== null) clearTimeout(created.timer);
                created.timer = null;
                created.chain = created.chain.then(() => send(created));
                return created.chain;
            }
        };
        states.set(id, created);
        state = created;
    }
    const current = state;
    current.mounted++;

    const amount = computed(() => current.effective.value);
    const canIncrement = computed(() => current.effective.value < MAX_STACK_AMOUNT);

    function schedule(): void {
        if (current.timer !== null) clearTimeout(current.timer);
        current.timer = setTimeout(() => void current.flush(), DEBOUNCE_MS);
    }

    function increment(): void {
        if (!canIncrement.value) return;
        current.effective.value++;
        show(current.effective.value);
        schedule();
    }

    function decrement(): boolean {
        if (current.effective.value <= 1) {
            void current.flush();
            return false;
        }
        current.effective.value--;
        show(current.effective.value);
        schedule();
        return true;
    }

    onBeforeUnmount(() => {
        current.mounted--;
        void current.flush().then(() => {
            if (current.mounted > 0 || current.timer !== null) return;
            states.delete(id);
            if (current.saved && onThisPage()) router.reload({ only: ["table", ...params.totals] });
        });
    });

    return { amount, canIncrement, increment, decrement };
}
