import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { setTestMessages } from "@/test/i18n.ts";
import type { UnavailableCard } from "@/utils/unavailableCards.ts";
import DeckUnavailableCardsModal from "../DeckUnavailableCardsModal.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const card = (overrides: Partial<UnavailableCard> = {}): UnavailableCard => ({
    id: "deck-card-1",
    name: "Lightning Bolt",
    quantity: 4,
    setCode: "lea",
    setName: "Limited Edition Alpha",
    setPath: "/set/lea.svg",
    collectorNumber: "161",
    image: "/img/bolt.jpg",
    availability: { state: "partial", needed: 4, exact: 3, other: 0, blocked: 0 },
    ...overrides
});

let writeText: ReturnType<typeof vi.fn>;

beforeEach(() => {
    writeText = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal("navigator", { clipboard: { writeText } });
});

afterEach(() => {
    vi.unstubAllGlobals();
    document.body.innerHTML = "";
});

/** Modal.vue teleports into <body>, so assertions go through `document`. */
const open = (cards: UnavailableCard[] = [card()]) =>
    mount(DeckUnavailableCardsModal, { props: { cards } });

const rows = () => document.querySelectorAll(".unavailable-cards__row");

describe("DeckUnavailableCardsModal — the list", () => {
    it("renders one row per card", () => {
        open([card(), card({ id: "deck-card-2", name: "Arcane Signet" })]);

        expect(rows()).toHaveLength(2);
    });

    it("names the printing, not just the card", () => {
        // The point of the list is buying the right thing, so set code and
        // collector number are as load-bearing as the name.
        open();
        const row = rows()[0];

        expect(row.textContent).toContain("Lightning Bolt");
        expect(row.textContent).toContain("LEA");
        expect(row.textContent).toContain("161");
        expect(row.textContent).toContain("4x");
    });

    it("shows the card thumbnail and the set icon", () => {
        open();
        const row = rows()[0];

        expect(row.querySelector(".unavailable-cards__thumb")?.getAttribute("src")).toBe("/img/bolt.jpg");
        expect(row.querySelector(".unavailable-cards__set")?.getAttribute("src")).toBe("/set/lea.svg");
    });

    it("falls back to a placeholder when a printing has no image", () => {
        open([card({ image: null })]);

        expect(document.querySelector(".unavailable-cards__thumb--empty")).not.toBeNull();
    });

    it("carries each row's availability badge", () => {
        // Reused rather than re-derived, so the row inherits the tooltip that
        // already explains the counts.
        open([card(), card({ id: "x", availability: { state: "unavailable", needed: 1, exact: 0, other: 0, blocked: 0 } })]);

        expect(document.querySelector(".collection-availability--partial")).not.toBeNull();
        expect(document.querySelector(".collection-availability--unavailable")).not.toBeNull();
    });
});

describe("DeckUnavailableCardsModal — copying a name", () => {
    it("copies the card name, not the whole row", () => {
        open();

        document.querySelector<HTMLButtonElement>(".unavailable-cards__copy")!.click();

        expect(writeText).toHaveBeenCalledWith("Lightning Bolt");
    });

    it("confirms next to the card that was copied", async () => {
        setTestMessages({ de: { pages: { deck: { unavailable: { copied: "kopiert" } } } } });
        open([card(), card({ id: "deck-card-2", name: "Arcane Signet" })]);

        document.querySelectorAll<HTMLButtonElement>(".unavailable-cards__copy")[1].click();
        await flushPromises();

        const confirmations = document.querySelectorAll(".unavailable-cards__copied");

        expect(confirmations).toHaveLength(1);
        expect(rows()[1].textContent).toContain("kopiert");
    });

    it("moves the confirmation when a second card is copied", async () => {
        open([card(), card({ id: "deck-card-2", name: "Arcane Signet" })]);
        const buttons = document.querySelectorAll<HTMLButtonElement>(".unavailable-cards__copy");

        buttons[0].click();
        await flushPromises();
        buttons[1].click();
        await flushPromises();

        expect(document.querySelectorAll(".unavailable-cards__copied")).toHaveLength(1);
        expect(rows()[1].querySelector(".unavailable-cards__copied")).not.toBeNull();
    });

    it("shows nothing until a copy happens", () => {
        open();

        expect(document.querySelector(".unavailable-cards__copied")).toBeNull();
    });
});
