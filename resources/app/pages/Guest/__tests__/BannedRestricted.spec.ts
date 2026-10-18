import { mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import { setTestMessages } from "@/test/i18n.ts";
import { routerMock } from "@/test/inertia.ts";
import type { BannedRestrictedCard, BannedRestrictedCards, BannedRestrictedFormat } from "Types/bannedRestricted.ts";
import BannedRestricted from "../BannedRestricted.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const card = (name: string, printingId: string | null = `p-${name}`): BannedRestrictedCard => ({
    name,
    mana_cost: ["{1}"],
    printing: printingId === null ? null : { id: printingId, image: `/card-images/${printingId}.webp` }
});

const CARDS: Record<string, BannedRestrictedCard> = {
    lotus: card("Black Lotus"),
    balance: card("Balance"),
    brainstorm: card("Brainstorm"),
    arbor: card("Dryad Arbor"),
    unprinted: card("Unprinted Card", null),
    plan: card("Backup Plan"),
    goblin: card("_____ Goblin"),
    elephant: card("Aerialephant")
};

const NONE: BannedRestrictedCards = { groups: [], categories: [] };

/**
 * Sent in the enum's order, which the page must not keep: by deck count
 * first, then by name — so the formats without decks come out as modern,
 * penny, vintage rather than modern, vintage, penny.
 */
const FORMATS: BannedRestrictedFormat[] = [
    {
        format: "modern",
        decks: 0,
        banned: {
            groups: [{ group: "multicolor", cards: ["brainstorm"] }],
            categories: [
                { category: "conspiracy", cards: ["plan"] },
                { category: "sticker", cards: ["goblin", "elephant"] }
            ]
        },
        restricted: NONE
    },
    {
        format: "legacy",
        decks: 1,
        banned: { groups: [{ group: "land", cards: ["arbor", "unprinted"] }], categories: [] },
        restricted: NONE
    },
    {
        format: "vintage",
        decks: 0,
        banned: NONE,
        restricted: {
            groups: [
                { group: "U", cards: ["brainstorm"] },
                { group: "colorless", cards: ["lotus"] }
            ],
            categories: []
        }
    },
    {
        format: "commander",
        decks: 3,
        banned: {
            groups: [
                { group: "W", cards: ["balance"] },
                { group: "colorless", cards: ["lotus"] }
            ],
            categories: []
        },
        restricted: NONE
    },
    { format: "penny", decks: 0, banned: NONE, restricted: NONE }
];

const render = (url = "/b+r", formats = FORMATS) => {
    history.replaceState(null, "", url);
    return mount(BannedRestricted, {
        props: { formats, cards: CARDS },
        attachTo: document.body,
        global: { stubs: { CardPreviewModal: true } }
    });
};

type Wrapper = ReturnType<typeof render>;

const tabs = (wrapper: Wrapper) => wrapper.findAll('[role="tab"]');
const selectedTab = (wrapper: Wrapper) => wrapper.find('[role="tab"][aria-selected="true"]');
const listHeadlines = (wrapper: Wrapper) => wrapper.findAll(".banned-restricted-list > h3").map(h => h.text());
const groupHeadlines = (wrapper: Wrapper) => wrapper.findAll(".text-card-group h4").map(h => h.text());
const cardNames = (wrapper: Wrapper) => wrapper.findAll(".card__name").map(n => n.text());

afterEach(() => history.replaceState(null, "", "/"));

