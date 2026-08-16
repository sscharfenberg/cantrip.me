import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import DataTablePagination from "../DataTablePagination.vue";

const render = (page: number, pageSize: number, total: number) =>
    mount(DataTablePagination, { props: { page, pageSize, total } });

/** A navigation button addressed by its accessible name, not by position. */
const control = (wrapper: ReturnType<typeof render>, name: "first" | "previous" | "next" | "last") =>
    wrapper.get(`[aria-label="components.datatable.${name}"]`);

/** The page buttons and ellipses, in order, as the user sees them. */
const pageStrip = (wrapper: ReturnType<typeof render>): string[] =>
    wrapper
        .findAll(".dt-pagination__page, .dt-pagination__ellipsis")
        .map(node => node.text())
        .filter(text => text !== "");

describe("DataTablePagination — the range readout", () => {
    it("counts from one, not from zero", () => {
        expect(render(1, 25, 200).find(".dt-pagination__info").text()).toBe("1–25 / 200");
    });

    it("offsets by the pages already behind it", () => {
        expect(render(3, 25, 200).find(".dt-pagination__info").text()).toBe("51–75 / 200");
    });

    it("stops the upper bound at the real total on a short last page", () => {
        expect(render(5, 25, 110).find(".dt-pagination__info").text()).toBe("101–110 / 110");
    });

    it("still reads sensibly for a single short page", () => {
        expect(render(1, 25, 3).find(".dt-pagination__info").text()).toBe("1–3 / 3");
    });

    it("reads 1–0 / 0 for an empty table", () => {
        // What a brand-new collection shows. Pinned as-is rather than fixed:
        // the readout is only rendered alongside a row list that is itself
        // empty, so the oddity never reaches a user on its own.
        expect(render(1, 25, 0).find(".dt-pagination__info").text()).toBe("1–0 / 0");
    });
});

describe("DataTablePagination — the page strip", () => {
    it("is hidden entirely when everything fits on one page", () => {
        const wrapper = render(1, 25, 20);

        expect(wrapper.findAll(".dt-pagination__page")).toHaveLength(0);
        expect(wrapper.find(".dt-pagination__info").exists()).toBe(true);
    });

    it("lists every page when they all fit in the window", () => {
        expect(pageStrip(render(1, 25, 75))).toEqual(["1", "2", "3"]);
    });

    it("shows two neighbours either side of the current page", () => {
        expect(pageStrip(render(5, 25, 500))).toEqual(["…", "3", "4", "5", "6", "7", "…"]);
    });

    it("drops the leading ellipsis at the start of the range", () => {
        expect(pageStrip(render(1, 25, 500))).toEqual(["1", "2", "3", "…"]);
    });

    it("drops the trailing ellipsis at the end of the range", () => {
        expect(pageStrip(render(20, 25, 500))).toEqual(["…", "18", "19", "20"]);
    });

    it("marks the current page for assistive tech", () => {
        const current = render(5, 25, 500).find('[aria-current="page"]');

        expect(current.text()).toBe("5");
        expect(current.classes()).toContain("dt-pagination__current");
    });

    it("marks only one page as current", () => {
        expect(render(5, 25, 500).findAll('[aria-current="page"]')).toHaveLength(1);
    });
});

