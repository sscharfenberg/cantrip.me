import { expect, test } from "@playwright/test";
import type { Locator, Page } from "@playwright/test";
import { COPIES_STORAGE_STATE } from "../support/environment";

/******************************************************************************
 * Finish, language and proxy of a deck card's copies.
 *
 * A deck card plans the copies that are not in the collection yet — "Edit
 * copies" in its menu — and a copy a stack claims shows that stack instead.
 * What Vitest cannot answer is the round trip: the PATCH lands, the server
 * re-plans (splitting the row when only some copies change), the partial
 * reload brings the new rows AND the new worth, and "add all to collection"
 * really mints the stack the plan described.
 *
 * Every test writes, so the spec runs as its own account with one deck per
 * test — see `E2EDeckCopiesSeeder`, whose ids are mirrored below. Prices are
 * the snapshot's: Rhystic Study (PCY) €34.06 / foil €204.71, Evolving Wilds
 * (ROE) €0.18 / €1.01, Atraxa (C16) exists only in foil, €18.40.
 *****************************************************************************/

test.use({ storageState: COPIES_STORAGE_STATE });

const DECKS = {
    plan: "0198a1b2-0000-7000-8000-000000000501",
    addAll: "0198a1b2-0000-7000-8000-000000000502",
    commander: "0198a1b2-0000-7000-8000-000000000503",
    claimed: "0198a1b2-0000-7000-8000-000000000504"
} as const;

/** Open a deck by id and wait until its header has rendered. */
const openDeck = async (page: Page, id: string): Promise<void> => {
    await page.goto(`/decks/${id}`);
    await expect(page.locator(".deck-meta__name")).toBeVisible();
};

/** The header badge carrying the deck's total worth — the only one with a currency in it. */
const worth = (page: Page): Locator => page.locator(".deck-meta__badges .badge").filter({ hasText: "€" });

/** A card's rows in the text view, which the seeded account uses. */
const cardRows = (page: Page, name: string): Locator => page.locator("li.card").filter({ hasText: name });

/** Open a popover menu inside `scope` and return it, so entries are looked up in that menu alone. */
const openMenu = async (page: Page, trigger: string, scope: Locator): Promise<Locator> => {
    const menu = scope.locator(".popover").filter({ has: page.getByRole("button", { name: trigger, exact: true }) });
    await menu.getByRole("button", { name: trigger, exact: true }).click();

    return menu;
};

/** Open "Edit copies" from a row's menu and return the dialog. */
const editCopies = async (page: Page, row: Locator, trigger = "Actions"): Promise<Locator> => {
    await (await openMenu(page, trigger, row)).getByRole("button", { name: "Exemplare bearbeiten" }).click();
    const dialog = page.locator("dialog.modal-dialog[open]");
    await expect(dialog.locator("#deck-card-copies-form")).toBeVisible();

    return dialog;
};

test("re-planning some copies foil splits them off and re-prices the deck", async ({ page }) => {
    await openDeck(page, DECKS.plan);
    await expect(worth(page)).toContainText("136,24");
    await expect(cardRows(page, "Rhystic Study")).toHaveCount(1);
    await expect(cardRows(page, "Rhystic Study").locator(".deck-copies")).toHaveCount(0);

    const dialog = await editCopies(page, cardRows(page, "Rhystic Study"));
    await dialog.locator("#deck-card-copies-count").fill("1");
    await dialog.locator("#deck-card-copies-count").press("Tab");
    await dialog.locator('label[for="finish-foil"]').click();
    await dialog.getByRole("button", { name: "Speichern" }).click();

    /* 3 × €34.06 + 1 × €204.71 — the foil copy is priced as one. */
    await expect(worth(page)).toContainText("306,89");
    const rows = cardRows(page, "Rhystic Study");
    await expect(rows).toHaveCount(2);
    await expect(rows.filter({ hasText: "1x" }).locator(".deck-copies__marker--foil")).toBeVisible();
    await expect(rows.filter({ hasText: "3x" }).locator(".deck-copies")).toHaveCount(0);
});

test("add all to collection mints the copies as the deck planned them", async ({ page }) => {
    await openDeck(page, DECKS.addAll);
    const row = cardRows(page, "Krenko, Mob Boss");
    await expect(row.locator(".deck-copies__marker--foil")).toBeVisible();
    await expect(row.locator(".deck-copies__marker--lang-de")).toBeVisible();

    await (
        await openMenu(page, "Deck-Aktionen", page.locator("body"))
    )
        .getByRole("button", { name: "Alle Karten zur Sammlung hinzufügen" })
        .click();
    await page.getByRole("button", { name: "Alle hinzufügen" }).click();

    /* Claimed now — the markers come from the new stack and still say foil, German. */
    await expect(row.locator(".collection-status--claimed_for_this_deck")).toBeVisible();
    await expect(row.locator(".deck-copies__marker--foil")).toBeVisible();
    await expect(row.locator(".deck-copies__marker--lang-de")).toBeVisible();

    await page.goto("/collection");
    const stack = page.locator("tbody tr").filter({ hasText: "Krenko, Mob Boss" });
    await expect(stack).toHaveCount(1);
    await expect(stack.locator("svg.star")).toBeVisible();
    await expect(stack.locator('img[alt="Deutsch"]')).toBeVisible();
});

test("a foil-only commander is planned and priced as foil, and its language can change", async ({ page }) => {
    await openDeck(page, DECKS.commander);
    const commander = cardRows(page, "Atraxa, Praetors' Voice");
    await expect(commander.locator(".deck-copies__marker--foil")).toBeVisible();
    await expect(worth(page)).toContainText("18,40");

    const dialog = await editCopies(page, commander, "Commander actions");
    await expect(dialog.locator("#finish-nonfoil")).toHaveCount(0);
    await expect(dialog.locator("#deck-card-copies-count")).toHaveCount(0);
    await dialog.locator('label[for="language-ja"]').click();
    await dialog.getByRole("button", { name: "Speichern" }).click();

    await expect(commander.locator(".deck-copies__marker--lang-ja")).toBeVisible();
    await expect(commander.locator(".deck-copies__marker--foil")).toBeVisible();
});

test("editing one claimed copy foil splits its stack in the collection and re-prices the deck", async ({ page }) => {
    /* Two Evolving Wilds, both claimed by one nonfoil stack in the binder. */
    await openDeck(page, DECKS.claimed);
    await expect(worth(page)).toContainText("0,36");
    const row = cardRows(page, "Evolving Wilds");
    await expect(row.locator(".deck-copies")).toHaveCount(0);

    const dialog = await editCopies(page, row);
    /* The stack is the card's only group, so the dialog opens on it — the collection's cards. */
    await expect(dialog.getByText("Das sind Karten aus deiner Sammlung", { exact: false })).toBeVisible();
    await dialog.locator("#deck-card-copies-count").fill("1");
    await dialog.locator("#deck-card-copies-count").press("Tab");
    await dialog.locator('label[for="finish-foil"]').click();
    await dialog.getByRole("button", { name: "Speichern" }).click();

    /* €0.18 + €1.01: the claimed foil copy is priced as its stack now is. */
    await expect(worth(page)).toContainText("1,19");
    await expect(row.locator(".deck-copies__marker--foil .deck-copies__count")).toHaveText("1");

    await page.goto("/collection");
    const stacks = page.locator("tbody tr").filter({ hasText: "Evolving Wilds" });
    await expect(stacks).toHaveCount(2);
    await expect(stacks.filter({ has: page.locator("svg.star") })).toHaveCount(1);
});
