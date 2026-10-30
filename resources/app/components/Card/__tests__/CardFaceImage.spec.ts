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

/** Real copies owned per finish, and the proxies. */
const owned = (nonfoil: number, foil = 0, etched = 0, proxy = 0): OwnedCopies => ({ nonfoil, foil, etched, proxy });

const messages = () =>
    setTestMessages({
        de: {
            enums: { finishes: { nonfoil: "Normal", foil: "Foil", etched: "Geätzt" } },
            components: {
                card_face_image: {
                    owned_title: "In deiner Sammlung:",
                    owned_finish: "{count}× {finish}",
                    owned_proxy: "{count}× Proxy"
                }
            }
        }
    });

describe("CardFaceImage — collection ownership badge", () => {
    it("shows one number: every copy owned, proxies included", () => {
        const counts = badge(render({ finishes: ["nonfoil", "foil"], owned: owned(2, 1, 0, 1) })).findAll(
            ".face-image__panel-count"
        );

        expect(counts.map(count => count.text())).toEqual(["4"]);
    });

    it("shows a badge for proxies alone", () => {
        expect(
            badge(render({ owned: owned(0, 0, 0, 2) }))
                .find(".face-image__panel-count")
                .text()
        ).toBe("2");
    });

    it("marks the number with the storage icon only — no finish or proxy symbol", () => {
        const icons = badge(render({ finishes: ["nonfoil", "foil"], owned: owned(1, 1, 1, 1) })).findAll("use");

        expect(icons.map(icon => icon.attributes("href"))).toEqual(["#storage"]);
    });

    it("hides the bare number from a screen reader and tells it in words instead", () => {
        messages();
        const wrapper = badge(render({ owned: owned(2, 1, 0, 1) }));

        expect(wrapper.find(".face-image__panel-count").attributes("aria-hidden")).toBe("true");
        expect(wrapper.find(".sr-only").text()).toBe("In deiner Sammlung: 2× Normal, 1× Foil, 1× Proxy");
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
    it("names each finish owned and the proxies, one line each, and leaves out what is not owned", () => {
        messages();

        expect(badge(render({ finishes: ["nonfoil", "foil"], owned: owned(2, 1) })).attributes("data-tooltip")).toBe(
            "In deiner Sammlung:<br />2× Normal<br />1× Foil"
        );
        expect(badge(render({ owned: owned(0, 0, 3, 2) })).attributes("data-tooltip")).toBe(
            "In deiner Sammlung:<br />3× Geätzt<br />2× Proxy"
        );
    });

    // The `container: tooltipContainer ?? "body"` half of the binding is not
    // asserted: the shared v-tooltip stub records only the resolved content,
    // and the set-icon tooltip directly above uses the same expression.
});
