import { expect, test } from "@playwright/test";

/******************************************************************************
 * The deck list.
 *
 * Also the proof that the committed Scryfall snapshot reaches the UI at all:
 * every deck row's colour pips, card count and worth are computed from the
 * seeded oracle rows and printings, so a broken fixture cannot render this page
 * correctly by accident.
 *****************************************************************************/

/**
 * A deck row, matched on the part of its accessible name that identifies it.
 *
 * The whole row is one link, so its accessible name is everything inside —
 * colour pips, name, state, card count, worth, and the actions button. Matching
 * a substring rather than the whole string keeps these tests from breaking every
 * time a badge is added, while still being specific enough to name one deck.
 */
const deckRow = (name: string) => ({ name: new RegExp(name, "u") });

/** A format folder's toggle. The count is part of its label, so match the prefix. */
const formatFolder = (format: string) => ({ name: new RegExp(`^${format}\\b`, "u") });

test("groups the seeded decks into one folder per format", async ({ page }) => {
    await page.goto("/decks");

    await expect(page.getByRole("heading", { name: "Deine Decks" })).toBeVisible();

    /*
     * Commander opens on load because it is the BUSIEST format — two seeded
     * decks against Legacy's one — and only one folder is open at a time. So
     * this asserts both halves: what is inside the open folder, and that the
     * other format's deck is not on the page yet. Asserting only the first half
     * would pass just as well if every folder were open.
     */
    await expect(page.getByRole("link", deckRow("Atraxa Superfriends"))).toBeVisible();
    await expect(page.getByRole("link", deckRow("Yoshi and Rograkh"))).toBeVisible();
    await expect(page.getByRole("link", deckRow("Legacy Burn"))).toBeHidden();
});

test("opens a format folder and closes the one that was open", async ({ page }) => {
    await page.goto("/decks");
    await expect(page.getByRole("link", deckRow("Atraxa Superfriends"))).toBeVisible();

    /*
     * By ROLE, not by text. "Legacy" also appears in the format breakdown of the
     * deck-stats panel above, so `getByText("Legacy")` is a strict-mode
     * violation — and one that only shows up once a second format exists.
     */
    await page.getByRole("button", formatFolder("Legacy")).click();

    await expect(page.getByRole("link", deckRow("Legacy Burn"))).toBeVisible();
    /* The single-open rule: opening one folder closes the other. */
    await expect(page.getByRole("link", deckRow("Atraxa Superfriends"))).toBeHidden();
});

test("labels each deck with the colour identity computed from its commander", async ({ page }) => {
    await page.goto("/decks");

    /*
     * The pips are `<img>`s with the colour letter as their alt text, so they
     * are part of the row's accessible name and can be asserted in order.
     *
     * This is the fixture's own arithmetic coming back rather than decoration:
     * the seeder never writes `decks.colors`, `DeckService` computes it from the
     * command zone's `color_identity`. WUBG here therefore means the real Atraxa
     * oracle row was loaded and read — and the second assertion is what makes it
     * non-vacuous, since a stuck or hard-coded value would have to be wrong for
     * one of the two.
     */
    await expect(page.getByRole("link", deckRow("^W U B G Atraxa Superfriends"))).toBeVisible();
    await expect(page.getByRole("link", deckRow("^W R Yoshi and Rograkh"))).toBeVisible();
});

test("opens a deck from the list", async ({ page }) => {
    await page.goto("/decks");

    await page.getByRole("link", deckRow("Atraxa Superfriends")).click();

    await expect(page).toHaveURL(/\/decks\/[0-9a-f-]{36}$/u);
    await expect(page).toHaveTitle("cantrip.me: Deck: Atraxa Superfriends");
    /*
     * The deck's own name is a `<header class="deck-meta__name">` rather than a
     * heading, and the template upper-cases it in JavaScript — so the DOM really
     * does hold "ATRAXA SUPERFRIENDS", and a case-insensitive matcher would hide
     * that rather than assert it.
     *
     * `toContainText`, not `toHaveText`, because the deck-actions popover lives
     * inside that same `<header>`: its menu items are in the DOM (hidden) and
     * count towards the element's text.
     */
    await expect(page.locator(".deck-meta__name")).toContainText("ATRAXA SUPERFRIENDS");
});
