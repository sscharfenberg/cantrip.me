import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { setTestMessages } from "@/test/i18n.ts";
import type { StackClaim } from "Types/cardStackRow.ts";
import CardStackClaimBadge from "../CardStackClaimBadge.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const claim = (deck_id: string, deck_name: string): StackClaim => ({ deck_id, deck_name }) as StackClaim;

/**
 * Real messages, not the default key echo: every label here interpolates a deck
 * name, and key-echo assertions would leave the interpolation unverified.
 */
beforeEach(() => {
    setTestMessages({
        de: {
            pages: {
                collection: {
                    claim_badge: {
                        label: "Reserviert für {deck}",
                        multi_label: "{deck} +{count} weitere",
                        tooltip_multi: "Außerdem: {decks}"
                    }
                }
            }
        }
    });
});

const render = (claims: StackClaim[]) => mount(CardStackClaimBadge, { props: { claims } });

describe("CardStackClaimBadge — no claims", () => {
    it("renders nothing, so call sites can mount it unconditionally", () => {
        expect(render([]).find(".claim-badge").exists()).toBe(false);
    });
});

describe("CardStackClaimBadge — one claim", () => {
    const single = [claim("deck-1", "Mono-White Aggro")];

    it("links to the claiming deck", () => {
        expect(render(single).find("a").attributes("href")).toBe("/decks/deck-1");
    });

    it("labels the link with the deck's name", () => {
        expect(render(single).text()).toContain("Mono-White Aggro");
    });

    it("names the deck in the tooltip too", () => {
        expect(render(single).find("a").attributes("data-tooltip")).toBe("Reserviert für Mono-White Aggro");
    });

    it("renders one link, not one per claim slot", () => {
        expect(render(single).findAll("a")).toHaveLength(1);
    });
});

describe("CardStackClaimBadge — several claims", () => {
    // The schema permits it even though the UX assumes a single claim.
    const many = [
        claim("deck-1", "Mono-White Aggro"),
        claim("deck-2", "Boros Burn"),
        claim("deck-3", "Azorius Control")
    ];

    it("still links to the first deck only", () => {
        const links = render(many).findAll("a");

        expect(links).toHaveLength(1);
        expect(links[0].attributes("href")).toBe("/decks/deck-1");
    });

    it("names the first deck and counts the rest", () => {
        expect(render(many).text()).toContain("Mono-White Aggro +2 weitere");
    });

    it("moves the other decks' names into the tooltip", () => {
        expect(render(many).find("a").attributes("data-tooltip")).toBe("Außerdem: Boros Burn, Azorius Control");
    });

    it("uses the plain label again for exactly one claim", () => {
        expect(render([claim("deck-1", "Mono-White Aggro")]).text()).toBe("Mono-White Aggro");
    });
});
