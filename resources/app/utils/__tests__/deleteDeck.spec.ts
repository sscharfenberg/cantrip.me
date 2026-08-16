import { describe, expect, it } from "vitest";
import type { DeleteDeckTarget } from "../deleteDeck.ts";
import { hasDeletableContent } from "../deleteDeck.ts";

/** An empty deck — every flag off. Each test flips exactly one thing. */
const emptyDeck = (overrides: Partial<DeleteDeckTarget> = {}): DeleteDeckTarget => ({
    id: "deck-1",
    name: "Untitled",
    cardCount: 0,
    hasCompanion: false,
    hasDescription: false,
    hasImage: false,
    ...overrides
});

describe("hasDeletableContent", () => {
    it("is false for a deck with nothing in it, so the confirm can be skipped", () => {
        expect(hasDeletableContent(emptyDeck())).toBe(false);
    });

    it.each([
        ["cards", { cardCount: 1 }],
        ["a companion", { hasCompanion: true }],
        ["a description", { hasDescription: true }],
        ["a hero image", { hasImage: true }]
    ] satisfies [string, Partial<DeleteDeckTarget>][])("is true for a deck with %s", (_label, overrides) => {
        expect(hasDeletableContent(emptyDeck(overrides))).toBe(true);
    });

    it("is true when several kinds of content are present at once", () => {
        expect(hasDeletableContent(emptyDeck({ cardCount: 99, hasCompanion: true, hasImage: true }))).toBe(true);
    });
});
