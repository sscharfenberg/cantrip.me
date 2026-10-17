import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { defineComponent, h } from "vue";
import { withSetup } from "@/test/withSetup.ts";
import type { DeckHighlightApi, HighlightableCard } from "../useDeckHighlight.ts";
import { provideDeckHighlight, useDeckHighlight } from "../useDeckHighlight.ts";

/** Minimal highlightable card; every field the matcher may read has a default. */
const card = (overrides: Partial<HighlightableCard> = {}): HighlightableCard => ({
    cmc: 2,
    type_line: "Creature — Human Soldier",
    produced_mana: null,
    mana_cost: ["{1}{W}"],
    category_id: null,
    ...overrides
});

/** Provide the api inside a throwaway component and hand it back. */
const provide = (getDeckCards?: () => HighlightableCard[]): DeckHighlightApi => {
    const [api] = withSetup(() => provideDeckHighlight(getDeckCards));
    return api;
};

describe("provideDeckHighlight — initial state", () => {
    it("starts with every axis cleared", () => {
        const api = provide();

        expect(api.hasHighlight.value).toBe(false);
        expect(api.highlight.value).toEqual({
            mv: null,
            category: null,
            colorProduction: null,
            colorProductionMv: null,
            colorConsumption: null
        });
    });

    it("highlights nothing at all while no axis is set", () => {
        const api = provide();

        expect(api.isHighlighted(card())).toBe(false);
    });
});

describe("provideDeckHighlight — setters", () => {
    it("exposes each axis through its own computed", () => {
        const api = provide();

        api.setManaValue(3);
        api.setCategory({ kind: "type", label: "Creature" });
        api.setColorProduction("W");
        api.setColorConsumption("U");

        expect(api.selectedManaValue.value).toBe(3);
        expect(api.selectedCategory.value).toEqual({ kind: "type", label: "Creature" });
        expect(api.selectedColorProduction.value).toBe("W");
        expect(api.selectedColorConsumption.value).toBe("U");
        expect(api.hasHighlight.value).toBe(true);
    });

    it("treats mana value 0 as a real selection, not as absent", () => {
        const api = provide();

        api.setManaValue(0);

        expect(api.hasHighlight.value).toBe(true);
        expect(api.isHighlighted(card({ cmc: 0 }))).toBe(true);
    });

    it("clears every axis at once", () => {
        const api = provide();
        api.setManaValue(3);
        api.setCategory({ kind: "type", label: "Creature" });
        api.setColorProduction("W");
        api.setColorConsumption("U");

        api.clear();

        expect(api.highlight.value).toEqual({
            mv: null,
            category: null,
            colorProduction: null,
            colorProductionMv: null,
            colorConsumption: null
        });
        expect(api.hasHighlight.value).toBe(false);
    });

    it("reports the whole selection through the aggregate computed", () => {
        const api = provide();

        api.setManaValue(3);
        api.setCategory({ kind: "category", id: "category-ramp" });
        api.setColorProduction("W", 4);
        api.setColorConsumption("U");

        expect(api.highlight.value).toEqual({
            mv: 3,
            category: { kind: "category", id: "category-ramp" },
            colorProduction: "W",
            colorProductionMv: 4,
            colorConsumption: "U"
        });
    });

    it("clears one axis without disturbing the others", () => {
        const api = provide();
        api.setManaValue(3);
        api.setColorConsumption("U");

        api.setManaValue(null);

        expect(api.selectedManaValue.value).toBeNull();
        expect(api.selectedColorConsumption.value).toBe("U");
        expect(api.hasHighlight.value).toBe(true);
    });
});

describe("provideDeckHighlight — mana value axis", () => {
    it("matches on the exact mana value", () => {
        const api = provide();
        api.setManaValue(3);

        expect(api.isHighlighted(card({ cmc: 3 }))).toBe(true);
        expect(api.isHighlighted(card({ cmc: 2 }))).toBe(false);
    });

    it("floors fractional mana values before comparing", () => {
        const api = provide();
        api.setManaValue(3);

        expect(api.isHighlighted(card({ cmc: 3.5 }))).toBe(true);
    });

    it("treats bucket 8 as `8 or more`, mirroring the curve chart", () => {
        const api = provide();
        api.setManaValue(8);

        expect(api.isHighlighted(card({ cmc: 8 }))).toBe(true);
        expect(api.isHighlighted(card({ cmc: 15 }))).toBe(true);
        expect(api.isHighlighted(card({ cmc: 7 }))).toBe(false);
    });
});

