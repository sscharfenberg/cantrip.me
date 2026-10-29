import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { installFetchMock } from "@/test/http.ts";
import DeckCardSwitchPrintingModal from "../DeckCardSwitchPrintingModal.vue";

/******************************************************************************
 * The modal renders through `<Modal>`, which teleports into `<body>` — so
 * assertions go through `document`, not through the wrapper, whose own subtree
 * is just the teleport anchor.
 *
 * Only `setTimeout` is faked: it drives the search's debounce, while
 * `flushPromises` keeps running on the real `setImmediate`.
 *****************************************************************************/

const PRINTINGS_URL = "/api/decks/deck-1/cards/card-1/printings";

const SETS = {
    pm21: { code: "pm21", name: "Core Set 2021 Promos", path: null },
    m21: { code: "m21", name: "Core Set 2021", path: "/set/m21.svg" },
    znr: { code: "znr", name: "Zendikar Rising", path: "/set/znr.svg" },
    lea: { code: "lea", name: "Limited Edition Alpha", path: "/set/lea.svg" }
};

const printing = (id: string, set: (typeof SETS)[keyof typeof SETS], overrides: Record<string, unknown> = {}) => ({
    id,
    name: "Forest",
    card_image_0: null,
    card_image_1: null,
    artist: null,
    cn: id,
    finishes: ["nonfoil"],
    set,
    in_collection: false,
    is_current: false,
    ...overrides
});

/**
 * Newest set first, as the endpoint sends them. The promo set leads so that
 * "exact code first" has something to reorder, and the current printing sits
 * mid-list so that pinning it has something to move.
 */
const PRINTINGS = [
    printing("pm21-1", SETS.pm21),
    printing("m21-1", SETS.m21, { in_collection: true }),
    printing("m21-2", SETS.m21),
    printing("znr-current", SETS.znr, { is_current: true }),
    printing("znr-2", SETS.znr),
    printing("lea-1", SETS.lea, { in_collection: true })
];

beforeEach(() => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    installFetchMock().json(PRINTINGS_URL, PRINTINGS);
});

afterEach(() => {
    vi.useRealTimers();
});

/** Mount and wait for the printings request to land. */
const render = async () => {
    const wrapper = mount(DeckCardSwitchPrintingModal, {
        props: { printingsUrl: PRINTINGS_URL, name: "Forest" },
        attachTo: document.body
    });
    await flushPromises();
    return wrapper;
};

