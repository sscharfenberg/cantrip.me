import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";

/******************************************************************************
 * A deck's own page.
 *
 * The centrepiece here is quick-add's COLOUR IDENTITY filter, and it is the
 * single strongest reason this suite runs against a real MariaDB rather than
 * sqlite: `DeckCardSearchService` filters identity with `REGEXP`, which sqlite
 * cannot execute at all. There is no way to prove it works other than to ask a
 * real deck a real question.
 *
 * WHICH DECK EACH TEST USES IS LOAD-BEARING. Everything that only reads works on
 * "Atraxa Superfriends"; the one test that WRITES works on "Yoshi and Rograkh",
 * so it cannot disturb a reader in another worker. `fullyParallel` splits tests
 * within a file as well as across files, so same-file is no protection.
 *****************************************************************************/

/** Open a deck from the deck list, which is how a user gets to one. */
const openDeck = async (page: Page, name: string): Promise<void> => {
    await page.goto("/decks");
    await page.getByRole("link", { name: new RegExp(name, "u") }).click();
    await expect(page.locator(".deck-meta__name")).toContainText(name.toUpperCase());
};

/**
 * Type into quick-add and wait for the popover to settle.
 *
 * The composable ignores anything under two characters and debounces the rest,
 * so a spec that asserted immediately after `fill` would be reading the previous
 * query's results — or none at all.
 */
const quickAdd = async (page: Page, query: string) => {
    const results = page.locator(".quickadd__results");

    await page.getByPlaceholder("Quick add").fill(query);
    await expect(results).toBeVisible();

    return results;
};

test("shows the command zone, and a partner pair as two cards in it", async ({ page }) => {
    await openDeck(page, "Yoshi and Rograkh");

    /*
     * The count in the heading is the assertion. Partners are not a special
     * table — they are two ordinary `deck_cards` rows in `zone=command`
     * distinguished by `role` — so "(2)" is what says the partner slot was
     * filled rather than silently dropped.
     */
    await expect(page.getByRole("heading", { name: "Command Zone (2)" })).toBeVisible();
    await expect(page.getByText("Yoshimaru, Ever Faithful")).toBeVisible();
    await expect(page.getByText("Rograkh, Son of Rohgahh")).toBeVisible();
});

test("groups the mainboard into type categories", async ({ page }) => {
    await openDeck(page, "Atraxa Superfriends");

    /*
     * Derived from each card's `type_line`, not from anything the seeder wrote,
     * and the counts are what make it non-vacuous: the deck holds exactly two
     * artifacts (Sol Ring, Arcane Signet) and one sorcery (Cultivate).
     */
    await expect(page.getByRole("heading", { name: "Artefakte (2)" })).toBeVisible();
    await expect(page.getByRole("heading", { name: "Hexereien (1)" })).toBeVisible();
    await expect(page.getByRole("heading", { name: "Länder (17)" })).toBeVisible();
});

test("quick-add hides a card that is outside the deck's colour identity", async ({ page }) => {
    await openDeck(page, "Atraxa Superfriends");

    /*
     * Krenko is mono-RED and Atraxa's identity is WUBG, so a correct search
     * returns nothing. Krenko is in the fixture and in no deck, so "nothing"
     * cannot be explained by the card being absent or already added — and the
     * next test asks the same question of a deck where the answer is yes.
     */
    const results = await quickAdd(page, "Krenko");

    await expect(results.getByText("Keine Karten für dieses Deck gefunden.")).toBeVisible();
});

test("quick-add offers the same card in a deck whose identity allows it", async ({ page }) => {
    await openDeck(page, "Yoshi and Rograkh");

    const results = await quickAdd(page, "Krenko");

    await expect(results.getByText("Krenko, Mob Boss")).toBeVisible();
    /* And says so instead of showing both, which is what makes the pair tight. */
    await expect(results.getByText("Keine Karten für dieses Deck gefunden.")).toBeHidden();
});

test("quick-add filters the other way round too", async ({ page }) => {
    /*
     * The mirror of the two above, and the reason neither can pass by accident:
     * Dark Ritual is mono-BLACK, so it is inside Atraxa's WUBG and outside the
     * partners' RW. A search that ignored `color_identity` would offer both
     * cards in both decks; one that returned nothing would fail all four.
     */
    await openDeck(page, "Atraxa Superfriends");
    const inAtraxa = await quickAdd(page, "Dark Ritual");
    await expect(inAtraxa.getByText("Dark Ritual")).toBeVisible();
    await expect(inAtraxa.getByText("Keine Karten für dieses Deck gefunden.")).toBeHidden();

    await openDeck(page, "Yoshi and Rograkh");
    await expect(
        (await quickAdd(page, "Dark Ritual")).getByText("Keine Karten für dieses Deck gefunden.")
    ).toBeVisible();
});

test("adds a card to the deck through quick-add", async ({ page }) => {
    /*
     * THE ONE TEST HERE THAT WRITES, and everything about which card it adds to
     * which deck is a constraint rather than a preference.
     *
     * THE DECK: the partner deck, so it cannot disturb the readers above, which
     * all use Atraxa. `decks.spec.ts` matches this deck's row by name and colour
     * pips only, and a colourless land moves neither.
     *
     * THE CARD: Evolving Wilds, because `DeckCardSearchService` DROPS cards the
     * deck already holds at the format's per-card maximum — singleton, in
     * Commander. Adding Krenko here would therefore make "quick-add offers the
     * same card in a deck whose identity allows it" find nothing, and `fullyParallel`
     * means that test may run before or after this one. It would have failed
     * roughly half the time, in a different file's worth of confusion. No reader
     * searches for Evolving Wilds.
     */
    await openDeck(page, "Yoshi and Rograkh");

    const results = await quickAdd(page, "Evolving Wilds");
    await results.getByRole("button", { name: /Evolving Wilds/u }).click();

    await expect(results.getByText("Zum Hauptdeck hinzugefügt")).toBeVisible();

    /*
     * Reloaded, because the optimistic "added" label above proves only that the
     * button was clicked. Coming back to a freshly rendered page is what proves
     * the row was actually written.
     */
    await page.reload();
    await expect(page.getByRole("listitem").filter({ hasText: "Evolving Wilds" })).toHaveCount(1);
});
