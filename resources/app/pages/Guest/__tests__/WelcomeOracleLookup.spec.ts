import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { installFetchMock, type FetchMock } from "@/test/http.ts";
import { setTestMessages } from "@/test/i18n.ts";
import type { OracleLookupCard } from "Types/oracleLookup.ts";
import WelcomeOracleLookup from "../WelcomeOracleLookup.vue";

const LOOKUP = "/api/cards/lookup";

const card = (overrides: Partial<OracleLookupCard> = {}): OracleLookupCard => ({
    id: "oracle-sol-ring",
    name: "Sol Ring",
    color_identity: null,
    printing_id: "lea-269",
    faces: [{ type_line: "Artifact", mana_cost: "{1}" }],
    ...overrides
});

const FABLE = card({
    id: "oracle-fable",
    name: "Fable of the Mirror-Breaker // Reflection of Kiki-Jiki",
    color_identity: "R",
    printing_id: "neo-141",
    faces: [
        { type_line: "Enchantment — Saga", mana_cost: "{2}{R}" },
        { type_line: "Enchantment Creature — Goblin Shaman", mana_cost: null }
    ]
});

let http: FetchMock;

beforeEach(() => {
    vi.useFakeTimers();
    http = installFetchMock();
});

afterEach(() => vi.useRealTimers());

const render = () =>
    mount(WelcomeOracleLookup, { attachTo: document.body, global: { stubs: { CardPreviewModal: true } } });

type Wrapper = ReturnType<typeof render>;

/** Type into the field and let the debounce run out. */
const search = async (wrapper: Wrapper, query: string): Promise<void> => {
    await wrapper.find("input").setValue(query);
    vi.advanceTimersByTime(500);
    await flushPromises();
};

const names = (wrapper: Wrapper) => wrapper.findAll(".oracle-lookup__name").map(name => name.text());

describe("WelcomeOracleLookup — searching", () => {
    it("waits for typing to pause before it looks anything up", async () => {
        http.json(LOOKUP, { cards: [card()], truncated: false });
        const wrapper = render();

        await wrapper.find("input").setValue("sol");
        vi.advanceTimersByTime(300);
        await wrapper.find("input").setValue("sol ring");
        vi.advanceTimersByTime(499);

        expect(http.calls).toHaveLength(0);

        vi.advanceTimersByTime(1);
        await flushPromises();

        expect(http.calls).toHaveLength(1);
        expect(new URL(http.calls[0].url, "http://x").searchParams.get("q")).toBe("sol ring");
    });

    it("passes set: and number: through to the server untouched", async () => {
        http.json(LOOKUP, { cards: [card()], truncated: false });

        await search(render(), "set:mh2 number:189");

        expect(new URL(http.calls[0].url, "http://x").searchParams.get("q")).toBe("set:mh2 number:189");
    });

    it("looks nothing up for a single character, and clears what was there", async () => {
        http.json(LOOKUP, { cards: [card()], truncated: false });
        const wrapper = render();
        await search(wrapper, "sol");

        await search(wrapper, "s");

        expect(http.calls).toHaveLength(1);
        expect(names(wrapper)).toEqual([]);
    });

    it("spins while the lookup runs", async () => {
        http.hang(LOOKUP);
        const wrapper = render();

        await search(wrapper, "sol");

        expect(wrapper.find(".loading-spinner").exists()).toBe(true);
    });

    it("keeps spinning while a newer lookup runs, though the one it overtook has ended", async () => {
        http.hang(LOOKUP);
        const wrapper = render();
        await search(wrapper, "sol");

        await search(wrapper, "sol ring");

        expect(http.calls[0].signal?.aborted).toBe(true);
        expect(wrapper.find(".loading-spinner").exists()).toBe(true);
    });

    it("drops a lookup a newer one overtook", async () => {
        http.hang(LOOKUP);
        const wrapper = render();
        await search(wrapper, "sol");
        const first = http.calls[0];

        http.json(LOOKUP, { cards: [card()], truncated: false });
        await search(wrapper, "sol ring");

        expect(first.signal?.aborted).toBe(true);
        expect(names(wrapper)).toEqual(["Sol Ring"]);
        expect(wrapper.find(".loading-spinner").exists()).toBe(false);
    });
});

describe("WelcomeOracleLookup — the results", () => {
    it("shows each card's name, color identity, and every face's type and cost", async () => {
        http.json(LOOKUP, { cards: [FABLE], truncated: false });
        const wrapper = render();

        await search(wrapper, "fable");

        const row = wrapper.find(".oracle-lookup__result");
        expect(names(wrapper)).toEqual(["Fable of the Mirror-Breaker // Reflection of Kiki-Jiki"]);
        expect(row.findAll(".color-identity img").map(img => img.attributes("src"))).toEqual(["/symbol/R.svg"]);
        expect(row.findAll(".oracle-lookup__type").map(type => type.text())).toEqual([
            "Enchantment — Saga",
            "Enchantment Creature — Goblin Shaman"
        ]);
        expect(row.findAll(".oracle-lookup__face-sep")).toHaveLength(1);
        expect(
            row
                .findAll(".oracle-lookup__face")[0]
                .findAll("img")
                .map(img => img.attributes("src"))
        ).toEqual(["/symbol/2.svg", "/symbol/R.svg"]);
    });

    it("says so when nothing matches", async () => {
        http.json(LOOKUP, { cards: [], truncated: false });
        const wrapper = render();

        await search(wrapper, "zzz");

        expect(wrapper.find(".oracle-lookup__note").text()).toBe("pages.welcome.lookup.no_results");
    });

    it("says so when the list was cut short", async () => {
        setTestMessages({ de: { pages: { welcome: { lookup: { truncated: "Erste {count}" } } } } });
        http.json(LOOKUP, { cards: [card(), FABLE], truncated: true });
        const wrapper = render();

        await search(wrapper, "dragon");

        expect(wrapper.findAll(".oracle-lookup__note").map(note => note.text())).toEqual(["Erste 2"]);
    });

    it("shows no note on a complete list", async () => {
        http.json(LOOKUP, { cards: [card()], truncated: false });
        const wrapper = render();

        await search(wrapper, "sol");

        expect(wrapper.findAll(".oracle-lookup__note")).toHaveLength(0);
    });
});

describe("WelcomeOracleLookup — the card modal", () => {
    it("opens the card's printing in the detail modal", async () => {
        http.json(LOOKUP, { cards: [card(), FABLE], truncated: false });
        const wrapper = render();
        await search(wrapper, "sol");

        await wrapper.findAll(".oracle-lookup__result")[1].trigger("click");

        expect(wrapper.findComponent({ name: "CardPreviewModal" }).props("previewUrl")).toBe("/cards/neo-141/preview");
    });

    it("cannot open a card that has no printing", async () => {
        http.json(LOOKUP, { cards: [card({ printing_id: null })], truncated: false });
        const wrapper = render();
        await search(wrapper, "sol");

        const button = wrapper.find(".oracle-lookup__result");

        expect(button.attributes("disabled")).toBeDefined();
    });

    it("hands focus back to the field when the modal closes, so the results come back", async () => {
        http.json(LOOKUP, { cards: [card()], truncated: false });
        const wrapper = render();
        await search(wrapper, "sol");
        await wrapper.find(".oracle-lookup__result").trigger("click");

        wrapper.findComponent({ name: "CardPreviewModal" }).vm.$emit("close");
        await flushPromises();

        expect(wrapper.find("card-preview-modal-stub").exists()).toBe(false);
        expect(document.activeElement).toBe(wrapper.find("input").element);
    });
});
