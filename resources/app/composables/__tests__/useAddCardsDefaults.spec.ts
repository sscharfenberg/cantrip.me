import { beforeEach, describe, expect, it, vi } from "vitest";
import type * as AddCardsDefaults from "../useAddCardsDefaults.ts";

const STORAGE_KEY = "cantrip:add-cards-defaults";

/** App-wide fallbacks, restated so a change to them has to be deliberate. */
const APP_DEFAULTS = { amount: 1, language: "en", condition: "", finish: "nonfoil" };

/**
 * The module reads localStorage once at import time, so a spec that wants to
 * test the load path has to seed storage and *then* import. Every test goes
 * through this rather than a top-level import.
 */
const load = async (): Promise<typeof AddCardsDefaults> => {
    vi.resetModules();
    return import("../useAddCardsDefaults.ts");
};

beforeEach(() => {
    vi.resetModules();
});

describe("useAddCardsDefaults — resolved defaults", () => {
    it("falls back to the app defaults when the user has saved nothing", async () => {
        const { useAddCardsDefaults } = await load();

        expect(useAddCardsDefaults().defaults.value).toEqual(APP_DEFAULTS);
    });

    it("layers saved values over the app defaults", async () => {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({ amount: 4, finish: "foil" }));
        const { useAddCardsDefaults } = await load();

        expect(useAddCardsDefaults().defaults.value).toEqual({ ...APP_DEFAULTS, amount: 4, finish: "foil" });
    });

    it("survives a corrupt storage entry", async () => {
        localStorage.setItem(STORAGE_KEY, "{not json");
        const { useAddCardsDefaults } = await load();

        expect(useAddCardsDefaults().defaults.value).toEqual(APP_DEFAULTS);
    });

    it.each(["null", '"nonfoil"', "[]", "42", "true"])(
        "survives a stored %s, which is valid JSON of the wrong shape",
        async raw => {
            // These slip past `JSON.parse`'s catch. A stored `null` in
            // particular used to reach `Object.keys(null)` in
            // `hasSavedDefaults` and take the whole page down on render.
            localStorage.setItem(STORAGE_KEY, raw);
            const { useAddCardsDefaults } = await load();
            const form = useAddCardsDefaults();

            expect(form.defaults.value).toEqual(APP_DEFAULTS);
            expect(form.hasSavedDefaults.value).toBe(false);
        }
    );

    it("survives storage being unreadable", async () => {
        vi.spyOn(localStorage, "getItem").mockImplementation(() => {
            throw new Error("SecurityError");
        });
        const { useAddCardsDefaults } = await load();

        expect(useAddCardsDefaults().defaults.value).toEqual(APP_DEFAULTS);
    });
});

describe("useAddCardsDefaults — hasSavedDefaults", () => {
    it("is false before the user saves anything", async () => {
        const { useAddCardsDefaults } = await load();

        expect(useAddCardsDefaults().hasSavedDefaults.value).toBe(false);
    });

    it("is true once anything is stored", async () => {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({ amount: 4 }));
        const { useAddCardsDefaults } = await load();

        expect(useAddCardsDefaults().hasSavedDefaults.value).toBe(true);
    });
});

describe("useAddCardsDefaults — form values", () => {
    it("seeds the form from the resolved defaults", async () => {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({ amount: 4, language: "de" }));
        const { useAddCardsDefaults } = await load();

        const { amount, language, condition, finish } = useAddCardsDefaults();

        expect([amount.value, language.value, condition.value, finish.value]).toEqual([4, "de", "", "nonfoil"]);
    });

    it("starts the remount key at zero", async () => {
        const { useAddCardsDefaults } = await load();

        expect(useAddCardsDefaults().resetKey.value).toBe(0);
    });
});

describe("useAddCardsDefaults — saveDefaults", () => {
    it("writes the current form values to storage", async () => {
        const { useAddCardsDefaults } = await load();
        const form = useAddCardsDefaults();

        form.amount.value = 4;
        form.finish.value = "foil";
        form.saveDefaults();

        expect(JSON.parse(localStorage.getItem(STORAGE_KEY)!)).toEqual({
            amount: 4,
            language: "en",
            condition: "",
            finish: "foil"
        });
    });

    it("updates the resolved defaults without a reload", async () => {
        const { useAddCardsDefaults } = await load();
        const form = useAddCardsDefaults();

        form.amount.value = 4;
        form.saveDefaults();

        expect(form.defaults.value.amount).toBe(4);
        expect(form.hasSavedDefaults.value).toBe(true);
    });

    it("still applies the defaults for this session when the write fails", async () => {
        const { useAddCardsDefaults } = await load();
        const form = useAddCardsDefaults();
        vi.spyOn(localStorage, "setItem").mockImplementation(() => {
            throw new Error("QuotaExceededError");
        });

        form.amount.value = 4;
        expect(() => form.saveDefaults()).not.toThrow();

        expect(form.defaults.value.amount).toBe(4);
        expect(localStorage.getItem(STORAGE_KEY)).toBeNull();
    });

    it("shares saved state across every consumer", async () => {
        // Module-level state: the settings panel and the add form are separate
        // components reading one source.
        const { useAddCardsDefaults } = await load();
        const settings = useAddCardsDefaults();
        const addForm = useAddCardsDefaults();

        settings.amount.value = 4;
        settings.saveDefaults();

        expect(addForm.defaults.value.amount).toBe(4);
    });
});

describe("useAddCardsDefaults — clearDefaults", () => {
    it("drops the stored entry and reverts to the app defaults", async () => {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({ amount: 4 }));
        const { useAddCardsDefaults } = await load();
        const form = useAddCardsDefaults();

        form.clearDefaults();

        expect(localStorage.getItem(STORAGE_KEY)).toBeNull();
        expect(form.defaults.value).toEqual(APP_DEFAULTS);
        expect(form.hasSavedDefaults.value).toBe(false);
    });

    it("leaves the already-typed form values alone", async () => {
        // Clearing is about the *saved* defaults; the user's in-progress entry
        // is theirs until they reset it.
        localStorage.setItem(STORAGE_KEY, JSON.stringify({ amount: 4 }));
        const { useAddCardsDefaults } = await load();
        const form = useAddCardsDefaults();

        form.clearDefaults();

        expect(form.amount.value).toBe(4);
    });
});

describe("useAddCardsDefaults — resetToDefaults", () => {
    it("puts every form value back to the resolved default", async () => {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({ amount: 4, finish: "foil" }));
        const { useAddCardsDefaults } = await load();
        const form = useAddCardsDefaults();

        form.amount.value = 99;
        form.language.value = "de";
        form.resetToDefaults();

        expect([form.amount.value, form.language.value, form.finish.value]).toEqual([4, "en", "foil"]);
    });

    it("bumps the remount key so keyed children re-render", async () => {
        const { useAddCardsDefaults } = await load();
        const form = useAddCardsDefaults();

        form.resetToDefaults();
        form.resetToDefaults();

        expect(form.resetKey.value).toBe(2);
    });
});
