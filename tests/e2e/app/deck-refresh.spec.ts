import { expect, test } from "@playwright/test";
import type { Locator, Page } from "@playwright/test";
import { REFRESH_STORAGE_STATE } from "../support/environment";

/******************************************************************************
 * The deck page keeps up with its own actions.
 *
 * Almost every action on a deck page fetches its endpoint and then asks Inertia
 * for a PARTIAL reload — `router.reload({ only: [...] })` — naming the props it
 * changed. A prop left off that list is not refreshed: the page keeps showing
 * what was true before the action until the next full load. That went wrong
 * twice. Switching a card's printing left the header's total worth on the old
 * printing's price, and no action at all refreshed `hasUnclaimedCards`, the
 * flag behind the "Offene Karten" entry of the deck actions menu.
 *
 * Vitest pins each `only` list, which proves the component ASKS for the prop.
 * This proves the round trip: the name matches what the server ships, the
 * server recomputes it from what the action just wrote, and the page renders
 * the new value — without a reload, which is the point. Every test therefore
 * checks its starting state, acts once, and waits for the change.
 *
 * EVERY TEST HERE WRITES, so all of them run as a separate account with decks
 * no reader looks at — see `E2EDeckRefreshSeeder`. One deck per test, since
 * `fullyParallel` would let two tests sharing a deck race. The ids below mirror
 * that seeder's constants.
 *
 * Mutation-checked: with `hasUnclaimedCards` (and, for the printing switches,
 * `deck`) dropped from each component's `only` list, every test fails except
 * "add all to collection" — that flow redirects back to the deck page, which
 * reloads every prop regardless of the list.
 *****************************************************************************/

test.use({ storageState: REFRESH_STORAGE_STATE });

const DECKS = {
    cardPrinting: "0198a1b2-0000-7000-8000-000000000401",
    commanderPrinting: "0198a1b2-0000-7000-8000-000000000402",
    companionPrinting: "0198a1b2-0000-7000-8000-000000000403",
    quantity: "0198a1b2-0000-7000-8000-000000000404",
    quickAdd: "0198a1b2-0000-7000-8000-000000000405",
    addCards: "0198a1b2-0000-7000-8000-000000000406",
    sideboard: "0198a1b2-0000-7000-8000-000000000407",
    split: "0198a1b2-0000-7000-8000-000000000408",
    cardClaim: "0198a1b2-0000-7000-8000-000000000409",
    commanderClaim: "0198a1b2-0000-7000-8000-000000000410",
    companionClaim: "0198a1b2-0000-7000-8000-000000000411",
    commandZone: "0198a1b2-0000-7000-8000-000000000412",
    companionSlot: "0198a1b2-0000-7000-8000-000000000413",
    addAll: "0198a1b2-0000-7000-8000-000000000414"
} as const;

/** Where the seeder keeps the stacks a test claims for itself. */
const SPARE_BINDER = "Spare Binder";

/** Open a deck by id and wait until its header has rendered. */
const openDeck = async (page: Page, id: string): Promise<void> => {
    await page.goto(`/decks/${id}`);
    await expect(page.locator(".deck-meta__name")).toBeVisible();
};

/** The header badge carrying the deck's total worth — the only one with a currency in it. */
const worth = (page: Page): Locator => page.locator(".deck-meta__badges .badge").filter({ hasText: "€" });

/**
 * The "Offene Karten" entry of the deck actions menu.
 *
 * Counted with `includeHidden`, rather than opened and looked at, because the
 * menu is a closed popover while the action under test runs: the question is
 * whether the entry is rendered, which is exactly the `v-if` the flag drives.
 * Every test asserts the count before acting as well as after, so a label that
 * matched nothing could not pass either way.
 */
const unclaimedEntry = (page: Page): Locator =>
    page.getByRole("button", { name: "Offene Karten", includeHidden: true });

/**
 * Open a popover menu and return its container, so entries are looked up in
 * that menu alone — "Entfernen" or "Edition wechseln" exist in more than one.
 *
 * The container is `PopOver.vue`'s wrapper, which holds both the trigger and
 * the `<dialog popover>` with the entries.
 */
const openMenu = async (page: Page, trigger: string, scope: Page | Locator = page): Promise<Locator> => {
    const menu = scope.locator(".popover").filter({ has: page.getByRole("button", { name: trigger, exact: true }) });
    await menu.getByRole("button", { name: trigger, exact: true }).click();

    return menu;
};

/** A card's row in the text view, which the seeded account uses. */
const cardRow = (page: Page, name: string): Locator => page.locator("li.card").filter({ hasText: name });

/** The card actions menu of one deck card. */
const cardMenu = (page: Page, name: string): Promise<Locator> => openMenu(page, "Actions", cardRow(page, name));

/**
 * Pick a printing in the open switch-printing modal.
 *
 * `tile` narrows the list to one printing. By SET for Lightning Bolt — leb and
 * 2ed both number it 162 — whose set icon's alt text starts with the code; by
 * collector number for Lurrus, whose two printings share a set.
 */
