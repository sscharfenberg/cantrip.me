import { router, usePage } from "@inertiajs/vue3";
import type { ComputedRef } from "vue";
import { computed, ref, watch } from "vue";
import type { DeckCardDefaultCard, DeckCardRow } from "Types/deckPage";
import type { DeckPrinting } from "Types/defaultCardImage";

/** Parameters for {@link useDeckCardActions}. */
export interface DeckCardActionParams {
    /** UUID of the deck. */
    deckId: string;
    /** UUID of the deck card entry. */
    cardId: string;
    /** UUID of the oracle card — used to sum quantities across split rows. */
    oracleCardId: string;
    /** Getter for the server-authoritative quantity (reactive prop). */
    quantity: () => number;
    /**
     * Getter for the full deck card list — used to sum sibling rows sharing
     * the same oracle card. Must be a reactive getter (the same source the
     * parent uses to render) so the computed updates after split/merge.
     */
    cards: () => DeckCardRow[];
    /** Whether this card is a basic land (exempt from copy limits). */
    isBasicLand: boolean;
    /**
     * Whether this card carries the "a deck can have any number of cards
     * named" clause (e.g. Rat Colony) — exempt from copy limits and singleton.
     */
    isUnlimited: boolean;
    /** Maximum copies allowed by the format (e.g. 4, or 1 for singleton). */
    maxCopies: number;
    /** Whether the format is singleton. */
    isSingleton: boolean;
}

/** Return type of {@link useDeckCardActions}. */
export type UseDeckCardActionsReturn = {
    /** Whether one more copy can be added (accounts for pending clicks). */
    canIncrement: ComputedRef<boolean>;
    /** Add one copy. Debounced — rapid clicks are batched into a single request. */
    increment: () => void;
    /** Remove one copy. Deletes the card when the effective quantity reaches zero. */
    decrement: () => void;
    /** Remove the card entirely, regardless of quantity. */
    destroy: () => Promise<void>;
    /** Move one copy of this card to the opposite zone (main ↔ side). */
    moveZone: (targetZone: "main" | "side") => Promise<void>;
    /** Optimistically swap the card's printing in place; rolls back on failure. */
    switchPrinting: (printing: DeckPrinting) => Promise<void>;
};

/** Milliseconds to wait after the last click before flushing the delta. */
const DEBOUNCE_MS = 500;

/**
 * Manages deck card quantity mutations with debounced batching.
 *
 * Tracks an optimistic local quantity so that `canIncrement` stays
 * accurate across rapid clicks (e.g. disables the button at the copy
 * limit). Clicks within {@link DEBOUNCE_MS} are collapsed into a single
 * PATCH request carrying the net delta.
 *
 * After a successful response the composable updates the Inertia page
 * props in place — mutating the same reactive `cards` array the
 * components reference — so the DOM patches without a full reload.
 * Every successful mutation then triggers a lightweight
 * `router.reload({ only: ["deck", "cards", "violations", "tokens"] })` so the
 * server-computed legality (per-card `is_illegal` and the legality panel)
 * stays in sync; the in-place mutation is what gives instant UI feedback,
 * and the reload catches up in the background.
 *
 * @param params — card identity, format rules, and a reactive quantity getter.
 * @param closePopover — callback to dismiss the host popover after destructive actions.
 */
