import { mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import type { CardStackFilterOptions, CardStackFilterPatch } from "Types/cardStackFilters";
import CardStackFilters from "../CardStackFilters.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

/** The price fields' debounce — a typed price goes out this long after the last keystroke. */
const DEBOUNCE = 750;

const STORAGE_KEY = "collection-filters-accordion-open";

const baseOptions: CardStackFilterOptions = {
    languages: ["de", "en"],
    conditions: ["near_mint", "played"],
    finishes: ["nonfoil", "foil", "etched"],
    rarities: ["common", "rare"],
    types: ["Creature", "Land"],
    sets: [{ code: "lea", name: "Limited Edition Alpha", path: "/set/lea.svg" }]
};

const render = (
    filters: Record<string, string> | null = null,
    options: Partial<CardStackFilterOptions> = {}
): ReturnType<typeof mount<typeof CardStackFilters>> =>
    mount(CardStackFilters, {
        props: { filters, options: { ...baseOptions, ...options }, storageKey: STORAGE_KEY }
    });

/** Every patch the component asked the table to apply, in order. */
const changes = (wrapper: ReturnType<typeof render>): CardStackFilterPatch[] =>
    (wrapper.emitted("change") ?? []).map(([patch]) => patch as CardStackFilterPatch);

const minInput = (wrapper: ReturnType<typeof render>) => wrapper.get('input[id$="-min_price"]');
const maxInput = (wrapper: ReturnType<typeof render>) => wrapper.get('input[id$="-max_price"]');

/** The dropdown whose group is labelled with this key (key echo: labels are i18n keys). */
const select = (wrapper: ReturnType<typeof render>, field: string) =>
    wrapper.findAllComponents(MonoSelect).find(component => component.element.closest(`.card-stack-filters__field--${field}`));

const selectLabels = (wrapper: ReturnType<typeof render>): string[] =>
    wrapper.findAll(".card-stack-filters__field[role=group] > .card-stack-filters__label").map(label => label.text());

describe("CardStackFilters — accordion", () => {
    it("starts closed on a first visit", () => {
        const wrapper = render();

        expect(wrapper.get(".collapsible__head").attributes("aria-expanded")).toBe("false");
    });

    it("opens when the user last left it open", () => {
        localStorage.setItem(STORAGE_KEY, "true");

        const wrapper = render();

        expect(wrapper.get(".collapsible__head").attributes("aria-expanded")).toBe("true");
    });

    it("remembers a toggle", async () => {
        const wrapper = render();

        await wrapper.get(".collapsible__head").trigger("click");

        expect(localStorage.getItem(STORAGE_KEY)).toBe("true");
    });

    it("counts the active filters in its head, the color match not among them", () => {
        expect(render().find(".badge").exists()).toBe(false);
        expect(render({ lang: "de", colors: "WU", color_mode: "exact" }).get(".badge").text()).toBe("2");
    });
});

describe("CardStackFilters — prices", () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("waits for a pause in typing before sending a price", async () => {
        const wrapper = render();

        await minInput(wrapper).setValue("2,5");
        vi.advanceTimersByTime(DEBOUNCE - 1);
        expect(changes(wrapper)).toEqual([]);

        vi.advanceTimersByTime(1);
        expect(changes(wrapper)).toEqual([{ min_price: "2.5" }]);
    });

    it("sends right away on Enter", async () => {
        const wrapper = render();

        await minInput(wrapper).setValue("3");
        await minInput(wrapper).trigger("keydown", { key: "Enter" });

        expect(changes(wrapper)).toEqual([{ min_price: "3" }]);
        vi.advanceTimersByTime(DEBOUNCE);
        expect(changes(wrapper)).toHaveLength(1);
    });

    it("does not send a price that does not parse, or one already applied", async () => {
        const wrapper = render({ min_price: "1.5" });

        await minInput(wrapper).setValue("abc");
        vi.advanceTimersByTime(DEBOUNCE);
        await minInput(wrapper).setValue("1.50");
        vi.advanceTimersByTime(DEBOUNCE);

        expect(changes(wrapper)).toEqual([]);
    });

    it("switches proxies off along with a maximum of 1 or less", async () => {
        const wrapper = render();

        await maxInput(wrapper).setValue("0,5");
        vi.advanceTimersByTime(DEBOUNCE);

        expect(changes(wrapper)).toEqual([{ max_price: "0.5", proxy: "none" }]);
    });

    it("sends a price still waiting out the debounce together with the next filter", async () => {
        const wrapper = render();

        await maxInput(wrapper).setValue("5");
        select(wrapper, "lang")!.vm.$emit("change", "de");
        vi.advanceTimersByTime(DEBOUNCE);

        expect(changes(wrapper)).toEqual([{ max_price: "5", lang: "de" }]);
    });

    it("shows a price changed from outside, but not over one being typed", async () => {
        const wrapper = render({ min_price: "1" });

        await wrapper.setProps({ filters: { min_price: "2" } });
        expect((minInput(wrapper).element as HTMLInputElement).value).toBe("2");

        await minInput(wrapper).setValue("25");
        await wrapper.setProps({ filters: { min_price: "3" } });
        expect((minInput(wrapper).element as HTMLInputElement).value).toBe("25");
    });
});