const pickPrinting = (page: Page, tile: { set: string } | { cn: string }): Promise<void> => {
    const tiles = page.locator(".switch-printing__list button");
    const choice =
        "set" in tile
            ? tiles.filter({ has: page.locator(`img[alt^="${tile.set} - "]`) })
            : tiles.filter({ hasText: tile.cn });

    return choice.click();
};

/** Pick the spare-binder stack in the open assign modal — the one stack there that is free and the deck's own. */
const pickSpareStack = (page: Page): Promise<void> =>
    page.locator(".assign-stack__button").filter({ hasText: SPARE_BINDER }).click();

test.describe("switching a printing", () => {
    test("re-prices the header and re-checks the deckbox, for a deck card", async ({ page }) => {
        /*
         * Mode B, with a deckbox holding the LEA Lightning Bolt the deck runs —
         * so the printing decides coverage as well as price. LEA is €409.41,
         * 2ED €18.21. Switched there and back, so both directions are seen.
         */
        await openDeck(page, DECKS.cardPrinting);
        await expect(worth(page)).toContainText("409,41");
        await expect(unclaimedEntry(page)).toHaveCount(0);

        await (await cardMenu(page, "Lightning Bolt")).getByRole("button", { name: "Edition wechseln" }).click();
        await pickPrinting(page, { set: "2ED" });

        await expect(worth(page)).toContainText("18,21");
        await expect(unclaimedEntry(page)).toHaveCount(1);

        await (await cardMenu(page, "Lightning Bolt")).getByRole("button", { name: "Edition wechseln" }).click();
        await pickPrinting(page, { set: "LEA" });

        await expect(worth(page)).toContainText("409,41");
        await expect(unclaimedEntry(page)).toHaveCount(0);
    });

    test("re-prices the header and re-checks the deckbox, for the commander", async ({ page }) => {
        /* Lurrus #226 is €1.52, #355 €3.50; the deckbox holds #226. */
        await openDeck(page, DECKS.commanderPrinting);
        await expect(worth(page)).toContainText("1,52");
        await expect(unclaimedEntry(page)).toHaveCount(0);

        await (await openMenu(page, "Commander actions")).getByRole("button", { name: "Edition wechseln" }).click();
        await pickPrinting(page, { cn: "355" });

        await expect(worth(page)).toContainText("3,50");
        await expect(unclaimedEntry(page)).toHaveCount(1);
    });

    test("re-prices the header and re-checks the deckbox, for the companion", async ({ page }) => {
        /* Atraxa has no price in the snapshot, so the worth is the companion's alone. */
        await openDeck(page, DECKS.companionPrinting);
        await expect(worth(page)).toContainText("1,52");
        await expect(unclaimedEntry(page)).toHaveCount(0);

        await (await openMenu(page, "Companion actions")).getByRole("button", { name: "Edition wechseln" }).click();
        await pickPrinting(page, { cn: "355" });

        await expect(worth(page)).toContainText("3,50");
        await expect(unclaimedEntry(page)).toHaveCount(1);
    });
});

/*
 * The rest are mode C: a deck card is covered by the stacks claimed for it, so
 * anything that adds an unclaimed copy opens the deck, and claiming closes it.
 */
