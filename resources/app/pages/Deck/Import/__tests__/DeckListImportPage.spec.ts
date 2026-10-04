import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { makeCommanderResult, makeDeckListLine, makeParseResult } from "@/test/factories/deckList.ts";
import { installFetchMock } from "@/test/http.ts";
import type { FetchMock } from "@/test/http.ts";
import { setPageProps } from "@/test/inertia.ts";
import DeckListImportPage from "../DeckListImportPage.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

let http: FetchMock;

beforeEach(() => {
    setPageProps({ csrfToken: "csrf-token" });
    http = installFetchMock();
});

const render = () =>
    mount(DeckListImportPage, {
        props: { formats: ["legacy", "commander"], nameMax: 64, maxChars: 100, maxLines: 3 },
        global: { stubs: { CommanderCommandZonePickerModal: true, OathbreakerCommandZonePickerModal: true } }
    });

const submitButton = (wrapper: ReturnType<typeof render>) => wrapper.find("button[type=submit]");

/** Pick a format and paste a list. */
const fill = async (wrapper: ReturnType<typeof render>, text: string, format = "legacy") => {
    wrapper.findComponent({ name: "MonoSelect" }).vm.$emit("change", format);
    await wrapper.find("#deck_list").setValue(text);
};

/** Submit the paste and let the request settle. */
const parse = async (wrapper: ReturnType<typeof render>) => {
    await wrapper.find("form").trigger("submit");
    await flushPromises();
};

describe("DeckListImportPage — the paste form", () => {
    it("needs a format and some text before it parses", async () => {
        const wrapper = render();
        expect(submitButton(wrapper).attributes("disabled")).toBeDefined();

        await wrapper.find("#deck_list").setValue("4 Lightning Bolt");
        expect(submitButton(wrapper).attributes("disabled")).toBeDefined();

        wrapper.findComponent({ name: "MonoSelect" }).vm.$emit("change", "legacy");
        await flushPromises();
        expect(submitButton(wrapper).attributes("disabled")).toBeUndefined();
    });

    it("refuses a paste over the line limit before sending it", async () => {
        const wrapper = render();

        await fill(wrapper, "1 A\n\n1 B\n1 C\n1 D");

        expect(submitButton(wrapper).attributes("disabled")).toBeDefined();
        expect(wrapper.find(".deck-list-counter--over").exists()).toBe(true);
    });

    it("counts non-blank lines only", async () => {
        const wrapper = render();

        await fill(wrapper, "1 A\n\n\n1 B\n1 C");

        expect(submitButton(wrapper).attributes("disabled")).toBeUndefined();
    });
});

describe("DeckListImportPage — parsing", () => {
    it("sends the paste and shows the review", async () => {
        http.json("/api/decks/import-list/parse", makeParseResult([makeDeckListLine()]));
        const wrapper = render();

        await fill(wrapper, "4 Lightning Bolt");
        await parse(wrapper);

        expect(http.lastCall("/api/decks/import-list/parse")?.body).toEqual({
            format: "legacy",
            text: "4 Lightning Bolt"
        });
        expect(wrapper.find(".deck-list-review").exists()).toBe(true);
        expect(wrapper.find("#deck_list").exists()).toBe(false);
    });

    it("names the deck after a pre-filled commander when no name was given", async () => {
        http.json(
            "/api/decks/import-list/parse",
            makeParseResult([], {
                command_zone: {
                    commander: makeCommanderResult("Krenko, Mob Boss"),
                    partner: null,
                    signature_spell: null
                }
            })
        );
        const wrapper = render();

        await fill(wrapper, "Commander\n1 Krenko, Mob Boss", "commander");
        await parse(wrapper);

        expect((wrapper.find("#deck_list_name").element as HTMLInputElement).value).toBe("Krenko, Mob Boss");
    });

    it("keeps a name the user gave", async () => {
        http.json(
            "/api/decks/import-list/parse",
            makeParseResult([], {
                command_zone: { commander: makeCommanderResult(), partner: null, signature_spell: null }
            })
        );
        const wrapper = render();

        await wrapper.find("#deck_name").setValue("Goblins!");
        await fill(wrapper, "1 Krenko, Mob Boss", "commander");
        await parse(wrapper);

        expect((wrapper.find("#deck_list_name").element as HTMLInputElement).value).toBe("Goblins!");
    });

    it("shows validation errors on the paste form", async () => {
        http.json("/api/decks/import-list/parse", { errors: { text: ["Too many lines."] } }, 422);
        const wrapper = render();

        await fill(wrapper, "4 Lightning Bolt");
        await parse(wrapper);

        expect(wrapper.text()).toContain("Too many lines.");
        expect(wrapper.find(".deck-list-review").exists()).toBe(false);
    });

    it("goes back to the paste with the text kept", async () => {
        http.json("/api/decks/import-list/parse", makeParseResult([makeDeckListLine()]));
        const wrapper = render();
        await fill(wrapper, "4 Lightning Bolt");
        await parse(wrapper);

        await wrapper.find(".deck-list-review__actions button").trigger("click");

        expect((wrapper.find("#deck_list").element as HTMLTextAreaElement).value).toBe("4 Lightning Bolt");
    });
});
