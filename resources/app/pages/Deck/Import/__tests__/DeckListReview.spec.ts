import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import {
    commanderRules,
    makeCommanderResult,
    makeDeckListCard,
    makeDeckListLine,
    makeParseResult
} from "@/test/factories/deckList.ts";
import { installFetchMock } from "@/test/http.ts";
import type { FetchMock } from "@/test/http.ts";
import { routerMock, setPageProps } from "@/test/inertia.ts";
import type { DeckListParseResult } from "Types/deckListImport.ts";
import DeckListReview from "../DeckListReview.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

let http: FetchMock;

beforeEach(() => {
    setPageProps({ csrfToken: "csrf-token" });
    http = installFetchMock();
});

const render = (result: DeckListParseResult, deckName = "My Deck") =>
    mount(DeckListReview, {
        props: { result, format: result.rules.format, nameMax: 64, deckName, "onUpdate:deckName": () => {} },
        global: { stubs: { CommanderCommandZonePickerModal: true, OathbreakerCommandZonePickerModal: true } }
    });

const confirmButton = (wrapper: ReturnType<typeof render>) => wrapper.find(".deck-list-review__confirm button");

/** Click confirm and let the request settle. */
const confirm = async (wrapper: ReturnType<typeof render>) => {
    await confirmButton(wrapper).trigger("click");
    await flushPromises();
};

describe("DeckListReview — the lines", () => {
    it("shows each resolved line with its card and printing", () => {
        const line = makeDeckListLine(
            makeDeckListCard("Lightning Bolt", { set_code: "2x2", collector_number: "117" }),
            {
                quantity: 4
            }
        );

        const text = render(makeParseResult([line]))
            .find(".review-line")
            .text();

        expect(text).toContain("Lightning Bolt");
        expect(text).toContain("(2X2) 117");
        expect(text).toContain("4");
    });

    it("shows the availability badge only when the collection is visible", () => {
        const owned = makeDeckListLine(makeDeckListCard("Bolt"), {
            availability: { state: "partial", needed: 4, exact: 1, other: 2, blocked: 0 }
        });
        const hidden = makeDeckListLine(makeDeckListCard("Shock"));
        const lines = render(makeParseResult([owned, hidden])).findAll(".review-line");

        expect(lines[0].find(".collection-availability--partial").exists()).toBe(true);
        expect(lines[1].find(".collection-availability").exists()).toBe(false);
    });

    it("shows server notices and client warnings", () => {
        const line = makeDeckListLine(makeDeckListCard("Mana Drain", { is_legal: false }), {
            notices: ["printing_not_found"]
        });

        const text = render(makeParseResult([line])).text();

        expect(text).toContain("pages.deck_list_import.notices.printing_not_found");
        expect(text).toContain("pages.deck_list_import.warnings.not_legal");
    });

    it("reports skipped maybeboard cards", () => {
        expect(render(makeParseResult([makeDeckListLine()], { dropped: 3 })).text()).toContain(
            "pages.deck_list_import.review.dropped"
        );
    });
});

describe("DeckListReview — what blocks the confirm", () => {
    it("is blocked by an unresolved line and released by picking a suggestion", async () => {
        const unresolved = makeDeckListLine(null, {
            raw: "4 Lightnig Bolt",
            suggestions: [{ card: makeDeckListCard("Lightning Bolt"), availability: null }]
        });
        const wrapper = render(makeParseResult([unresolved]));

        expect(confirmButton(wrapper).attributes("disabled")).toBeDefined();
        expect(wrapper.text()).toContain("pages.deck_list_import.review.unresolved");

        await wrapper.find(".review-line__suggestions button").trigger("click");

        expect(confirmButton(wrapper).attributes("disabled")).toBeUndefined();
        expect(wrapper.find(".review-line").text()).toContain("Lightning Bolt");
    });

    it("is released by leaving the unresolved line out", async () => {
        const wrapper = render(makeParseResult([makeDeckListLine(null), makeDeckListLine()]));

        await wrapper.find(".review-line input[type=checkbox]").setValue(false);

        expect(confirmButton(wrapper).attributes("disabled")).toBeUndefined();
    });

    it("is blocked while the deck has no name", () => {
        expect(confirmButton(render(makeParseResult([makeDeckListLine()]), "  ")).attributes("disabled")).toBeDefined();
    });

    it("is blocked while a commander format has no commander", () => {
        const wrapper = render(makeParseResult([makeDeckListLine()], { rules: commanderRules() }));

        expect(confirmButton(wrapper).attributes("disabled")).toBeDefined();
        expect(wrapper.text()).toContain("pages.deck_list_import.review.command_zone_missing");
    });

    it("is blocked while an Oathbreaker deck has no signature spell, and sends both once it has", async () => {
        http.json("/decks/import-list", { redirect: "/decks/x" });
        const rules = commanderRules({ format: "oathbreaker", hasSignatureSpell: true });
        const walker = makeCommanderResult("Chandra, Fire Artisan");
        const spell = makeCommanderResult("Lightning Bolt");

        const missing = render(
            makeParseResult([makeDeckListLine()], {
                rules,
                command_zone: { commander: walker, partner: null, signature_spell: null, printings: {} }
            })
        );
        const complete = render(
            makeParseResult([makeDeckListLine()], {
                rules,
                command_zone: { commander: walker, partner: null, signature_spell: spell, printings: {} }
            })
        );

        expect(confirmButton(missing).attributes("disabled")).toBeDefined();
        await confirm(complete);
        expect(http.lastCall("/decks/import-list")?.body).toMatchObject({
            commander_id: walker.id,
            companion_id: null,
            signature_spell_id: spell.id
        });
    });

    it("is not blocked when the paste pre-filled the commander", () => {
        const result = makeParseResult([makeDeckListLine()], {
            rules: commanderRules(),
            command_zone: { commander: makeCommanderResult(), partner: null, signature_spell: null, printings: {} }
        });

        expect(confirmButton(render(result)).attributes("disabled")).toBeUndefined();
    });
});

