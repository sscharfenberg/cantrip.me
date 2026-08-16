import { afterEach, describe, expect, it } from "vitest";
import { intersectionObservers } from "@/test/observers.ts";
import { withSetup } from "@/test/withSetup.ts";
import { useStickyNav } from "../useStickyNav.ts";

const SECTIONS = ["stats", "legality", "cards"];

/** Put the section anchors in the document so `getElementById` finds them. */
const renderSections = (ids: string[] = SECTIONS): HTMLElement[] =>
    ids.map(id => {
        const element = document.createElement("section");
        element.id = id;
        document.body.appendChild(element);
        return element;
    });

/**
 * Mount the composable with a sentinel bound, as the template does.
 *
 * Two observers are created on mount, in this order: the sentinel watcher that
 * drives `isStuck`, then the section watcher that drives `activeSection`.
 */
const mountNav = (sections: string[] = SECTIONS) => {
    const sentinelEl = document.createElement("div");
    const [nav, app] = withSetup(() => {
        const result = useStickyNav(sections);
        result.sentinel.value = sentinelEl;
        return result;
    });

    return {
        nav,
        app,
        sentinelEl,
        sentinelObserver: intersectionObservers[0],
        sectionObserver: intersectionObservers[1]
    };
};

afterEach(() => {
    document.body.innerHTML = "";
});

describe("useStickyNav — initial state", () => {
    it("starts unstuck with no active section", () => {
        const { nav } = mountNav();

        expect(nav.isStuck.value).toBe(false);
        expect(nav.activeSection.value).toBeNull();
    });

    it("watches the sentinel and every section element", () => {
        const elements = renderSections();
        const { sentinelObserver, sectionObserver, sentinelEl } = mountNav();

        expect(sentinelObserver.targets).toEqual([sentinelEl]);
        expect(sectionObserver.targets).toEqual(elements);
    });

    it("skips sections whose element is not in the document", () => {
        renderSections(["stats"]);
        const { sectionObserver } = mountNav(["stats", "not-rendered"]);

        expect(sectionObserver.targets).toHaveLength(1);
    });

    it("does not observe a sentinel that was never bound", () => {
        const [, app] = withSetup(() => useStickyNav([]));

        expect(intersectionObservers[0].targets).toEqual([]);
        app.unmount();
    });
});

describe("useStickyNav — isStuck", () => {
    it("becomes stuck once the sentinel scrolls out of view", () => {
        // CSS `position: sticky` gives no event of its own; the sentinel
        // leaving the viewport is the proxy for the nav having stuck.
        const { nav, sentinelObserver, sentinelEl } = mountNav();

        sentinelObserver.trigger([{ target: sentinelEl, isIntersecting: false }]);

        expect(nav.isStuck.value).toBe(true);
    });

    it("unsticks again when the sentinel comes back", () => {
        const { nav, sentinelObserver, sentinelEl } = mountNav();
        sentinelObserver.trigger([{ target: sentinelEl, isIntersecting: false }]);

        sentinelObserver.trigger([{ target: sentinelEl, isIntersecting: true }]);

        expect(nav.isStuck.value).toBe(false);
    });

    it("only reacts at full visibility, avoiding half-stuck flicker", () => {
        expect(mountNav().sentinelObserver.options?.threshold).toEqual([1]);
    });
});

describe("useStickyNav — activeSection", () => {
    it("activates a section as it enters the detection band", () => {
        const [stats] = renderSections();
        const { nav, sectionObserver } = mountNav();

        sectionObserver.trigger([{ target: stats, isIntersecting: true }]);

        expect(nav.activeSection.value).toBe("stats");
    });

    it("keeps the last section that entered, not the last one reported", () => {
        // Scrolling emits both the leaver and the enterer in one callback, and
        // the leaver comes last here — so only an implementation that checks
        // `isIntersecting` lands on the right section.
        const [stats, legality] = renderSections();
        const { nav, sectionObserver } = mountNav();
        sectionObserver.trigger([{ target: stats, isIntersecting: true }]);

        sectionObserver.trigger([
            { target: legality, isIntersecting: true },
            { target: stats, isIntersecting: false }
        ]);

        expect(nav.activeSection.value).toBe("legality");
    });

    it("leaves the highlight in place when some other section merely leaves", () => {
        // The leaver is deliberately not the active section: re-assigning it
        // would be visible here, where re-assigning the active one would not.
        const [stats, legality] = renderSections();
        const { nav, sectionObserver } = mountNav();
        sectionObserver.trigger([{ target: stats, isIntersecting: true }]);

        sectionObserver.trigger([{ target: legality, isIntersecting: false }]);

        expect(nav.activeSection.value).toBe("stats");
    });

    it("watches only the top of the viewport", () => {
        // The band is the top 30%: a section counts as active once its top
        // edge crosses it, whether the user scrolled or clicked a jump link.
        const { sectionObserver } = mountNav();

        expect(sectionObserver.options).toMatchObject({ rootMargin: "0px 0px -70% 0px", threshold: 0 });
    });
});

describe("useStickyNav — teardown", () => {
    it("disconnects both observers when the component unmounts", () => {
        renderSections();
        const { app, sentinelObserver, sectionObserver } = mountNav();

        app.unmount();

        expect(sentinelObserver.disconnected).toBe(true);
        expect(sectionObserver.disconnected).toBe(true);
    });
});
