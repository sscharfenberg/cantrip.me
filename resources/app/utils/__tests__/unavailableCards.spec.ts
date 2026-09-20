// @vitest-environment node
import { describe, expect, it } from "vitest";
import { makeCommander, makeCompanion, makeDeckCard } from "@/test/factories/deckCard.ts";
import { collectUnavailableCards } from "@/utils/unavailableCards.ts";
import type { CollectionAvailability } from "Types/deckPage.ts";

const availability = (
    state: CollectionAvailability["state"],
    overrides: Partial<CollectionAvailability> = {}
): CollectionAvailability => ({ state, needed: 1, exact: 0, other: 0, blocked: 0, ...overrides });

const row = (name: string, state: CollectionAvailability["state"] | null, quantity = 1) =>
    makeDeckCard({
        name,
        quantity,
        collection_availability: state === null ? null : availability(state, { needed: quantity })
    });

describe("collectUnavailableCards — what lands on the list", () => {
    it("keeps everything the collection cannot fully cover", () => {
        const cards = [row("Sol Ring", "available"), row("Arcane Signet", "unavailable"), row("Brainstorm", "partial")];

        expect(collectUnavailableCards(cards).map(c => c.name)).toEqual(["Arcane Signet", "Brainstorm"]);
    });

    it("skips a card with no availability at all", () => {
        // Null is the deck page saying the feature is off — not planned, not
        // the owner, or the master switch is down. Absent is not missing.
        expect(collectUnavailableCards([row("Sol Ring", null)])).toEqual([]);
    });

    it("is empty when the collection covers the whole deck", () => {
        // This emptiness is the gate for the menu entry, so it has its own test.
        expect(collectUnavailableCards([row("Sol Ring", "available")])).toEqual([]);
    });

    it("includes the command zone and the companion", () => {
        const commanders = [makeCommander({ name: "Atraxa", collection_availability: availability("unavailable") })];
        const companion = makeCompanion({ name: "Lurrus", collection_availability: availability("partial") });

        const names = collectUnavailableCards([row("Sol Ring", "available")], commanders, companion).map(c => c.name);

        expect(names).toEqual(["Atraxa", "Lurrus"]);
    });

    it("leaves the companion out when the deck has none", () => {
        expect(collectUnavailableCards([], [], null)).toEqual([]);
    });
});

describe("collectUnavailableCards — ordering", () => {
    it("puts the cards you own nothing of first", () => {
        const cards = [row("Brainstorm", "partial"), row("Arcane Signet", "unavailable")];

        expect(collectUnavailableCards(cards).map(c => c.availability.state)).toEqual(["unavailable", "partial"]);
    });

    it("sorts by name within a state", () => {
        const cards = [row("Zealot", "unavailable"), row("Abrade", "unavailable")];

        expect(collectUnavailableCards(cards).map(c => c.name)).toEqual(["Abrade", "Zealot"]);
    });

    it("does not reorder the caller's arrays", () => {
        // They are page props; sorting them in place would reshuffle the deck
        // list behind the modal.
        const cards = [row("Zealot", "unavailable"), row("Abrade", "unavailable")];

        collectUnavailableCards(cards);

        expect(cards.map(c => c.name)).toEqual(["Zealot", "Abrade"]);
    });
});

describe("collectUnavailableCards — row contents", () => {
    it("carries the printing identity the list renders", () => {
        const card = makeDeckCard({
            name: "Lightning Bolt",
            quantity: 4,
            collection_availability: availability("partial", { needed: 4, exact: 3 }),
            default_card: {
                id: "printing-1",
                name: "Lightning Bolt",
                card_image_0: "/img/bolt.jpg",
                card_image_1: null,
                collector_number: "161",
                set: { name: "Limited Edition Alpha", code: "lea", path: "/set/lea.svg" }
            }
        });

        expect(collectUnavailableCards([card])[0]).toEqual({
            id: card.id,
            name: "Lightning Bolt",
            quantity: 4,
            setCode: "lea",
            setName: "Limited Edition Alpha",
            setPath: "/set/lea.svg",
            collectorNumber: "161",
            image: "/img/bolt.jpg",
            availability: availability("partial", { needed: 4, exact: 3 })
        });
    });

    it("counts a commander as one copy and keys it by its deck_card id", () => {
        // Commanders have no `quantity`; a row that read it as undefined would
        // render "undefinedx" beside the name.
        const commander = makeCommander({ collection_availability: availability("unavailable") });

        const [first] = collectUnavailableCards([], [commander]);

        expect(first.quantity).toBe(1);
        expect(first.id).toBe(commander.deck_card_id);
    });

    it("tolerates a printing with no set", () => {
        const card = makeDeckCard({ collection_availability: availability("unavailable") });

        expect(collectUnavailableCards([card])[0]).toMatchObject({
            setCode: null,
            setName: null,
            setPath: null,
            collectorNumber: null
        });
    });
});