describe("provideDeckHighlight — category axis", () => {
    it("matches a type selection as a substring of the type line", () => {
        const api = provide();
        api.setCategory({ kind: "type", label: "Creature" });

        expect(api.isHighlighted(card({ type_line: "Artifact Creature — Golem" }))).toBe(true);
        expect(api.isHighlighted(card({ type_line: "Instant" }))).toBe(false);
    });

    it("matches a category selection on category_id", () => {
        const api = provide();
        api.setCategory({ kind: "category", id: "category-ramp" });

        expect(api.isHighlighted(card({ category_id: "category-ramp" }))).toBe(true);
        expect(api.isHighlighted(card({ category_id: "category-draw" }))).toBe(false);
    });

    it("matches the uncategorised bucket via a null id", () => {
        const api = provide();
        api.setCategory({ kind: "category", id: null });

        expect(api.isHighlighted(card({ category_id: null }))).toBe(true);
        expect(api.isHighlighted(card({ category_id: "category-ramp" }))).toBe(false);
    });

    it("never matches a commander on the category axis — it has no category", () => {
        const api = provide();
        api.setCategory({ kind: "category", id: null });

        // Commanders and companions ship without the field at all, which must
        // not be conflated with "uncategorised".
        const commander = card();
        delete commander.category_id;

        expect(api.isHighlighted(commander)).toBe(false);
    });

    it("matches a subtype only when the card type matches too", () => {
        const api = provide();
        api.setCategory({ kind: "subtype", cardType: "Creature", subtype: "Soldier" });

        expect(api.isHighlighted(card({ type_line: "Creature — Human Soldier" }))).toBe(true);
        expect(api.isHighlighted(card({ type_line: "Creature — Human" }))).toBe(false);
        expect(api.isHighlighted(card({ type_line: "Artifact — Soldier" }))).toBe(false);
    });

    it("matches the no-subtype bucket via a null subtype", () => {
        const api = provide();
        api.setCategory({ kind: "subtype", cardType: "Artifact", subtype: null });

        expect(api.isHighlighted(card({ type_line: "Artifact" }))).toBe(true);
        expect(api.isHighlighted(card({ type_line: "Artifact — Equipment" }))).toBe(false);
    });
});

describe("provideDeckHighlight — colour consumption axis", () => {
    it("matches a plain coloured pip", () => {
        const api = provide();
        api.setColorConsumption("W");

        expect(api.isHighlighted(card({ mana_cost: ["{1}{W}"] }))).toBe(true);
        expect(api.isHighlighted(card({ mana_cost: ["{1}{U}"] }))).toBe(false);
    });

    it("matches hybrid, phyrexian and twobrid pips", () => {
        const api = provide();
        api.setColorConsumption("W");

        expect(api.isHighlighted(card({ mana_cost: ["{W/U}"] }))).toBe(true);
        expect(api.isHighlighted(card({ mana_cost: ["{W/P}"] }))).toBe(true);
        expect(api.isHighlighted(card({ mana_cost: ["{2/W}"] }))).toBe(true);
    });

    it("never matches generic, colourless or variable pips", () => {
        const api = provide();
        api.setColorConsumption("W");

        expect(api.isHighlighted(card({ mana_cost: ["{3}", "{C}", "{X}"] }))).toBe(false);
    });

    it("matches on any face of a multi-faced card", () => {
        const api = provide();
        api.setColorConsumption("U");

        expect(api.isHighlighted(card({ mana_cost: ["{W}", null, "{U}"] }))).toBe(true);
    });

    it("does not match a land with no cost", () => {
        // Behavioural, not guard coverage: `mana_cost: [null]` also cannot
        // match because a null face stringifies to "null".
        const api = provide();
        api.setColorConsumption("W");

        expect(api.isHighlighted(card({ type_line: "Basic Land — Plains", mana_cost: [null] }))).toBe(false);
    });
});

