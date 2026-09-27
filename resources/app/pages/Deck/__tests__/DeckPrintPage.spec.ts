import { mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { setPageProps } from "@/test/inertia.ts";
import { MAJOR_DIVIDER } from "@/utils/printableDeck.ts";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
import DeckPrintPage from "../DeckPrintPage.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

type Row = {
    id: string;
    name: string;
    quantity: number;
    set_code: string | null;
    collector_number: string | null;
    cmc: number;
    type_line: string;
    zone: "main" | "side" | "maybe";
    category_id: string | null;
};

let sequence = 0;
const row = (name: string, overrides: Partial<Row> = {}): Row => ({
    id: `row-${++sequence}`,
    name,
    quantity: 1,
    set_code: "cmd",
    collector_number: "1",
    cmc: 1,
    type_line: "Creature — Human",
    zone: "main",
    category_id: null,
    ...overrides
});

const baseProps = () => ({
    isOwner: true,
    deck: {
        id: "deck-1",
        name: "Atraxa Superfriends",
        description: "Proliferate.",
        format: "commander",
        state: "built",
        bracket: 3,
        card_count: { main: 5, companion: 0, side: 0 },
        max_sideboard_size: 0
    },
    commanders: [{ name: "Atraxa", quantity: 1, set_code: "cmr", collector_number: "347" }],
    companion: null,
    cards: [
        row("Forest", { type_line: "Basic Land — Forest", quantity: 2, cmc: 0 }),
        row("Sol Ring", { type_line: "Artifact", category_id: "cat-ramp", set_code: "cmm", collector_number: "400" }),
        row("Thalia", { cmc: 2 })
    ],
    categories: [{ id: "cat-ramp", name: "Ramp" }]
});

const render = (props: Record<string, unknown> = {}) =>
    mount(DeckPrintPage, { props: { ...baseProps(), ...props }, attachTo: document.body });

const textOf = (wrapper: ReturnType<typeof render>): string =>
    (wrapper.find("textarea").element as HTMLTextAreaElement).value;

beforeEach(() => {
    setPageProps({ auth: { user: null } });
});

afterEach(() => {
    vi.restoreAllMocks();
    document.querySelectorAll("iframe").forEach(frame => frame.remove());
});

describe("DeckPrintPage — the text", () => {
    it("starts with name, description and metadata", () => {
        const text = textOf(render());

        expect(text.startsWith(`Atraxa Superfriends\n${MAJOR_DIVIDER}\nProliferate.\n${MAJOR_DIVIDER}\n`)).toBe(true);
        expect(text).toContain("pages.deck.format: enums.card_formats.commander");
        expect(text).toContain("pages.deck.bracket: 3 – enums.bracket.3");
        expect(text).toContain("pages.deck.state: enums.deck_state.built");
        expect(text).toContain("pages.deck.card_count: pages.deck.card_count_tooltip.main");
    });

    it("lists groups as the deck page does: command zone, alphabetical groups, lands last", () => {
        const text = textOf(render());
        const headlines = text.split("\n").filter(line => /\(\d+\)$/.test(line));

        // Key echo: "pages.deck.groups.creature" sorts before "Ramp" by localeCompare.
        expect(headlines).toEqual([
            "pages.deck.commanders (1)",
            "pages.deck.groups.creature (1)",
            "Ramp (1)",
            "pages.deck.groups.land (2)"
        ]);
        expect(text).toContain("1 Atraxa (CMR) 347");
        expect(text).toContain("1 Sol Ring (CMM) 400");
        expect(text).toContain("2 Forest (CMD) 1");
    });

    it("leaves the bracket out of a deck without commanders", () => {
        expect(textOf(render({ commanders: [] }))).not.toContain("pages.deck.bracket");
    });

    it("prints the sideboard separately, after the mainboard", () => {
        const text = textOf(
            render({
                deck: { ...baseProps().deck, format: "legacy", max_sideboard_size: 15 },
                commanders: [],
                cards: [row("Thalia"), row("Pyroblast", { zone: "side", type_line: "Instant", quantity: 3 })]
            })
        );

        const [mainboard, sideboard] = text.split(`${MAJOR_DIVIDER}\n\npages.deck.groups.side (3)`);
        expect(mainboard).toContain("1 Thalia");
        expect(mainboard).not.toContain("Pyroblast");
        expect(sideboard).toContain("3 Pyroblast (CMD) 1");
    });

    it("lists the companion as its own group", () => {
        const text = textOf(
            render({ companion: { name: "Lurrus", quantity: 1, set_code: "iko", collector_number: "226" } })
        );

        expect(text).toContain("pages.deck.companion.heading (1)");
        expect(text).toContain("1 Lurrus (IKO) 226");
    });
});

describe("DeckPrintPage — breadcrumbs", () => {
    it("links the owner back to their deck list and the deck", () => {
        render();

        expect(useBreadcrumbs().crumbs.value).toEqual([
            { labelKey: "pages.decks.link", href: "/decks", icon: "deck" },
            { label: "Atraxa Superfriends", href: "/decks/deck-1", icon: "deck" },
            { label: "pages.deck_print.link" }
        ]);
    });

    it("gives a visitor no link into the owner's deck list", () => {
        render({ isOwner: false });

        const [decks, deck] = useBreadcrumbs().crumbs.value;
        expect(decks).toEqual({ labelKey: "pages.decks.link", icon: "deck" });
        expect(deck.href).toBe("/decks/deck-1");
    });
});

describe("DeckPrintPage — actions", () => {
    it("prints only the (edited) text, through a throwaway iframe", async () => {
        const wrapper = render();
        await wrapper.find("textarea").setValue("edited list");
        const print = vi.fn();
        const append = document.body.append.bind(document.body);
        vi.spyOn(document.body, "append").mockImplementation((...nodes) => {
            append(...nodes);
            const frame = nodes[0];
            if (frame instanceof HTMLIFrameElement && frame.contentWindow) {
                Object.assign(frame.contentWindow, { print, focus: vi.fn() });
            }
        });

        await wrapper.findAll("button")[0].trigger("click");

        const frame = document.querySelector("iframe");
        expect(frame?.contentDocument?.querySelector("pre")?.textContent).toBe("edited list");
        expect(print).toHaveBeenCalledOnce();
    });

    it("removes the iframe once printing is done", async () => {
        const wrapper = render();
        const append = document.body.append.bind(document.body);
        vi.spyOn(document.body, "append").mockImplementation((...nodes) => {
            append(...nodes);
            const frame = nodes[0];
            if (frame instanceof HTMLIFrameElement && frame.contentWindow) {
                Object.assign(frame.contentWindow, { print: vi.fn(), focus: vi.fn() });
            }
        });

        await wrapper.findAll("button")[0].trigger("click");
        document.querySelector("iframe")?.contentWindow?.dispatchEvent(new Event("afterprint"));

        expect(document.querySelector("iframe")).toBeNull();
    });

    it("downloads the text as a .txt named after the deck", async () => {
        const blobs: Blob[] = [];
        vi.spyOn(URL, "createObjectURL").mockImplementation(blob => {
            blobs.push(blob as Blob);
            return "blob:deck";
        });
        vi.spyOn(URL, "revokeObjectURL").mockImplementation(() => undefined);
        const names: string[] = [];
        vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(function (this: HTMLAnchorElement) {
            names.push(this.download);
        });
        const wrapper = render({ deck: { ...baseProps().deck, name: "Atraxa: 4/4?" } });

        await wrapper.findAll("button")[1].trigger("click");

        expect(names).toEqual(["Atraxa_ 4_4_.txt"]);
        expect(await blobs[0].text()).toBe(textOf(wrapper));
    });
});