/** Collector numbers of the listed printings, in order — the fixture makes them the ids. The panel writes them `#270`. */
const listed = (): string[] =>
    [...document.querySelectorAll(".switch-printing__list .face-image__panel-line:first-child")].map(
        line => line.textContent?.trim().replace(/^#/u, "") ?? ""
    );

const searchInput = (): HTMLInputElement => document.querySelector(".set-search input") as HTMLInputElement;

/** The dropdown's rows as "[CODE] Name count" — the spans sit apart by CSS gap, not by whitespace. */
const dropdown = (): string[] =>
    [...document.querySelectorAll(".set-search__results li")].map(row => {
        const parts = row.querySelectorAll("span");
        return parts.length
            ? [...parts].map(part => part.textContent?.trim()).join(" ")
            : (row.textContent?.trim() ?? "");
    });

const chip = (): HTMLButtonElement | null => document.querySelector(".switch-printing__chip");

/** Type into the set search; by default wait out the debounce too. */
const type = async (value: string, { settle = true } = {}): Promise<void> => {
    const input = searchInput();
    input.value = value;
    input.dispatchEvent(new Event("input"));
    if (settle) await vi.advanceTimersByTimeAsync(250);
    await flushPromises();
};

const pickFromDropdown = async (code: string): Promise<void> => {
    const row = [...document.querySelectorAll<HTMLButtonElement>(".set-search__result")].find(button =>
        button.textContent?.includes(`[${code}]`)
    );
    if (!row) throw new Error(`no dropdown row for ${code}`);
    row.click();
    await flushPromises();
};

const toggleOnlyAvailable = async (): Promise<void> => {
    const checkbox = document.getElementById("only_collection_printings") as HTMLInputElement;
    checkbox.checked = !checkbox.checked;
    checkbox.dispatchEvent(new Event("change"));
    await flushPromises();
};

describe("DeckCardSwitchPrintingModal — printings", () => {
    it("lists every printing newest first, the current one pinned to the top", async () => {
        await render();

        expect(listed()).toEqual(["znr-current", "pm21-1", "m21-1", "m21-2", "znr-2", "lea-1"]);
    });

    it("says so when the filters leave no printing", async () => {
        await render();
        await toggleOnlyAvailable();
        await type("znr");

        expect(listed()).toEqual([]);
        expect(document.querySelector(".switch-printing__empty")?.textContent).toContain(
            "pages.deck.switch_printing.no_printings"
        );
    });
});

describe("DeckCardSwitchPrintingModal — set search", () => {
    it("narrows the printings to every matching set once typing pauses, spinning meanwhile", async () => {
        await render();
        await type("m21", { settle: false });

        expect(document.querySelector(".set-search .loading-spinner")).not.toBeNull();
        expect(listed()).toHaveLength(6);

        await vi.advanceTimersByTimeAsync(250);
        await flushPromises();

        expect(document.querySelector(".set-search .loading-spinner")).toBeNull();
        expect(listed()).toEqual(["pm21-1", "m21-1", "m21-2"]);
    });

    it("drops down the matching sets, the exact code first, each with its printing count", async () => {
        await render();
        await type("m21");

        expect(dropdown()).toEqual(["[M21] Core Set 2021 2", "[PM21] Core Set 2021 Promos 1"]);
    });

    it("does not search on a single character", async () => {
        await render();
        await type("m");

        expect(dropdown()).toEqual([]);
        expect(listed()).toHaveLength(6);
    });

    it("shows an empty-state row when no set matches", async () => {
        await render();
        await type("xyz");

        expect(dropdown()).toEqual(["pages.deck.switch_printing.set_search.no_results"]);
    });

    it("narrows to a picked set and shows it as a chip, clearing the search", async () => {
        await render();
        await type("core");
        await pickFromDropdown("M21");

        expect(listed()).toEqual(["m21-1", "m21-2"]);
        expect(chip()?.textContent).toContain("[M21] Core Set 2021");
        expect(searchInput().value).toBe("");
        expect(dropdown()).toEqual([]);
    });

    it("replaces the chip with a new search", async () => {
        await render();
        await type("m21");
        await pickFromDropdown("M21");
        await type("zendikar");

        expect(chip()).toBeNull();
        expect(listed()).toEqual(["znr-current", "znr-2"]);
    });

    it("brings every printing back when the chip is removed, and focuses the search", async () => {
        await render();
        await type("m21");
        await pickFromDropdown("PM21");
        chip()?.click();
        await flushPromises();

        expect(chip()).toBeNull();
        expect(listed()).toHaveLength(6);
        expect(document.activeElement).toBe(searchInput());
    });

    it("picks the best match on Enter without waiting for the debounce", async () => {
        await render();
        await type("m21", { settle: false });
        searchInput().dispatchEvent(new KeyboardEvent("keydown", { key: "Enter", bubbles: true }));
        await flushPromises();

        expect(chip()?.textContent).toContain("[M21]");
        expect(listed()).toEqual(["m21-1", "m21-2"]);
    });

    it("only offers sets with an available printing, counting only those, while the switch is on", async () => {
        await render();
        await toggleOnlyAvailable();
        await type("m21");

        expect(dropdown()).toEqual(["[M21] Core Set 2021 1"]);
        expect(listed()).toEqual(["m21-1"]);
    });
});

describe("DeckCardSwitchPrintingModal — set search scroll position", () => {
    const scroller = (): HTMLElement => document.querySelector(".switch-printing__scroller") as HTMLElement;

    it("returns to the top when the search changes the listed printings, not when it keeps them", async () => {
        await render();
        scroller().scrollTop = 300;
        await type("zendikar");

        expect(scroller().scrollTop).toBe(0);

        scroller().scrollTop = 300;
        await type("zendikar rising");

        expect(scroller().scrollTop).toBe(300);
    });
});

describe("DeckCardSwitchPrintingModal — dropdown", () => {
    /**
     * jsdom's popover methods are no-ops. These record open-ness on the
     * element instead, and deliberately fire no `toggle` event: a browser
     * fires it a task later, and the component must not wait for it.
     */
    const open = new WeakSet<HTMLElement>();
    beforeEach(() => {
        vi.spyOn(HTMLElement.prototype, "showPopover").mockImplementation(function (this: HTMLElement) {
            open.add(this);
        });
        vi.spyOn(HTMLElement.prototype, "hidePopover").mockImplementation(function (this: HTMLElement) {
            open.delete(this);
        });
    });

    const dropdownOpen = (): boolean => open.has(document.querySelector(".set-search__results") as HTMLElement);

    const press = (key: string, init: KeyboardEventInit = {}): KeyboardEvent => {
        const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true, ...init });
        searchInput().dispatchEvent(event);
        return event;
    };

    it("opens once the search applies, while the input has focus", async () => {
        await render();
        searchInput().focus();
        await type("m21");

        expect(dropdownOpen()).toBe(true);
    });

    it("stays shut when focus left the input before the search applied", async () => {
        await render();
        searchInput().focus();
        await type("m21", { settle: false });
        searchInput().blur();
        await vi.advanceTimersByTimeAsync(250);
        await flushPromises();

        expect(dropdownOpen()).toBe(false);
        expect(listed()).toEqual(["pm21-1", "m21-1", "m21-2"]);
    });

    it("is shut after Enter picks a set before the debounce ran", async () => {
        await render();
        searchInput().focus();
        await type("m21", { settle: false });
        press("Enter");
        await flushPromises();

        expect(chip()?.textContent).toContain("[M21]");
        expect(dropdownOpen()).toBe(false);
    });

    it("leaves an Enter that confirms an IME composition alone", async () => {
        await render();
        searchInput().focus();
        await type("m21");
        const event = press("Enter", { isComposing: true });
        await flushPromises();

        expect(event.defaultPrevented).toBe(false);
        expect(chip()).toBeNull();
    });

    it("closes on Escape and cancels the keydown, so the modal stays open", async () => {
        await render();
        searchInput().focus();
        await type("m21");

        const event = press("Escape");

        expect(event.defaultPrevented).toBe(true);
        expect(dropdownOpen()).toBe(false);
    });

    it("leaves Escape alone with the dropdown shut, so the modal can close", async () => {
        await render();

        expect(press("Escape").defaultPrevented).toBe(false);
    });
});