describe("provideDeckHighlight — colour production axis", () => {
    it("matches a card that counts as a source of the colour", () => {
        const api = provide();
        api.setColorProduction("W");

        expect(api.isHighlighted(card({ produced_mana: ["W", "U"], mana_source: "ability" }))).toBe(true);
        expect(api.isHighlighted(card({ produced_mana: ["U"], mana_source: "ability" }))).toBe(false);
        expect(api.isHighlighted(card({ produced_mana: null }))).toBe(false);
    });

    it("leaves out what the stats do not count — a ritual, a card that must transform first", () => {
        const api = provide();
        api.setColorProduction("B");

        const darkRitual = card({ type_line: "Instant", produced_mana: ["B"], mana_source: "ability" });
        const flip = card({ type_line: "Legendary Enchantment", produced_mana: ["B"], mana_source: null });

        expect(api.isHighlighted(darkRitual)).toBe(false);
        expect(api.isHighlighted(flip)).toBe(false);
    });

    it("matches only the cards that count toward the spell the ring was priced for", () => {
        // Priced for a two-drop, a two-mana Signet is not online yet; the land is.
        const plains = card({ type_line: "Basic Land — Plains", produced_mana: ["W"], mana_cost: [null], cmc: 0 });
        const signet = card({ type_line: "Artifact", produced_mana: ["W"], mana_source: "ability", cmc: 2 });
        const api = provide();

        api.setColorProduction("W", 2);
        expect(api.isHighlighted(plains)).toBe(true);
        expect(api.isHighlighted(signet)).toBe(false);

        api.setColorProduction("W", 3);
        expect(api.isHighlighted(signet)).toBe(true);
    });

    it("drops the priced-for mana value with the colour", () => {
        const api = provide();
        api.setColorProduction("W", 3);

        api.setColorProduction(null, 5);

        expect(api.highlight.value.colorProductionMv).toBeNull();
    });

    it("resolves a fetchland against the deck's other lands", () => {
        const shockland = card({
            type_line: "Land — Plains Island",
            produced_mana: ["W", "U"],
            mana_cost: [null],
            is_basic_land: false
        });
        const fetchland = card({
            type_line: "Land",
            produced_mana: null,
            mana_cost: [null],
            fetch_pattern: "typed:WU"
        });
        const api = provide(() => [shockland, fetchland]);
        api.setColorProduction("U");

        // Scryfall reports no produced_mana for a fetch; without the deck-aware
        // resolver it would never highlight.
        expect(api.isHighlighted(fetchland)).toBe(true);
    });

    it("resolves a land-search spell against the deck's lands", () => {
        // Three Visits finds a Forest card; in a deck with a Bayou that is black too.
        const bayou = card({ type_line: "Land — Swamp Forest", produced_mana: ["B", "G"], mana_cost: [null] });
        const threeVisits = card({
            type_line: "Sorcery",
            produced_mana: null,
            mana_cost: ["{1}{G}"],
            land_search_pattern: "typed:G"
        });
        const api = provide(() => [bayou, threeVisits]);
        api.setColorProduction("B");

        expect(api.isHighlighted(threeVisits)).toBe(true);
    });

    it("leaves a fetchland unmatched when no deck getter was supplied", () => {
        // The pre-fetchland behaviour — stats panels that don't pass the deck
        // still work, they just can't resolve fetches.
        const api = provide();
        api.setColorProduction("U");

        expect(api.isHighlighted(card({ produced_mana: null, fetch_pattern: "typed:WU" }))).toBe(false);
    });
});

describe("provideDeckHighlight — production share", () => {
    const plains = card({ type_line: "Basic Land — Plains", produced_mana: ["W"], mana_cost: [null], cmc: 0 });
    const signet = card({ type_line: "Artifact", produced_mana: ["W", "U"], mana_source: "ability", cmc: 2 });

    it("reports how much a highlighted partial source counts, and from which mana value", () => {
        const api = provide();
        api.setColorProduction("W", 3);

        expect(api.productionShare(signet)).toEqual({ weight: 0.75, steps: [{ weight: 0.75, fromMv: 3 }] });
    });

    it("says nothing about a full source — every land would carry a 1", () => {
        const api = provide();
        api.setColorProduction("W", 3);

        expect(api.isHighlighted(plains)).toBe(true);
        expect(api.productionShare(plains)).toBeNull();
    });

    it("says nothing about a card another axis keeps unhighlighted", () => {
        const api = provide();
        api.setColorProduction("W", 3);
        api.setManaValue(5);

        expect(api.productionShare(signet)).toBeNull();
    });

    it("says nothing for a selection without a mana value — the production ring counts every source as one", () => {
        const api = provide();
        api.setColorProduction("W");

        expect(api.isHighlighted(signet)).toBe(true);
        expect(api.isLaterSource(signet)).toBe(false);
        expect(api.productionShare(signet)).toBeNull();
    });

    it("says nothing while no production colour is selected", () => {
        const api = provide();
        api.setManaValue(2);

        expect(api.isHighlighted(signet)).toBe(true);
        expect(api.productionShare(signet)).toBeNull();
    });
});

