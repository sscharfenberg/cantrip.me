import { beforeEach, describe, expect, it, vi } from "vitest";
import type { UseNavigationReturn } from "../useNavigation.ts";

/** Module-level state, so each test gets a freshly evaluated module. */
let navigation: UseNavigationReturn;

beforeEach(async () => {
    vi.resetModules();
    navigation = (await import("../useNavigation.ts")).useNavigation();
});

describe("useNavigation", () => {
    it("starts idle", () => {
        expect(navigation.navigating.value).toBe(false);
    });

    it("shares one flag across every call site", async () => {
        // `main.ts` flips this from the Inertia router's start/finish events,
        // outside any component; the overlay reads it from inside one.
        const overlay = (await import("../useNavigation.ts")).useNavigation();

        navigation.navigating.value = true;

        expect(overlay.navigating.value).toBe(true);
        expect(overlay.navigating).toBe(navigation.navigating);
    });
});
