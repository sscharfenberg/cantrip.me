import { usePage } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";

export type UseFormattingReturn = {
    formatDecimals: (num: number) => string;
    formatBytes: (bytes: number, si?: boolean, dp?: number) => string;
    formatPrice: (amount: number, currency?: string) => string;
    formatDate: (iso: string) => string;
    formatDateTime: (iso: string) => string;
};

/**
 * Composable that exposes number and byte formatting helpers.
 *
 * Reads the active vue-i18n locale so all output matches the user's selected
 * language without any hardcoded locale strings.
 */
export const useFormatting = (): UseFormattingReturn => {
    const { locale } = useI18n();

    /**
     * Format a decimal number as a locale-aware string.
     *
     * @param num - The number to format.
     * @return Formatted string using the active app locale.
     */
    const formatDecimals = (num: number): string => {
        return num.toLocaleString(locale.value, { style: "decimal" });
    };

    /**
     * Format a byte count as a human-readable string.
     *
     * @param bytes - Number of bytes.
     * @param si    - True to use metric (SI) units (powers of 1000).
     *               False to use binary (IEC) units (powers of 1024).
     * @param dp    - Number of decimal places to display.
     * @return Formatted string with the appropriate unit suffix.
     */
    const formatBytes = (bytes: number, si = false, dp = 1): string => {
        const thresh = si ? 1000 : 1024;

        if (Math.abs(bytes) < thresh) {
            return bytes + " B";
        }

        const units = si
            ? ["kB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB"]
            : ["KiB", "MiB", "GiB", "TiB", "PiB", "EiB", "ZiB", "YiB"];
        let u = -1;
        const r = 10 ** dp;

        do {
            bytes /= thresh;
            ++u;
        } while (Math.round(Math.abs(bytes) * r) / r >= thresh && u < units.length - 1);

        return bytes.toFixed(dp) + " " + units[u];
    };

    /**
     * Format a monetary amount using the user's selected currency and locale.
     *
     * @param amount - The numeric amount to format.
     * @param currency - A currency code to format in instead (`"usd"`), for a
     *   price that is in its own currency whatever the user's — a market's.
     * @return Formatted currency string (e.g. "125,56 €" or "$125.56").
     */
    const formatPrice = (amount: number, currency: string = (usePage().props.currency as string) ?? "eur"): string => {

        return amount.toLocaleString(locale.value, {
            style: "currency",
            currency: currency.toUpperCase()
        });
    };

    /**
     * Format an ISO 8601 date string as a short locale-aware date.
     *
     * @param iso - ISO 8601 date string (e.g. "2026-04-02T07:25:01+00:00").
     * @return Formatted date string (e.g. "02.04.2026" or "4/2/2026").
     */
    const formatDate = (iso: string): string => {
        return new Date(iso).toLocaleDateString(locale.value, {
            year: "numeric",
            month: "2-digit",
            day: "2-digit"
        });
    };

    /**
     * Format an ISO 8601 timestamp as a locale-aware date and time string.
     *
     * @param iso - ISO 8601 timestamp (e.g. "2026-04-02T07:25:01+00:00").
     * @return Formatted date-time string (e.g. "02.04.2026, 07:25" or "4/2/2026, 7:25 AM").
     */
    const formatDateTime = (iso: string): string => {
        return new Date(iso).toLocaleString(locale.value, {
            year: "numeric",
            month: "2-digit",
            day: "2-digit",
            hour: "2-digit",
            minute: "2-digit"
        });
    };

    return { formatDecimals, formatBytes, formatPrice, formatDate, formatDateTime };
};
