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
            colorConsumption: null
        });
        expect(api.hasHighlight.value).toBe(false);
    });

    it("reports the whole selection through the aggregate computed", () => {
        const api = provide();

        api.setManaValue(3);
        api.setCategory({ kind: "category", id: "category-ramp" });
        api.setColorProduction("W");
        api.setColorConsumption("U");

        expect(api.highlight.value).toEqual({
            mv: 3,
            category: { kind: "category", id: "category-ramp" },
            colorProduction: "W",
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
    it("matches on produced_mana", () => {
        const api = provide();
        api.setColorProduction("W");

        expect(api.isHighlighted(card({ produced_mana: ["W", "U"] }))).toBe(true);
        expect(api.isHighlighted(card({ produced_mana: ["U"] }))).toBe(false);
        expect(api.isHighlighted(card({ produced_mana: null }))).toBe(false);
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

    it("leaves a fetchland unmatched when no deck getter was supplied", () => {
        // The pre-fetchland behaviour — stats panels that don't pass the deck
        // still work, they just can't resolve fetches.
        const api = provide();
        api.setColorProduction("U");

        expect(api.isHighlighted(card({ produced_mana: null, fetch_pattern: "typed:WU" }))).toBe(false);
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
