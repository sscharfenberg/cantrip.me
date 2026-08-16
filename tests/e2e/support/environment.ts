/******************************************************************************
 * Standing the real app up for an end-to-end run.
 *
 * Everything in this file exists because the committed `.env` points somewhere
 * else entirely — at the `mbos` development database, at Mailtrap, at
 * `https://cantrip.me`. A run must override all of that WITHOUT editing the
 * file, and Laravel's dotenv is immutable: a real environment variable always
 * wins over a `.env` line. That is the mechanism `serverEnv` below relies on,
 * and it is why nothing here writes to `.env`.
 *****************************************************************************/
import { execFileSync } from "node:child_process";
import { existsSync, readFileSync, renameSync } from "node:fs";
import { createConnection } from "node:net";
import path from "node:path";
import { fileURLToPath } from "node:url";

/** Repo root — this module sits three levels down, in tests/e2e/support/. */
export const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../..");

/**
 * Port the E2E app listens on.
 *
 * Not 8000, so a hand-started `php artisan serve` survives a run — and not 8100
 * either, which is what the mixtape.v2 suite uses. That second one is not
 * fussiness: `reuseExistingServer` is on locally, so a shared port means a run
 * started while that other suite's server is still up would drive THAT app,
 * answer `/up` happily, and then fail every selector for reasons no amount of
 * staring at this repo could explain.
 */
export const PORT = 8101;

/** Base URL every spec navigates against. */
export const BASE_URL = `http://127.0.0.1:${PORT}`;

/** The compose project that owns the throwaway MariaDB. See docs/testing.md. */
const COMPOSE_FILE = path.join(repoRoot, "docker-compose.e2e.yml");

/** Port the E2E MariaDB publishes on — 3307, deliberately not dev's 3306. */
const DB_PORT = 3307;

/**
 * Where a stale `public/hot` is parked for the duration of a run.
 *
 * A fixed path rather than a temp name on purpose: if a run is killed halfway,
 * the next one finds this file and puts it back (see `restoreHotFile`), so a
 * crash can never cost the developer their dev-server marker.
 */
const HOT = path.join(repoRoot, "public", "hot");
const HOT_BACKUP = path.join(repoRoot, "public", "hot.e2e-backup");

/**
 * The environment the app server runs with.
 *
 * The database is the container from `docker-compose.e2e.yml` — a REAL MariaDB
 * rather than a throwaway sqlite file, because the deck-building core filters
 * colour identity with `REGEXP`, which sqlite cannot run at all. That is the
 * same engine split the PHPUnit `Local` / `Staging` suites already encode.
 *
 * Sessions, cache and queue all go to that database, which is safe here for a
 * reason worth stating: on sqlite this arrangement is what produces "database is
 * locked" under parallel workers, and it is exactly why the mixtape suite pushes
 * sessions to files. InnoDB takes row locks, so several workers logging in at
 * once is unremarkable.
 *
 * Sending the cache there too has a second, useful effect: `migrate:fresh` drops
 * the `cache` table, so it also resets Fortify's login throttle. See
 * `resetDatabase`.
 */
export const serverEnv: Record<string, string> = {
    APP_ENV: "local",
    APP_DEBUG: "true",
    APP_URL: BASE_URL,
    DB_CONNECTION: "mariadb",
    DB_HOST: "127.0.0.1",
    DB_PORT: String(DB_PORT),
    DB_DATABASE: "cantrip_e2e",
    DB_USERNAME: "cantrip_e2e",
    DB_PASSWORD: "cantrip_e2e",
    SESSION_DRIVER: "database",
    /*
     * Pinned false rather than left out. The committed `.env` has no
     * `SESSION_SECURE_COOKIE` today, so the default already works — but `APP_URL`
     * there is `https://cantrip.me`, and the day someone adds the line that
     * matches it, every login in this http-only run would silently drop its
     * cookie and the whole `app` project would fail as "not signed in".
     */
    SESSION_SECURE_COOKIE: "false",
    CACHE_STORE: "database",
    QUEUE_CONNECTION: "sync",
    /* No Mailtrap. Password-reset specs read the log, not an inbox. */
    MAIL_MAILER: "log"
};

/** Run an artisan command with the E2E overrides applied, returning its stdout. */
export const artisan = (...args: string[]): string =>
    execFileSync("php", ["artisan", ...args], {
        cwd: repoRoot,
        env: { ...process.env, ...serverEnv },
        encoding: "utf8",
        stdio: ["ignore", "pipe", "pipe"]
    });

/** True when something is accepting connections on `port`. */
const isListening = (port: number): Promise<boolean> =>
    new Promise(resolve => {
        const socket = createConnection({ port, host: "127.0.0.1" })
            .on("connect", () => {
                socket.end();
                resolve(true);
            })
            .on("error", () => resolve(false));
        socket.setTimeout(500, () => {
            socket.destroy();
            resolve(false);
        });
    });

/**
 * Bring the E2E database container up, unless it already is.
 *
 * Started for the developer rather than demanded of them, so `npm run e2e` is
 * one command. `--wait` blocks on the compose healthcheck, which is what makes
 * the migration that follows safe: MariaDB accepts TCP connections for a second
 * or two before it will accept a login, and a `migrate:fresh` fired into that
 * window fails with an authentication error that reads like a wrong password.
 *
 * It is deliberately NOT torn down afterwards — see globalTeardown.
 */
