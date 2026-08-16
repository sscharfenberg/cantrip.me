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

/**
 * Port the E2E MariaDB publishes on.
 *
 * Not 3306 (local dev's) and not 3307 either — `~/.ssh/config` forwards that one
 * to STAGING'S database for the `cantrip` host. See the comment on the `ports:`
 * key in docker-compose.e2e.yml, and `assertIsE2EDatabase` below.
 */
const DB_PORT = 3399;

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
    /*
     * Pinned, not inherited. The committed `.env` happens to say `de` today, and
     * the specs assert on German catalog strings — including SERVER-rendered
     * ones like Fortify's `auth.failed`, which no amount of browser `locale`
     * setting would change. Leaving it out makes the suite pass because of a
     * line in a file it does not own.
     */
    APP_LOCALE: "de",
    APP_FALLBACK_LOCALE: "en",
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

/** The compose container id for the `db` service, or "" when it is not running. */
const runningContainerId = (): string =>
    execFileSync("docker", ["compose", "-f", COMPOSE_FILE, "ps", "--status", "running", "--quiet"], {
        cwd: repoRoot,
        encoding: "utf8",
        stdio: ["ignore", "pipe", "pipe"]
    }).trim();

/**
 * Refuse to go on unless the server answering on `DB_PORT` is THIS container.
 *
 * WHY A LIVENESS CHECK IS NOT ENOUGH, and it is not a hypothetical: the next
 * thing that happens after this returns is `migrate:fresh`, which drops every
 * table it can reach. "Something is listening" is not a safe precondition for
 * that — this suite originally used port 3307, and `~/.ssh/config` forwards 3307
 * to staging's MariaDB for the `cantrip` host, so an open ssh session was enough
 * to make a test run point `migrate:fresh` at staging. The only thing that would
 * have stopped it was the credentials happening not to match.
 *
 * So the identity is checked directly. MariaDB reports `@@hostname` as the
 * container's own id, which is the first twelve characters of what compose calls
 * the container — a value no tunnel, no local server and no second compose
 * project can accidentally produce.
 */
const assertIsE2EDatabase = (containerId: string): void => {
    /*
     * Before the probe, or the probe is worthless: a cached
     * `bootstrap/cache/config.php` beats the environment overrides, so a stale
     * one would have this connect to the DEVELOPMENT database and then abort
     * with a message about the port — which is the wrong diagnosis entirely.
     * `resetDatabase` clears it again for its own reasons; both are cheap.
     */
    artisan("config:clear");

    const probe = artisan(
        "tinker",
        "--execute",
        "echo json_encode(DB::selectOne('select database() as db, @@hostname as host'));"
    );

    let server: { db?: string; host?: string };
    try {
        server = JSON.parse(probe.trim());
    } catch {
        throw new Error(`Could not read the identity of whatever is listening on port ${DB_PORT}:\n${probe}`);
    }

    /*
     * A prefix match, because MariaDB reports the SHORT container id (twelve
     * characters) where compose reports the full sixty-four. Written as an
     * explicit `undefined` check rather than a `??` fallback: the fallback would
     * be a sentinel string chosen never to match, which reads as a typo.
     */
    const isThisContainer = server.host !== undefined && containerId.startsWith(server.host);

    if (server.db !== serverEnv.DB_DATABASE || !isThisContainer) {
        throw new Error(
            `Port ${DB_PORT} is NOT the E2E database container — refusing to migrate.\n` +
                `  expected: database ${serverEnv.DB_DATABASE} on container ${containerId.slice(0, 12)}\n` +
                `  found:    database ${server.db} on host ${server.host}\n` +
                "Something else owns the port. The likely culprit is an ssh tunnel: the `cantrip`\n" +
                "host in ~/.ssh/config forwards a local port to STAGING's MariaDB. Close it and retry."
        );
    }
};

/**
 * Bring the E2E database container up, unless it already is, and prove that what
 * answers on the port really is it.
 *
 * Started for the developer rather than demanded of them, so `npm run e2e` is
 * one command. `--wait` blocks on the compose healthcheck, which is what makes
 * the migration that follows safe in the ordinary case: MariaDB accepts TCP
 * connections for a second or two before it will accept a login, and a
 * `migrate:fresh` fired into that window fails with an authentication error that
 * reads like a wrong password.
 *
 * The container is deliberately NOT torn down afterwards — see globalTeardown.
 */
export const ensureDatabase = async (): Promise<void> => {
    let containerId: string;
    try {
        containerId = runningContainerId();
    } catch {
        throw new Error(
            "Could not talk to Docker, which is where the E2E database lives.\n" +
                "Start Docker Desktop and retry. See docs/testing.md for what the container is\n" +
                "and why it is not the development database."
        );
    }

    if (containerId === "") {
        /*
         * COMPOSE SAYS IT IS DOWN, so anything already on the port is a
         * stranger — and `up` would fail on a bind conflict without ever saying
         * why. Named here instead, because the answer is almost always an ssh
         * tunnel and that is not a guess anyone makes quickly.
         */
        if (await isListening(DB_PORT)) {
            throw new Error(
                `Port ${DB_PORT} is in use, but the E2E database container is not running.\n` +
                    "Something else has taken the port — most likely an ssh tunnel. Close it and retry."
            );
        }

        execFileSync("docker", ["compose", "-f", COMPOSE_FILE, "up", "-d", "--wait"], {
            cwd: repoRoot,
            stdio: "inherit"
        });
        containerId = runningContainerId();
    }

    assertIsE2EDatabase(containerId);
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
 * The second seeded account, owned by the logout spec.
 *
 * It exists for the login THROTTLE rather than for any rows of its own —
 * `E2ESeeder::LOGOUT_USER_NAME` carries the full reasoning. In short: Fortify
 * limits login to five per minute per name, signing out has to be a real login
 * (a parked session cannot be used, because logging out invalidates it
 * server-side for everyone), and a separate name is a separate bucket.
 */
export const LOGOUT_USER = { name: "E2E Logout", password: SEED_USER.password } as const;

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
