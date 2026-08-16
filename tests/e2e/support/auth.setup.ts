import { expect, test as setup } from "@playwright/test";
import { signIn } from "./actions";
import { STORAGE_STATE } from "./environment";

/**
 * Sign in once and save the session, so the authenticated specs do not each pay
 * for a login round trip.
 *
 * DONE THROUGH THE REAL FORM rather than by minting a session cookie. Two
 * reasons: it is the only place the non-2FA login path is exercised end to end,
 * and a broken login then fails the whole suite loudly, here, instead of showing
 * up as thirty confusing redirect failures in specs that have nothing to do with
 * auth.
 *
 * It also keeps the run inside Fortify's throttle. The limiter is
 * `Limit::perMinute(5)` keyed on `name|ip` and lives in the CACHE — which for
 * this suite is the database, so `migrate:fresh` in global setup resets it. One
 * login here plus what the guest specs do for real must stay under five per
 * minute for the seeded name.
 */
setup("sign in as the seeded user", async ({ page }) => {
    await signIn(page);

    /*
     * Proof the session is real before it is parked. A login that merely stopped
     * redirecting would save a file full of anonymous cookies, and every spec
     * using it would fail a long way from here — so this asserts on something
     * only the authenticated dashboard renders.
     */
    await expect(page.getByRole("heading", { name: "Mein Benutzerkonto" })).toBeVisible();

    await page.context().storageState({ path: STORAGE_STATE });
});
