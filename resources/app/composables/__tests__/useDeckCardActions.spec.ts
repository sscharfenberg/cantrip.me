import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { Mock } from "vitest";
import { nextTick, ref } from "vue";
import { makeDeckCard } from "@/test/factories/deckCard.ts";
import { installFetchMock } from "@/test/http.ts";
import type { FetchMock } from "@/test/http.ts";
import { routerMock, setPageProps } from "@/test/inertia.ts";
import type { DeckCardRow } from "Types/deckPage.ts";
import type { DeckPrinting } from "Types/defaultCardImage.ts";
import type { DeckCardActionParams } from "../useDeckCardActions.ts";
import { useDeckCardActions } from "../useDeckCardActions.ts";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const DECK_ID = "deck-1";
const CARD_ID = "card-1";
const ORACLE_ID = "oracle-1";
const QUANTITY_URL = `/api/decks/${DECK_ID}/cards/${CARD_ID}/quantity`;
const CARD_URL = `/api/decks/${DECK_ID}/cards/${CARD_ID}`;
const MOVE_ZONE_URL = `/api/decks/${DECK_ID}/cards/${CARD_ID}/move-zone`;
const PRINTING_URL = `/api/decks/${DECK_ID}/cards/${CARD_ID}/printing`;

/** The four props the reload after a mutation asks the server to refresh. */
const RELOADED_PROPS = { only: ["deck", "cards", "violations", "tokens"] };

let http: FetchMock;
let closePopover: Mock<() => void>;

const deckCard = (overrides: Partial<DeckCardRow> = {}): DeckCardRow =>
    makeDeckCard({ id: CARD_ID, oracle_card_id: ORACLE_ID, quantity: 1, ...overrides });

/**
 * Wire the composable up against a live `page.props.cards` array — the
 * composable mutates that array in place to patch the DOM without a reload, so
 * the array itself is part of the contract.
 */
const setup = (
    options: {
        cards?: DeckCardRow[];
        quantity?: number;
        params?: Partial<DeckCardActionParams>;
    } = {}
) => {
    const cards = options.cards ?? [deckCard({ quantity: options.quantity ?? 1 })];
    setPageProps({ csrfToken: "csrf-token", cards });

    const quantity = ref(options.quantity ?? cards.find(c => c.id === CARD_ID)?.quantity ?? 1);
    const actions = useDeckCardActions(
        {
            deckId: DECK_ID,
            cardId: CARD_ID,
            oracleCardId: ORACLE_ID,
            quantity: () => quantity.value,
            cards: () => cards,
            isBasicLand: false,
            isUnlimited: false,
            maxCopies: 4,
            isSingleton: false,
            ...options.params
        },
        closePopover
    );

    return { actions, cards, quantity };
};

/** Advance past the 500ms debounce and let the fetch chain settle. */
const flush = () => vi.advanceTimersByTimeAsync(500);

beforeEach(() => {
    vi.useFakeTimers();
    closePopover = vi.fn<() => void>();
    http = installFetchMock();
    http.json(QUANTITY_URL, { quantity: 2 });
});

afterEach(() => {
    vi.useRealTimers();
});

