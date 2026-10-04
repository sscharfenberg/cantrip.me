import { expect, test } from "@playwright/test";

/******************************************************************************
 * The deck list import (`/decks/import-list`).
 *
 * Vitest pins the review logic against a mocked parse response and PHPUnit pins
 * the resolver against SQLite. What only the real app can answer is the whole
 * path at once: a paste going through the real parser and resolver on MariaDB
 * (the name ranking the search shares is MariaDB-only SQL), the deck-less
 * replacement search, the existing commander picker working before any deck
 * exists, and the confirm creating a deck the deck page then shows.
 *
 * WRITES ONE DECK, "Krenko Paste Import", in the Commander folder. No other
 * spec counts the decks in that folder or reads a deck by that name, and the
 * folder stays the busiest one, so it still opens by default.
 *
 * The paste is shaped like Moxfield's bulk edit: no headers, and the commander
 * listed among the 99 — so the commander has to be picked, and its copy in the
 * list must not be imported a second time.
 *****************************************************************************/

const DECK_NAME = "Krenko Paste Import";

const PASTE = ["1 Krenko, Mob Boss", "1 Sol Ring", "1 Arcane Signet", "4 Lightnig Bolt", "30 Mountain"].join("\n");

test("a pasted list becomes a deck once its typo is fixed and its commander picked", async ({ page }) => {
    await page.goto("/decks");
    await page.getByRole("link", { name: "Deckliste einfügen" }).click();
    await expect(page).toHaveURL(/\/decks\/import-list$/u);

    await page.locator(".form-select__button").click();
    await page.locator('[role="option"][data-value="commander"]').click();
    await page.locator("#deck_name").fill(DECK_NAME);
    await page.locator("#deck_list").fill(PASTE);
    await page.getByRole("button", { name: "Deckliste prüfen" }).click();

    /* The misspelt line blocks the import until it gets a card. */
    const confirm = page.getByRole("button", { name: "Deck anlegen" });
    const typo = page.locator(".review-line").filter({ hasText: "Lightnig Bolt" });
    await expect(typo).toContainText('Keine Karte namens "Lightnig Bolt" gefunden.');
    await expect(confirm).toBeDisabled();

    /* The search opens pre-filled with the pasted name; the typo sinks the
       whole query, so it finds the card through the single word "bolt". */
    await expect(typo.getByRole("searchbox", { name: "Nach einer Karte suchen" })).toHaveValue("Lightnig Bolt");
    await typo.locator(".candidate-list__row").filter({ hasText: "Lightning Bolt" }).click();
    await expect(page.locator(".review-line").filter({ hasText: "Ersetzt" })).toContainText("Lightning Bolt");

    /* Still blocked: a Commander deck needs its command zone. */
    await expect(confirm).toBeDisabled();
    await page.getByRole("button", { name: "Command Zone auswählen" }).click();
    await page.locator("#commander_id").fill("Krenko");
    await page.locator(".commander-picker__commander").filter({ hasText: "Krenko, Mob Boss" }).click();
    await page.getByRole("button", { name: "Command Zone bestätigen" }).click();

    /* The commander's copy among the 99 is taken out, not imported twice. */
    await expect(page.locator(".review-line").filter({ hasText: "Krenko, Mob Boss" })).toContainText(
        "Ein Exemplar liegt in der Kommandozone"
    );
    await expect(page.locator(".deck-list-review")).toContainText("Hauptdeck: 37 Karten");

    await confirm.click();

    await expect(page).toHaveURL(/\/decks\/[0-9a-f-]{36}$/u);
    await expect(page.locator(".deck-meta__name")).toContainText(DECK_NAME.toUpperCase());
    /* One Krenko, in the command zone — its copy among the 99 was not imported. */
    for (const [card, quantity] of [
        ["Krenko, Mob Boss", "1x"],
        ["Sol Ring", "1x"],
        ["Arcane Signet", "1x"],
        ["Lightning Bolt", "4x"],
        ["Mountain", "30x"]
    ]) {
        const rows = page.getByRole("listitem").filter({ hasText: card });
        await expect(rows).toHaveCount(1);
        await expect(rows).toContainText(`${quantity} ${card}`);
    }
});