describe("BannedRestricted — the tabs", () => {
    it("puts the formats with most decks first, then the rest alphabetically", () => {
        expect(tabs(render()).map(tab => tab.text())).toEqual([
            "enums.card_formats.commander",
            "enums.card_formats.legacy",
            "enums.card_formats.modern",
            "enums.card_formats.penny",
            "enums.card_formats.vintage"
        ]);
    });

    it("sorts by the name the tab shows, not by the format id", () => {
        setTestMessages({
            de: { enums: { card_formats: { modern: "Zeta", penny: "Alpha", vintage: "Mu", legacy: "Legacy" } } }
        });

        expect(tabs(render()).map(tab => tab.text())).toEqual([
            "enums.card_formats.commander",
            "Legacy",
            "Alpha",
            "Mu",
            "Zeta"
        ]);
    });

    it("opens the first tab without a hash", () => {
        const wrapper = render();

        expect(selectedTab(wrapper).text()).toBe("enums.card_formats.commander");
        expect(cardNames(wrapper)).toEqual(["Balance", "Black Lotus"]);
    });

    it("opens the tab the hash names", () => {
        const wrapper = render("/b+r#legacy");

        expect(selectedTab(wrapper).text()).toBe("enums.card_formats.legacy");
        expect(cardNames(wrapper)).toEqual(["Dryad Arbor", "Unprinted Card"]);
    });

    it("switches tab on click and writes it to the hash", async () => {
        const wrapper = render();

        await tabs(wrapper)[4].trigger("click");

        expect(selectedTab(wrapper).text()).toBe("enums.card_formats.vintage");
        expect(routerMock.replace).toHaveBeenCalledWith(expect.objectContaining({ url: "/b+r#vintage" }));
        expect(cardNames(wrapper)).toEqual(["Brainstorm", "Black Lotus"]);
    });

    it("keeps only the open tab in the Tab order", () => {
        const wrapper = render("/b+r#vintage");

        expect(tabs(wrapper).map(tab => tab.attributes("tabindex"))).toEqual(["-1", "-1", "-1", "-1", "0"]);
    });

    it.each([
        ["ArrowRight", "#legacy", "enums.card_formats.modern"],
        ["ArrowLeft", "#legacy", "enums.card_formats.commander"],
        ["ArrowRight", "#vintage", "enums.card_formats.commander"],
        ["ArrowLeft", "#commander", "enums.card_formats.vintage"],
        ["Home", "#vintage", "enums.card_formats.commander"],
        ["End", "#legacy", "enums.card_formats.vintage"]
    ])("moves %s from %s to %s and focuses it", async (key, hash, expected) => {
        const wrapper = render(`/b+r${hash}`);

        await selectedTab(wrapper).trigger("keydown", { key });
        await vi.waitFor(() => expect(document.activeElement?.textContent?.trim()).toBe(expected));

        expect(selectedTab(wrapper).text()).toBe(expected);
    });

    it("leaves modified arrow keys to the browser — Alt+ArrowLeft is Back", async () => {
        const wrapper = render("/b+r#legacy");

        await selectedTab(wrapper).trigger("keydown", { key: "ArrowLeft", altKey: true });

        expect(selectedTab(wrapper).text()).toBe("enums.card_formats.legacy");
    });

    it("ignores other keys", async () => {
        const wrapper = render("/b+r#legacy");

        await selectedTab(wrapper).trigger("keydown", { key: "ArrowDown" });

        expect(selectedTab(wrapper).text()).toBe("enums.card_formats.legacy");
    });

    it("labels the panel with its tab", () => {
        const wrapper = render("/b+r#vintage");

        expect(wrapper.find('[role="tabpanel"]').attributes("aria-labelledby")).toBe(
            selectedTab(wrapper).attributes("id")
        );
    });
});

describe("BannedRestricted — the lists", () => {
    it("titles the banned list with its count and each colour group, one level below, with its own", () => {
        const wrapper = render();

        expect(listHeadlines(wrapper)).toEqual(["enums.card_legalities.banned (2)"]);
        expect(groupHeadlines(wrapper)).toEqual([
            "pages.banned_restricted.groups.W (1)",
            "pages.banned_restricted.groups.colorless (1)"
        ]);
    });

    it("marks each colour group with its mana symbols — all five for multicolor, none for lands", () => {
        const symbolsOf = (wrapper: Wrapper) =>
            wrapper.findAll(".text-card-group h4").map(h => h.findAll("img").map(img => img.attributes("src")));

        expect(symbolsOf(render())).toEqual([["/symbol/W.svg"], ["/symbol/C.svg"]]);
        expect(symbolsOf(render("/b+r#legacy"))).toEqual([[]]);
        expect(symbolsOf(render("/b+r#modern"))).toEqual([
            ["/symbol/W.svg", "/symbol/U.svg", "/symbol/B.svg", "/symbol/R.svg", "/symbol/G.svg"]
        ]);
    });

    it("shows a restricted list on its own, and no banned list when nothing is banned", () => {
        expect(listHeadlines(render("/b+r#vintage"))).toEqual(["enums.card_legalities.restricted (2)"]);
    });

    it("says so when a format has neither list", () => {
        const wrapper = render("/b+r#penny");

        expect(listHeadlines(wrapper)).toEqual([]);
        expect(wrapper.find('[role="tabpanel"]').text()).toBe("pages.banned_restricted.empty");
    });

    it("pictures each card by its printing, with a placeholder when it has none", () => {
        const wrapper = render("/b+r#legacy");

        expect(wrapper.findAll("img.banned-card__thumb").map(img => img.attributes("src"))).toEqual([
            "/card-images/p-Dryad Arbor.webp"
        ]);
        expect(wrapper.findAll(".banned-card__thumb--empty")).toHaveLength(1);
    });
});

