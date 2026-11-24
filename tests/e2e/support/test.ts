import { expect, test as base } from "@playwright/test";

/******************************************************************************
 * The suite's `test`: Playwright's, plus a Content-Security-Policy guard.
 *
 * Every spec imports `test` and `expect` from here rather than from
 * "@playwright/test", so every test fails on a CSP violation in any page of its
 * browser context — a script, style, image, font or request the policy
 * (App\Http\Middleware\ContentSecurityPolicy) does not allow. The server runs
 * with the policy enforced (`CSP_ENFORCE` in environment.ts), so a violation is
 * also something that did not load; the guard names it instead of leaving a
 * feature assertion to time out somewhere downstream.
 *
 * Two sources, because each misses something: the `securitypolicyviolation`
 * event (directive and blocked URL, but only in documents the init script
 * reaches) and Chrome's own console line ("Refused to …", every frame).
 *****************************************************************************/
export const test = base.extend<{ contentSecurityPolicyGuard: void }>({
    contentSecurityPolicyGuard: [
        async ({ context }, use) => {
            const violations: string[] = [];

            await context.addInitScript(() => {
                document.addEventListener("securitypolicyviolation", event => {
                    console.error(
                        `CSP violation: ${event.effectiveDirective} blocked ${event.blockedURI || "inline"} ` +
                            `on ${event.documentURI} (${event.sourceFile}:${event.lineNumber})`
                    );
                });
            });
            context.on("console", message => {
                if (/CSP violation|Content Security Policy/u.test(message.text())) {
                    violations.push(message.text());
                }
            });

            await use();

            expect(violations, "Content-Security-Policy violations").toEqual([]);
        },
        { auto: true }
    ]
});

export { expect };