describe("DeckListReview — confirm", () => {
    it("sends the deck, the command zone and the rows, then visits the new deck", async () => {
        http.json("/decks/import-list", { redirect: "/decks/new-deck" });
        const krenko = makeDeckListCard("Krenko, Mob Boss");
        const result = makeParseResult(
            [
                makeDeckListLine(krenko),
                makeDeckListLine(makeDeckListCard("Sol Ring", { color_identity: null }), { category: "Ramp" }),
                makeDeckListLine(makeDeckListCard("Pyroblast"), { zone: "side", quantity: 2 })
            ],
            {
                rules: commanderRules(),
                command_zone: {
                    commander: makeCommanderResult("Krenko, Mob Boss"),
                    partner: makeCommanderResult("Partner"),
                    signature_spell: null,
                    // The partner was picked, not pasted — it has no printing.
                    printings: { "oracle-Krenko, Mob Boss": "printing-ddt-52", "oracle-Somebody Else": "printing-x" }
                }
            }
        );

        await confirm(render(result, " Krenko Goblins "));

        expect(http.lastCall("/decks/import-list")?.body).toEqual({
            format: "commander",
            deck_name: "Krenko Goblins",
            commander_id: "oracle-Krenko, Mob Boss",
            companion_id: "oracle-Partner",
            signature_spell_id: null,
            command_zone_printings: { "oracle-Krenko, Mob Boss": "printing-ddt-52" },
            rows: [
                {
                    oracle_card_id: "oracle-Sol Ring",
                    default_card_id: "printing-Sol Ring",
                    quantity: 1,
                    zone: "main",
                    category: "Ramp"
                },
                {
                    oracle_card_id: "oracle-Pyroblast",
                    default_card_id: "printing-Pyroblast",
                    quantity: 2,
                    zone: "side",
                    category: null
                }
            ]
        });
        expect(routerMock.visit).toHaveBeenCalledWith("/decks/new-deck");
    });

    it("sends the zone chosen for a guessed section", async () => {
        http.json("/decks/import-list", { redirect: "/decks/x" });
        const guessed = makeDeckListLine(makeDeckListCard("Pyroblast"), {
            section: 1,
            zone: "side",
            zone_guessed: true
        });
        const result = makeParseResult([makeDeckListLine(), guessed]);
        result.sections[1] = { index: 1, label: null, zone: "side", zone_guessed: true };
        const wrapper = render(result);

        wrapper.findComponent({ name: "MonoSelect" }).vm.$emit("change", "main");
        await confirm(wrapper);

        const rows = (http.lastCall("/decks/import-list")?.body as { rows: Array<{ zone: string }> }).rows;
        expect(rows.map(row => row.zone)).toEqual(["main", "main"]);
    });

    it("shows validation errors and stays on the page", async () => {
        http.json("/decks/import-list", { errors: { deck_name: ["Name taken."], "rows.0.zone": ["Bad zone."] } }, 422);
        const wrapper = render(makeParseResult([makeDeckListLine()]));

        await confirm(wrapper);

        expect(wrapper.text()).toContain("Name taken.");
        expect(wrapper.text()).toContain("Bad zone.");
        expect(routerMock.visit).not.toHaveBeenCalled();
    });

    it("says so when the server fails", async () => {
        http.status("/decks/import-list", 500);
        const wrapper = render(makeParseResult([makeDeckListLine()]));

        await confirm(wrapper);

        expect(wrapper.text()).toContain("pages.deck_list_import.review.failed");
    });

    it("goes back to the paste form", async () => {
        const wrapper = render(makeParseResult([makeDeckListLine()]));

        await wrapper.find(".deck-list-review__actions button").trigger("click");

        expect(wrapper.emitted("back")).toHaveLength(1);
    });
});
