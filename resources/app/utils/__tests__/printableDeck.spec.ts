// @vitest-environment node
import { describe, expect, it } from "vitest";
import { buildPrintableDeck, formatCardLine, MAJOR_DIVIDER, MINOR_DIVIDER } from "../printableDeck.ts";
import type { PrintableCard, PrintableDeck } from "../printableDeck.ts";

const card = (name: string, overrides: Partial<PrintableCard> = {}): PrintableCard => ({
    name,
    quantity: 1,
    set_code: "cmd",
    collector_number: "1",
    ...overrides
});

const deck = (overrides: Partial<PrintableDeck> = {}): PrintableDeck => ({
    name: "Mono-White Aggro",
    description: "Go wide.",
    meta: ["Format: Legacy", "State: Finished"],
    groups: [{ label: "Creatures", cards: [card("Thalia", { quantity: 4 }), card("Adeline")] }],
    sideboard: null,
    ...overrides
});

describe("formatCardLine", () => {
    it("writes amount, name, upper-cased set code and collector number", () => {
        expect(formatCardLine(card("Sol Ring", { set_code: "cmm", collector_number: "400", quantity: 2 }))).toBe(
            "2 Sol Ring (CMM) 400"
        );
    });

    it("drops the collector number, then the whole printing, when missing", () => {
        expect(formatCardLine(card("Sol Ring", { collector_number: null }))).toBe("1 Sol Ring (CMD)");
        expect(formatCardLine(card("Sol Ring", { set_code: null, collector_number: null }))).toBe("1 Sol Ring");
    });
});

describe("buildPrintableDeck", () => {
    it("lays out name, description, metadata and groups with dividers", () => {
        expect(buildPrintableDeck(deck())).toBe(
            [
                "Mono-White Aggro",
                MAJOR_DIVIDER,
                "Go wide.",
                MAJOR_DIVIDER,
                "Format: Legacy",
                "State: Finished",
                MAJOR_DIVIDER,
                "",
                "Creatures (5)",
                MINOR_DIVIDER,
                "4 Thalia (CMD) 1",
                "1 Adeline (CMD) 1",
                ""
            ].join("\n")
        );
    });

    it("omits the description block when there is none", () => {
        for (const description of [null, "", "   "]) {
            const text = buildPrintableDeck(deck({ description }));
            expect(text.startsWith(`Mono-White Aggro\n${MAJOR_DIVIDER}\nFormat: Legacy`)).toBe(true);
        }
    });

    it("skips empty groups so no headline stands alone", () => {
        const text = buildPrintableDeck(
            deck({
                groups: [
                    { label: "Command Zone", cards: [] },
                    { label: "Lands", cards: [card("Plains")] }
                ]
            })
        );

        expect(text).not.toContain("Command Zone");
        expect(text).toContain(`Lands (1)\n${MINOR_DIVIDER}\n1 Plains (CMD) 1`);
    });

    it("separates groups by a blank line", () => {
        const text = buildPrintableDeck(
            deck({
                groups: [
                    { label: "A", cards: [card("One")] },
                    { label: "B", cards: [card("Two")] }
                ]
            })
        );

        expect(text).toContain(`1 One (CMD) 1\n\nB (1)`);
    });

    it("prints the sideboard after the mainboard, behind a major divider", () => {
        const text = buildPrintableDeck(
            deck({ sideboard: { label: "Sideboard", cards: [card("Pyroblast", { quantity: 3 })] } })
        );

        expect(
            text.endsWith(
                `1 Adeline (CMD) 1\n\n${MAJOR_DIVIDER}\n\nSideboard (3)\n${MINOR_DIVIDER}\n3 Pyroblast (CMD) 1\n`
            )
        ).toBe(true);
    });

    it("leaves an empty sideboard out entirely", () => {
        const text = buildPrintableDeck(deck({ sideboard: { label: "Sideboard", cards: [] } }));

        expect(text).not.toContain("Sideboard");
        expect(text.split(MAJOR_DIVIDER)).toHaveLength(4);
    });
});