export function useDeckCardActions(params: DeckCardActionParams, closePopover: () => void): UseDeckCardActionsReturn {
    const page = usePage();

    /**
     * Local quantity reflecting pending clicks. Drives the `canIncrement`
     * check so rapid clicking correctly disables the button at the limit.
     * Reset whenever Inertia delivers fresh server data.
     */
    const effectiveQty = ref(params.quantity());
    watch(params.quantity, q => {
        effectiveQty.value = q;
    });

    /**
     * Whether one more copy can be added.
     *
     * Sums sibling rows with the same oracle card (split printings) so the
     * limit applies to the oracle card in aggregate, not to each row
     * independently.
     */
    const canIncrement = computed((): boolean => {
        if (params.isBasicLand) return true;
        if (params.isUnlimited) return true;
        if (params.isSingleton) return false;
        const siblingSum = params
            .cards()
            .filter(c => c.oracle_card_id === params.oracleCardId && c.id !== params.cardId)
            .reduce((sum, c) => sum + c.quantity, 0);
        return siblingSum + effectiveQty.value < params.maxCopies;
    });

    /** Debounce handle — cleared and reset on every click. */
    let flushTimer: ReturnType<typeof setTimeout> | null = null;

    /** Schedule a flush after the debounce window. */
    function scheduleFlush(): void {
        if (flushTimer !== null) clearTimeout(flushTimer);
        flushTimer = setTimeout(() => void flush(), DEBOUNCE_MS);
    }

    /**
     * Remove a card from the Inertia page props array by id.
     * Triggers Vue reactivity so the card disappears from the DOM
     * without a full page reload.
     */
    function spliceCard(): void {
        const cards = page.props.cards as DeckCardRow[];
        const idx = cards.findIndex(c => c.id === params.cardId);
        if (idx !== -1) cards.splice(idx, 1);
    }

    /**
     * Send the accumulated delta to the server, then patch page props
     * in place. Only reloads deck metadata when the card is deleted
     * (card count / colors may have changed).
     */
    async function flush(): Promise<void> {
        flushTimer = null;
        const delta = effectiveQty.value - params.quantity();
        if (delta === 0) return;

        const response = await fetch(`/api/decks/${params.deckId}/cards/${params.cardId}/quantity`, {
            method: "PATCH",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": page.props.csrfToken as string,
                Accept: "application/json"
            },
            body: JSON.stringify({ delta })
        });

        if (!response.ok) {
            effectiveQty.value = params.quantity();
            return;
        }

        // `.catch` because `flush` is fired and forgotten from `increment` /
        // `decrement`: a 200 carrying HTML (a Fortify redirect, a 419 page)
        // would otherwise reject with nothing to catch it.
        const data = (await response.json().catch(() => ({}))) as { quantity?: number; deleted?: boolean };

        if (data.deleted) {
            spliceCard();
        } else if (data.quantity !== undefined) {
            const cards = page.props.cards as DeckCardRow[];
            const card = cards.find(c => c.id === params.cardId);
            if (card) card.quantity = data.quantity;
        }

        router.reload({ only: ["deck", "cards", "violations", "tokens"] });
    }

    /** Add one copy (debounced). */
    function increment(): void {
        if (!canIncrement.value) return;
        effectiveQty.value++;
        scheduleFlush();
    }

    /**
     * Remove one copy (debounced). When the effective quantity reaches
     * zero the flush fires immediately and the popover is closed.
     */
    function decrement(): void {
        effectiveQty.value--;
        if (effectiveQty.value <= 0) {
            if (flushTimer !== null) clearTimeout(flushTimer);
            void flush();
            closePopover();
            return;
        }
        scheduleFlush();
    }

    /** Remove the card entirely, cancelling any pending quantity flush. */
    async function destroy(): Promise<void> {
        if (flushTimer !== null) clearTimeout(flushTimer);
        closePopover();

        const response = await fetch(`/api/decks/${params.deckId}/cards/${params.cardId}`, {
            method: "DELETE",
            headers: {
                "X-CSRF-TOKEN": page.props.csrfToken as string,
                Accept: "application/json"
            }
        });

        if (response.ok) {
            spliceCard();
            router.reload({ only: ["deck", "cards", "violations", "tokens"] });
        }
    }

    /**
     * Move one copy of the card to the opposite zone. Flushes any pending
     * quantity delta first so the source row's server-side quantity is
     * authoritative before the move runs.
     *
     * Avoids the visible "jerk" that comes from a `cards` reload (every
     * row reactively rebinds, and the brand-new target row pops in at its
     * sorted position once the round trip completes). Instead the response
     * carries the post-move identities, the target is mutated/synthesised
     * locally — synth rows clone the source's oracle/printing data, only
     * `id` / `zone` / `quantity` / `category_id` differ — and only
     * `violations` is reloaded so the legality panel stays current. Per-
     * card `is_illegal` flags may briefly lag for zone-sensitive rules
     * (companion restrictions); the next `cards`-reloading action catches
     * up.
     */
    async function moveZone(targetZone: "main" | "side"): Promise<void> {
        if (flushTimer !== null) {
            clearTimeout(flushTimer);
            await flush();
        }
        closePopover();

        const response = await fetch(`/api/decks/${params.deckId}/cards/${params.cardId}/move-zone`, {
            method: "PATCH",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": page.props.csrfToken as string,
                Accept: "application/json"
            },
            body: JSON.stringify({ zone: targetZone })
        });
        if (!response.ok) return;

        const data = (await response.json().catch(() => null)) as {
            source_quantity: number;
            target_id: string;
            target_quantity: number;
        } | null;
        if (data === null) return;

        const cards = page.props.cards as DeckCardRow[];
        const source = cards.find(c => c.id === params.cardId);
        if (source === undefined) return;

        // Apply target updates first — synthesis needs the source's data,
        // and we may about to splice the source row off the list below.
        const survivor = cards.find(c => c.id === data.target_id);
        if (survivor !== undefined) {
            // Server merged the moved copy into `data.target_id` and
            // potentially absorbed other matching rows in the target zone.
            // Mirror that locally: write the new quantity onto the
            // survivor and splice any sibling rows that share the same
            // mergeable identity (no category, no specific card_stack).
            survivor.quantity = data.target_quantity;
            for (let i = cards.length - 1; i >= 0; i--) {
                const c = cards[i];
                if (
                    c.id !== data.target_id &&
                    c.zone === targetZone &&
                    c.oracle_card_id === source.oracle_card_id &&
                    c.default_card.id === source.default_card.id &&
                    c.category_id === null
                ) {
                    cards.splice(i, 1);
                }
            }
        } else {
            // No matching target row pre-existed; synthesise from source.
            cards.push({
                ...source,
                id: data.target_id,
                zone: targetZone,
                quantity: data.target_quantity,
                category_id: null
            });
        }

        if (data.source_quantity === 0) {
            spliceCard();
        } else {
            source.quantity = data.source_quantity;
        }

        router.reload({ only: ["violations"] });
    }

    /**
     * Optimistically swap the deck card's printing: update the page's card
     * in place so the UI reflects the change immediately, then PATCH the
     * server. On failure, restore the previous printing. No reload — color
     * identity and legality are unchanged when only the printing shifts.
     */
    async function switchPrinting(printing: DeckPrinting): Promise<void> {
        const cards = page.props.cards as DeckCardRow[];
        const card = cards.find(c => c.id === params.cardId);
        if (card === undefined) return;
        const previous: DeckCardDefaultCard = { ...card.default_card };
        card.default_card = {
            id: printing.id,
            name: printing.name,
            card_image_0: printing.card_image_0,
            card_image_1: printing.card_image_1,
            set: printing.set ? { name: printing.set.name, code: printing.set.code } : null
        };
        const response = await fetch(`/api/decks/${params.deckId}/cards/${params.cardId}/printing`, {
            method: "PATCH",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": page.props.csrfToken as string,
                Accept: "application/json"
            },
            body: JSON.stringify({ default_card_id: printing.id })
        });
        if (!response.ok) {
            const target = cards.find(c => c.id === params.cardId);
            if (target !== undefined) target.default_card = previous;
        }
    }

    return { canIncrement, increment, decrement, destroy, moveZone, switchPrinting };
}