export const ensureDatabase = async (): Promise<void> => {
    if (await isListening(DB_PORT)) return;

    try {
        execFileSync("docker", ["compose", "-f", COMPOSE_FILE, "up", "-d", "--wait"], {
            cwd: repoRoot,
            stdio: "inherit"
        });
    } catch {
        throw new Error(
            `Could not start the E2E database on port ${DB_PORT}.\n` +
                "It runs in Docker — start Docker Desktop, then `npm run e2e:db:up`.\n" +
                "See docs/testing.md for what the container is and why it is not the dev database."
        );
    }
};

/**
 * Put a previously stashed `public/hot` back. Called at the START of a run as
 * well as at the end, so a killed run self-heals on the next one.
 */
export const restoreHotFile = (): void => {
    if (existsSync(HOT_BACKUP)) renameSync(HOT_BACKUP, HOT);
};

/**
 * Deal with `public/hot`, which decides where the app loads its assets from.
 *
 * The file is written by `npm run dev` and is NOT removed when that server
 * stops, so it very often outlives it. While it exists, `@vite` points every
 * asset at the URL it names and ignores the built manifest entirely — so a stale
 * one means a run against a page with no CSS and no JavaScript, which presents
 * as every selector timing out.
 *
 * This repo has a second, sharper version of the trap: development happens on
 * the staging box, whose `public/hot` names `https://staging.cantrip.me:5174`.
 * If that file ever lands locally, the assertions run against a page pulling its
 * bundle from a remote host that may not even be serving — and PhpStorm is
 * configured to exclude `public/hot` from deployment precisely because the two
 * sides disagree about who owns it.
 *
 * A LIVE dev server is left alone: it serves assets perfectly well, and stealing
 * the marker out from under a running `npm run dev` would be worse than the
 * problem. Only a stale marker is stashed, and it is put back in teardown.
 */
export const stashStaleHotFile = async (): Promise<void> => {
    restoreHotFile();
    if (!existsSync(HOT)) return;

    const origin = readFileSync(HOT, "utf8").trim();
    const port = Number(new URL(origin).port || 80);
    if (await isListening(port)) return;

    renameSync(HOT, HOT_BACKUP);
};

/**
 * Rebuild the bundle and the icon sprite, since with no `public/hot` the app
 * serves from the manifest.
 *
 * ALWAYS, not only when the manifest is missing. Building conditionally is wrong
 * in the most confusing way available: after any frontend change the suite would
 * run against the PREVIOUS bundle, so a brand-new component simply is not on the
 * page and every selector for it times out. Nothing about that failure points at
 * a stale build.
 *
 * `build-only` skips the lint and type-check that `npm run build` chains, which
 * the developer and CI run separately.
 *
 * THE SPRITE IS NOT OPTIONAL, and it is not part of the Vite build. `npm run
 * icons` writes `storage/app/public/sprite.svg`, and `app.blade.php` INLINES
 * that file into every response; `<Icon>` then renders `<use href="#name">`
 * against it. With no sprite the markup is still there and still valid, so
 * nothing errors — the icons are simply invisible, and every icon-only control
 * (the deck row menus, the zone pickers) becomes unclickable.
 */
export const buildAssets = (): void => {
    execFileSync("npm", ["run", "build-only"], { cwd: repoRoot, stdio: "inherit" });
    execFileSync("npm", ["run", "icons"], { cwd: repoRoot, stdio: "inherit" });
};

/**
 * Reset the database to a freshly seeded state.
 *
 * `config:clear` first, and it is load-bearing: a cached `bootstrap/cache/config.php`
 * beats real environment variables, so a stale one would point this whole run at
 * the DEVELOPMENT database named in `.env` — and the next line is `migrate:fresh`,
 * which drops every table it can reach. Locally that database is not even running,
 * so the failure is a connection error; on a machine where it is, the cost is the
 * developer's data. One cheap command removes the entire class.
 *
 * `E2ESeeder`, not the default `--seed`. `DatabaseSeeder` chains `DeckSeeder`,
 * which pins every slot to a specific Scryfall printing and therefore needs a
 * full `scryfall:update` behind it — 475 MB of bulk data this suite has no way to
 * assume. `E2ESeeder` is a fixed, committed fixture instead: same ids, same
 * names, same printings on every run.
 */
export const resetDatabase = (): void => {
    artisan("config:clear");
    artisan("migrate:fresh", "--force");
    artisan("db:seed", "--class=Database\\Seeders\\E2ESeeder", "--force");
};

/**
 * The seeded account every authenticated spec signs in as.
 *
 * LOGIN IS BY NAME, not by email — `config/fortify.php` sets
 * `'username' => 'name'`. The email exists only because the column is unique and
 * not null.
 */
export const SEED_USER = { name: "E2E Tester", password: "e2e-password" } as const;

/**
 * Where `auth.setup.ts` parks the signed-in session for the `app` project.
 *
 * HERE RATHER THAN IN auth.setup.ts, where it is used, because playwright.config.ts
 * needs it too — and importing the setup file from the config makes Playwright
 * refuse to start: the config is loaded before the test collector exists, so the
 * `setup(...)` call at the top level of that module throws "did not expect
 * test() to be called here". A constants module both sides can import is the way
 * round it.
 */
export const STORAGE_STATE = path.join(repoRoot, "tests/e2e/.auth/user.json");