describe("useDeckCardActions — canIncrement", () => {
    it("allows another copy below the format's limit", () => {
        expect(setup({ quantity: 3 }).actions.canIncrement.value).toBe(true);
    });

    it("stops at the limit", () => {
        expect(setup({ quantity: 4 }).actions.canIncrement.value).toBe(false);
    });

    it("counts sibling rows of the same oracle card, so split printings share the limit", () => {
        // Two rows, one oracle card, different printings: three plus two is
        // already over a four-copy limit.
        const cards = [
            deckCard({ quantity: 3 }),
            makeDeckCard({ id: "card-2", oracle_card_id: ORACLE_ID, quantity: 2 })
        ];

        expect(setup({ cards, quantity: 3 }).actions.canIncrement.value).toBe(false);
    });

    it("ignores rows of a different oracle card", () => {
        const cards = [
            deckCard({ quantity: 3 }),
            makeDeckCard({ id: "card-2", oracle_card_id: "oracle-2", quantity: 4 })
        ];

        expect(setup({ cards, quantity: 3 }).actions.canIncrement.value).toBe(true);
    });

    it("never limits a basic land", () => {
        expect(setup({ quantity: 40, params: { isBasicLand: true } }).actions.canIncrement.value).toBe(true);
    });

    it("never limits a card that says a deck may have any number", () => {
        // Rat Colony, Persistent Petitioners, Dragon's Approach…
        expect(setup({ quantity: 40, params: { isUnlimited: true } }).actions.canIncrement.value).toBe(true);
    });

    it("always refuses a second copy in a singleton format", () => {
        expect(setup({ quantity: 1, params: { isSingleton: true } }).actions.canIncrement.value).toBe(false);
    });

    it("exempts basics and unlimited cards from singleton too", () => {
        const basic = setup({ quantity: 20, params: { isSingleton: true, isBasicLand: true } });
        const unlimited = setup({ quantity: 20, params: { isSingleton: true, isUnlimited: true } });

        expect(basic.actions.canIncrement.value).toBe(true);
        expect(unlimited.actions.canIncrement.value).toBe(true);
    });

    it("accounts for clicks that have not been sent yet", async () => {
        // Rapid clicking must disable the button at the limit, not four
        // requests later.
        const { actions } = setup({ quantity: 2 });

        actions.increment();
        expect(actions.canIncrement.value).toBe(true);

        actions.increment();
        expect(actions.canIncrement.value).toBe(false);
    });

    it("re-syncs when the server sends a fresh quantity", async () => {
        const { actions, quantity } = setup({ quantity: 4 });
        expect(actions.canIncrement.value).toBe(false);

        quantity.value = 1;
        await nextTick();

        expect(actions.canIncrement.value).toBe(true);
    });
});

describe("useDeckCardActions — increment", () => {
    it("sends one request carrying the net delta of a click burst", async () => {
        const { actions } = setup({ quantity: 1 });

        actions.increment();
        actions.increment();
        await flush();

        expect(http.callsTo(QUANTITY_URL)).toHaveLength(1);
        expect(http.lastCall(QUANTITY_URL)).toMatchObject({
            method: "PATCH",
            headers: { "X-CSRF-TOKEN": "csrf-token", Accept: "application/json" },
            body: { delta: 2 }
        });
    });

    it("waits out the debounce before sending anything", async () => {
        const { actions } = setup({ quantity: 1 });

        actions.increment();
        await vi.advanceTimersByTimeAsync(499);
        expect(http.calls).toHaveLength(0);

        await vi.advanceTimersByTimeAsync(1);
        expect(http.calls).toHaveLength(1);
    });

    it("does nothing at the copy limit", async () => {
        const { actions } = setup({ quantity: 4 });

        actions.increment();
        await flush();

        expect(http.calls).toHaveLength(0);
    });

    it("writes the server's quantity onto the page's own card row", async () => {
        // In-place mutation is what patches the DOM without a full reload.
        http.json(QUANTITY_URL, { quantity: 7 });
        const { actions, cards } = setup({ quantity: 1 });

        actions.increment();
        await flush();

        expect(cards[0].quantity).toBe(7);
    });

    it("reloads the server-computed legality afterwards", async () => {
        const { actions } = setup({ quantity: 1 });

        actions.increment();
        await flush();

        expect(routerMock.reload).toHaveBeenCalledExactlyOnceWith(RELOADED_PROPS);
    });

    it("rolls the optimistic quantity back when the server refuses", async () => {
        // Starting at 3 of a 4-copy limit: the un-rolled-back value would be 4
        // and leave the button disabled, so only a real rollback re-enables it.
        http.status(QUANTITY_URL, 422);
        const { actions } = setup({ quantity: 3 });

        actions.increment();
        expect(actions.canIncrement.value).toBe(false);

        await flush();

        expect(actions.canIncrement.value).toBe(true);
        expect(routerMock.reload).not.toHaveBeenCalled();
    });

    it("survives a 200 whose body is not JSON", async () => {
        // `flush` is fired and forgotten, so a rejection here would be an
        // unhandled one — a Fortify redirect or a 419 page does exactly this.
        http.malformed(QUANTITY_URL);
        const { actions, cards } = setup({ quantity: 1 });

        actions.increment();
        await flush();

        expect(cards[0].quantity).toBe(1);
        expect(routerMock.reload).toHaveBeenCalledOnce();
    });

    it("sends nothing when the clicks cancel out", async () => {
        const { actions } = setup({ quantity: 2 });

        actions.increment();
        actions.decrement();
        await flush();

        expect(http.calls).toHaveLength(0);
    });
});

