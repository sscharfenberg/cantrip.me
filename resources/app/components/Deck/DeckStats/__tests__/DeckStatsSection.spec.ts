import { mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, h, nextTick, shallowRef } from "vue";
import { makeCommander, makeCompanion, makeDeckCard } from "@/test/factories/deckCard.ts";
import { setTestMessages } from "@/test/i18n.ts";
import { setPageProps } from "@/test/inertia.ts";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import { type DeckHighlightApi, provideDeckHighlight } from "Composables/useDeckHighlight.ts";
import type { DeckCardRow, DeckCommander, DeckCompanion, DeckMeta } from "Types/deckPage.ts";
import DeckStatsSection from "../DeckStatsSection.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

/** A fresh deck id per test — picks are cached per deck at module level. */
let sequence = 0;

const deck = (): DeckMeta => ({
    id: `deck-${++sequence}`,
    name: "Mono White",
    description: null,
    format: "modern",
    state: "built",
    visibility: "private",
    colors: "W",
    bracket: null,
    card_count: { main: 16, companion: 0, side: 0 },
    total_worth: 0,
    max_sideboard_size: 15,
    max_copies: 4,
    is_singleton: false,
    enforces_color_identity: false,
    allows_companion: true,
    banned_as_companion: [],
    uses_game_changer_list: false,
    last_activity: "2026-10-17T00:00:00+00:00",
    hero_card: null
});

/**
 * Ten Plains and four Signets. The three-drop is the tighter spell (13 of 18
 * sources); the rocks are not online for the one-drop (10 of 14).
 */
const oneDrop = makeDeckCard({ name: "Thraben Inspector", mana_cost: ["{W}"], cmc: 1 });
const threeDrop = makeDeckCard({ name: "Brimaz", mana_cost: ["{1}{W}{W}"], cmc: 3 });
const cards: DeckCardRow[] = [
    makeDeckCard({
        name: "Plains",
        type_line: "Basic Land — Plains",
        mana_cost: [null],
        cmc: 0,
        produced_mana: ["W"],
        quantity: 10
    }),
    makeDeckCard({
        name: "Mind Stone",
        type_line: "Artifact",
        mana_cost: ["{2}"],
        cmc: 2,
        produced_mana: ["W"],
        mana_source: "ability",
        quantity: 4
    }),
    oneDrop,
    threeDrop
];

beforeEach(() => {
    setPageProps({ auth: { user: { deck_sort_default: "name" } } });
    setTestMessages({
        de: {
            pages: {
                deck: {
                    stats: {
                        colors: {
                            karsten: { short: "{have} für {spell}", sufficient: "{have} für {spell}", spell: "{card}" }
                        }
                    }
                }
            }
        }
    });
});

afterEach(() => {
    document.body.innerHTML = "";
});

/** Mount the section under a highlight provider and hand back the api it drives. */
/**
 * Mount the section under a highlight provider and hand back the api it
 * drives. The deck's cards sit in a ref, so a test can change the deck
 * under an open picker or an active highlight, as a reload would.
 */
const render = (
    options: {
        meta?: DeckMeta;
        cards?: DeckCardRow[];
        commanders?: DeckCommander[];
        companion?: DeckCompanion | null;
    } = {}
) => {
    const meta = options.meta ?? deck();
    const deckCards = shallowRef(options.cards ?? cards);
    let api!: DeckHighlightApi;
    const Host = defineComponent({
        setup() {
            api = provideDeckHighlight(() => deckCards.value);
            return () =>
                h(DeckStatsSection, {
                    cards: deckCards.value,
                    commanders: options.commanders ?? [],
                    companion: options.companion ?? null,
                    categories: [],
                    deck: meta
                });
        }
    });
    return { wrapper: mount(Host, { attachTo: document.body }), api: () => api, meta, deckCards };
};

/** Open the white row's picker and pick the named card. */
const pickWhite = async (wrapper: ReturnType<typeof render>["wrapper"], name: string): Promise<void> => {
    await wrapper.get(".spell-link").trigger("click");
    pickerButton(name).click();
    await nextTick();
};

const whiteRow = (wrapper: ReturnType<typeof render>["wrapper"]) =>
    wrapper.get(".mana-sources__group--single .mana-sources__row");

const pickerButton = (name: string): HTMLButtonElement =>
    [...document.querySelectorAll<HTMLButtonElement>(".spell-picker__card")].find(b => b.textContent?.includes(name))!;

