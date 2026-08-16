import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { makeCategory, makeDeckCard } from "@/test/factories/deckCard.ts";
import { routerMock, setPageProps } from "@/test/inertia.ts";
import DeckActionsMenu from "../DeckActionsMenu.vue";
import type { DeckActionsTarget } from "../DeckActionsMenu.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const deck = (overrides: Partial<DeckActionsTarget> = {}): DeckActionsTarget => ({
    id: "deck-1",
    name: "Mono-White Aggro",
    state: "built",
    visibility: "private",
    card_count: { main: 60, companion: 0, side: 15 },
    has_companion: false,
    has_description: true,
    has_image: false,
    ...overrides
});

/** The full set of props that unlocks every gated entry. */
const fullyEquipped = {
    isOwner: true,
    isArchived: false,
    collectionMode: "C" as const,
    hasUnclaimedCards: true,
    cards: [makeDeckCard()],
    categories: [makeCategory("Ramp")],
    categoryNameMax: 40,
    containers: [{ id: "c1", name: "Deckbox", type: "deckbox", is_deckbox: true }]
};

const render = (props: Record<string, unknown> = {}) =>
    mount(DeckActionsMenu, { props: { deck: deck(), ...props }, attachTo: document.body });

/** The menu's visible entries, by label. */
const entries = (wrapper: ReturnType<typeof render>): string[] =>
    wrapper.findAll(".popover-list-item").map(item => item.text());

/** Click the entry whose label contains `label`. */
const click = async (wrapper: ReturnType<typeof render>, label: string): Promise<void> => {
    const item = wrapper.findAll(".popover-list-item").find(node => node.text().includes(label));
    if (!item) throw new Error(`no menu entry matching "${label}" — saw: ${entries(wrapper).join(", ")}`);
    await item.trigger("click");
};

beforeEach(() => {
    setPageProps({ csrfToken: "csrf-token" });
    // The harness already installs a no-op `hidePopover`; this shadows it with
    // a spy so the "did it close the menu" assertions have something to read.
    Object.defineProperty(HTMLElement.prototype, "hidePopover", { value: vi.fn(), configurable: true });
});

describe("DeckActionsMenu — what a non-owner sees", () => {
    it("gets the CSV export and nothing else", () => {
        expect(entries(render({ ...fullyEquipped, isOwner: false }))).toEqual(["pages.decks.actions.export"]);
    });

    it("cannot delete, edit or change the deck's state", () => {
        const labels = entries(render({ ...fullyEquipped, isOwner: false })).join(" ");

        expect(labels).not.toContain("delete");
        expect(labels).not.toContain("edit_link");
        expect(labels).not.toContain("set_archived");
    });
});

describe("DeckActionsMenu — what an owner sees", () => {
    it("gets the editing, grouping, collection and destructive entries", () => {
        const labels = entries(render(fullyEquipped));

        expect(labels).toEqual(
            expect.arrayContaining([
                "pages.create_deck.edit_link",
                "pages.deck.create_group.link",
                "pages.deck.custom_groups.link",
                "pages.deck.bulk_claim.menu_link",
                "pages.deck.unclaimed.menu_link",
                "pages.deck.add_all_to_collection.link",
                "pages.deck_qr.link",
                "pages.decks.actions.export",
                "pages.decks.actions.delete"
            ])
        );
    });

    it("offers only the two states the deck is not currently in", () => {
        const labels = entries(render({ ...fullyEquipped, deck: deck({ state: "built" }) }));

        expect(labels).toContain("pages.decks.actions.set_planned");
        expect(labels).toContain("pages.decks.actions.set_archived");
        expect(labels).not.toContain("pages.decks.actions.set_built");
    });

    it("offers to publish a private deck and to hide a public one", () => {
        expect(entries(render({ ...fullyEquipped, deck: deck({ visibility: "private" }) }))).toContain(
            "pages.decks.actions.set_public"
        );
        expect(entries(render({ ...fullyEquipped, deck: deck({ visibility: "public" }) }))).toContain(
            "pages.decks.actions.set_private"
        );
    });

    it("hides the grouping entries when the caller has no card data", () => {
        // The deck-list row popover renders without cards or categories.
        const labels = entries(render({ isOwner: true }));

        expect(labels).not.toContain("pages.deck.create_group.link");
        expect(labels).not.toContain("pages.deck.custom_groups.link");
    });

    it("hides the bulk-add entry when the caller passes no containers", () => {
        // The modal behind it is gated on the same prop, so offering the entry
        // would close the popover and open nothing.
        expect(entries(render({ isOwner: true, collectionMode: "C" }))).not.toContain(
            "pages.deck.add_all_to_collection.link"
        );
    });
});