describe("useDeckCardActions — decrement", () => {
    it("debounces like increment while copies remain", async () => {
        const { actions } = setup({ quantity: 3 });

        actions.decrement();
        expect(http.calls).toHaveLength(0);

        await flush();
        expect(http.lastCall(QUANTITY_URL)?.body).toEqual({ delta: -1 });
    });

    it("sends immediately on the last copy, without waiting out the debounce", async () => {
        const { actions } = setup({ quantity: 1 });

        actions.decrement();
        await vi.advanceTimersByTimeAsync(0);

        expect(http.lastCall(QUANTITY_URL)?.body).toEqual({ delta: -1 });
    });

    it("closes the popover on the last copy — the row is about to vanish", async () => {
        const { actions } = setup({ quantity: 1 });

        actions.decrement();

        expect(closePopover).toHaveBeenCalledOnce();
    });

    it("leaves the popover open while copies remain", async () => {
        const { actions } = setup({ quantity: 3 });

        actions.decrement();
        await flush();

        expect(closePopover).not.toHaveBeenCalled();
    });

    it("removes the row from the page when the server reports it deleted", async () => {
        http.json(QUANTITY_URL, { deleted: true });
        const { actions, cards } = setup({ quantity: 1 });

        actions.decrement();
        await vi.advanceTimersByTimeAsync(0);

        expect(cards).toHaveLength(0);
        expect(routerMock.reload).toHaveBeenCalledExactlyOnceWith(RELOADED_PROPS);
    });
});

describe("useDeckCardActions — destroy", () => {
    it("sends a DELETE and closes the popover", async () => {
        const { actions } = setup();

        await actions.destroy();

        expect(http.lastCall(CARD_URL)).toMatchObject({
            method: "DELETE",
            headers: { "X-CSRF-TOKEN": "csrf-token" }
        });
        expect(closePopover).toHaveBeenCalledOnce();
    });

    it("removes the row and reloads on success", async () => {
        const { actions, cards } = setup();

        await actions.destroy();

        expect(cards).toHaveLength(0);
        expect(routerMock.reload).toHaveBeenCalledExactlyOnceWith(RELOADED_PROPS);
    });

    it("leaves the row alone when the server refuses", async () => {
        http.status(CARD_URL, 403);
        const { actions, cards } = setup();

        await actions.destroy();

        expect(cards).toHaveLength(1);
        expect(routerMock.reload).not.toHaveBeenCalled();
    });

    it("cancels a pending quantity change rather than racing it", async () => {
        const { actions } = setup({ quantity: 3 });
        actions.increment();

        await actions.destroy();
        await flush();

        expect(http.callsTo(QUANTITY_URL)).toHaveLength(0);
    });
});

