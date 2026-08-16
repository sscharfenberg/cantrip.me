import { expect, test } from "@playwright/test";

/******************************************************************************
 * The collection.
 *
 * Every number on these pages is arithmetic the app does over the seeded card
 * stacks — per-container totals, worth, the row count in the table footer — so
 * they are worth asserting for the same reason the deck colours were: the
 * seeder writes none of them.
 *
 * All read-only. Nothing here writes, so these tests are safe to run beside any
 * other spec in any worker.
 *****************************************************************************/

/** The container ids `E2ESeeder` pins, so a spec can deep-link to one. */
const DECKBOX = "0198a1b2-0000-7000-8000-000000000202";

test("lists every card stack in the collection", async ({ page }) => {
    await page.goto("/collection");

    const table = page.getByRole("table");

    /*
     * Eight rows, which is the whole fixture — the footer's own count, so a
     * silently paginated or filtered table cannot satisfy it.
     */
    await expect(page.getByText("1–8 / 8")).toBeVisible();
    await expect(table.getByRole("row").filter({ hasText: "Dark Ritual" })).toContainText("Trade Binder");
    await expect(table.getByRole("row").filter({ hasText: "Forest" })).toContainText("Atraxa Deckbox");
});

test("totals each container from the stacks inside it", async ({ page }) => {
    await page.goto("/containers");

    await expect(page.getByText("Zeige 3 / 3 Container.")).toBeVisible();

    /*
     * 7, 11 and 3 are sums of the `amount` column, not stored anywhere — the
     * binder holds 2 + 1 + 4 and the deckbox 1 + 1 + 1 + 8. Three different
     * numbers, so a total that ignored `amount` and counted rows (3, 4, 1) would
     * be wrong for all three.
     */
    for (const [name, cards] of [
        ["Trade Binder", "7"],
        ["Atraxa Deckbox", "11"],
        ["Sealed Display", "3"]
    ] as const) {
        await expect(page.getByRole("listitem").filter({ hasText: name })).toContainText(cards);
    }
});

test("filters the container list as you type", async ({ page }) => {
    await page.goto("/containers");

    /*
     * "Trade" rather than "Deckbox": the latter is both a container's name and a
     * TYPE label rendered on every card, so it would prove nothing about the
     * filter. The assertion is the pair — one stays, the others go.
     */
    await page.locator("#container-search").fill("Trade");

    await expect(page.getByText("Zeige 1 / 3 Container.")).toBeVisible();
    await expect(page.getByRole("listitem").filter({ hasText: "Trade Binder" })).toBeVisible();
    await expect(page.getByRole("listitem").filter({ hasText: "Sealed Display" })).toBeHidden();
});

test("shows only its own cards inside a container", async ({ page }) => {
    await page.goto(`/containers/${DECKBOX}`);

    await expect(page.getByRole("heading", { name: /Atraxa Deckbox/u })).toBeVisible();

    /*
     * Counted through the TABLE's rows rather than looked up as page text: a
     * card name appears more than once on this page (the row, and the preview
     * the row links to), so a bare `getByText` is a strict-mode violation.
     *
     * The second assertion is the one with teeth. Lightning Bolt is in the
     * collection but in the DISPLAY, so a container page that quietly listed
     * every stack the user owns would pass the first line and fail this one.
     */
    const rows = page.getByRole("table").getByRole("row");

    await expect(rows.filter({ hasText: "Swords to Plowshares" })).toHaveCount(1);
    await expect(rows.filter({ hasText: "Lightning Bolt" })).toHaveCount(0);
});
