import type { CardStackFilterPatch } from "Types/cardStackFilters";

/**
 * Every query parameter the card-stack filters own. "Clear filters" removes
 * all of them; `color_mode` is listed but never counted as a filter on its
 * own, since it only qualifies `colors`.
 */
export const FILTER_KEYS = [
    "min_price",
    "max_price",
    "proxy",
    "lang",
    "condition",
    "finish",
    "rarity",
    "set",
    "type",
    "colors",
    "color_mode",
    "reserved",
    "container",
    "claimed"
] as const;

/**
 * A maximum price at or below this is a hunt for cheap real cards, which a
 * proxy (worth 0) would only clutter — so setting one switches proxies off,
 * and asking for proxies again drops the maximum.
 */
export const PROXY_EXCLUDING_MAX_PRICE = 1;

/** Colors in WUBRG order; colorless (`C`) is exclusive and handled apart. */
export const COLORS = ["W", "U", "B", "R", "G"] as const;

export const COLORLESS = "C";

/**
 * The number of active filters, for the accordion head. `color_mode` does
 * not count: it is part of the color filter, not a filter of its own.
 */
export const activeFilterCount = (filters: Record<string, unknown> | null): number =>
    filters ? Object.keys(filters).filter(key => key !== "color_mode").length : 0;

/**
 * Parse what the user typed into a price field: `null` for an empty field,
 * the price as a plain decimal string ("1,50" → "1.50") when it is a
 * non-negative number, `undefined` when it is neither — a half-typed or
 * mistyped value that should not be sent.
 */
export const parsePrice = (input: string): string | null | undefined => {
    const trimmed = input.trim().replace(",", ".");
    if (trimmed === "") return null;
    if (!/^\d+(\.\d*)?$|^\.\d+$/u.test(trimmed)) return undefined;
    return trimmed;
};

/** Whether a price value is a maximum that switches proxies off. */
const excludesProxies = (maxPrice: string | null | undefined): boolean =>
    maxPrice !== null && maxPrice !== undefined && Number(maxPrice) <= PROXY_EXCLUDING_MAX_PRICE;

/**
 * Apply the cheap-cards rule to a filter change before it is sent:
 *
 *  - asking for proxies (`All` or `Only`) while the maximum price is at or
 *    below {@link PROXY_EXCLUDING_MAX_PRICE} clears the maximum;
 *  - otherwise, setting such a maximum switches proxies to `None`.
 *
 * Asking for proxies wins when one change does both — the proxy choice is
 * what the user clicked; a maximum in the same change was typed earlier and
 * merely flushed along with it.
 *
 * @param current - The filters the table shows now.
 * @param patch - The change to send.
 * @returns The patch, with the rule's consequence added.
 */
export const applyProxyPriceRule = (
    current: Record<string, unknown> | null,
    patch: CardStackFilterPatch
): CardStackFilterPatch => {
    const maxPrice = "max_price" in patch ? patch.max_price : (current?.max_price as string | undefined);

    if ("proxy" in patch && patch.proxy !== "none") {
        return excludesProxies(maxPrice) ? { ...patch, max_price: null } : patch;
    }

    const proxy = "proxy" in patch ? patch.proxy : (current?.proxy as string | undefined);
    if ("max_price" in patch && excludesProxies(maxPrice) && proxy !== "none") {
        return { ...patch, proxy: "none" };
    }

    return patch;
};