describe("DeckStatsSection — picking a spell for Mana sources", () => {
    it("opens the picker from the row's spell and closes it once a spell is picked", async () => {
        const { wrapper } = render();
        expect(whiteRow(wrapper).text()).toBe("13 für Brimaz");

        await wrapper.get(".spell-link").trigger("click");
        expect(document.querySelector(".spell-picker__groups")).not.toBeNull();

        pickerButton("Thraben Inspector").click();
        await nextTick();

        expect(document.querySelector(".spell-picker__groups")).toBeNull();
        expect(whiteRow(wrapper).text()).toBe("10 für Thraben Inspector");
    });

    it("highlights the picked spell's sources, priced for its mana value", async () => {
        const { wrapper, api } = render();

        await wrapper.get(".spell-link").trigger("click");
        pickerButton("Thraben Inspector").click();
        await nextTick();

        expect(api().highlight.value.spellSources).toEqual({ colors: ["W"], mv: 1 });
    });

    it("remembers the pick for the deck", async () => {
        const { wrapper, meta } = render();

        await wrapper.get(".spell-link").trigger("click");
        pickerButton("Thraben Inspector").click();
        await nextTick();

        expect(JSON.parse(localStorage.getItem(`cantrip:deck-mana-source-picks:${meta.id}`) ?? "null")).toEqual({
            W: oneDrop.oracle_card_id
        });
    });

    it("resets every row to automatic, and the highlight follows its row back", async () => {
        const { wrapper, api, meta } = render();
        await wrapper.get(".spell-link").trigger("click");
        pickerButton("Thraben Inspector").click();
        await nextTick();

        await wrapper.get(".mana-sources__reset").trigger("click");
        await nextTick();

        expect(whiteRow(wrapper).text()).toBe("13 für Brimaz");
        expect(wrapper.find(".mana-sources__reset").exists()).toBe(false);
        expect(api().highlight.value.spellSources).toEqual({ colors: ["W"], mv: 3 });
        expect(localStorage.getItem(`cantrip:deck-mana-source-picks:${meta.id}`)).toBeNull();
    });

    it("moves focus to the automatic-spell choice once the reset link is gone", async () => {
        const { wrapper } = render();
        await pickWhite(wrapper, "Thraben Inspector");

        await wrapper.get(".mana-sources__reset").trigger("click");
        await nextTick();
        await nextTick();

        expect(wrapper.get(".mana-sources__automatic").element.contains(document.activeElement)).toBe(true);
    });

    it("shows the easiest spell when asked to, and remembers that for the deck", async () => {
        const { wrapper, meta } = render();

        wrapper.getComponent(MonoSelect).vm.$emit("change", "easiest");
        await nextTick();

        expect(whiteRow(wrapper).text()).toBe("10 für Thraben Inspector");
        expect(localStorage.getItem(`cantrip:deck-mana-source-automatic:${meta.id}`)).toBe("easiest");
    });

    it("moves a highlighted automatic spell along when the choice changes", async () => {
        const { wrapper, api } = render();
        await wrapper.get(".spell-link").trigger("click");
        document.querySelector<HTMLButtonElement>(".spell-picker__automatic .spell-picker__card")!.click();
        await nextTick();
        expect(api().highlight.value.spellSources).toEqual({ colors: ["W"], mv: 3 });

        wrapper.getComponent(MonoSelect).vm.$emit("change", "easiest");
        await nextTick();

        expect(api().highlight.value.spellSources).toEqual({ colors: ["W"], mv: 1 });
    });

    it("leaves the pick alone when the highlight is cleared", async () => {
        const { wrapper, api } = render();
        await wrapper.get(".spell-link").trigger("click");
        pickerButton("Thraben Inspector").click();
        await nextTick();

        api().clear();
        await nextTick();

        expect(api().hasHighlight.value).toBe(false);
        expect(whiteRow(wrapper).text()).toBe("10 für Thraben Inspector");
    });

    it("closes the picker without picking anything", async () => {
        const { wrapper, api } = render();

        await wrapper.get(".spell-link").trigger("click");
        document.querySelector<HTMLButtonElement>(".btn-close")!.click();
        // Modal.vue skips its exit animation under reduced motion; jsdom's
        // matchMedia double reports no preference, so finish the animation.
        document.querySelector(".modal-dialog__content")?.dispatchEvent(new AnimationEvent("animationend"));
        await nextTick();

        expect(document.querySelector(".spell-picker__groups")).toBeNull();
        expect(whiteRow(wrapper).text()).toBe("13 für Brimaz");
        expect(api().hasHighlight.value).toBe(false);
    });
});

