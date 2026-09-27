import { readFile } from "node:fs/promises";
import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";

/******************************************************************************
 * The printable deck list (`/decks/{deck}/print`).
 *
 * Vitest already pins the text layout against a mocked payload. What only the
 * real app can answer is whether the route, the controller's lean payload and
 * the page agree with each other and with the database — that the deck-menu
 * entry lands on a page whose text names the real seeded printings, grouped
 * the way the deck page groups them — and whether a real browser actually
 * hands over a file and runs the print path.
 *
 * READ-ONLY. Every test here reads "Legacy Burn" and writes nothing; editing
 * the textarea is page-local and never reaches the server. Legacy Burn is the
 * one seeded deck with a real sideboard and quantities above one, which is
 * what gives "sideboard printed separately" and the amount column something to
 * be wrong about.
 *
 * BUT IT IS SOMEONE ELSE'S WRITE LANE. `collection-integration.spec.ts` adds
 * ONE Counterspell to its mainboard, and `fullyParallel` may run that before or
 * after these. So the assertions tolerate exactly that write and nothing more:
 * the mainboard count may be 24 or 25 and the instant group 4 or 5, while the
 * lands and the sideboard — which that write never touches — are pinned
 * exactly. Lightning Bolt stays the instant group's first line either way,
 * because the default mana sort puts its one mana ahead of Counterspell's two.
 *****************************************************************************/

const DIVIDER = "=".repeat(40);
const RULE = "-".repeat(40);

/**
 * The card half of the text. The seeder takes each card's OLDEST
 * printing in the snapshot, which is Alpha for all three — Lightning Bolt has
 * LEA, LEB and 2ED, so a payload that picked the wrong printing reads
 * differently here. Lands go last among the mainboard groups; the sideboard
 * follows behind its own major divider.
 */
const EXPECTED_INSTANTS = new RegExp(`\\nSpontanzauber \\([45]\\)\\n${RULE}\\n4 Lightning Bolt \\(LEA\\) 161\\n`, "u");
const EXPECTED_TAIL = [
    "Länder (20)",
    RULE,
    "20 Mountain (LEA) 292",
    "",
    DIVIDER,
    "",
    "Sideboard (2)",
    RULE,
    "2 Swords to Plowshares (LEA) 40",
    ""
].join("\n");

/**
 * Reach the print page the way a user does: deck list → Legacy folder → deck →
 * deck menu. The list opens one format folder at a time and Commander is the
 * one open by default, so the Legacy folder has to be opened first.
 */
const openPrintPage = async (page: Page): Promise<void> => {
    await page.goto("/decks");
    await page.getByRole("button", { name: /^Legacy\b/u }).click();
    await page.getByRole("link", { name: /Legacy Burn/u }).click();
    await expect(page.locator(".deck-meta__name")).toContainText("LEGACY BURN");

    await page.getByRole("button", { name: "Deck-Aktionen" }).click();
    await page.getByRole("button", { name: "Druckbares Deck anzeigen" }).click();

    await expect(page).toHaveURL(/\/decks\/[0-9a-f-]{36}\/print$/u);
};

const deckList = (page: Page) => page.getByRole("textbox", { name: "Deckliste" });

test("the deck menu opens a plain-text list of the deck", async ({ page }) => {
    await openPrintPage(page);

    await expect(page).toHaveTitle(/Druckbare Deckliste: Legacy Burn/u);
    const text = await deckList(page).inputValue();

    expect(text.startsWith(`Legacy Burn\n${DIVIDER}\nZiel ist der Kopf.\n${DIVIDER}\n`)).toBe(true);
    expect(text).toMatch(/\nFormat: Legacy\nStatus: Geplant\nKarten: 2[45] Maindeck-Karten, 2 Sideboard-Karten\n/u);
    expect(text).toMatch(EXPECTED_INSTANTS);
    /* Lands last among the mainboard groups, then the sideboard on its own. */
    expect(text.endsWith(`\n\n${EXPECTED_TAIL}`)).toBe(true);
});

test("downloads what is in the box, edits included, as <deck name>.txt", async ({ page }) => {
    await openPrintPage(page);
    /*
     * Edited first, so the file cannot match by coming from the payload
     * instead of the textarea.
     */
    await deckList(page).fill("4 Lightning Bolt\n");

    const [download] = await Promise.all([
        page.waitForEvent("download"),
        page.getByRole("button", { name: "Als Textdatei herunterladen" }).click()
    ]);

    expect(download.suggestedFilename()).toBe("Legacy Burn.txt");
    expect(await readFile(await download.path(), "utf8")).toBe("4 Lightning Bolt\n");
});

test("prints the text alone, from a frame of its own", async ({ page }) => {
    /*
     * `print()` is stubbed in EVERY frame — init scripts run in the throwaway
     * iframe too — and records what that frame would have printed. So the
     * assertion is about the frame the app built, not about the page: printing
     * the whole page would record the page's text, not the list.
     */
    await page.addInitScript(() => {
        window.print = () => {
            const top = window.top as Window & { printed?: string[] };
            (top.printed ??= []).push(document.body.innerText);
            window.dispatchEvent(new Event("afterprint"));
        };
    });
    await openPrintPage(page);

    await page.getByRole("button", { name: "Drucken" }).click();

    const printed = await page.evaluate(() => (window as Window & { printed?: string[] }).printed ?? []);
    expect(printed).toHaveLength(1);
    expect(printed[0].trimEnd()).toBe((await deckList(page).inputValue()).trimEnd());
    /* And the frame is gone again once printing is over. */
    await expect(page.locator("iframe")).toHaveCount(0);
});
