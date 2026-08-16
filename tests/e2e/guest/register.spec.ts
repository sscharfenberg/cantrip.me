import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";

/******************************************************************************
 * Registering.
 *
 * Unlike login, this really is an Inertia `<Form action="/register">` with
 * Precognition validation, so what is worth pinning here is the round trip: a
 * server rejection has to come back and land on the right field, and a success
 * has to leave the page.
 *
 * ONE NETWORK DEPENDENCY, and it is the app's, not the harness's:
 * `CreateNewUser` validates the address with `email:rfc,dns`, which resolves the
 * domain's MX records for real. That rules out both a made-up `.test` domain and
 * — less obviously — `example.com`, which publishes a null MX under RFC 7505 and
 * is therefore rejected as undeliverable, correctly. Hence a domain that really
 * does accept mail; nothing is ever sent, since the run's mailer is `log`.
 *
 * Offline, the happy-path test fails on the email field with a message about the
 * address rather than about the thing under test.
 *****************************************************************************/

/** Fill every field on the register form. */
const fillForm = async (
    page: Page,
    values: { name: string; email: string; password: string }
): Promise<void> => {
    await page.locator("#name").fill(values.name);
    await page.locator("#email").fill(values.email);
    await page.locator("#password").fill(values.password);
    await page.locator("#password_confirmation").fill(values.password);
};

/* Comfortably past `min:8` and the PasswordEntropy rule. */
const STRONG_PASSWORD = "korrekt-pferd-batterie-heftklammer";

test("refuses a username somebody already has", async ({ page }) => {
    await page.goto("/register");

    /* The seeded account's name — a collision the fixture guarantees. */
    await fillForm(page, { name: "E2E Tester", email: "someone-else@example.com", password: STRONG_PASSWORD });
    await page.getByRole("button", { name: "Registrieren" }).click();

    await expect(page.getByText("Dieser Benutzername ist bereits vergeben.")).toBeVisible();
    await expect(page).toHaveURL(/\/register$/u);
});

test("registers a new account and sends it to confirm its address", async ({ page }) => {
    await page.goto("/register");

    await fillForm(page, {
        name: "Neuzugang Nummer Eins",
        email: "neuzugang@gmail.com",
        password: STRONG_PASSWORD
    });
    await page.getByRole("button", { name: "Registrieren" }).click();

    /*
     * The welcome page, with a flash. Fortify signs a new registration in
     * automatically; `RegisterResponse` undoes that with an explicit
     * `Auth::logout()` while email verification is enabled, because
     * `pages.register.intro` promises a confirmation mail first.
     */
    await expect(page).toHaveURL(/127\.0\.0\.1:\d+\/$/u);
    await expect(page.getByText(/Registrierung erfolgreich/u)).toBeVisible();

    /*
     * The half that matters, and the half a redirect assertion cannot see: the
     * new account is NOT signed in. Were `Auth::logout()` ever dropped from that
     * response, everything above would still pass and an unverified user would
     * be walking around the app.
     */
    await page.goto("/dashboard");
    await expect(page).toHaveURL(/\/login$/u);
});