describe("useDeckCardActions — moveZone", () => {
    beforeEach(() => {
        http.json(MOVE_ZONE_URL, { source_quantity: 0, target_id: "card-side", target_quantity: 1 });
    });

    it("PATCHes the target zone and closes the popover", async () => {
        const { actions } = setup();

        await actions.moveZone("side");

        expect(http.lastCall(MOVE_ZONE_URL)).toMatchObject({ method: "PATCH", body: { zone: "side" } });
        expect(closePopover).toHaveBeenCalledOnce();
    });

    it("issues the pending quantity change before the move", async () => {
        // Order of issue only — the test below is the one that proves the move
        // actually waits for the answer.
        const { actions } = setup({ quantity: 3 });
        actions.increment();

        await actions.moveZone("side");

        expect(http.calls.map(call => call.url)).toEqual([QUANTITY_URL, MOVE_ZONE_URL]);
    });

    it("does not start the move until the quantity request has answered", async () => {
        // Ordering of the *calls* alone would not show this — both are issued
        // in the same order either way. What matters is that the move waits for
        // the answer, so the quantity response is deliberately made slow.
        const { actions } = setup({ quantity: 3 });
        http.on(
            QUANTITY_URL,
            () =>
                new Promise<Response>(resolve => {
                    setTimeout(() => resolve(new Response(JSON.stringify({ quantity: 4 }), { status: 200 })), 50);
                })
        );
        actions.increment();

        const move = actions.moveZone("side");
        await vi.advanceTimersByTimeAsync(0);

        expect(http.callsTo(MOVE_ZONE_URL)).toHaveLength(0);

        await vi.advanceTimersByTimeAsync(50);
        await move;

        expect(http.callsTo(MOVE_ZONE_URL)).toHaveLength(1);
    });

    it("changes nothing when the move response is not JSON", async () => {
        http.malformed(MOVE_ZONE_URL);
        const { actions, cards } = setup({ quantity: 2 });

        await actions.moveZone("side");

        expect(cards).toHaveLength(1);
        expect(cards[0].zone).toBe("main");
        expect(routerMock.reload).not.toHaveBeenCalled();
    });

    it("synthesises the target row when the zone had no matching card", async () => {
        const { actions, cards } = setup({ quantity: 1 });

        await actions.moveZone("side");

        // The source row is gone (quantity 0) and the new row clones its
        // printing data, differing only in id / zone / quantity / category.
        expect(cards).toHaveLength(1);
        expect(cards[0]).toMatchObject({
            id: "card-side",
            zone: "side",
            quantity: 1,
            category_id: null,
            oracle_card_id: ORACLE_ID
        });
    });

    it("merges into an existing target row instead of adding a second one", async () => {
        const existing = makeDeckCard({ id: "card-side", oracle_card_id: ORACLE_ID, zone: "side", quantity: 1 });
        http.json(MOVE_ZONE_URL, { source_quantity: 1, target_id: "card-side", target_quantity: 2 });
        const { actions, cards } = setup({ cards: [deckCard({ quantity: 2 }), existing], quantity: 2 });

        await actions.moveZone("side");

        expect(cards).toHaveLength(2);
        expect(cards.find(c => c.id === "card-side")?.quantity).toBe(2);
        expect(cards.find(c => c.id === CARD_ID)?.quantity).toBe(1);
    });

    it("absorbs sibling rows the server merged away", async () => {
        // Same oracle, same printing, no category: the server collapses them,
        // so the local list has to as well or a ghost row lingers.
        const printing = { id: "printing-1", name: "Sol Ring", card_image_0: null, card_image_1: null, set: null };
        const source = deckCard({ quantity: 2, default_card: printing });
        const survivor = makeDeckCard({
            id: "card-side",
            oracle_card_id: ORACLE_ID,
            zone: "side",
            quantity: 1,
            default_card: printing
        });
        const absorbed = makeDeckCard({
            id: "card-side-2",
            oracle_card_id: ORACLE_ID,
            zone: "side",
            quantity: 1,
            default_card: printing
        });
        http.json(MOVE_ZONE_URL, { source_quantity: 1, target_id: "card-side", target_quantity: 3 });
        const { actions, cards } = setup({ cards: [source, survivor, absorbed], quantity: 2 });

        await actions.moveZone("side");

        expect(cards.map(c => c.id)).toEqual([CARD_ID, "card-side"]);
        expect(cards.find(c => c.id === "card-side")?.quantity).toBe(3);
    });

    it("keeps a categorised sibling out of the merge", async () => {
        const printing = { id: "printing-1", name: "Sol Ring", card_image_0: null, card_image_1: null, set: null };
        const source = deckCard({ quantity: 2, default_card: printing });
        const survivor = makeDeckCard({
            id: "card-side",
            oracle_card_id: ORACLE_ID,
            zone: "side",
            quantity: 1,
            default_card: printing
        });
        const categorised = makeDeckCard({
            id: "card-side-cat",
            oracle_card_id: ORACLE_ID,
            zone: "side",
            quantity: 1,
            default_card: printing,
            category_id: "category-ramp"
        });
        http.json(MOVE_ZONE_URL, { source_quantity: 1, target_id: "card-side", target_quantity: 2 });
        const { actions, cards } = setup({ cards: [source, survivor, categorised], quantity: 2 });

        await actions.moveZone("side");

        expect(cards.map(c => c.id)).toContain("card-side-cat");
    });

    it("reloads only the legality panel, avoiding a full card-list reflow", async () => {
        const { actions } = setup();

        await actions.moveZone("side");

        expect(routerMock.reload).toHaveBeenCalledExactlyOnceWith({ only: ["violations"] });
    });

    it("changes nothing when the server refuses", async () => {
        http.status(MOVE_ZONE_URL, 422);
        const { actions, cards } = setup();

        await actions.moveZone("side");

        expect(cards).toHaveLength(1);
        expect(cards[0].zone).toBe("main");
        expect(routerMock.reload).not.toHaveBeenCalled();
    });
});

