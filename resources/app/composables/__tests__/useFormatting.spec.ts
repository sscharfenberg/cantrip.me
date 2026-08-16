import { beforeEach, describe, expect, it, vi } from "vitest";
import { createTestI18n } from "@/test/i18n.ts";
import { setPageProps } from "@/test/inertia.ts";
import { withSetup } from "@/test/withSetup.ts";
import type { UseFormattingReturn } from "../useFormatting.ts";
import { useFormatting } from "../useFormatting.ts";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

/** Run the composable under a given app locale. */
const formattingIn = (locale: "de" | "en"): UseFormattingReturn => {
    const i18n = createTestI18n();
    (i18n.global as unknown as { locale: { value: string } }).locale.value = locale;
    const [formatting] = withSetup(() => useFormatting(), [i18n]);
    return formatting;
};

beforeEach(() => {
    setPageProps({ currency: "eur" });
});

describe("formatDecimals", () => {
    it("uses German grouping and decimal separators", () => {
        expect(formattingIn("de").formatDecimals(1234567.5)).toBe("1.234.567,5");
    });

    it("uses English grouping and decimal separators", () => {
        expect(formattingIn("en").formatDecimals(1234567.5)).toBe("1,234,567.5");
    });

    it("leaves small integers alone", () => {
        expect(formattingIn("de").formatDecimals(0)).toBe("0");
        expect(formattingIn("en").formatDecimals(42)).toBe("42");
    });
});

describe("formatBytes", () => {
    it("returns raw bytes below the unit threshold", () => {
        const { formatBytes } = formattingIn("en");

        expect(formatBytes(0)).toBe("0 B");
        expect(formatBytes(1023)).toBe("1023 B");
    });

    it("defaults to binary units", () => {
        const { formatBytes } = formattingIn("en");

        expect(formatBytes(1024)).toBe("1.0 KiB");
        expect(formatBytes(1024 ** 2)).toBe("1.0 MiB");
        expect(formatBytes(1024 ** 3)).toBe("1.0 GiB");
    });

    it("switches to metric units on request", () => {
        const { formatBytes } = formattingIn("en");

        expect(formatBytes(1000, true)).toBe("1.0 kB");
        expect(formatBytes(1_500_000, true)).toBe("1.5 MB");
    });

    it("honours the requested decimal places", () => {
        const { formatBytes } = formattingIn("en");

        expect(formatBytes(1536, false, 0)).toBe("2 KiB");
        expect(formatBytes(1536, false, 3)).toBe("1.500 KiB");
    });

    it("steps up a unit when rounding would otherwise print the threshold itself", () => {
        // 1048000 bytes is 1023.4 KiB, which rounds to 1023.4 — stays KiB.
        // 1048570 is 1023.99 KiB, which would round to 1024.0 KiB; the loop
        // promotes it to MiB instead.
        const { formatBytes } = formattingIn("en");

        expect(formatBytes(1_048_000)).toBe("1023.4 KiB");
        expect(formatBytes(1_048_570)).toBe("1.0 MiB");
    });

    it("handles negative sizes symmetrically", () => {
        const { formatBytes } = formattingIn("en");

        expect(formatBytes(-512)).toBe("-512 B");
        expect(formatBytes(-1024)).toBe("-1.0 KiB");
    });

    it("caps at the largest unit it knows", () => {
        const { formatBytes } = formattingIn("en");

        expect(formatBytes(1024 ** 9)).toContain("YiB");
    });
});

describe("formatPrice", () => {
    it("formats euros the German way", () => {
        const formatted = formattingIn("de").formatPrice(125.56);

        // Asserted piecewise: ICU puts a non-breaking space before the symbol,
        // and which space it uses has changed between ICU releases.
        expect(formatted).toContain("125,56");
        expect(formatted).toContain("€");
    });

    it("formats dollars the English way", () => {
        setPageProps({ currency: "usd" });

        expect(formattingIn("en").formatPrice(125.56)).toBe("$125.56");
    });

    it("reads the currency from the user's Inertia shared props", () => {
        setPageProps({ currency: "usd" });
        const inDollars = formattingIn("en").formatPrice(10);

        setPageProps({ currency: "eur" });
        const inEuros = formattingIn("en").formatPrice(10);

        expect(inDollars).toContain("$");
        expect(inEuros).toContain("€");
    });

    it("falls back to euros when no currency is shared", () => {
        setPageProps({});

        expect(formattingIn("de").formatPrice(10)).toContain("€");
    });

    it("always shows two decimals, so prices line up in a column", () => {
        setPageProps({ currency: "usd" });

        expect(formattingIn("en").formatPrice(10)).toBe("$10.00");
        expect(formattingIn("en").formatPrice(1234.5)).toBe("$1,234.50");
    });
});

describe("reactivity", () => {
    it("reads the locale on every call, so a language switch re-formats", () => {
        // Template computeds depend on this: hoisting the locale read to
        // composable-creation time would freeze the formatting of any
        // long-lived layout component at whatever locale it mounted with.
        const i18n = createTestI18n();
        const locale = (i18n.global as unknown as { locale: { value: string } }).locale;
        locale.value = "de";
        const [formatting] = withSetup(() => useFormatting(), [i18n]);

        expect(formatting.formatDecimals(1234.5)).toBe("1.234,5");

        locale.value = "en";

        expect(formatting.formatDecimals(1234.5)).toBe("1,234.5");
    });

    it("reads the currency on every call, so an Inertia navigation is picked up", () => {
        const formatting = formattingIn("en");
        expect(formatting.formatPrice(10)).toContain("€");

        setPageProps({ currency: "usd" });

        expect(formatting.formatPrice(10)).toContain("$");
    });
});

describe("formatDate and formatDateTime", () => {
    // Midday UTC, so the calendar date is the same in every timezone the app
    // is realistically read in — these assertions must not depend on the
    // machine's TZ.
    const NOON = "2026-04-02T12:00:00+00:00";

    it("formats a date the German way", () => {
        expect(formattingIn("de").formatDate(NOON)).toBe("02.04.2026");
    });

    it("formats a date the English way", () => {
        expect(formattingIn("en").formatDate(NOON)).toBe("04/02/2026");
    });

    it("pads single-digit days and months", () => {
        expect(formattingIn("de").formatDate("2026-01-05T12:00:00+00:00")).toBe("05.01.2026");
    });

    it("adds the time in formatDateTime, and only there", () => {
        const { formatDate, formatDateTime } = formattingIn("de");

        expect(formatDate(NOON)).not.toMatch(/\d{2}:\d{2}/);
        expect(formatDateTime(NOON)).toMatch(/02\.04\.2026.*\d{2}:\d{2}/);
    });
});