describe("CardStackFilters — proxies", () => {
    it("checks the applied choice, All by default", () => {
        expect((render().get('input[value="all"]').element as HTMLInputElement).checked).toBe(true);
        expect((render({ proxy: "none" }).get('input[value="none"]').element as HTMLInputElement).checked).toBe(true);
    });

    it("sends All as no filter at all", async () => {
        const wrapper = render({ proxy: "only" });

        await wrapper.get('input[value="all"]').setValue(true);

        expect(changes(wrapper)).toEqual([{ proxy: null }]);
    });

    it("clears a maximum of 1 or less when proxies are asked for again", async () => {
        const wrapper = render({ max_price: "0.5", proxy: "none" });

        await wrapper.get('input[value="only"]').setValue(true);

        expect(changes(wrapper)).toEqual([{ proxy: "only", max_price: null }]);
        expect((maxInput(wrapper).element as HTMLInputElement).value).toBe("");
    });
});

describe("CardStackFilters — dropdowns", () => {
    it("sends a pick, and the clear button as no filter", () => {
        const wrapper = render({ rarity: "rare" });

        select(wrapper, "set")!.vm.$emit("change", "lea");
        select(wrapper, "rarity")!.vm.$emit("change", "");

        expect(changes(wrapper)).toEqual([{ set: "lea" }, { rarity: null }]);
    });

    it("shows the container filter only where there are containers to pick", () => {
        expect(selectLabels(render())).not.toContain("form.fields.container_name");
        expect(selectLabels(render(null, { containers: [{ id: "c1", name: "Binder" }] }))).toContain(
            "form.fields.container_name"
        );
    });

    it("shows the claim filter only where the decks were shipped — the owner's view", () => {
        expect(selectLabels(render())).not.toContain("components.card_stack_filters.claimed.label");

        const wrapper = render(null, { decks: [{ id: "d1", name: "Burn" }] });
        expect(selectLabels(wrapper)).toContain("components.card_stack_filters.claimed.label");
        expect(select(wrapper, "claimed")!.props("options")).toEqual([
            { value: "any", label: "components.card_stack_filters.claimed.any" },
            { value: "none", label: "components.card_stack_filters.claimed.none" },
            { value: "d1", label: "Burn" }
        ]);
    });
});

describe("CardStackFilters — color identity", () => {
    const colorButton = (wrapper: ReturnType<typeof render>, color: string) =>
        wrapper.get(`.card-stack-filters__color[aria-label="enums.colors.${color}"]`);

    it("adds a color in WUBRG order and keeps the match mode", async () => {
        const wrapper = render({ colors: "R", color_mode: "exact" });

        await colorButton(wrapper, "W").trigger("click");

        expect(changes(wrapper)).toEqual([{ colors: "WR" }]);
    });

    it("drops the match mode with the last color", async () => {
        const wrapper = render({ colors: "R", color_mode: "exact" });

        await colorButton(wrapper, "R").trigger("click");

        expect(changes(wrapper)).toEqual([{ colors: null, color_mode: null }]);
    });

    it("treats colorless as exclusive", async () => {
        const wrapper = render({ colors: "WU" });

        await colorButton(wrapper, "C").trigger("click");

        expect(changes(wrapper)).toEqual([{ colors: "C", color_mode: null }]);
        expect(colorButton(render({ colors: "C" }), "C").attributes("aria-pressed")).toBe("true");
        expect(colorButton(render({ colors: "C" }), "W").attributes("aria-pressed")).toBe("false");
    });

    it("offers the match mode only for actual colors", () => {
        const mode = (wrapper: ReturnType<typeof render>) =>
            wrapper.findAllComponents(MonoSelect).find(c => c.props("options")[0]?.value === "include")!;

        expect(mode(render()).props("disabled")).toBe(true);
        expect(mode(render({ colors: "C" })).props("disabled")).toBe(true);
        expect(mode(render({ colors: "G" })).props("disabled")).toBe(false);
    });
});

describe("CardStackFilters — clear", () => {
    it("is disabled while nothing is filtered", () => {
        expect(render().get(".btn-default").attributes("disabled")).toBeDefined();
    });

    it("removes every filter and empties the price fields", async () => {
        const wrapper = render({ min_price: "1", lang: "de" });

        await wrapper.get(".btn-default").trigger("click");

        const [patch] = changes(wrapper);
        expect(Object.values(patch).every(value => value === null)).toBe(true);
        expect(Object.keys(patch)).toEqual(expect.arrayContaining(["min_price", "lang", "colors", "color_mode"]));
        expect((minInput(wrapper).element as HTMLInputElement).value).toBe("");
    });
});
