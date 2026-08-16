import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";
import { routerMock } from "@/test/inertia.ts";
import CollectionModeBadge from "../CollectionModeBadge.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const render = (mode: "A" | "B" | "C") =>
    mount(CollectionModeBadge, { props: { deckId: "deck-1", mode }, attachTo: document.body });

/** The menu buttons, one per mode, in A/B/C order. */
const menuItems = (wrapper: ReturnType<typeof render>) => wrapper.findAll(".popover-list-item");

beforeEach(() => {
    // jsdom does not implement the popover API the trigger relies on.
    Object.defineProperty(HTMLElement.prototype, "hidePopover", { value: vi.fn(), configurable: true });
});

describe("CollectionModeBadge — the trigger", () => {
    it.each([
        ["A", "#clear"],
        ["B", "#storage"],
        ["C", "#key"]
    ] as ["A" | "B" | "C", string][])("shows mode %s with the %s icon", (mode, icon) => {
        expect(render(mode).find(".collection-mode-badge__trigger use").attributes("href")).toBe(icon);
    });

    it("labels the trigger with the active mode", () => {
        expect(render("B").find(".collection-mode-badge__trigger").text()).toBe(
            "pages.deck.collection_mode.modes.B.label"
        );
    });

    it("explains the active mode in the tooltip", () => {
        expect(render("B").find(".collection-mode-badge__trigger").attributes("data-tooltip")).toBe(
            "pages.deck.collection_mode.modes.B.tooltip"
        );
    });

    it("points the trigger at its own menu, with a distinct id per instance", () => {
        // Both badges are mounted inside one parent because `useId` counts per
        // app — two separate `mount()` calls would each start from the same id
        // and hide a collision.
        const TwoBadges = defineComponent({
            components: { CollectionModeBadge },
            template: `<div>
                <CollectionModeBadge deck-id="deck-1" mode="A" />
                <CollectionModeBadge deck-id="deck-2" mode="B" />
            </div>`
        });
        const wrapper = mount(TwoBadges);

        const pairs = wrapper.findAll(".collection-mode-badge").map(badge => ({
            target: badge.find(".collection-mode-badge__trigger").attributes("popovertarget"),
            id: badge.find("dialog").attributes("id")
        }));

        expect(pairs).toHaveLength(2);
        for (const { target, id } of pairs) {
            expect(target).toBeTruthy();
            expect(target).toBe(id);
        }
        expect(pairs[0].id).not.toBe(pairs[1].id);
    });
});

describe("CollectionModeBadge — the menu", () => {
    it("offers all three modes", () => {
        const labels = menuItems(render("A")).map(item => item.text());

        expect(labels).toEqual([
            "pages.deck.collection_mode.modes.A.label",
            "pages.deck.collection_mode.modes.B.label",
            "pages.deck.collection_mode.modes.C.label"
        ]);
    });

    it("marks the active mode as selected", () => {
        const selected = menuItems(render("B")).filter(item => item.classes().includes("popover-list-item--selected"));

        expect(selected).toHaveLength(1);
        expect(selected[0].text()).toBe("pages.deck.collection_mode.modes.B.label");
    });
});

describe("CollectionModeBadge — switching mode", () => {
    it("PATCHes the deck's collection mode", async () => {
        const wrapper = render("A");

        await menuItems(wrapper)[2].trigger("click");

        expect(routerMock.patch).toHaveBeenCalledExactlyOnceWith(
            "/decks/deck-1/collection-mode",
            { mode: "C" },
            expect.objectContaining({ preserveScroll: true })
        );
    });

    it("does nothing when the user picks the mode already in force", async () => {
        const wrapper = render("B");

        await menuItems(wrapper)[1].trigger("click");

        expect(routerMock.patch).not.toHaveBeenCalled();
    });

    it("closes the menu on any pick, including a no-op one", async () => {
        const wrapper = render("B");

        await menuItems(wrapper)[1].trigger("click");

        expect(HTMLElement.prototype.hidePopover).toHaveBeenCalled();
    });

    it("locks the control while the request is in flight", async () => {
        const wrapper = render("A");

        await menuItems(wrapper)[1].trigger("click");

        expect(wrapper.find(".collection-mode-badge__trigger").attributes("disabled")).toBeDefined();
        expect(menuItems(wrapper)[0].attributes("disabled")).toBeDefined();
    });

    it("refuses a second pick until the first has finished", async () => {
        const wrapper = render("A");
        await menuItems(wrapper)[1].trigger("click");

        await menuItems(wrapper)[2].trigger("click");

        expect(routerMock.patch).toHaveBeenCalledOnce();
    });

    it("unlocks again once the visit finishes", async () => {
        const wrapper = render("A");
        await menuItems(wrapper)[1].trigger("click");

        const options = routerMock.patch.mock.calls[0][2] as { onFinish: () => void };
        options.onFinish();
        await wrapper.vm.$nextTick();

        expect(wrapper.find(".collection-mode-badge__trigger").attributes("disabled")).toBeUndefined();
    });
});
