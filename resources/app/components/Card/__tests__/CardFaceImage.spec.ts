import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { setTestMessages } from "@/test/i18n.ts";
import type { DefaultCardImage, OwnedCopies } from "Types/defaultCardImage.ts";
import CardFaceImage from "../CardFaceImage.vue";

const card = (overrides: Partial<DefaultCardImage> = {}): DefaultCardImage => ({
    id: "printing-1",
    name: "Sol Ring",
    card_image_0: "/card-images/lea/sol-ring--0.jpg",
    card_image_1: null,
    artist: "Mark Tedin",
    cn: "270",
    finishes: ["nonfoil"],
    set: { name: "Limited Edition Alpha", code: "lea", path: "/set/lea.svg" },
    ...overrides
});

const render = (overrides: Partial<DefaultCardImage> = {}, props: Record<string, unknown> = {}) =>
    mount(CardFaceImage, { props: { card: card(overrides), ...props } });

const badge = (wrapper: ReturnType<typeof render>) => wrapper.find(".face-image__panel-owned");

/** Copies owned per finish. */
const owned = (nonfoil: number, foil = 0, etched = 0): OwnedCopies => ({ nonfoil, foil, etched });

describe("CardFaceImage — collection ownership badge", () => {
    const dual = { finishes: ["nonfoil", "foil"] };

    it("shows a count for each finish the printing exists in", () => {
        const wrapper = render({ ...dual, owned: owned(3, 1) });

        expect(badge(wrapper).find(".face-image__panel-count--nonfoil").text()).toBe("3");
        expect(badge(wrapper).find(".face-image__panel-count--foil").text()).toBe("1");
    });

    it("marks the foil count with the foil star, as the collection does", () => {
        expect(
            badge(render({ ...dual, owned: owned(1, 2) }))
                .find(".face-image__panel-count--foil use")
                .attributes("href")
        ).toBe("#star");
    });

    it("shows a dual-finish printing's 0 foil, so the counts keep their places", () => {
        const wrapper = render({ ...dual, owned: owned(2) });

        expect(badge(wrapper).find(".face-image__panel-count--foil").text()).toBe("0");
    });

    it("offers no foil count on a printing that has no foil", () => {
        const wrapper = render({ finishes: ["nonfoil"], owned: owned(2) });

        expect(badge(wrapper).find(".face-image__panel-count--nonfoil").text()).toBe("2");
        expect(badge(wrapper).find(".face-image__panel-count--foil").exists()).toBe(false);
    });

    it("shows a finish owned even where the printing data does not list it", () => {
        const wrapper = render({ finishes: ["nonfoil"], owned: owned(0, 1) });

        expect(badge(wrapper).find(".face-image__panel-count--nonfoil").text()).toBe("0");
        expect(badge(wrapper).find(".face-image__panel-count--foil").text()).toBe("1");
    });

    it("adds the etched count for an etched printing or etched copies", () => {
        expect(
            badge(render({ ...dual, owned: owned(1, 1) }))
                .find(".face-image__panel-count--etched")
                .exists()
        ).toBe(false);

        const etched = badge(render({ finishes: ["etched"], owned: owned(0, 0, 2) })).find(
            ".face-image__panel-count--etched"
        );
        expect(etched.text()).toBe("2");
        expect(etched.find("use").attributes("href")).toBe("#texture");
    });

    it("hides the bare digits from a screen reader and tells it in words instead", () => {
        setTestMessages({
            de: {
                enums: { finishes: { nonfoil: "Normal", foil: "Foil", etched: "Geätzt" } },
                components: {
                    card_face_image: { owned_title: "In deiner Sammlung:", owned_finish: "{count}× {finish}" }
                }
            }
        });
        const wrapper = badge(render({ ...dual, owned: owned(2, 1) }));

        expect(
            wrapper.findAll(".face-image__panel-count").every(count => count.attributes("aria-hidden") === "true")
        ).toBe(true);
        expect(wrapper.find(".sr-only").text()).toBe("In deiner Sammlung: 2× Normal, 1× Foil");
    });

    it("uses the storage icon", () => {
        expect(
            badge(render({ owned: owned(1) }))
                .find("use")
                .attributes("href")
        ).toBe("#storage");
    });

    it.each([
        ["the field is absent — a guest, or an endpoint that doesn't ship it", undefined],
        ["the master switch is off, so the backend sent null", null],
        ["the viewer owns none of this printing", owned(0)]
    ] satisfies [string, OwnedCopies | null | undefined][])("stays hidden when %s", (_case, copies) => {
        // The badge is a positive signal only: it never announces a zero,
        // and the three nothing-to-show cases are indistinguishable by design.
        expect(badge(render({ owned: copies })).exists()).toBe(false);
    });

    it("shares the artist's row rather than adding a third one", () => {
        // The panel is two rows: collector number + set symbol, then artist
        // + owned count. A third row would push the panel over the art.
        const wrapper = render({ owned: owned(2) });
        const rows = wrapper.findAll(".face-image__panel > .face-image__panel-line");

        expect(rows).toHaveLength(2);
        expect(rows[1].find(".face-image__panel-artist").exists()).toBe(true);
        expect(rows[1].find(".face-image__panel-owned").exists()).toBe(true);
    });

    it("keeps the second row for a printing with no artist", () => {
        // Tokens routinely have no artist; the count still needs its row,
        // and `margin-left: auto` keeps it flush right without one.
        const wrapper = render({ owned: owned(2), artist: null });
        const rows = wrapper.findAll(".face-image__panel > .face-image__panel-line");

        expect(rows).toHaveLength(2);
        expect(wrapper.find(".face-image__panel-artist").exists()).toBe(false);
        expect(badge(wrapper).exists()).toBe(true);
    });

    it("leaves the panel at one row when there is neither artist nor count", () => {
        const wrapper = render({ artist: null, owned: null });

        expect(wrapper.findAll(".face-image__panel > .face-image__panel-line")).toHaveLength(1);
    });
});

describe("CardFaceImage — ownership tooltip", () => {
    it("names each finish owned, one line each, and leaves out the ones not owned", () => {
        setTestMessages({
            de: {
                enums: { finishes: { nonfoil: "Normal", foil: "Foil", etched: "Geätzt" } },
                components: {
                    card_face_image: { owned_title: "In deiner Sammlung:", owned_finish: "{count}× {finish}" }
                }
            }
        });

        expect(badge(render({ finishes: ["nonfoil", "foil"], owned: owned(2, 1) })).attributes("data-tooltip")).toBe(
            "In deiner Sammlung:<br />2× Normal<br />1× Foil"
        );
        expect(badge(render({ owned: owned(0, 0, 3) })).attributes("data-tooltip")).toBe(
            "In deiner Sammlung:<br />3× Geätzt"
        );
    });

    // The `container: tooltipContainer ?? "body"` half of the binding is not
    // asserted: the shared v-tooltip stub records only the resolved content,
    // and the set-icon tooltip directly above uses the same expression.
});
