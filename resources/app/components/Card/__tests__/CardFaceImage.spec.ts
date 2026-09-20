import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { setTestMessages } from "@/test/i18n.ts";
import type { DefaultCardImage } from "Types/defaultCardImage.ts";
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

describe("CardFaceImage — collection ownership badge", () => {
    it("shows the count when the viewer owns copies of this printing", () => {
        const wrapper = render({ owned: 3 });

        expect(badge(wrapper).exists()).toBe(true);
        expect(badge(wrapper).text()).toContain("3");
    });

    it("uses the storage icon", () => {
        expect(badge(render({ owned: 1 })).find("use").attributes("href")).toBe("#storage");
    });

    it.each([
        ["the field is absent — a guest, or an endpoint that doesn't ship it", undefined],
        ["the master switch is off, so the backend sent null", null],
        ["the viewer owns none of this printing", 0]
    ] satisfies [string, number | null | undefined][])("stays hidden when %s", (_case, owned) => {
        // The badge is a positive signal only: it never announces a zero,
        // and the three nothing-to-show cases are indistinguishable by design.
        expect(badge(render({ owned })).exists()).toBe(false);
    });

    it("shares the artist's row rather than adding a third one", () => {
        // The panel is two rows: collector number + set symbol, then artist
        // + owned count. A third row would push the panel over the art.
        const wrapper = render({ owned: 2 });
        const rows = wrapper.findAll(".face-image__panel > .face-image__panel-line");

        expect(rows).toHaveLength(2);
        expect(rows[1].find(".face-image__panel-artist").exists()).toBe(true);
        expect(rows[1].find(".face-image__panel-owned").exists()).toBe(true);
    });

    it("keeps the second row for a printing with no artist", () => {
        // Tokens routinely have no artist; the count still needs its row,
        // and `margin-left: auto` keeps it flush right without one.
        const wrapper = render({ owned: 2, artist: null });
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
    it("counts singular and plural apart", () => {
        setTestMessages({
            de: {
                components: {
                    card_face_image: {
                        owned: "{count} Exemplar in deiner Sammlung | {count} Exemplare in deiner Sammlung"
                    }
                }
            }
        });

        expect(badge(render({ owned: 1 })).attributes("data-tooltip")).toBe("1 Exemplar in deiner Sammlung");
        expect(badge(render({ owned: 4 })).attributes("data-tooltip")).toBe("4 Exemplare in deiner Sammlung");
    });

    // The `container: tooltipContainer ?? "body"` half of the binding is not
    // asserted: the shared v-tooltip stub records only the resolved content,
    // and the set-icon tooltip directly above uses the same expression.
});
