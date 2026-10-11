import { mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import DataTableToolbar from "../DataTableToolbar.vue";

/** The toolbar's debounce — a search goes out this long after the last keystroke. */
const DEBOUNCE = 750;

const render = (search: string | null = null) => mount(DataTableToolbar, { props: { search, selectedCount: 0 } });

/** What the search input currently shows. */
const shown = (wrapper: ReturnType<typeof render>): string => (wrapper.get("input").element as HTMLInputElement).value;

/** Every query the toolbar asked the table to search for, in order. */
const searches = (wrapper: ReturnType<typeof render>): string[] =>
    (wrapper.emitted("search") ?? []).map(([query]) => query as string);

describe("DataTableToolbar — search input", () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("waits for a pause in typing before searching", async () => {
        const wrapper = render();

        await wrapper.get("input").setValue("lig");
        vi.advanceTimersByTime(DEBOUNCE - 1);
        expect(searches(wrapper)).toEqual([]);

        vi.advanceTimersByTime(1);
        expect(searches(wrapper)).toEqual(["lig"]);
    });

    it("restarts the wait on every keystroke", async () => {
        const wrapper = render();

        await wrapper.get("input").setValue("lig");
        vi.advanceTimersByTime(DEBOUNCE / 2);
        await wrapper.get("input").setValue("ligh");
        vi.advanceTimersByTime(DEBOUNCE);

        expect(searches(wrapper)).toEqual(["ligh"]);
    });

    /**
     * The swallowed-keystrokes bug: a pause sends "lig", the user types on,
     * and the server's answer for "lig" used to be copied into the input —
     * wiping out everything typed while it was loading.
     */
    it("keeps what was typed while the results were loading", async () => {
        const wrapper = render();

        await wrapper.get("input").setValue("lig");
        vi.advanceTimersByTime(DEBOUNCE);
        await wrapper.get("input").setValue("lightning");
        await wrapper.setProps({ search: "lig" });

        expect(shown(wrapper)).toBe("lightning");

        vi.advanceTimersByTime(DEBOUNCE);
        expect(searches(wrapper)).toEqual(["lig", "lightning"]);
    });

    /**
     * Laravel trims the query on the way in, so a pause right after the space
     * in "lightning bolt" used to come back as "lightning" and eat the space.
     */
    it("keeps the space typed between two words", async () => {
        const wrapper = render();

        await wrapper.get("input").setValue("lightning ");
        vi.advanceTimersByTime(DEBOUNCE);
        await wrapper.setProps({ search: "lightning" });

        expect(searches(wrapper)).toEqual(["lightning"]);
        expect(shown(wrapper)).toBe("lightning ");
    });

    it("does not search again for the same query with whitespace added", async () => {
        const wrapper = render("lightning");

        await wrapper.get("input").setValue("lightning ");
        vi.advanceTimersByTime(DEBOUNCE);

        expect(searches(wrapper)).toEqual([]);
    });

    it("shows a search that came from elsewhere without sending it back", async () => {
        const wrapper = render("lightning");

        // Browser back from ?search=lightning to ?search=lig.
        await wrapper.setProps({ search: "lig" });
        vi.advanceTimersByTime(DEBOUNCE);

        expect(shown(wrapper)).toBe("lig");
        expect(searches(wrapper)).toEqual([]);
    });

    it("clears the search when the input is emptied before the results arrive", async () => {
        const wrapper = render();

        await wrapper.get("input").setValue("lig");
        vi.advanceTimersByTime(DEBOUNCE);
        await wrapper.get("input").setValue("");
        vi.advanceTimersByTime(DEBOUNCE);

        expect(searches(wrapper)).toEqual(["lig", ""]);
    });
});

describe("DataTableToolbar — minimum query length", () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("does not search for a single letter", async () => {
        const wrapper = render();

        await wrapper.get("input").setValue("l");
        vi.advanceTimersByTime(DEBOUNCE);

        expect(searches(wrapper)).toEqual([]);
    });

    it("searches from the second letter on", async () => {
        const wrapper = render();

        await wrapper.get("input").setValue("li");
        vi.advanceTimersByTime(DEBOUNCE);

        expect(searches(wrapper)).toEqual(["li"]);
    });

    it("counts the letters, not the whitespace around them", async () => {
        const wrapper = render();

        await wrapper.get("input").setValue(" l ");
        vi.advanceTimersByTime(DEBOUNCE);

        expect(searches(wrapper)).toEqual([]);
    });

    it("keeps the previous results while only one letter is left", async () => {
        const wrapper = render("li");

        await wrapper.get("input").setValue("l");
        vi.advanceTimersByTime(DEBOUNCE);

        expect(searches(wrapper)).toEqual([]);
        expect(shown(wrapper)).toBe("l");
    });

    it("still clears the search once the input is emptied", async () => {
        const wrapper = render("li");

        await wrapper.get("input").setValue("");
        vi.advanceTimersByTime(DEBOUNCE);

        expect(searches(wrapper)).toEqual([""]);
    });
});

describe("DataTableToolbar — Enter", () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("searches right away instead of waiting", async () => {
        const wrapper = render();

        await wrapper.get("input").setValue("lig");
        await wrapper.get("input").trigger("keydown", { key: "Enter" });

        expect(searches(wrapper)).toEqual(["lig"]);
    });

    it("does not send the same search again when the wait runs out", async () => {
        const wrapper = render();

        await wrapper.get("input").setValue("lig");
        await wrapper.get("input").trigger("keydown", { key: "Enter" });
        vi.advanceTimersByTime(DEBOUNCE);

        expect(searches(wrapper)).toEqual(["lig"]);
    });

    it("does nothing for the search the table already shows", async () => {
        const wrapper = render("lig");

        await wrapper.get("input").trigger("keydown", { key: "Enter" });

        expect(searches(wrapper)).toEqual([]);
    });

    it("does not search for a single letter either", async () => {
        const wrapper = render();

        await wrapper.get("input").setValue("l");
        await wrapper.get("input").trigger("keydown", { key: "Enter" });

        expect(searches(wrapper)).toEqual([]);
    });

    it("clears the search right away on an emptied input", async () => {
        const wrapper = render("lig");

        await wrapper.get("input").setValue("");
        await wrapper.get("input").trigger("keydown", { key: "Enter" });

        expect(searches(wrapper)).toEqual([""]);
    });

    /** Enter confirming a Japanese or Chinese IME composition must not search the half-typed text. */
    it("leaves Enter to an open IME composition", async () => {
        const wrapper = render();

        await wrapper.get("input").setValue("lig");
        await wrapper.get("input").trigger("keydown", { key: "Enter", isComposing: true });

        expect(searches(wrapper)).toEqual([]);
    });
});
