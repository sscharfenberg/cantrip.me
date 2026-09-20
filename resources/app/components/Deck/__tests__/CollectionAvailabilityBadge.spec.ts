import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { setTestMessages } from "@/test/i18n.ts";
import type { CollectionAvailability } from "Types/deckPage.ts";
import CollectionAvailabilityBadge from "../CollectionAvailabilityBadge.vue";

const availability = (
    state: CollectionAvailability["state"],
    needed: number,
    exact: number,
    other: number,
    blocked = 0
): CollectionAvailability => ({ state, needed, exact, other, blocked });

const render = (value: CollectionAvailability, variant?: "inline" | "corner") =>
    mount(CollectionAvailabilityBadge, { props: { availability: value, variant } });

const tooltipOf = (value: CollectionAvailability): string | undefined =>
    render(value).attributes("data-tooltip");

describe("CollectionAvailabilityBadge — icon and colour", () => {
    it.each([
        ["available", "#check", "collection-availability--available"],
        ["partial", "#planned", "collection-availability--partial"],
        ["unavailable", "#money", "collection-availability--unavailable"]
    ] as [CollectionAvailability["state"], string, string][])(
        "shows %s as %s",
        (state, icon, colourClass) => {
            const wrapper = render(availability(state, 1, 0, 0));

            expect(wrapper.find("use").attributes("href")).toBe(icon);
            expect(wrapper.classes()).toContain(colourClass);
        }
    );

    it("gives each state its own icon, so the three read apart at a glance", () => {
        const icons = (["available", "partial", "unavailable"] as CollectionAvailability["state"][]).map(
            state => render(availability(state, 1, 0, 0)).find("use").attributes("href")
        );

        expect(new Set(icons).size).toBe(3);
    });

    it("keeps the shared base class so the flag styling applies", () => {
        expect(render(availability("available", 1, 1, 0)).classes()).toContain("collection-availability");
    });

    it("lays out inline unless told otherwise", () => {
        // Text rows use `inline`; the image grid overlays a `corner`.
        expect(render(availability("available", 1, 1, 0)).classes()).toContain("collection-availability--inline");
    });

    it("switches to the corner variant on request", () => {
        const classes = render(availability("available", 1, 1, 0), "corner").classes();

        expect(classes).toContain("collection-availability--corner");
        expect(classes).not.toContain("collection-availability--inline");
    });
});

describe("CollectionAvailabilityBadge — tooltip phrasing", () => {
    it.each([
        ["available", availability("available", 4, 4, 0)],
        ["partial_printing", availability("partial", 4, 2, 0)],
        ["partial_other", availability("partial", 4, 0, 3)],
        ["partial_mixed", availability("partial", 4, 1, 2)],
        ["unavailable_held", availability("unavailable", 4, 0, 0, 4)],
        ["unavailable_missing", availability("unavailable", 4, 0, 0, 0)]
    ] satisfies [string, CollectionAvailability][])("uses the %s phrasing", (key, value) => {
        expect(tooltipOf(value)).toContain(`pages.deck.collection_availability.${key}`);
    });

    it("distinguishes the three partial shapes from one another", () => {
        // A prefix check would pass even if every partial row collapsed to
        // one branch — these three must disagree.
        const phrasings = [
            tooltipOf(availability("partial", 4, 2, 0)),
            tooltipOf(availability("partial", 4, 0, 3)),
            tooltipOf(availability("partial", 4, 1, 2))
        ];

        expect(new Set(phrasings).size).toBe(3);
    });

    it("adds the held-elsewhere line when free copies and held copies coexist", () => {
        const tooltip = tooltipOf(availability("partial", 4, 1, 0, 3));

        expect(tooltip).toContain("pages.deck.collection_availability.partial_printing");
        expect(tooltip).toContain("<br />");
        expect(tooltip).toContain("pages.deck.collection_availability.also_held");
    });

    it("leaves the held-elsewhere line off when nothing is held", () => {
        expect(tooltipOf(availability("partial", 4, 1, 0, 0))).not.toContain(
            "pages.deck.collection_availability.also_held"
        );
    });

    it("does not repeat the held count when the row is unavailable because of it", () => {
        // `unavailable_held` already names `blocked` — appending `also_held`
        // would say the same thing twice.
        const tooltip = tooltipOf(availability("unavailable", 4, 0, 0, 4));

        expect(tooltip).toContain("pages.deck.collection_availability.unavailable_held");
        expect(tooltip).not.toContain("pages.deck.collection_availability.also_held");
    });
});

describe("CollectionAvailabilityBadge — tooltip numbers", () => {
    it("interpolates the counts the user needs to act on", () => {
        setTestMessages({
            de: {
                pages: {
                    deck: {
                        collection_availability: {
                            partial_mixed: "{exact} von {needed} ({other} andere Drucke)",
                            also_held: "{blocked} gehören anderen Decks"
                        }
                    }
                }
            }
        });

        expect(tooltipOf(availability("partial", 4, 1, 2, 3))).toBe(
            "1 von 4 (2 andere Drucke)<br />3 gehören anderen Decks"
        );
    });
});
