import { expect, test } from "@playwright/test";

/******************************************************************************
 * The stored session.
 *
 * The `app` project hands every spec a `storageState` minted once by
 * `support/auth.setup.ts`. If that file is stale, anonymous, or written before
 * the login actually completed, every authenticated spec fails at its first
 * navigation with a redirect to `/login` — a failure that looks like the feature
 * under test is broken. This pins the session itself, so that diagnosis is one
 * spec name away.
 *****************************************************************************/
test("carries the seeded user's session into a fresh browser context", async ({ page }) => {
    await page.goto("/dashboard");

    /* Not merely "did not redirect": the dashboard is what a session buys. */
    await expect(page).toHaveURL(/\/dashboard$/u);
    await expect(page.getByRole("heading", { name: "Mein Benutzerkonto" })).toBeVisible();
});

test("is signed in as the seeded account, not merely as somebody", async ({ page }) => {
    await page.goto("/dashboard");

    /*
     * The profile form prefills from the `auth.user` SHARED prop, which
     * `HandleInertiaRequests` fills from `$request->user()`. Reading it back
     * distinguishes "a session exists" from "the session belongs to the account
     * this fixture seeded" — the case a storageState left over from an older
     * fixture would otherwise sail through.
     */
    await expect(page.locator("#name")).toHaveValue("E2E Tester");
});
