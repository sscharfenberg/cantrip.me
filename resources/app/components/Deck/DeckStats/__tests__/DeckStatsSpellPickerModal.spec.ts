import { mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import { makeCategory, makeCommander, makeCompanion, makeDeckCard } from "@/test/factories/deckCard.ts";
import { setTestMessages } from "@/test/i18n.ts";
import type { AutomaticReason } from "Composables/useDeckStats.ts";
import type { DeckCardRow, DeckCommander, DeckCompanion } from "Types/deckPage.ts";
import DeckStatsSpellPickerModal from "../DeckStatsSpellPickerModal.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

afterEach(() => {
    document.body.innerHTML = "";
});

const ramp = makeCategory("Ramp");

/** A white deck across every kind of group the text view has. */
const commander = makeCommander({ name: "Isamaru, Hound of Konda" });
const companion = makeCompanion({ name: "Lurrus of the Dream-Den" });
const lions = makeDeckCard({ name: "Savannah Lions", quantity: 2 });
const swords = makeDeckCard({ name: "Swords to Plowshares", type_line: "Instant" });
const signet = makeDeckCard({ name: "Azorius Signet", type_line: "Artifact", mana_cost: ["{2}"] });
const tithe = makeDeckCard({
    name: "Smothering Tithe",
    type_line: "Enchantment",
    mana_cost: ["{3}{W}"],
    category_id: ramp.id
});
const wrath = makeDeckCard({ name: "Wrath of God", type_line: "Sorcery", zone: "side" });
const plains = makeDeckCard({ name: "Plains", type_line: "Basic Land — Plains", mana_cost: [null], quantity: 10 });

const cards: DeckCardRow[] = [lions, swords, signet, tithe, wrath, plains];

const open = (
    overrides: Partial<{
        candidates: string[];
        picked: string | null;
        colors: ("W" | "U")[];
        automatic: { card: string; mv: number; chosenBy: AutomaticReason };
        commanders: DeckCommander[];
        companion: DeckCompanion | null;
        allowsSideboard: boolean;
    }> = {}
) =>
    mount(DeckStatsSpellPickerModal, {
        props: {
            colors: ["W"],
            candidates: [lions, swords, tithe, wrath, commander, companion].map(c => c.oracle_card_id),
            picked: null,
            automatic: { card: "Swords to Plowshares", mv: 1, chosenBy: "hardest" as const },
            cards,
            commanders: [commander],
            companion,
            categories: [ramp],
            sortMode: "name",
            allowsSideboard: true,
            ...overrides
        }
    });

/** Modal.vue teleports into <body>, so assertions go through `document`. */
const headlines = () => [...document.querySelectorAll(".spell-picker__groups h3")].map(h => h.textContent?.trim());
const names = () => [...document.querySelectorAll(".spell-picker__groups .card__name")].map(n => n.textContent?.trim());
const button = (name: string): HTMLButtonElement =>
    [...document.querySelectorAll<HTMLButtonElement>(".spell-picker__groups .spell-picker__card")].find(b =>
        b.textContent?.includes(name)
    )!;

describe("DeckStatsSpellPickerModal — the list", () => {
    it("groups the candidates as the text view does, command zone and sideboard included", () => {
        open();

        expect(headlines()).toEqual([
            "pages.deck.commanders (1)",
            "pages.deck.companion.heading",
            "pages.deck.groups.creature (2)",
            "pages.deck.groups.instant (1)",
            "Ramp (1)",
            "pages.deck.groups.side (1)"
        ]);
    });

    it("lists only the candidates, and drops the groups left empty", () => {
        // Neither the Signet (no white pip) nor the Plains is a candidate, so
        // the artifact and land groups disappear rather than render empty.
        open();

        expect(names()).toEqual([
            "1x Isamaru, Hound of Konda",
            "1x Lurrus of the Dream-Den",
            "2x Savannah Lions",
            "1x Swords to Plowshares",
            "1x Smothering Tithe",
            "1x Wrath of God"
        ]);
        expect(headlines()).not.toContain("pages.deck.groups.artifact (1)");
        expect(headlines()).not.toContain("pages.deck.groups.land (10)");
    });

    it("leaves out a commander and a companion that are no candidates", () => {
        open({ candidates: [lions.oracle_card_id] });

        expect(headlines()).toEqual(["pages.deck.groups.creature (2)"]);
    });

    it("hides the sideboard group in a format without one, as the text view does", () => {
        open({ allowsSideboard: false });

        expect(headlines()).not.toContain("pages.deck.groups.side (1)");
    });

    it("shows quantity, name and mana cost — no drag handle, no actions menu", () => {
        open();
        const row = button("Smothering Tithe");

        expect(row.querySelector(".card__qty")?.textContent).toBe("1x ");
        expect([...row.querySelectorAll(".mana-cost__symbol")].map(img => img.getAttribute("alt"))).toEqual([
            "{3}",
            "{W}"
        ]);
        // Exactly the name (with its quantity) and the cost — no drag handle,
        // no badges, no actions menu, and no nested button inside the button.
        expect([...row.children].map(child => child.className)).toEqual(["card__name", "mana-cost"]);
        expect(row.querySelector("button")).toBeNull();
    });

    it("mentions the sideboard only in a format that has one", () => {
        setTestMessages({
            de: { pages: { deck: { stats: { colors: { karsten: { picker: { intro_sideboard: "Sideboard!" } } } } } } }
        });
        open({ allowsSideboard: false });
        expect(document.querySelector(".spell-picker__intro")?.textContent).not.toContain("Sideboard!");
        document.body.innerHTML = "";

        open();
        expect(document.querySelector(".spell-picker__intro")?.textContent).toContain("Sideboard!");
    });
});

describe("DeckStatsSpellPickerModal — picking", () => {
    it("picks the clicked card by its oracle id", () => {
        const wrapper = open();

        button("Wrath of God").click();

        expect(wrapper.emitted("select")).toEqual([[wrath.oracle_card_id]]);
    });

    it("picks a commander too", () => {
        const wrapper = open();

        button("Isamaru").click();

        expect(wrapper.emitted("select")).toEqual([[commander.oracle_card_id]]);
    });

    it("hands the row back to automatic from the entry above the groups", () => {
        setTestMessages({ de: { pages: { deck: { stats: { colors: { karsten: { spell: "{card} ({mv})" } } } } } } });
        const wrapper = open({ picked: wrath.oracle_card_id });
        const automatic = document.querySelector<HTMLButtonElement>(".spell-picker__automatic .spell-picker__card")!;

        expect(automatic.textContent).toContain("Swords to Plowshares (1)");
        automatic.click();

        expect(wrapper.emitted("select")).toEqual([[null]]);
    });

    it.each(["hardest", "easiest", "cheapest", "commander", "oathbreaker", "signature_spell"] as const)(
        "names how the automatic spell was chosen (%s)",
        choice => {
            open({ automatic: { card: "Swords to Plowshares", mv: 1, chosenBy: choice } });

            expect(document.querySelector(".spell-picker__automatic .spell-picker__hint")?.textContent).toBe(
                `pages.deck.stats.colors.karsten.picker.automatic_hint_${choice}`
            );
        }
    );

    it("marks the current choice — the automatic entry while nothing is picked", () => {
        open();

        const automatic = document.querySelector(".spell-picker__automatic .spell-picker__card")!;
        expect(automatic.classList).toContain("highlighted");
        expect(automatic.getAttribute("aria-pressed")).toBe("true");
        expect(document.querySelectorAll(".spell-picker__groups .highlighted")).toHaveLength(0);
    });

    it("marks the picked card, and only it", () => {
        open({ picked: tithe.oracle_card_id });

        const marked = [...document.querySelectorAll(".highlighted")];
        expect(marked).toHaveLength(1);
        expect(marked[0].textContent).toContain("Smothering Tithe");
        expect(marked[0].getAttribute("aria-pressed")).toBe("true");
    });

    it("explains a multicolor row's list differently", () => {
        setTestMessages({
            de: { pages: { deck: { stats: { colors: { karsten: { picker: { title: "Zauber für {colors}" } } } } } } }
        });
        open({ colors: ["W", "U"] });

        expect(document.querySelector(".spell-picker__intro")?.textContent).toContain(
            "pages.deck.stats.colors.karsten.picker.intro_combined"
        );
        expect(document.querySelectorAll(".spell-picker__colors img")).toHaveLength(2);
    });
});
