import { defineConfig, devices } from "@playwright/test";
import { BASE_URL, PORT, STORAGE_STATE, serverEnv } from "./tests/e2e/support/environment.ts";

/*
 * Playwright — the end-to-end layer (https://playwright.dev/docs/test-configuration).
 *
 * This exists to answer the questions the Vitest suite structurally cannot. jsdom has no
 * layout, no real navigation, no Inertia server and no database, so anything that depends
 * on the browser actually being a browser — and on the app actually being the app — lands
 * here: that a page really boots with its assets, that auth genuinely gates a route, that
 * a deck's cards come back from a real `REGEXP` colour-identity query.
 *
 * IT DRIVES THE REAL APP: Laravel over the throwaway MariaDB from
 * `docker-compose.e2e.yml`, migrated and seeded fresh at the start of every run. See
 * tests/e2e/support/environment.ts for why the environment is overridden rather than
 * configured, and for the `public/hot` trap that silently blanks every asset.
 *
 * IT RUNS IN CI AND LOCALLY, AND THE LOCAL RUN IS THE ONE THAT MATTERS WHEN IT BREAKS.
 * A browser failure wants a trace, a screenshot and a re-run of one spec, none of which a
 * CI round trip gives you cheaply — so the harness starts everything it needs itself
 * (`npm run e2e` and nothing else), and CI runs that exact command rather than a bespoke
 * pipeline of its own. Same database container, same setup, same failure.
 */
export default defineConfig({
    testDir: "./tests/e2e",

    /* Container, assets, the public/hot guard and a fresh schema, before anything runs. */
    globalSetup: "./tests/e2e/support/globalSetup.ts",
    globalTeardown: "./tests/e2e/support/globalTeardown.ts",

    /*
     * Parallel across files, but modestly. The whole run shares ONE app server, and that
     * server is `artisan serve` — PHP's built-in server, which is strictly SERIAL, one
     * connection at a time however many workers ask. So workers past the first buy
     * overlap on the browser side only, and each extra one lengthens the queue in front
     * of the bottleneck.
     *
     * ONE worker in CI. A shared runner has fewer, slower cores than a developer's machine
     * and nothing else to do, so the second worker buys little and costs determinism —
     * and a red CI run is the one you cannot attach a debugger to.
     */
    fullyParallel: true,
    workers: process.env.CI ? 1 : 2,

    /* A test that only passes sometimes is worse than no test — never let one land green. */
    retries: 0,

    /* A `test.only` left in a commit turns the whole suite into one test. Not on CI it does not. */
    forbidOnly: Boolean(process.env.CI),

    /*
     * ROOM FOR THE SERVER TO STALL. `artisan serve` answers one request at a time, so with
     * two workers a page load can sit behind another worker's Inertia visit, its asset
     * requests and its API calls before PHP even looks at it. Playwright's default 5s
     * assertion budget is thin against that, and what it produces is not an honest failure
     * but a different red spec each run, all green in isolation.
     *
     * IT HIDES NOTHING, which is why it is this rather than `retries`. An assertion
     * resolves the instant it is true, so a green run costs exactly the same; a genuinely
     * broken app still fails, just later. Retries would let a flaky test land green, which
     * the line above rejects on principle.
     *
     * The per-TEST timeout goes up with it, or a spec making several slow waits would hit
     * the 30s default while every individual assertion was still inside its own.
     */
    timeout: 60_000,
    expect: { timeout: 15_000 },

    /*
     * The JUnit file is CI-only and has ONE reader: resources/build/testBadges.ts, which
     * turns each suite's totals into the README's badge. It must be written even when the
     * run fails — that is the point, since a red badge has to be able to say how many
     * failed. The `github` reporter annotates the failing line in the diff view.
     */
    reporter: process.env.CI
        ? [["github"], ["junit", { outputFile: "reports/playwright.xml" }], ["html", { open: "never" }]]
        : [["list"], ["html", { open: "never" }]],

    use: {
        baseURL: BASE_URL,
        /* Evidence for a failure, and nothing kept for a pass — traces are large. */
        trace: "retain-on-failure",
        screenshot: "only-on-failure",
        video: "off",
        /*
         * The app's default locale is German (`resources/app/lang/de.json`, and
         * `config/app.php`), and the specs assert on catalog strings, so this pins the
         * browser to match rather than leaving it to the machine's own locale.
         */
        locale: "de-DE",
        timezoneId: "UTC"
    },

    projects: [
        /* Signs in once and parks the session for the `app` project. */
        { name: "setup", testMatch: /support\/auth\.setup\.ts/u },

        /*
         * Guest specs run with NO stored session. Separated by DIRECTORY rather than by
         * clearing cookies per test, so a stray storageState can never let an auth-gate
         * test pass by accident — which is the one failure mode that would make the whole
         * project worthless while looking green.
         */
        {
            name: "guest",
            testMatch: /guest\/.*\.spec\.ts/u,
            use: { ...devices["Desktop Chrome"], storageState: undefined }
        },
        {
            name: "app",
            testMatch: /app\/.*\.spec\.ts/u,
            dependencies: ["setup"],
            use: { ...devices["Desktop Chrome"], storageState: STORAGE_STATE }
        }
    ],

    /*
     * `--no-reload` so the server does not restart mid-run when a file is touched, and a
     * port that is neither 8000 nor mixtape's 8100 (see environment.ts). Reused when
     * already up, which makes re-running a single spec fast — but never on CI, where
     * anything already holding that port is a leftover, not a convenience.
     */
    webServer: {
        command: `php artisan serve --host=127.0.0.1 --port=${PORT} --no-reload`,
        /*
         * THE HEALTH ROUTE, not a page. Readiness here means "PHP is answering"; a page
         * would mean "whatever that page happens to need", and global setup migrates the
         * database only AFTER this probe succeeds — so probing anything that reads a table
         * deadlocks the suite. `/up` is Laravel's own health endpoint, registered in
         * bootstrap/app.php, and touches nothing.
         */
        url: `${BASE_URL}/up`,
        reuseExistingServer: !process.env.CI,
        timeout: 60_000,
        stdout: "ignore",
        stderr: "pipe",
        env: serverEnv
    }
});