describe("DeckActionsMenu — group dividers", () => {
    /** Dividers are `<li>`s without a button, so they need their own selector. */
    const dividerCount = (wrapper: ReturnType<typeof render>): number =>
        wrapper.findAll(".popover-list__divider").length;

    it("draws none for a non-owner, whose menu is a single entry", () => {
        expect(dividerCount(render({ ...fullyEquipped, isOwner: false }))).toBe(0);
    });

    it("separates each visible group for a fully-equipped owner", () => {
        // Settings | groups | collection | QR + CSV | delete.
        expect(dividerCount(render(fullyEquipped))).toBe(4);
    });

    it("drops the group and collection dividers when those groups are hidden", () => {
        // Owner, archived, nothing unclaimed: only the settings and delete
        // dividers remain.
        const wrapper = render({
            ...fullyEquipped,
            isArchived: true,
            hasUnclaimedCards: false,
            deck: deck({ state: "archived" })
        });

        expect(dividerCount(wrapper)).toBe(2);
    });

    it("keeps the collection divider while an archived deck still has unclaimed cards", () => {
        const wrapper = render({
            ...fullyEquipped,
            isArchived: true,
            hasUnclaimedCards: true,
            deck: deck({ state: "archived" })
        });

        expect(dividerCount(wrapper)).toBe(3);
    });
});

describe("DeckActionsMenu — an archived deck", () => {
    const archived = { ...fullyEquipped, isArchived: true, deck: deck({ state: "archived" }) };

    it("collapses to the read-only set plus restore and delete", () => {
        const labels = entries(render(archived));

        expect(labels).toEqual([
            "pages.decks.actions.set_planned",
            "pages.decks.actions.set_built",
            "pages.deck.unclaimed.menu_link",
            "pages.deck_qr.link",
            "pages.decks.actions.export",
            "pages.decks.actions.delete"
        ]);
    });

    it("hides editing, grouping and bulk-add — there is nothing to build", () => {
        const labels = entries(render(archived)).join(" ");

        expect(labels).not.toContain("edit_link");
        expect(labels).not.toContain("create_group");
        expect(labels).not.toContain("add_all_to_collection");
        expect(labels).not.toContain("bulk_claim");
    });

    it("keeps the unclaimed-cards entry, which is still useful after archiving", () => {
        expect(entries(render(archived))).toContain("pages.deck.unclaimed.menu_link");
    });

    it("drops the unclaimed entry once there is nothing left unclaimed", () => {
        expect(entries(render({ ...archived, hasUnclaimedCards: false }))).not.toContain(
            "pages.deck.unclaimed.menu_link"
        );
    });
});

describe("DeckActionsMenu — collection-mode gating", () => {
    it("offers bulk claim only in the per-copy mode", () => {
        for (const mode of ["A", "B"] as const) {
            expect(entries(render({ ...fullyEquipped, collectionMode: mode }))).not.toContain(
                "pages.deck.bulk_claim.menu_link"
            );
        }
        expect(entries(render({ ...fullyEquipped, collectionMode: "C" }))).toContain("pages.deck.bulk_claim.menu_link");
    });

    it("offers the unclaimed list in both tracking modes, but not with tracking off", () => {
        for (const mode of ["B", "C"] as const) {
            expect(entries(render({ ...fullyEquipped, collectionMode: mode }))).toContain(
                "pages.deck.unclaimed.menu_link"
            );
        }
        expect(entries(render({ ...fullyEquipped, collectionMode: "A" }))).not.toContain(
            "pages.deck.unclaimed.menu_link"
        );
    });

    it("hides the unclaimed list when the deck is fully covered", () => {
        expect(entries(render({ ...fullyEquipped, hasUnclaimedCards: false }))).not.toContain(
            "pages.deck.unclaimed.menu_link"
        );
    });
});

describe("DeckActionsMenu — navigation", () => {
    it.each([
        ["pages.create_deck.edit_link", "/decks/deck-1/edit"],
        ["pages.deck.bulk_claim.menu_link", "/decks/deck-1/bulk-claim"],
        ["pages.deck.unclaimed.menu_link", "/decks/deck-1/unclaimed"],
        ["pages.deck_qr.link", "/decks/deck-1/qr"]
    ])("sends %s to %s", async (label, url) => {
        const wrapper = render(fullyEquipped);

        await click(wrapper, label);

        expect(routerMock.visit).toHaveBeenCalledExactlyOnceWith(url);
    });

    it("hands the CSV export to the browser, not to Inertia", async () => {
        // The endpoint answers with Content-Disposition: attachment, which an
        // Inertia visit would not act on — so the component assigns
        // `location.href` instead. jsdom refuses to navigate, so the assignment
        // is intercepted rather than performed.
        const assigned: string[] = [];
        vi.stubGlobal("location", {
            ...window.location,
            set href(value: string) {
                assigned.push(value);
            },
            get href() {
                return assigned[assigned.length - 1] ?? "";
            }
        });
        const wrapper = render(fullyEquipped);

        await click(wrapper, "pages.decks.actions.export");

        expect(assigned).toEqual(["/decks/deck-1/export"]);
        expect(routerMock.visit).not.toHaveBeenCalled();
    });
});

