import { expect, test } from "@playwright/test";
import { signIn } from "../support/actions";
import { SEED_USER } from "../support/environment";

/******************************************************************************
 * Signing in.
 *
 * `useLogin` does NOT submit a form — it posts JSON to `/login` so Fortify
 * answers `{ two_factor: true }` instead of redirecting, keeping the whole
 * challenge on one page, and then hands off to `router.visit`. So there is no
 * navigation event to await and no server-rendered error page to read: both the
 * success and the failure are things the running bundle does with a fetch
 * response. That is exactly why they are worth a browser test.
 *
 * THE LOGIN BUDGET. Fortify throttles at five per minute keyed on `name|ip`, and
 * this file spends two of the seeded account's five (the setup project spends a
 * third). Adding another real login here needs an account of its own — see
 * `E2ESeeder::LOGOUT_USER_NAME`.
 *****************************************************************************/
test("rejects a wrong password without leaving the login page", async ({ page }) => {
    await signIn(page, { name: SEED_USER.name, password: "not-the-password" }, null);

    /*
     * The message is rendered by the SERVER (`lang/de/auth.php`), not by the
     * Vue catalog — Fortify throws a ValidationException keyed on the username
     * field and `useLogin` maps `errors.name` into the form. Asserting the text
     * therefore also pins that the run's `APP_LOCALE` reached PHP, which the
     * browser's own `locale` setting has no say over.
     */
    await expect(page.getByText("Diese Zugangsdaten entsprechen nicht unseren Aufzeichnungen.")).toBeVisible();
    await expect(page).toHaveURL(/\/login$/u);
});

test("signs in with the right password and lands on the dashboard", async ({ page }) => {
    await signIn(page);

    await expect(page).toHaveURL(/\/dashboard$/u);
    await expect(page.getByRole("heading", { name: "Mein Benutzerkonto" })).toBeVisible();
});

test("offers the way out to a visitor who cannot sign in", async ({ page }) => {
    await page.goto("/login");

    /*
     * Both links are gated on Fortify feature flags shipped as the `features`
     * shared prop, so this is a real assertion about configuration rather than
     * about markup — with `Features::registration()` disabled the page would
     * render perfectly and simply not offer to register anyone.
     */
    await expect(page.getByRole("link", { name: "Registrierung" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Probleme beim Anmelden?" })).toBeVisible();
});
