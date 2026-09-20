import { expect, test } from "@playwright/test";
import type { Locator, Page } from "@playwright/test";

/******************************************************************************
 * Collection integration where it touches a deck.
 *
 * These are the seams the cheaper suites structurally cannot reach:
 *
 *  - Availability is resolved from `card_stacks`, `decks.container_id` and the
 *    claim pivot, and the choice of which badge to ship is made per deck STATE
 *    in the controller. Vitest proves each badge renders for a given payload;
 *    only a real database proves the payload is the right one.
 *  - Quick add's printing preference is the one rule with no cheaper home at
 *    all: `searchOracleCardsForDeck` orders by `CHAR_LENGTH`, so it cannot run
 *    on sqlite, and its write-heavy fixture cannot live in the MariaDB suite.
 *    Its two halves are unit-tested; that they are wired together is proven
 *    here or nowhere.
 *  - The printing hover preview has to escape a native `<dialog>` opened with
 *    `showModal()`. jsdom has neither a top layer nor a containing block, so
 *    "is it actually on top and positioned against the viewport" is a question
 *    only a browser can answer — and getting it wrong once is what prompted
 *    this spec.
 *
 * WHICH DECK EACH TEST USES IS LOAD-BEARING, as in `deck.spec.ts`. Readers use
 * "Atraxa Superfriends". The one test that WRITES uses "Legacy Burn" rather
 * than that file's "Yoshi and Rograkh", because the card it has to add —
 * Counterspell, the only card the fixture owns in two printings — is outside
 * the Boros deck's colour identity and already singleton-blocked in Atraxa.
 * Nothing else in the suite reads Legacy Burn's card list; `decks.spec.ts`
 * matches it by name in the deck list only.
 *****************************************************************************/

/**
 * Open a deck from the deck list, which is how a user gets to one.
 *
 * `format` is not optional decoration. The list keeps one format folder open at
 * a time and Commander wins that on load, being the busiest — so a Legacy deck
 * is not on the page until its folder is clicked. The folder is addressed by
 * ROLE because the format name also appears in the stats panel above, which
 * would make a text lookup a strict-mode violation.
 */
const openDeck = async (page: Page, name: string, format?: string): Promise<void> => {
    await page.goto("/decks");
    if (format) {
        await page.getByRole("button", { name: new RegExp(`^${format}\\b`, "u") }).click();
    }
    await page.getByRole("link", { name: new RegExp(name, "u") }).click();
    await expect(page.locator(".deck-meta__name")).toContainText(name.toUpperCase());
};

/**
 * One card's row in the text deck view.
 *
 * Scoped to `li.card` rather than page text: a card name also appears in the
 * hover preview it triggers and, for some cards, in the legality panel.
 */
const cardRow = (page: Page, name: string): Locator =>
    page.locator("li.card").filter({ hasText: name });

/**
 * Flip one of the modal's switches.
 *
 * Addressed as the LABEL, because `Switch.vue` hides its checkbox
 * (`visibility: hidden; width: 0`) and styles the label as the visible track —
 * so the input is not clickable and Playwright is right to refuse it. The
 * `.wrapper >` prefix disambiguates: `FormGroup` renders a second `<label>`
 * with the same `for`, which would be a strict-mode violation on its own.
 */
const flipSwitch = (page: Page, id: string): Promise<void> =>
    page.locator(`.wrapper > label[for="${id}"]`).click();

test("badges each deck card with what the collection can cover", async ({ page }) => {
    await openDeck(page, "Atraxa Superfriends");

    /*
     * Sol Ring sits in the Atraxa deckbox and the deck wants one, so it is
     * covered. Arcane Signet is owned nowhere. The pair is the assertion: a
     * payload that shipped one badge for every row would satisfy either line
     * alone, so each is also asserted NOT to carry the other's badge.
     */
    await expect(cardRow(page, "Sol Ring").locator(".collection-availability--available")).toBeVisible();
    await expect(cardRow(page, "Sol Ring").locator(".collection-availability--unavailable")).toHaveCount(0);

    await expect(cardRow(page, "Arcane Signet").locator(".collection-availability--unavailable")).toBeVisible();
    await expect(cardRow(page, "Arcane Signet").locator(".collection-availability--available")).toHaveCount(0);
});

test("shows availability instead of the tracking-mode picker while planned", async ({ page }) => {
    await openDeck(page, "Atraxa Superfriends");

    /*
     * The state badge first, so the two absences below are "not offered"
     * rather than "page never rendered".
     */
    await expect(page.locator(".deck-state")).toContainText("Geplant");

    // A planned deck ignores its tracking mode, so the picker is not offered…
    await expect(page.locator(".collection-mode-badge")).toHaveCount(0);
    // …and the mode-C / mode-B per-card badges give way to availability.
    await expect(page.locator(".collection-status")).toHaveCount(0);
    await expect(page.locator(".collection-implicit")).toHaveCount(0);
    await expect(page.locator(".collection-availability").first()).toBeVisible();
});