describe("DeckActionsMenu — state and visibility changes", () => {
    it.each(["planned", "built", "archived"])("PATCHes the %s state", async state => {
        const wrapper = render({ ...fullyEquipped, deck: deck({ state: "planned" === state ? "built" : "planned" }) });

        await click(wrapper, `pages.decks.actions.set_${state}`);

        expect(routerMock.patch).toHaveBeenCalledExactlyOnceWith(
            "/decks/deck-1/state",
            { state },
            expect.objectContaining({ preserveScroll: true })
        );
    });

    it("flips a private deck to public", async () => {
        const wrapper = render({ ...fullyEquipped, deck: deck({ visibility: "private" }) });

        await click(wrapper, "pages.decks.actions.set_public");

        expect(routerMock.patch).toHaveBeenCalledExactlyOnceWith(
            "/decks/deck-1/visibility",
            { visibility: "public" },
            expect.objectContaining({ preserveScroll: true })
        );
    });

    it("flips a public deck back to private", async () => {
        const wrapper = render({ ...fullyEquipped, deck: deck({ visibility: "public" }) });

        await click(wrapper, "pages.decks.actions.set_private");

        expect(routerMock.patch).toHaveBeenCalledWith(
            "/decks/deck-1/visibility",
            { visibility: "private" },
            expect.anything()
        );
    });
});

describe("DeckActionsMenu — deleting", () => {
    it("asks first when the deck holds something worth losing", async () => {
        const wrapper = render({ ...fullyEquipped, deck: deck({ card_count: { main: 60, companion: 0, side: 0 } }) });

        await click(wrapper, "pages.decks.actions.delete");

        expect(wrapper.findComponent({ name: "DeleteDeckModal" }).exists()).toBe(true);
        expect(routerMock.delete).not.toHaveBeenCalled();
    });

    it("deletes an effectively-empty deck straight away", async () => {
        const empty = deck({
            card_count: { main: 0, companion: 0, side: 0 },
            has_companion: false,
            has_description: false,
            has_image: false
        });
        const wrapper = render({ ...fullyEquipped, deck: empty });

        await click(wrapper, "pages.decks.actions.delete");

        expect(wrapper.findComponent({ name: "DeleteDeckModal" }).exists()).toBe(false);
        expect(routerMock.delete).toHaveBeenCalledWith("/decks/deck-1", expect.anything());
    });

    it("returns to the deck list once the delete lands", async () => {
        const empty = deck({
            card_count: { main: 0, companion: 0, side: 0 },
            has_description: false
        });
        const wrapper = render({ ...fullyEquipped, deck: empty });
        await click(wrapper, "pages.decks.actions.delete");

        const options = routerMock.delete.mock.calls[0][1] as { onSuccess: () => void };
        options.onSuccess();

        expect(routerMock.visit).toHaveBeenCalledWith("/decks");
    });

    it("counts a companion as worth confirming even with no cards at all", async () => {
        // `card_count` has to be all-zero: the total the delete summary reads
        // sums main + companion + side, so a companion counted there would
        // short-circuit on the card count before reaching the companion flag.
        const wrapper = render({
            ...fullyEquipped,
            deck: deck({
                card_count: { main: 0, companion: 0, side: 0 },
                has_companion: true,
                has_description: false,
                has_image: false
            })
        });

        await click(wrapper, "pages.decks.actions.delete");

        expect(wrapper.findComponent({ name: "DeleteDeckModal" }).exists()).toBe(true);
        expect(routerMock.delete).not.toHaveBeenCalled();
    });
});

describe("DeckActionsMenu — modals", () => {
    it("opens none of them until asked", () => {
        const wrapper = render(fullyEquipped);

        expect(wrapper.findComponent({ name: "DeckCustomGroupsModal" }).exists()).toBe(false);
        expect(wrapper.findComponent({ name: "DeckAddGroupModal" }).exists()).toBe(false);
        expect(wrapper.findComponent({ name: "AddAllToCollectionModal" }).exists()).toBe(false);
    });

    it("opens the create-group modal", async () => {
        const wrapper = render(fullyEquipped);

        await click(wrapper, "pages.deck.create_group.link");

        expect(wrapper.findComponent({ name: "DeckAddGroupModal" }).exists()).toBe(true);
    });

    it("opens the custom-groups modal", async () => {
        const wrapper = render(fullyEquipped);

        await click(wrapper, "pages.deck.custom_groups.link");

        expect(wrapper.findComponent({ name: "DeckCustomGroupsModal" }).exists()).toBe(true);
    });

    it("opens the bulk add-to-collection modal", async () => {
        const wrapper = render(fullyEquipped);

        await click(wrapper, "pages.deck.add_all_to_collection.link");

        expect(wrapper.findComponent({ name: "AddAllToCollectionModal" }).exists()).toBe(true);
    });

    it("closes the popover whenever it opens a modal", async () => {
        const wrapper = render(fullyEquipped);

        await click(wrapper, "pages.deck.create_group.link");

        expect(HTMLElement.prototype.hidePopover).toHaveBeenCalled();
    });
});
