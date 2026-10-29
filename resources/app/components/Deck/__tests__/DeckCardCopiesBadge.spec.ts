import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { setTestMessages } from "@/test/i18n.ts";
import type { DeckCardCopyGroup } from "Types/deckPage.ts";
import DeckCardCopiesBadge from "../DeckCardCopiesBadge.vue";

const group = (overrides: Partial<DeckCardCopyGroup> = {}): DeckCardCopyGroup => ({
    count: 1,
    finish: "nonfoil",
    language: "en",
    proxy: false,
    claimed: false,
    ...overrides
});

const render = (copies: DeckCardCopyGroup[], variant?: "inline" | "corner") =>
    mount(DeckCardCopiesBadge, { props: { copies, variant } });

/** The icon names, in order. */
const icons = (wrapper: ReturnType<typeof render>): string[] =>
    wrapper.findAll("use").map(use => use.attributes("href")!.replace("#", ""));

const MESSAGES = {
    de: {
        enums: { card_languages: { de: "Deutsch", ja: "Japanisch" } },
        pages: {
            deck: {
                copies: {
                    marker: {
                        foil_all: "Foil",
                        foil_some: "{count} von {total} Foil",
                        etched_all: "Geätzt",
                        etched_some: "{count} von {total} geätzt",
                        proxy_all: "Proxy",
                        proxy_some: "{count} von {total} Proxies",
                        language_all: "{language}",
                        language_some: "{count} von {total} auf {language}"
                    }
                }
            }
        }
    }
};

describe("DeckCardCopiesBadge — nothing to say", () => {
    it("renders nothing for English nonfoil real cards", () => {
        const wrapper = render([group({ count: 3 }), group({ count: 1, claimed: true })]);

        expect(wrapper.find(".deck-copies").exists()).toBe(false);
    });

    it("renders nothing without copies", () => {
        expect(render([]).find(".deck-copies").exists()).toBe(false);
    });
});

describe("DeckCardCopiesBadge — the flags", () => {
    it.each([
        [{ finish: "foil" }, "star"],
        [{ finish: "etched" }, "texture"],
        [{ proxy: true }, "print"]
    ] as [Partial<DeckCardCopyGroup>, string][])("marks %o with %s", (overrides, icon) => {
        expect(icons(render([group(overrides)]))).toEqual([icon]);
    });

    it("shows one flag image per language other than English", () => {
        const wrapper = render([group({ language: "de" }), group({ language: "ja" }), group({ language: "en" })]);

        // The image URL is inlined as a data URI under Vitest, so the marker class names the language.
        expect(wrapper.findAll("img.deck-copies__flag")).toHaveLength(2);
        expect(wrapper.findAll(".deck-copies__marker").map(marker => marker.classes()[1])).toEqual([
            "deck-copies__marker--lang-de",
            "deck-copies__marker--lang-ja"
        ]);
    });

    it("puts the finish before the proxy flag before the languages", () => {
        const wrapper = render([group({ finish: "foil", proxy: true, language: "de" })]);

        expect(wrapper.findAll(".deck-copies__marker").map(marker => marker.classes()[1])).toEqual([
            "deck-copies__marker--foil",
            "deck-copies__marker--proxy",
            "deck-copies__marker--lang-de"
        ]);
    });
});

describe("DeckCardCopiesBadge — counting", () => {
    it("shows no count when a flag covers every copy", () => {
        const wrapper = render([group({ count: 4, finish: "foil" })]);

        expect(wrapper.find(".deck-copies__count").exists()).toBe(false);
    });

    it("counts claimed and planned copies together", () => {
        // Two claimed foils from the collection, two planned nonfoil copies.
        const wrapper = render([
            group({ count: 2, finish: "foil", claimed: true }),
            group({ count: 2, finish: "nonfoil", claimed: false })
        ]);

        expect(icons(wrapper)).toEqual(["star"]);
        expect(wrapper.find(".deck-copies__count").text()).toBe("2");
    });

    it("sums a flag over the groups that carry it", () => {
        const wrapper = render([
            group({ count: 1, proxy: true, claimed: true }),
            group({ count: 2, proxy: true }),
            group({ count: 3 })
        ]);

        expect(wrapper.find(".deck-copies__marker--proxy .deck-copies__count").text()).toBe("3");
    });
});

describe("DeckCardCopiesBadge — tooltip", () => {
    it("says 'all' when a flag covers every copy and 'n of total' otherwise", () => {
        setTestMessages(MESSAGES);
        const wrapper = render([
            group({ count: 3, finish: "foil", language: "de", proxy: true }),
            group({ count: 1, finish: "etched", language: "de" })
        ]);

        expect(wrapper.attributes("data-tooltip")).toBe(
            "3 von 4 Foil<br />1 von 4 geätzt<br />3 von 4 Proxies<br />Deutsch"
        );
        expect(wrapper.attributes("aria-label")).toBe("3 von 4 Foil, 1 von 4 geätzt, 3 von 4 Proxies, Deutsch");
    });

    it("names a language some copies are in", () => {
        setTestMessages(MESSAGES);
        const wrapper = render([group({ count: 2, language: "ja" }), group({ count: 2 })]);

        expect(wrapper.attributes("data-tooltip")).toBe("2 von 4 auf Japanisch");
    });

    it("words a flag on every copy without a count", () => {
        setTestMessages(MESSAGES);

        expect(render([group({ count: 2, finish: "foil" })]).attributes("data-tooltip")).toBe("Foil");
    });
});

describe("DeckCardCopiesBadge — layout", () => {
    it("lays out inline unless told otherwise", () => {
        expect(render([group({ finish: "foil" })]).classes()).toContain("deck-copies--inline");
    });

    it("switches to the corner variant on request", () => {
        const classes = render([group({ finish: "foil" })], "corner").classes();

        expect(classes).toContain("deck-copies--corner");
        expect(classes).not.toContain("deck-copies--inline");
    });
});