describe("DeckStatsSection — when the deck changes", () => {
    it("forgets an open picker whose row disappears, rather than reopening it later", async () => {
        const { wrapper, deckCards } = render();
        await wrapper.get(".spell-link").trigger("click");

        deckCards.value = cards.filter(card => card !== oneDrop && card !== threeDrop);
        await nextTick();
        expect(document.querySelector(".spell-picker__groups")).toBeNull();

        deckCards.value = cards;
        await nextTick();
        expect(document.querySelector(".spell-picker__groups")).toBeNull();
    });

    it("moves the highlight with its row when the picked card leaves the deck", async () => {
        const { wrapper, api, deckCards } = render();
        await pickWhite(wrapper, "Thraben Inspector");
        expect(api().highlight.value.spellSources).toEqual({ colors: ["W"], mv: 1 });

        deckCards.value = cards.filter(card => card !== oneDrop);
        await nextTick();

        expect(whiteRow(wrapper).text()).toBe("13 für Brimaz");
        expect(api().highlight.value.spellSources).toEqual({ colors: ["W"], mv: 3 });
    });

    it("drops the highlight once its row is gone", async () => {
        const { wrapper, api, deckCards } = render();
        await pickWhite(wrapper, "Thraben Inspector");

        deckCards.value = cards.filter(card => card !== oneDrop && card !== threeDrop);
        await nextTick();

        expect(api().highlight.value.spellSources).toBeNull();
    });

    it("leaves a highlight set elsewhere alone", async () => {
        // Once the ring takes over, a later deck change must not bring back
        // the old spell's sources.
        const { wrapper, api, deckCards } = render();
        await pickWhite(wrapper, "Thraben Inspector");

        api().setColorProduction("W");
        deckCards.value = cards.filter(card => card !== oneDrop);
        await nextTick();

        expect(api().highlight.value.spellSources).toBeNull();
        expect(api().highlight.value.colorProduction).toBe("W");
    });
});

describe("DeckStatsSection — details of a pick", () => {
    it("shows a stale pick as automatic in the picker", async () => {
        const meta = deck();
        localStorage.setItem(`cantrip:deck-mana-source-picks:${meta.id}`, JSON.stringify({ W: "oracle-card-gone" }));
        const { wrapper } = render({ meta });

        await wrapper.get(".spell-link").trigger("click");

        expect(
            document.querySelector(".spell-picker__automatic .spell-picker__card")?.getAttribute("aria-pressed")
        ).toBe("true");
        expect(document.querySelectorAll('.spell-picker__groups [aria-pressed="true"]')).toHaveLength(0);
    });

    it("hands focus back to the row's spell button after a pick", async () => {
        const { wrapper } = render();

        await pickWhite(wrapper, "Thraben Inspector");
        await nextTick();

        expect(document.activeElement).toBe(wrapper.get('[data-key="W"] .spell-link').element);
    });

    it("highlights only the colors inside the commanders' identity", async () => {
        // A mono-white commander deck with an off-identity blue card: the blue
        // row counts no sources, so its highlight must light up nothing.
        const blue = makeDeckCard({ name: "Ponder", type_line: "Sorcery", mana_cost: ["{U}"], cmc: 1 });
        const islands = makeDeckCard({
            name: "Island",
            type_line: "Basic Land — Island",
            mana_cost: [null],
            cmc: 0,
            produced_mana: ["U"],
            quantity: 5
        });
        const { wrapper, api } = render({
            meta: { ...deck(), format: "commander" },
            cards: [...cards, blue, islands],
            commanders: [makeCommander({ color_identity: "W", mana_cost: ["{1}{W}"], cmc: 2 })]
        });

        await wrapper.get('[data-key="U"] .spell-link').trigger("click");
        pickerButton("Ponder").click();
        await nextTick();

        expect(api().highlight.value.spellSources).toEqual({ colors: [], mv: 1 });
        expect(api().isHighlighted(islands)).toBe(false);
    });

    it("counts a companion only where the format allows one, as the text view shows it", () => {
        const companion = makeCompanion({ name: "Lurrus", mana_cost: ["{1}{B}"], cmc: 2 });

        const allowed = render({ companion }).wrapper;
        const disallowed = render({ meta: { ...deck(), allows_companion: false }, companion }).wrapper;

        expect(allowed.find('[data-key="B"]').exists()).toBe(true);
        expect(disallowed.find('[data-key="B"]').exists()).toBe(false);
    });
});
