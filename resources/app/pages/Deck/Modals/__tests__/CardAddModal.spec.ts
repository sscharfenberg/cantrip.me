import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { installFetchMock, type FetchMock } from "@/test/http.ts";
import { setTestMessages } from "@/test/i18n.ts";
import { setPageProps } from "@/test/inertia.ts";
import { useToast } from "Composables/useToast.ts";
import type { DeckCardCount, DeckMeta, DeckSearchResult } from "Types/deckPage.ts";
import CardAddModal from "../CardAddModal.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const SEARCH = "/api/decks/deck-1/card-search/printings";
const ADD = "/api/decks/deck-1/cards";

const deck = (cardCount: DeckCardCount = { main: 0, companion: 0, side: 0 }): DeckMeta =>
    ({
        id: "deck-1",
        name: "Test Deck",
        description: null,
        format: "legacy",
        state: "planned",
        visibility: "private",
        colors: "R",
        bracket: null,
        card_count: cardCount,
        total_worth: 0,
        max_deck_size: 60,
        max_sideboard_size: 15,
        max_copies: 4,
        is_singleton: false,
        enforces_color_identity: false,
        allows_companion: false,
        banned_as_companion: [],
        uses_game_changer_list: false,
        last_activity: "2026-09-20T00:00:00+00:00",
        hero_card: null
    }) as unknown as DeckMeta;

let http: FetchMock;

beforeEach(() => {
    http = installFetchMock();
    http.json(SEARCH, []);
});

afterEach(() => {
    document.body.innerHTML = "";
});

/** Modal.vue teleports into <body>, so assertions go through `document`. */
const open = (collectionIntegrationEnabled: boolean, cardCount?: DeckCardCount) => {
    setPageProps({
        csrfToken: "token",
        auth: { user: { id: "u1", collection_integration_enabled: collectionIntegrationEnabled } }
    });

    return mount(CardAddModal, { props: { deck: deck(cardCount) } });
};

/** One search hit, with a printing so it can be clicked and added. */
const hit = (): DeckSearchResult => ({
    oracle_id: "oracle-1",
    name: "Lightning Bolt",
    cmc: 1,
    color_identity: "R",
    printing: {
        id: "printing-1",
        name: "Lightning Bolt",
        card_image_0: "/card-images/lea/lightning-bolt--0.jpg",
        card_image_1: null,
        artist: "Christopher Rush",
        cn: "161",
        finishes: ["nonfoil"],
        set: { name: "Limited Edition Alpha", code: "lea", path: "/set/lea.svg" }
    }
});

/** Messages of the toasts currently on screen. */
const toastMessages = (): string[] => useToast().activeToasts.value.map(toast => toast.message);

const availabilitySwitch = (): HTMLInputElement | null =>
    document.querySelector<HTMLInputElement>("#card_add_only_available");

/** Type a query and let the 750ms debounce elapse. */
const search = async (wrapper: ReturnType<typeof open>) => {
    const input = document.querySelector<HTMLInputElement>("#card_add_query")!;
    input.value = "sol ring";
    input.dispatchEvent(new Event("input"));
    await wrapper.vm.$nextTick();
    vi.advanceTimersByTime(800);
    await flushPromises();
};

describe("CardAddModal — availability filter switch", () => {
    it("is offered when collection integration is on", () => {
        open(true);

        expect(availabilitySwitch()).not.toBeNull();
    });

    it("is hidden while collection integration is off", () => {
        // "Available" is a collection-integration notion; with the master
        // switch off the backend refuses the filter, so offering it would
        // promise something that cannot happen.
        open(false);

        expect(availabilitySwitch()).toBeNull();
    });

    it("keeps the legality switch either way", () => {
        open(false);

        expect(document.querySelector("#card_add_include_non_legal")).not.toBeNull();
    });

    it("starts switched off", () => {
        open(true);

        expect(availabilitySwitch()?.checked).toBe(false);
    });
});

describe("CardAddModal — what the switch sends", () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("omits the flag by default", async () => {
        const wrapper = open(true);

        await search(wrapper);

        expect(http.lastCall(SEARCH)?.url).not.toContain("only_available");
    });

    it("re-runs the search the moment it is toggled on", async () => {
        const wrapper = open(true);
        await search(wrapper);
        const before = http.calls.length;

        const box = availabilitySwitch()!;
        box.checked = true;
        box.dispatchEvent(new Event("change"));
        await flushPromises();

        expect(http.calls.length).toBe(before + 1);
        expect(http.lastCall(SEARCH)?.url).toContain("only_available=1");
    });

    it("re-runs the search again when toggled back off", async () => {
        const wrapper = open(true);
        await search(wrapper);

        const box = availabilitySwitch()!;
        box.checked = true;
        box.dispatchEvent(new Event("change"));
        await flushPromises();
        box.checked = false;
        box.dispatchEvent(new Event("change"));
        await flushPromises();

        expect(http.lastCall(SEARCH)?.url).not.toContain("only_available");
    });
});

describe("CardAddModal — adding a card", () => {
    beforeEach(() => {
        vi.useFakeTimers();
        http.json(SEARCH, [hit()]);
        const { activeToasts, removeToast } = useToast();
        for (const toast of [...activeToasts.value]) {
            removeToast(toast.id);
        }
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    const clickHit = async () => {
        document.querySelector<HTMLElement>(".card-add-results__item")!.click();
        await flushPromises();
    };

    it("offers both zones while the main deck has room", async () => {
        const wrapper = open(false, { main: 59, companion: 0, side: 0 });
        await search(wrapper);

        await clickHit();

        expect(document.querySelector(".card-add-results__zone-picker")).not.toBeNull();
        expect(http.callsTo(ADD)).toHaveLength(0);
    });

    it("counts copies, not rows — a full main deck sends the card to the sideboard", async () => {
        // No rows are passed at all: the old row count read this deck as empty.
        http.json(ADD, { id: "new" }, 201);
        const wrapper = open(false, { main: 60, companion: 0, side: 0 });
        await search(wrapper);

        await clickHit();

        expect(document.querySelector(".card-add-results__zone-picker")).toBeNull();
        expect(http.lastCall(ADD)?.body).toMatchObject({ zone: "side" });
    });

    it("explains a refused add in a toast", async () => {
        http.json(ADD, { reason: "exceeds_deck_size" }, 422);
        const wrapper = open(false, { main: 59, companion: 0, side: 15 });
        setTestMessages({ de: { pages: { deck: { add_refused: { exceeds_deck_size: "Deck voll ({max})" } } } } });
        await search(wrapper);

        await clickHit();

        expect(toastMessages()).toEqual(["Deck voll (60)"]);
    });
});