describe("BannedRestricted — the blanket bans", () => {
    const heads = (wrapper: Wrapper) => wrapper.findAll(".banned-restricted-category .collapsible__head");

    it("names each one in a line of its own, with its count, after the colour groups", () => {
        setTestMessages({
            de: {
                pages: {
                    banned_restricted: {
                        categories: {
                            conspiracy: "{count} Conspiracy card | {count} Conspiracy cards",
                            sticker: "{count} sticker card | {count} sticker cards"
                        }
                    }
                }
            }
        });

        const wrapper = render("/b+r#modern");

        expect(heads(wrapper).map(head => head.text())).toEqual(["1 Conspiracy card", "2 sticker cards"]);
        expect(wrapper.find(".banned-restricted-categories").classes()).toContain(
            "banned-restricted-categories--after-groups"
        );
    });

    it("counts their cards in the list's headline", () => {
        expect(listHeadlines(render("/b+r#modern"))).toEqual(["enums.card_legalities.banned (4)"]);
    });

    it("starts closed and lists the category's cards when opened", async () => {
        const wrapper = render("/b+r#modern");
        const sticker = wrapper.findAll(".banned-restricted-category")[1];

        expect(sticker.find(".collapsible__head").attributes("aria-expanded")).toBe("false");

        await sticker.find(".collapsible__head").trigger("click");

        expect(sticker.find(".collapsible__head").attributes("aria-expanded")).toBe("true");
        expect(sticker.findAll(".card__name").map(name => name.text())).toEqual(["_____ Goblin", "Aerialephant"]);
    });

    it("opens a category card's detail modal like any other", async () => {
        const wrapper = render("/b+r#modern");

        await wrapper.findAll(".banned-restricted-category")[1].find(".banned-card").trigger("click");

        expect(wrapper.findComponent({ name: "CardPreviewModal" }).props("previewUrl")).toBe(
            "/cards/p-_____ Goblin/preview"
        );
    });

    it("sits right under the headline when nothing is banned by name", () => {
        const wrapper = render("/b+r#modern", [
            { ...FORMATS[0], banned: { groups: [], categories: FORMATS[0].banned.categories } }
        ]);

        expect(groupHeadlines(wrapper)).toEqual([]);
        expect(wrapper.find(".banned-restricted-categories").classes()).not.toContain(
            "banned-restricted-categories--after-groups"
        );
    });
});

describe("BannedRestricted — the card modal", () => {
    it("opens the card's detail modal on click", async () => {
        const wrapper = render();

        await wrapper.findAll(".banned-card")[1].trigger("click");

        expect(wrapper.findComponent({ name: "CardPreviewModal" }).props("previewUrl")).toBe(
            "/cards/p-Black Lotus/preview"
        );
    });

    it("closes it again", async () => {
        const wrapper = render();
        await wrapper.findAll(".banned-card")[0].trigger("click");

        wrapper.findComponent({ name: "CardPreviewModal" }).vm.$emit("close");
        await wrapper.vm.$nextTick();

        expect(wrapper.find("card-preview-modal-stub").exists()).toBe(false);
    });

    it("disables the row of a card without a printing", () => {
        const buttons = render("/b+r#legacy").findAll(".banned-card");

        expect(buttons.map(button => button.attributes("disabled") !== undefined)).toEqual([false, true]);
    });

    it("opens no modal for a card without a printing, even when its row is clicked around the button", async () => {
        // `trigger()` is a no-op on a disabled button, so click the preview
        // trigger wrapping it — the path the guard has to stop on its own.
        const wrapper = render("/b+r#legacy");

        await wrapper.findAll(".card-preview__trigger")[1].trigger("click");

        expect(wrapper.find("card-preview-modal-stub").exists()).toBe(false);
    });
});
