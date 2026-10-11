import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it } from "vitest";
import { setTestMessages } from "@/test/i18n.ts";
import type { DeckViolation } from "Types/deckPage.ts";
import DeckLegalityPanel from "../DeckLegalityPanel.vue";

const render = (violations: DeckViolation[]) => mount(DeckLegalityPanel, { props: { violations, cards: [] } });

/** Text of the cut hint, or null when the panel renders none. */
const hint = (violations: DeckViolation[]): string | null => {
    const element = render(violations).find(".legality-panel__hint");
    return element.exists() ? element.text() : null;
};

describe("DeckLegalityPanel — oversized zones", () => {
    beforeEach(() => {
        setTestMessages({
            de: {
                pages: {
                    deck: {
                        legality: {
                            deck_size_min: "Hauptdeck {current}, mindestens {min}.",
                            deck_size_max: "Hauptdeck {current}, höchstens {max}.",
                            deck_size_max_hint: "Streiche {count} Karte. | Streiche {count} Karten.",
                            sideboard_size_max: "Sideboard {current}, höchstens {max}.",
                            sideboard_size_max_hint:
                                "Streiche {count} Sideboard-Karte. | Streiche {count} Sideboard-Karten."
                        }
                    }
                }
            }
        });
    });

    /**
     * Adding past the format's maximum is allowed so players can build big and
     * cut down; the panel is where they learn how far they still have to go.
     */
    it("says how many cards to cut", () => {
        expect(hint([{ type: "deck_size_max", current: 112, max: 100 }])).toBe("Streiche 12 Karten.");
    });

    it("uses the singular for the last card", () => {
        expect(hint([{ type: "deck_size_max", current: 101, max: 100 }])).toBe("Streiche 1 Karte.");
    });

    it("keeps the violation itself alongside the hint", () => {
        const text = render([{ type: "deck_size_max", current: 112, max: 100 }]).text();

        expect(text).toContain("Hauptdeck 112, höchstens 100.");
    });

    it("offers no cut hint for a deck that is too small", () => {
        expect(hint([{ type: "deck_size_min", current: 80, min: 100 }])).toBeNull();
    });

    it("says how many sideboard cards to cut", () => {
        expect(hint([{ type: "sideboard_size_max", current: 23, max: 15 }])).toBe("Streiche 8 Sideboard-Karten.");
    });

    it("uses the singular for the last sideboard card", () => {
        expect(hint([{ type: "sideboard_size_max", current: 16, max: 15 }])).toBe("Streiche 1 Sideboard-Karte.");
    });

    it("gives each oversized zone its own hint", () => {
        const hints = render([
            { type: "deck_size_max", current: 70, max: 60 },
            { type: "sideboard_size_max", current: 18, max: 15 }
        ]).findAll(".legality-panel__hint");

        expect(hints.map(h => h.text())).toEqual(["Streiche 10 Karten.", "Streiche 3 Sideboard-Karten."]);
    });
});
