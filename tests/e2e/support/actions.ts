import type { Page } from "@playwright/test";
import { SEED_USER } from "./environment";

/******************************************************************************
 * Actions and readers shared across the E2E specs.
 *
 * WHAT BELONGS HERE IS TRAP KNOWLEDGE, not merely anything used twice. Each
 * helper below encodes something about this app that is easy to get wrong and
 * expensive to re-learn; a plain three-line navigation is better left in the
 * spec that needs it, where its reader can see it.
 *****************************************************************************/

/**
 * Fill in and submit the login form.
 *
 * ADDRESSED BY ID, not by label. The ids are a real contract in the markup —
 * `<form-group for-id="…">` is what wires each label to its input — and label
 * lookup is genuinely ambiguous elsewhere in the auth pages: "Passwort" is a
 * prefix of "Passwort bestätigen" on the register and reset forms, so a
 * `getByLabel(/Passwort/u)` in a shared helper would be a strict-mode violation
 * waiting for the first spec that reuses it.
 *
 * `useLogin` posts JSON to `/login` and then hands off to `router.visit`, rather
 * than submitting a form the browser would follow. So a successful login is only
 * observable as a URL change, which is what `expectTo` waits for — there is no
 * navigation event to await.
 *
 * @param page     the page to drive
 * @param user     credentials; defaults to the seeded account
 * @param expectTo where a successful login should land — pass null to skip the
 *                 wait when the caller is testing a FAILING login
 */
export const signIn = async (
    page: Page,
    user: { name: string; password: string } = SEED_USER,
    expectTo: RegExp | null = /\/dashboard/u
): Promise<void> => {
    await page.goto("/login");
    await page.locator("#name").fill(user.name);
    await page.locator("#password").fill(user.password);
    await page.getByRole("button", { name: /^Anmelden$/u }).click();

    if (expectTo) await page.waitForURL(expectTo);
};
