import { beforeEach, describe, expect, it, vi } from "vitest";
import type { UseBreadcrumbsReturn } from "../useBreadcrumbs.ts";

/** Module-level state, so each test gets a freshly evaluated module. */
let breadcrumbs: UseBreadcrumbsReturn;

beforeEach(async () => {
    vi.resetModules();
    breadcrumbs = (await import("../useBreadcrumbs.ts")).useBreadcrumbs();
});

describe("useBreadcrumbs", () => {
    it("starts empty", () => {
        expect(breadcrumbs.crumbs.value).toEqual([]);
    });

    it("stores the trail it is given", () => {
        const trail = [
            { labelKey: "pages.collection.link", href: "/collection", icon: "deck" },
            { label: "Mono-White Aggro" }
        ];

        breadcrumbs.setBreadcrumbs(trail);

        expect(breadcrumbs.crumbs.value).toEqual(trail);
    });

    it("replaces the previous trail rather than appending to it", () => {
        breadcrumbs.setBreadcrumbs([{ label: "First" }]);

        breadcrumbs.setBreadcrumbs([{ label: "Second" }]);

        expect(breadcrumbs.crumbs.value).toEqual([{ label: "Second" }]);
    });

    it("clears on an empty array, which is how the router resets it per navigation", () => {
        breadcrumbs.setBreadcrumbs([{ label: "First" }]);

        breadcrumbs.setBreadcrumbs([]);

        expect(breadcrumbs.crumbs.value).toEqual([]);
    });

    it("shares one trail across every call site", async () => {
        const other = (await import("../useBreadcrumbs.ts")).useBreadcrumbs();

        other.setBreadcrumbs([{ label: "Set from elsewhere" }]);

        expect(breadcrumbs.crumbs.value).toEqual([{ label: "Set from elsewhere" }]);
        expect(breadcrumbs.crumbs).toBe(other.crumbs);
    });
});