describe("DataTablePagination — navigation", () => {
    it("emits the page a strip button stands for", async () => {
        const wrapper = render(5, 25, 500);

        await wrapper
            .findAll(".dt-pagination__page")
            .filter(node => node.text() === "7")[0]
            .trigger("click");

        expect(wrapper.emitted("navigate")).toEqual([[7]]);
    });

    it("jumps to the first and last pages from the dedicated buttons", async () => {
        const wrapper = render(5, 25, 500);

        await control(wrapper, "first").trigger("click");
        await control(wrapper, "last").trigger("click");

        expect(wrapper.emitted("navigate")).toEqual([[1], [20]]);
    });

    it("steps one page at a time from the chevrons", async () => {
        const wrapper = render(5, 25, 500);

        await control(wrapper, "previous").trigger("click");
        await control(wrapper, "next").trigger("click");

        expect(wrapper.emitted("navigate")).toEqual([[4], [6]]);
    });

    it("disables backward navigation on the first page", () => {
        const wrapper = render(1, 25, 500);

        expect(control(wrapper, "first").attributes("disabled")).toBeDefined();
        expect(control(wrapper, "previous").attributes("disabled")).toBeDefined();
        expect(control(wrapper, "next").attributes("disabled")).toBeUndefined();
    });

    it("disables forward navigation on the last page", () => {
        const wrapper = render(20, 25, 500);

        expect(control(wrapper, "next").attributes("disabled")).toBeDefined();
        expect(control(wrapper, "last").attributes("disabled")).toBeDefined();
        expect(control(wrapper, "previous").attributes("disabled")).toBeUndefined();
    });
});

describe("DataTablePagination — jump to page", () => {
    it("navigates to the typed page on Enter", async () => {
        const wrapper = render(1, 25, 500);

        await wrapper.find("#jumpToPage").setValue("12");
        await wrapper.find("#jumpToPage").trigger("keydown.enter");

        expect(wrapper.emitted("navigate")).toEqual([[12]]);
    });

    it("clamps a page number above the range", async () => {
        const wrapper = render(1, 25, 500);

        await wrapper.find("#jumpToPage").setValue("999");
        await wrapper.find("#jumpToPage").trigger("keydown.enter");

        expect(wrapper.emitted("navigate")).toEqual([[20]]);
    });

    it("clamps a page number below the range", async () => {
        const wrapper = render(5, 25, 500);

        await wrapper.find("#jumpToPage").setValue("0");
        await wrapper.find("#jumpToPage").trigger("keydown.enter");

        expect(wrapper.emitted("navigate")).toEqual([[1]]);
    });

    it("falls back to the current page when the box holds no number", async () => {
        // `v-model.number` leaves unparseable input as a string, which used to
        // clamp to NaN and navigate nowhere.
        const wrapper = render(7, 25, 500);

        await wrapper.find("#jumpToPage").setValue("abc");
        await wrapper.find("#jumpToPage").trigger("keydown.enter");

        expect(wrapper.emitted("navigate")).toEqual([[7]]);
        expect((wrapper.find("#jumpToPage").element as HTMLInputElement).value).toBe("7");
    });

    it("writes the clamped value back into the box", async () => {
        const wrapper = render(1, 25, 500);

        await wrapper.find("#jumpToPage").setValue("999");
        await wrapper.find("#jumpToPage").trigger("keydown.enter");

        expect((wrapper.find("#jumpToPage").element as HTMLInputElement).value).toBe("20");
    });
});

describe("DataTablePagination — page size", () => {
    it("keeps the size picker visible even on a single page", () => {
        // It is what lets the user get *off* a single oversized page.
        expect(render(1, 100, 20).findComponent({ name: "MonoSelect" }).exists()).toBe(true);
    });

    it("offers the three supported sizes, unsorted so they stay ascending", () => {
        const select = render(1, 25, 500).findComponent({ name: "MonoSelect" });

        expect(select.props("options")).toEqual([
            { value: "25", label: "25" },
            { value: "50", label: "50" },
            { value: "100", label: "100" }
        ]);
        expect(select.props("sort")).toBe(false);
    });

    it("shows the active size as selected", () => {
        expect(render(1, 50, 500).findComponent({ name: "MonoSelect" }).props("selected")).toBe("50");
    });

    it("emits the new size as a number", async () => {
        const wrapper = render(1, 25, 500);

        wrapper.findComponent({ name: "MonoSelect" }).vm.$emit("change", "100");
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted("pageSizeChange")).toEqual([[100]]);
    });
});