describe("provideDeckHighlight — later sources", () => {
    const forest = card({
        type_line: "Basic Land — Forest",
        produced_mana: ["G"],
        mana_cost: [null],
        cmc: 0,
        is_basic_land: true
    });
    const cultivate = card({
        type_line: "Sorcery",
        produced_mana: null,
        mana_cost: ["{2}{G}"],
        cmc: 3,
        land_search_pattern: "basic"
    });

    it("marks a source that only counts toward bigger spells, without highlighting it", () => {
        // Priced for a three-drop, a Cultivate cast on turn three is too late.
        const api = provide(() => [forest, cultivate]);
        api.setColorProduction("G", 3);

        expect(api.isHighlighted(cultivate)).toBe(false);
        expect(api.isLaterSource(cultivate)).toBe(true);
        expect(api.isLaterSource(forest)).toBe(false);
    });

    it("highlights it once the priced spell is big enough", () => {
        const api = provide(() => [forest, cultivate]);
        api.setColorProduction("G", 5);

        expect(api.isHighlighted(cultivate)).toBe(true);
        expect(api.isLaterSource(cultivate)).toBe(false);
    });

    it("lists every step of its worth, counting nothing toward the priced spell", () => {
        const api = provide(() => [forest, cultivate]);
        api.setColorProduction("G", 3);

        expect(api.productionShare(cultivate)).toEqual({
            weight: 0,
            steps: [
                { weight: 0.25, fromMv: 4 },
                { weight: 0.5, fromMv: 5 }
            ]
        });
    });

    it("reports the step that applies once it counts", () => {
        const api = provide(() => [forest, cultivate]);
        api.setColorProduction("G", 4);

        expect(api.productionShare(cultivate)?.weight).toBe(0.25);
    });

    it("merges two roles that come online together into one step", () => {
        // A mana creature (½) that also fetches a land (¾), both from MV 3.
        const both = card({
            type_line: "Creature — Elf",
            produced_mana: ["G"],
            mana_source: "ability",
            mana_cost: ["{1}{G}"],
            cmc: 2,
            land_search_pattern: "basic"
        });
        const api = provide(() => [forest, both]);
        api.setColorProduction("G", 3);

        expect(api.productionShare(both)).toEqual({ weight: 0.75, steps: [{ weight: 0.75, fromMv: 3 }] });
    });

    it("leaves a later source alone when another axis rules it out", () => {
        const api = provide(() => [forest, cultivate]);
        api.setColorProduction("G", 3);
        api.setManaValue(2);

        expect(api.isLaterSource(cultivate)).toBe(false);
        expect(api.productionShare(cultivate)).toBeNull();
    });

    it("has no later sources while no production colour is selected", () => {
        const api = provide(() => [forest, cultivate]);
        api.setManaValue(3);

        expect(api.isLaterSource(cultivate)).toBe(false);
    });
});

describe("provideDeckHighlight — combining axes", () => {
    it("requires a card to satisfy every active axis", () => {
        const api = provide();
        api.setManaValue(2);
        api.setColorConsumption("W");

        expect(api.isHighlighted(card({ cmc: 2, mana_cost: ["{1}{W}"] }))).toBe(true);
        expect(api.isHighlighted(card({ cmc: 2, mana_cost: ["{1}{U}"] }))).toBe(false);
        expect(api.isHighlighted(card({ cmc: 3, mana_cost: ["{1}{W}"] }))).toBe(false);
    });

    it("ignores axes that are not set", () => {
        const api = provide();
        api.setManaValue(2);

        expect(api.isHighlighted(card({ cmc: 2, produced_mana: null }))).toBe(true);
    });
});

describe("useDeckHighlight", () => {
    it("injects the api provided by an ancestor", () => {
        const Child = defineComponent({
            setup() {
                const api = useDeckHighlight();
                return () => h("span", String(api.hasHighlight.value));
            }
        });
        const Parent = defineComponent({
            setup() {
                provideDeckHighlight();
                return () => h(Child);
            }
        });

        expect(mount(Parent).text()).toBe("false");
    });

    it("shares one state between provider and injector", () => {
        let injected: DeckHighlightApi | null = null;
        const Child = defineComponent({
            setup() {
                injected = useDeckHighlight();
                return () => null;
            }
        });
        let provided: DeckHighlightApi | null = null;
        const Parent = defineComponent({
            setup() {
                provided = provideDeckHighlight();
                return () => h(Child);
            }
        });
        mount(Parent);

        provided!.setManaValue(4);

        expect(injected!.selectedManaValue.value).toBe(4);
    });

    it("throws rather than silently highlighting nothing when no provider exists", () => {
        // Vue logs on the way out — once for the failed `inject`, once because
        // the throw left `setup` with no render function. Both are the expected
        // shape of this path, so they are silenced rather than printed as
        // stderr noise on every run.
        const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
        const Orphan = defineComponent({
            setup() {
                useDeckHighlight();
                return () => null;
            }
        });

        expect(() => mount(Orphan)).toThrow(/no provideDeckHighlight/);
        expect(warn).toHaveBeenCalled();
    });
});
