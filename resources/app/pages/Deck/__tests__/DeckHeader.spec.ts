import { shallowMount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import type { DeckMeta } from "Types/deckPage.ts";
import DeckHeader from "../DeckHeader.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const deck = (state: string): DeckMeta => ({
    id: "deck-1",
    name: "Test Deck",
    description: null,
    format: "commander",
    state,
    visibility: "private",
    colors: "WU",
    bracket: null,
    card_count: { main: 100, companion: 0, side: 0 },
    total_worth: 0,
    max_deck_size: 100,
    max_sideboard_size: 0,
    max_copies: 1,
    is_singleton: true,
    enforces_color_identity: true,
    allows_companion: false,
    banned_as_companion: [],
    uses_game_changer_list: true,
    last_activity: "2026-09-20T00:00:00+00:00",
    hero_card: null
});

/**
 * Shallow — every child is stubbed, so the assertions are about which
 * badges this header decides to render, not about what they look like.
 */
const render = (state: string, overrides: Record<string, unknown> = {}) =>
    shallowMount(DeckHeader, {
        props: {
            deck: deck(state),
            isOwner: true,
            rulebreaker: null,
            isArchived: state === "archived",
            hasCommanders: false,
            companion: null,
            cards: [],
            categories: [],
            categoryNameMax: 32,
            violations: [],
            heroArtCrop: null,
            collectionMode: "C",
            collectionBadgeMode: "C",
            collectionModeContext: { master_switch_enabled: true },
            hasUnclaimedCards: false,
            containers: [],
            ...overrides
        }
    });

const hasModeBadge = (wrapper: ReturnType<typeof render>): boolean =>
    wrapper.findComponent({ name: "CollectionModeBadge" }).exists();

describe("DeckHeader — the collection-mode picker", () => {
    it("is hidden on a planned deck", () => {
        // A planned deck ignores its tracking mode and shows availability
        // instead, so a picker that changes nothing on the page would only
        // mislead. The stored mode is untouched.
        expect(hasModeBadge(render("planned"))).toBe(false);
    });

    it.each(["built", "archived"])("is offered on a %s deck", state => {
        expect(hasModeBadge(render(state))).toBe(true);
    });

    it("stays hidden for a non-owner whatever the state", () => {
        expect(hasModeBadge(render("built", { isOwner: false, collectionModeContext: null }))).toBe(false);
    });

    it("stays hidden while the collection master switch is off", () => {
        expect(
            hasModeBadge(render("built", { collectionModeContext: { master_switch_enabled: false } }))
        ).toBe(false);
    });
});

describe("DeckHeader — the state badge legend", () => {
    const explainsOn = (wrapper: ReturnType<typeof render>): unknown =>
        wrapper.findComponent({ name: "DeckState" }).props("explain");

    it("is on for an owner with the master switch on — including on a planned deck", () => {
        // The legend describes the availability icons, which is exactly the
        // planned case; it must not disappear with the mode picker.
        expect(explainsOn(render("planned"))).toBe(true);
        expect(explainsOn(render("built"))).toBe(true);
    });

    it("is off for a non-owner", () => {
        expect(explainsOn(render("planned", { isOwner: false, collectionModeContext: null }))).toBe(false);
    });

    it("is off while the collection master switch is off", () => {
        // No per-card badge renders at all, so there is nothing to explain.
        expect(explainsOn(render("planned", { collectionModeContext: { master_switch_enabled: false } }))).toBe(
            false
        );
    });
});