test("narrows Add Cards to printings the collection can cover", async ({ page }) => {
    await openDeck(page, "Atraxa Superfriends");
    await page.getByRole("button", { name: "Karten hinzufügen" }).click();

    const results = page.locator(".card-add-results");
    await page.locator("#card_add_query").fill("sol ring");

    /*
     * Three printings of Sol Ring are in the snapshot (lea, leb, 2ed) and the
     * fixture owns exactly one of them, so the filter has somewhere to go.
     * Counting face images rather than rows: the results list is the only
     * place they appear inside this modal.
     */
    const printings = results.locator(".face-image");
    await expect(printings).toHaveCount(3);

    await flipSwitch(page, "card_add_only_available");

    await expect(printings).toHaveCount(1);
    /*
     * And the survivor is the owned one — it carries the ownership badge the
     * face-image panel renders from the same collection data.
     */
    await expect(printings.locator(".face-image__panel-owned")).toBeVisible();
    await expect(printings.locator(".face-image__panel-owned")).toContainText("1");

    // Back off, and the printings it hid come back — so the filter is the
    // cause, not a search that happened to narrow.
    await flipSwitch(page, "card_add_only_available");
    await expect(printings).toHaveCount(3);
});

test("quick add reaches for the printing the collection has most of", async ({ page }) => {
    await openDeck(page, "Legacy Burn", "Legacy");

    /*
     * The fixture owns Counterspell twice: two copies of lea #54 in the binder
     * and one of leb #55 in the display. leb is the NEWER set, so the rule
     * that applied before today would have added #55 — and "any printing I
     * own" could pick either. Only "most free copies" lands on #54.
     */
    const results = page.locator(".quickadd__results");
    await page.getByPlaceholder("Quick add").fill("counterspell");
    await expect(results).toBeVisible();
    await results.getByRole("button", { name: /Counterspell/u }).click();

    const added = cardRow(page, "Counterspell");
    await expect(added).toBeVisible();

    // Which printing landed is only legible in the card preview, so open it.
    await added.locator(".card-preview__trigger").click();
    const panel = page.locator(".modal-dialog__body .face-image__panel-line").first();

    await expect(panel).toContainText("54");
    await expect(panel).not.toContainText("55");
});

test("the printing hover preview escapes the card modal", async ({ page }) => {
    await page.goto("/collection");

    // Counterspell is the card the fixture owns in two printings, so its
    // preview is the only one with an "other printings you own" list.
    const row = page.getByRole("table").getByRole("row").filter({ hasText: "Counterspell" }).first();
    await row.locator(".card-preview__trigger").click();

    const thumb = page.locator(".cardstack-preview__copies--printings .cardstack-preview__copies-thumb");
    await expect(thumb).toHaveCount(1);

    await thumb.hover();

    /*
     * The preview waits 300ms before showing, then promotes itself into the
     * top layer as a popover. Two independent things had to be true, and each
     * was broken on its own during development, so each is asserted:
     *
     *  - PAINT ORDER. The dialog is in the top layer, which paints above every
     *    z-index in the normal stacking context. `:popover-open` is the
     *    preview being in the top layer too — and nothing in the top layer is
     *    clipped by an ancestor's `overflow` either, so this covers clipping.
     *  - CONTAINING BLOCK. `.modal-dialog__content` keeps a `transform` from
     *    its open animation (`animation-fill-mode: forwards` over a final
     *    `translateY(0)`), which makes it the containing block for fixed
     *    descendants. A preview caught by that is laid out relative to the
     *    modal, so it lands at the modal's origin PLUS its own coordinates.
     *    Comparing the rect against the inline `left`/`top` the component
     *    asked for is what separates the two, and unlike a box-overlap test
     *    it does not depend on the modal's size or where the cursor was —
     *    an earlier version of this assertion checked that the preview
     *    extended past the modal's edges and passed only by luck of layout.
     */
    const preview = page.locator(".card-preview");
    await expect(preview).toBeVisible();

    const geometry = await page.evaluate(() => {
        const el = document.querySelector<HTMLElement>(".card-preview");
        if (!el) return null;
        const rect = el.getBoundingClientRect();

        return {
            inTopLayer: el.matches(":popover-open"),
            hasSize: rect.width > 0 && rect.height > 0,
            /** How far the element landed from the viewport coordinates it asked for. */
            driftX: Math.abs(rect.left - parseFloat(el.style.left)),
            driftY: Math.abs(rect.top - parseFloat(el.style.top))
        };
    });

    expect(geometry).not.toBeNull();
    expect(geometry!.inTopLayer).toBe(true);
    expect(geometry!.hasSize).toBe(true);
    expect(geometry!.driftX).toBeLessThan(1);
    expect(geometry!.driftY).toBeLessThan(1);
});