test.describe("the open-cards entry follows", () => {
    test("a copy added and removed again", async ({ page }) => {
        await openDeck(page, DECKS.quantity);
        await expect(unclaimedEntry(page)).toHaveCount(0);

        await (await cardMenu(page, "Lightning Bolt")).getByRole("button", { name: "Eine Karte hinzufügen" }).click();
        await expect(unclaimedEntry(page)).toHaveCount(1);

        /*
         * Closed and reopened rather than reused: the reload re-renders the row,
         * and whether its popover survives that is not what this test is about.
         */
        await page.keyboard.press("Escape");
        await (await cardMenu(page, "Lightning Bolt")).getByRole("button", { name: "Eine Karte entfernen" }).click();
        await expect(unclaimedEntry(page)).toHaveCount(0);
    });

    test("a card added through quick add, and removed", async ({ page }) => {
        await openDeck(page, DECKS.quickAdd);
        await expect(unclaimedEntry(page)).toHaveCount(0);

        const results = page.locator(".quickadd__results");
        await page.getByPlaceholder("Quick add").fill("Dark Ritual");
        await expect(results).toBeVisible();
        await results.getByRole("button", { name: /Dark Ritual/u }).click();
        await expect(unclaimedEntry(page)).toHaveCount(1);

        /* The results stay open over the page after an add. */
        await page.keyboard.press("Escape");
        await (await cardMenu(page, "Dark Ritual")).getByRole("button", { name: "Alle entfernen" }).click();
        await expect(unclaimedEntry(page)).toHaveCount(0);
    });

    test("a card added through the Add Cards modal", async ({ page }) => {
        /* A Commander deck: no sideboard, so a click on a result adds it straight to the main deck. */
        await openDeck(page, DECKS.addCards);
        await expect(unclaimedEntry(page)).toHaveCount(0);

        await page.getByRole("button", { name: "Karten hinzufügen" }).click();
        await page.locator("#card_add_query").fill("sol ring");
        await page.locator(".card-add-results__item").first().click();

        await expect(unclaimedEntry(page)).toHaveCount(1);
    });

    test("a copy moved to the sideboard", async ({ page }) => {
        /* The moved copy lands in a new sideboard row; the claim stays with the old one, which goes. */
        await openDeck(page, DECKS.sideboard);
        await expect(unclaimedEntry(page)).toHaveCount(0);

        await (
            await cardMenu(page, "Lightning Bolt")
        )
            .getByRole("button", { name: "1 in Sideboard verschieben" })
            .click();

        await expect(unclaimedEntry(page)).toHaveCount(1);
    });

    test("a copy split off onto another printing", async ({ page }) => {
        /* Two LEA copies under one claimed stack; the split-off LEB copy is a new, unclaimed row. */
        await openDeck(page, DECKS.split);
        await expect(unclaimedEntry(page)).toHaveCount(0);

        await (await cardMenu(page, "Lightning Bolt")).getByRole("button", { name: "Editionen aufteilen" }).click();
        await page
            .locator(".split-printing__item")
            .filter({ has: page.locator('img[alt^="LEB - "]') })
            .getByRole("button", { name: "Eine Karte hinzufügen" })
            .click();
        await page.getByRole("button", { name: "Aufteilung übernehmen" }).click();

        await expect(unclaimedEntry(page)).toHaveCount(1);
    });

    test("a deck card's copy claimed and released", async ({ page }) => {
        await openDeck(page, DECKS.cardClaim);
        await expect(unclaimedEntry(page)).toHaveCount(1);

        await (
            await cardMenu(page, "Lightning Bolt")
        )
            .getByRole("button", { name: "Physisches Exemplar zuordnen" })
            .click();
        await pickSpareStack(page);
        await expect(unclaimedEntry(page)).toHaveCount(0);

        await (
            await cardMenu(page, "Lightning Bolt")
        )
            .getByRole("button", { name: "Physisches Exemplar zuordnen" })
            .click();
        await page.getByRole("button", { name: "Zuordnung entfernen" }).click();
        await expect(unclaimedEntry(page)).toHaveCount(1);
    });

    test("the commander's copy claimed and released", async ({ page }) => {
        await openDeck(page, DECKS.commanderClaim);
        await expect(unclaimedEntry(page)).toHaveCount(1);

        await (
            await openMenu(page, "Commander actions")
        )
            .getByRole("button", { name: "Physisches Exemplar zuordnen" })
            .click();
        await pickSpareStack(page);
        await expect(unclaimedEntry(page)).toHaveCount(0);

        await (
            await openMenu(page, "Commander actions")
        )
            .getByRole("button", { name: "Physisches Exemplar zuordnen" })
            .click();
        await page.getByRole("button", { name: "Zuordnung entfernen" }).click();
        await expect(unclaimedEntry(page)).toHaveCount(1);
    });

    test("the companion's copy claimed", async ({ page }) => {
        await openDeck(page, DECKS.companionClaim);
        await expect(unclaimedEntry(page)).toHaveCount(1);

        await (
            await openMenu(page, "Companion actions")
        )
            .getByRole("button", { name: "Physisches Exemplar zuordnen" })
            .click();
        await pickSpareStack(page);

        await expect(unclaimedEntry(page)).toHaveCount(0);
    });

    test("a new commander", async ({ page }) => {
        /* The claimed Atraxa row is replaced by a Krenko row nothing is claimed for. */
        await openDeck(page, DECKS.commandZone);
        await expect(unclaimedEntry(page)).toHaveCount(0);

        await (await openMenu(page, "Commander actions")).getByRole("button", { name: "Kommandozone ändern" }).click();
        await page.locator("#commander_id").fill("Krenko");
        await page.locator(".commander-picker__commander").filter({ hasText: "Krenko, Mob Boss" }).click();
        await page.getByRole("button", { name: "Command Zone bestätigen" }).click();

        await expect(unclaimedEntry(page)).toHaveCount(1);
    });

    test("a companion added and removed", async ({ page }) => {
        await openDeck(page, DECKS.companionSlot);
        await expect(unclaimedEntry(page)).toHaveCount(0);

        await page.getByRole("button", { name: "Companion", exact: true }).click();
        await page.locator(".companion-picker__tile").filter({ hasText: "Lurrus of the Dream-Den" }).click();
        await expect(unclaimedEntry(page)).toHaveCount(1);

        await (await openMenu(page, "Companion actions")).getByRole("button", { name: "Entfernen" }).click();
        await expect(unclaimedEntry(page)).toHaveCount(0);
    });

    test("add all to collection", async ({ page }) => {
        /*
         * The one test here the `only` list cannot fail: the form posts and
         * redirects back to the deck page, and that visit reloads every prop.
         * It pins the flow end to end instead.
         */
        await openDeck(page, DECKS.addAll);
        await expect(unclaimedEntry(page)).toHaveCount(1);

        await (
            await openMenu(page, "Deck-Aktionen")
        )
            .getByRole("button", { name: "Alle Karten zur Sammlung hinzufügen" })
            .click();
        await page.getByRole("button", { name: "Alle hinzufügen" }).click();

        await expect(unclaimedEntry(page)).toHaveCount(0);
    });
});
