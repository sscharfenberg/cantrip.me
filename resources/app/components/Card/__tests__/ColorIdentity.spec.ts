import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import ColorIdentity from "../ColorIdentity.vue";

/** The colour letters rendered, in render order. */
const lettersOf = (colorIdentity: string | null): string[] =>
    mount(ColorIdentity, { props: { colorIdentity } })
        .findAll("img")
        .map(img => img.attributes("alt") ?? "");

describe("ColorIdentity", () => {
    it("renders one symbol per colour", () => {
        expect(lettersOf("RW")).toEqual(["W", "R"]);
    });

    it("normalises to WUBRG order, whatever order the database stores", () => {
        // Scryfall stores identities alphabetically (`GR`, `UW`), not in the
        // order the game presents them.
        expect(lettersOf("GR")).toEqual(["R", "G"]);
        expect(lettersOf("UW")).toEqual(["W", "U"]);
        expect(lettersOf("BGRUW")).toEqual(["W", "U", "B", "R", "G"]);
    });

    it("falls back to the colourless symbol", () => {
        expect(lettersOf(null)).toEqual(["C"]);
        expect(lettersOf("")).toEqual(["C"]);
    });

    it("points each symbol at its own sprite", () => {
        const sources = mount(ColorIdentity, { props: { colorIdentity: "WU" } })
            .findAll("img")
            .map(img => img.attributes("src"));

        expect(sources).toEqual(["/symbol/W.svg", "/symbol/U.svg"]);
    });

    it("ignores letters outside WUBRG rather than rendering a broken image", () => {
        expect(lettersOf("WC")).toEqual(["W"]);
    });

    it("renders a mono-coloured card as one symbol, not as colourless", () => {
        expect(lettersOf("W")).toEqual(["W"]);
    });
});
