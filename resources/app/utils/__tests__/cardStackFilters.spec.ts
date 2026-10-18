// @vitest-environment node

import { describe, expect, it } from "vitest";
import { activeFilterCount, applyProxyPriceRule, parsePrice } from "../cardStackFilters.ts";

describe("parsePrice", () => {
    it("reads an empty field as no bound", () => {
        expect(parsePrice("")).toBeNull();
        expect(parsePrice("   ")).toBeNull();
    });

    it("accepts a decimal comma as well as a point", () => {
        expect(parsePrice("1,50")).toBe("1.50");
        expect(parsePrice("1.50")).toBe("1.50");
        expect(parsePrice(" 12 ")).toBe("12");
        expect(parsePrice(",5")).toBe(".5");
    });

    it("leaves anything that is not a non-negative number unsent", () => {
        expect(parsePrice("-1")).toBeUndefined();
        expect(parsePrice("abc")).toBeUndefined();
        expect(parsePrice("1,5,0")).toBeUndefined();
        expect(parsePrice("1e3")).toBeUndefined();
    });
});

describe("activeFilterCount", () => {
    it("counts each filter once", () => {
        expect(activeFilterCount(null)).toBe(0);
        expect(activeFilterCount({ lang: "de", proxy: "none" })).toBe(2);
    });

    it("does not count the color match as a filter of its own", () => {
        expect(activeFilterCount({ colors: "WU", color_mode: "exact" })).toBe(1);
    });
});

describe("applyProxyPriceRule", () => {
    it("switches proxies off when the maximum price drops to 1 or less", () => {
        expect(applyProxyPriceRule(null, { max_price: "0.5" })).toEqual({ max_price: "0.5", proxy: "none" });
        expect(applyProxyPriceRule({ proxy: "only" }, { max_price: "1" })).toEqual({ max_price: "1", proxy: "none" });
    });

    it("leaves proxies alone for a maximum above 1, or no maximum", () => {
        expect(applyProxyPriceRule(null, { max_price: "1.01" })).toEqual({ max_price: "1.01" });
        expect(applyProxyPriceRule({ max_price: "0.5", proxy: "none" }, { max_price: null })).toEqual({
            max_price: null
        });
    });

    it("does not re-send proxies off when they already are", () => {
        expect(applyProxyPriceRule({ proxy: "none" }, { max_price: "0.5" })).toEqual({ max_price: "0.5" });
    });

    it("ignores the minimum price", () => {
        expect(applyProxyPriceRule(null, { min_price: "0.5" })).toEqual({ min_price: "0.5" });
    });

    it("clears a maximum of 1 or less when proxies are asked for again", () => {
        const current = { max_price: "0.5", proxy: "none" };

        expect(applyProxyPriceRule(current, { proxy: "only" })).toEqual({ proxy: "only", max_price: null });
        expect(applyProxyPriceRule(current, { proxy: null })).toEqual({ proxy: null, max_price: null });
    });

    it("keeps a maximum above 1 when proxies are asked for", () => {
        expect(applyProxyPriceRule({ max_price: "5", proxy: "none" }, { proxy: "only" })).toEqual({ proxy: "only" });
    });

    it("lets the proxy choice win over a maximum flushed along with it", () => {
        expect(applyProxyPriceRule({ proxy: "none" }, { max_price: "0.5", proxy: "only" })).toEqual({
            max_price: null,
            proxy: "only"
        });
    });

    it("leaves unrelated changes untouched", () => {
        expect(applyProxyPriceRule({ max_price: "0.5", proxy: "none" }, { lang: "de" })).toEqual({ lang: "de" });
    });
});
