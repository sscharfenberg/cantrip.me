import { expect, test } from "@playwright/test";

/******************************************************************************
 * The pages a visitor can reach without an account.
 *
 * Thin on purpose — the content is prose and pinning it would make every
 * copy-edit a test failure. What is worth having is the pair of facts a
 * middleware change can silently break: these routes must NOT redirect to
 * `/login`, and they must render through the app rather than as a bare
 * server-rendered error.
 *****************************************************************************/
const PUBLIC_PAGES = [
    { path: "/about", title: "cantrip.me: Über cantrip.me" },
    { path: "/imprint", title: "cantrip.me: Impressum" },
    { path: "/privacy", title: "cantrip.me: Datenschutzerklärung" }
] as const;

for (const { path, title } of PUBLIC_PAGES) {
    test(`serves ${path} to a visitor with no session`, async ({ page }) => {
        await page.goto(path);

        await expect(page).toHaveURL(new RegExp(`${path}$`, "u"));
        /*
         * The title, because Inertia's `<Head>` writes it from the page
         * component — so this is also the proof that the bundle ran and the
         * right component mounted, not merely that the server answered 200.
         */
        await expect(page).toHaveTitle(title);
    });
}

test("links to all three from the footer", async ({ page }) => {
    await page.goto("/");

    const footer = page.getByRole("contentinfo");

    for (const { path } of PUBLIC_PAGES) {
        await expect(footer.locator(`a[href="${path}"]`)).toBeVisible();
    }
});
