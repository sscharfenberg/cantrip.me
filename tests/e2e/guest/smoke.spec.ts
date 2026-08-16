import { expect, test } from "@playwright/test";

/******************************************************************************
 * The harness itself.
 *
 * Every other spec in this suite assumes four things the harness is responsible
 * for: the server answers, the CSS is on the page, the JavaScript ran, and the
 * icon sprite got inlined. When one of those is untrue, the failure appears in
 * whichever feature spec happened to run first and reads as a broken feature —
 * so each is asserted here, once, where the diagnosis is written down.
 *
 * These run as a GUEST, so they also pin that the welcome page is public.
 *****************************************************************************/
test.describe("harness", () => {
    test("serves the welcome page to a visitor with no session", async ({ page }) => {
        await page.goto("/");

        await expect(page.getByRole("heading", { name: "Organisiere deine Magic Sammlung." })).toBeVisible();
    });

    test("ran the JavaScript bundle", async ({ page }) => {
        await page.goto("/");

        /*
         * The title, of all things, because it is the cheapest proof that Vue
         * mounted. `app.blade.php` ships a bare `<title>cantrip.me</title>`; the
         * page component replaces it through Inertia's `<Head>`, and `main.ts`'s
         * `title` callback prefixes it — so the "cantrip.me: " half comes from
         * the server and the "Willkommen" half only ever from the bundle having
         * run. A blank `public/build` therefore fails HERE, with a legible diff,
         * rather than as thirty timed-out selectors elsewhere.
         */
        await expect(page).toHaveTitle("cantrip.me: Willkommen");
    });

    test("applied the stylesheet", async ({ page }) => {
        await page.goto("/");

        const heading = page.getByRole("heading", { name: "Organisiere deine Magic Sammlung." });

        /*
         * `display: flex` comes from Headline.vue's scoped block. The browser's
         * own default for an `<h2>` is `block`, so this distinguishes "the CSS
         * arrived" from "the element exists" — which a visibility check alone
         * does not.
         */
        await expect(heading).toHaveCSS("display", "flex");
    });

    test("inlined the icon sprite", async ({ page }) => {
        await page.goto("/");

        /*
         * The sprite is NOT part of the Vite build: `npm run icons` writes
         * `storage/app/public/sprite.svg` and `app.blade.php` echoes it into the
         * body, which is what makes `<use href="#home">` resolve. Without it the
         * markup still validates and nothing errors — the icons are simply
         * invisible, and every icon-only control in the app becomes unclickable.
         */
        await expect(page.locator("symbol#home")).toHaveCount(1);
    });
});

test.describe("auth gate", () => {
    test("sends a signed-out visitor from the dashboard to the login page", async ({ page }) => {
        await page.goto("/dashboard");

        await expect(page).toHaveURL(/\/login$/u);
        await expect(page.getByRole("heading", { name: "Anmeldung" })).toBeVisible();
    });
});
