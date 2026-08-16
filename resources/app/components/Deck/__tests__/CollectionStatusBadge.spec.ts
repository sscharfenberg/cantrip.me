import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import type { CollectionStatus } from "Types/deckPage.ts";
import CollectionStatusBadge from "../CollectionStatusBadge.vue";

const render = (status: CollectionStatus, variant?: "inline" | "corner") =>
    mount(CollectionStatusBadge, { props: { status, variant } });

const ALL_STATUSES: CollectionStatus[] = [
    "claimed_for_this_deck",
    "available",
    "claimed_by_other_deck",
    "wrong_printing",
    "not_owned"
];

describe("CollectionStatusBadge — icons", () => {
    it.each([
        ["claimed_for_this_deck", "#check"],
        ["available", "#edit"],
        ["claimed_by_other_deck", "#swords"],
        ["wrong_printing", "#planned"],
        ["not_owned", "#money"]
    ] as [CollectionStatus, string][])("shows %s as %s", (status, icon) => {
        expect(render(status).find("use").attributes("href")).toBe(icon);
    });

    it("gives each status its own icon, so the five are distinguishable at a glance", () => {
        const icons = ALL_STATUSES.map(status => render(status).find("use").attributes("href"));

        expect(new Set(icons).size).toBe(ALL_STATUSES.length);
    });
});

describe("CollectionStatusBadge — classes", () => {
    it.each(ALL_STATUSES)("carries %s as a colour class", status => {
        expect(render(status).classes()).toContain(`collection-status--${status}`);
    });

    it("lays out inline unless told otherwise", () => {
        // Text rows use `inline`; the image grid overlays a `corner`.
        expect(render("available").classes()).toContain("collection-status--inline");
    });

    it("switches to the corner variant on request", () => {
        const classes = render("available", "corner").classes();

        expect(classes).toContain("collection-status--corner");
        expect(classes).not.toContain("collection-status--inline");
    });

    it("keeps the shared base class so the flag styling applies", () => {
        expect(render("available").classes()).toContain("collection-status");
    });
});

describe("CollectionStatusBadge — tooltip", () => {
    it.each(ALL_STATUSES)("explains %s in the tooltip", status => {
        expect(render(status).attributes("data-tooltip")).toBe(`pages.deck.collection_status.${status}`);
    });
});
