import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import type { CollectionImplicitStatus } from "Types/deckPage.ts";
import CollectionImplicitBadge from "../CollectionImplicitBadge.vue";

const status = (in_deckbox: number, elsewhere: number, missing: number): CollectionImplicitStatus => ({
    in_deckbox,
    elsewhere,
    missing
});

const render = (value: CollectionImplicitStatus, quantity = 1, variant?: "inline" | "corner") =>
    mount(CollectionImplicitBadge, { props: { status: value, quantity, variant } });

/** The colour state baked into the class list. */
const colourOf = (wrapper: ReturnType<typeof render>): string | undefined =>
    wrapper
        .classes()
        .find(name => name.startsWith("collection-implicit--") && !name.endsWith("inline") && !name.endsWith("corner"));

describe("CollectionImplicitBadge — coverage colour", () => {
    it("is green when every needed copy sits in the deck's own deckbox", () => {
        expect(colourOf(render(status(4, 0, 0), 4))).toBe("collection-implicit--success");
    });

    it("is amber when the copies are owned but spread across containers", () => {
        // Logistics, not a problem: the user has the cards, just not to hand.
        expect(colourOf(render(status(2, 2, 0), 4))).toBe("collection-implicit--warning");
    });

    it("is amber when every copy sits somewhere other than the deckbox", () => {
        expect(colourOf(render(status(0, 4, 0), 4))).toBe("collection-implicit--warning");
    });

    it("is red as soon as a single copy is missing", () => {
        expect(colourOf(render(status(3, 0, 1), 4))).toBe("collection-implicit--error");
    });

    it("prefers red over amber when copies are both scattered and missing", () => {
        expect(colourOf(render(status(1, 1, 2), 4))).toBe("collection-implicit--error");
    });

    it("is red when the user owns none of them", () => {
        expect(colourOf(render(status(0, 0, 4), 4))).toBe("collection-implicit--error");
    });
});

describe("CollectionImplicitBadge — tooltip phrasing", () => {
    const tooltipKeyOf = (value: CollectionImplicitStatus, quantity: number): string | undefined =>
        render(value, quantity).attributes("data-tooltip");

    it.each([
        ["all_in_deckbox", status(4, 0, 0)],
        ["in_deckbox_and_elsewhere", status(2, 2, 0)],
        ["all_elsewhere", status(0, 4, 0)],
        ["partial_with_missing", status(1, 1, 2)],
        ["all_missing", status(0, 0, 4)]
    ] satisfies [string, CollectionImplicitStatus][])("uses the %s phrasing", (key, value) => {
        expect(tooltipKeyOf(value, 4)).toBe(`pages.deck.collection_implicit_status.${key}`);
    });

    it("picks the right one of the five phrasings for every count shape", () => {
        // Every combination that can reach the component, each mapped to the
        // phrasing it should produce — a prefix check alone would pass even if
        // the whole computed collapsed to a single branch.
        const expected = (inDeckbox: number, elsewhere: number, missing: number): string => {
            if (missing === 0 && elsewhere === 0) return "all_in_deckbox";
            if (missing === 0 && inDeckbox > 0) return "in_deckbox_and_elsewhere";
            if (missing === 0) return "all_elsewhere";
            if (inDeckbox + elsewhere === 0) return "all_missing";
            return "partial_with_missing";
        };

        for (const inDeckbox of [0, 1, 2]) {
            for (const elsewhere of [0, 1, 2]) {
                for (const missing of [0, 1, 2]) {
                    const tooltip = tooltipKeyOf(status(inDeckbox, elsewhere, missing), 4);

                    expect(tooltip).toBe(
                        `pages.deck.collection_implicit_status.${expected(inDeckbox, elsewhere, missing)}`
                    );
                }
            }
        }
    });
});

describe("CollectionImplicitBadge — layout", () => {
    it("always uses the storage icon — the colour carries the meaning", () => {
        expect(
            render(status(1, 0, 0))
                .find("use")
                .attributes("href")
        ).toBe("#storage");
        expect(
            render(status(0, 0, 1))
                .find("use")
                .attributes("href")
        ).toBe("#storage");
    });

    it("lays out inline unless told otherwise", () => {
        expect(render(status(1, 0, 0)).classes()).toContain("collection-implicit--inline");
    });

    it("switches to the corner variant on request", () => {
        expect(render(status(1, 0, 0), 1, "corner").classes()).toContain("collection-implicit--corner");
    });
});
