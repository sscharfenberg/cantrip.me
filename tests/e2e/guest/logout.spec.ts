import { expect, test } from "@playwright/test";
import { signIn } from "../support/actions";
import { LOGOUT_USER } from "../support/environment";

/******************************************************************************
 * Signing out.
 *
 * IN THE GUEST PROJECT, AND SIGNING IN FOR REAL, which looks like the long way
 * round until you try the short one. Logging out invalidates the session
 * SERVER-SIDE and cycles the remember-me token, so doing it with the parked
 * `storageState` would kill the cookie every other `app` spec is holding — and
 * they would fail as unexplained redirects to `/login`, in a different file.
 *
 * It uses its own account for the login throttle; see `LOGOUT_USER`.
 *****************************************************************************/
test("signs out and puts the auth gate back", async ({ page }) => {
    await signIn(page, LOGOUT_USER);

    await page.getByRole("button", { name: "Benutzer Navigation" }).click();
    /*
     * A BUTTON, not a link. Inertia renders `<Link method="post">` as a
     * `<button>` — a POST cannot be an anchor — even though every other item in
     * the same menu is a link.
     */
    await page.getByRole("button", { name: "Abmelden" }).click();

    await expect(page).toHaveURL(/\/$/u);

    /*
     * NOT just "we ended up on the welcome page". A logout that cleared the UI
     * without ending the session would look identical here, so the assertion
     * that matters is the one after it: the gated route has to gate again.
     */
    await page.goto("/dashboard");
    await expect(page).toHaveURL(/\/login$/u);
});