describe("useDeckCardActions — switchPrinting", () => {
    const printing = {
        id: "printing-2",
        name: "Sol Ring",
        card_image_0: "/img/front.jpg",
        card_image_1: null,
        set: { name: "Commander 2021", code: "c21", path: null }
    } as DeckPrinting;

    it("swaps the printing before the server has answered", async () => {
        let duringRequest: string | null = null;
        const { actions, cards } = setup();
        http.on(PRINTING_URL, () => {
            duringRequest = cards[0].default_card.id;
            return new Response("{}", { status: 200 });
        });

        await actions.switchPrinting(printing);

        expect(duringRequest).toBe("printing-2");
    });

    it("copies the printing's display fields onto the row", async () => {
        const { actions, cards } = setup();

        await actions.switchPrinting(printing);

        expect(cards[0].default_card).toEqual({
            id: "printing-2",
            name: "Sol Ring",
            card_image_0: "/img/front.jpg",
            card_image_1: null,
            set: { name: "Commander 2021", code: "c21" }
        });
    });

    it("PATCHes the chosen printing id", async () => {
        const { actions } = setup();

        await actions.switchPrinting(printing);

        expect(http.lastCall(PRINTING_URL)).toMatchObject({
            method: "PATCH",
            body: { default_card_id: "printing-2" }
        });
    });

    it("restores the previous printing when the server refuses", async () => {
        http.status(PRINTING_URL, 422);
        const original = { id: "printing-1", name: "Sol Ring", card_image_0: null, card_image_1: null, set: null };
        const { actions, cards } = setup({ cards: [deckCard({ default_card: original })] });

        await actions.switchPrinting(printing);

        expect(cards[0].default_card).toEqual(original);
    });

    it("does not reload — only the artwork changed", async () => {
        const { actions } = setup();

        await actions.switchPrinting(printing);

        expect(routerMock.reload).not.toHaveBeenCalled();
    });

    it("handles a printing that carries no set", async () => {
        const { actions, cards } = setup();

        await actions.switchPrinting({ ...printing, set: null } as unknown as DeckPrinting);

        expect(cards[0].default_card.set).toBeNull();
    });
});
