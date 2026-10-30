import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { setTestMessages } from "@/test/i18n.ts";
import { formMocks } from "@/test/inertia.ts";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import BulkClaimCardsPage from "../BulkClaimCardsPage.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const stack = (id: string, amount: number) => ({ id, amount, container: null });

/** One card per section: an owned printing, another printing owned, nothing owned. */
const cards = [
    {
        id: "sol-ring",
        name: "Sol Ring",
        quantity: 1,
        set_code: "cmd",
        collector_number: "1",
        card_image_0: null,
        section: "exact" as const,
        exact_stacks: [stack("sol-ring-stack", 1)],
        alt_stacks: []
    },
    {
        id: "forest",
        name: "Forest",
        quantity: 4,
        set_code: "dmu",
        collector_number: "281",
        card_image_0: null,
        section: "alt" as const,
        exact_stacks: [],
        alt_stacks: [
            {
                ...stack("forest-stack", 1),
                default_card_id: "forest-m21",
                set_code: "m21",
                collector_number: "274",
                card_image_0: null
            }
        ]
    },
    {
        id: "island",
        name: "Island",
        quantity: 2,
        set_code: "dmu",
        collector_number: "263",
        card_image_0: null,
        section: "missing" as const,
        exact_stacks: [],
        alt_stacks: []
    }
];

beforeEach(() => {
    setTestMessages({
        de: {
            pages: {
                deck: {
                    bulk_claim: {
                        section_exact: "exact",
                        section_alt: "alt",
                        section_new: "new",
                        buy_new: { label_full: "all {amount}", label_partial: "{amount} more" }
                    }
                }
            }
        }
    });
});

const render = () =>
    mount(BulkClaimCardsPage, {
        props: { deck: { id: "deck-1", name: "Elves", container_id: null }, cards, containers: [] }
    });

type Page = ReturnType<typeof render>;

const section = (wrapper: Page, heading: string) =>
    wrapper.findAll("section").find(s => s.find("h3, h2, h4").text() === heading)!;
const newRows = (wrapper: Page) =>
    section(wrapper, "new")
        .findAll("li")
        .map(row => [row.find("strong").text(), row.find(".bulk-claim__buy-new").text()]);
const pick = async (wrapper: Page, heading: string, stackId: string): Promise<void> => {
    section(wrapper, heading).findComponent(MonoSelect).vm.$emit("change", stackId);
    await wrapper.vm.$nextTick();
};

describe("BulkClaimCardsPage — add new card to collection", () => {
    it("lists every card, also those with a printing in the collection, and offers all their copies", () => {
        const wrapper = render();

        expect(newRows(wrapper)).toEqual([
            ["1× Sol Ring", "all 1"],
            ["4× Forest", "all 4"],
            ["2× Island", "all 2"]
        ]);
    });

    it("offers only the copies a picked stack leaves uncovered, and drops a card the stack covers whole", async () => {
        const wrapper = render();

        await pick(wrapper, "exact", "sol-ring-stack");
        await pick(wrapper, "alt", "forest-stack");

        expect(newRows(wrapper)).toEqual([
            ["4× Forest", "3 more"],
            ["2× Island", "all 2"]
        ]);
    });

    it("sends a card ticked there as bought new, and ticks the same choice next to its picked stack", async () => {
        const wrapper = render();
        await pick(wrapper, "alt", "forest-stack");

        await section(wrapper, "new").find("#add-new-forest").setValue(true);

        expect((section(wrapper, "alt").find("#buy-new-forest").element as HTMLInputElement).checked).toBe(true);
        await wrapper.find("form").trigger("submit");
        const form = formMocks[formMocks.length - 1];
        expect(form.post).toHaveBeenCalledWith("/decks/deck-1/bulk-claim");
        expect(form.buy_new).toEqual({ "sol-ring": false, forest: true, island: false });
        expect(form.assignments).toEqual({ "sol-ring": [], forest: ["forest-stack"], island: [] });
    });
});
