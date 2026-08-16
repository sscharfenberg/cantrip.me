/******************************************************************************
 * test badges
 *
 * Turns each suite's JUnit XML into a shields.io ENDPOINT document, so the README can show
 * how many tests a suite holds and whether they passed.
 *
 * WHY A GENERATED NUMBER RATHER THAN A WRITTEN ONE. A count typed into a README is wrong by
 * the next commit, which is why nothing else in this project states one. A badge is the same
 * fact with the staleness removed: CI rewrites it on every run, so it is either current or
 * visibly `unknown`.
 *
 * WHY JUNIT AND NOT EACH RUNNER'S OWN JSON. Vitest emits rich JSON and PHPUnit emits none —
 * `--log-junit` is the only machine-readable output the two have in common, so one parser
 * serves both instead of two shapes to keep in step. It is also what a Playwright badge would
 * need if the browser suite ever joins CI; that suite is deliberately local-only today (see
 * docs/testing.md), which is why nothing here asks for one.
 *
 * A MISSING FILE IS `unknown`, NOT A FAILURE. If a job dies before its tests run there is no
 * XML, and the honest badge says so; leaving the previous run's green in place would be the
 * one outcome worse than either colour.
 *
 * NODE BUILT-INS ONLY, WHICH IS WHY THERE IS NO `chalk` HERE as the sibling scripts have. This
 * one runs in a job that has installed nothing — its whole purpose is to read the XML files a
 * previous job uploaded — and an `npm ci` to colour a few lines of output would cost more than
 * the job itself.
 *****************************************************************************/
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";

/** What a suite's JUnit run amounts to, once the two report shapes are flattened. */
type Counts = {
    /** Every test the suite declared, skips included. */
    total: number;
    /** Failures and errors together — both are red, and the badge does not distinguish. */
    failed: number;
    /** Tests that never ran, so they can be kept out of the "passed" figure. */
    skipped: number;
};

/** A shields.io endpoint document — the schema that service defines, not one of ours. */
type Badge = {
    schemaVersion: 1;
    label: string;
    message: string;
    color: string;
};

const OUTPUT_DIR = "badges";

/**
 * Read the attributes off a single XML start-tag.
 *
 * A regex rather than a parser because the whole need is four integers from a tag this file
 * already located; adding an XML dependency to the tree to read them would cost more than it
 * explains.
 */
function attributesOf(tag: string): Record<string, string> {
    return Object.fromEntries([...tag.matchAll(/([\w-]+)="([^"]*)"/gu)].map(match => [match[1], match[2]]));
}

/**
 * Total up one JUnit file, or `null` when it is absent.
 *
 * TWO SHAPES, because the writers disagree. Vitest puts the totals on the root `<testsuites>`
 * element; PHPUnit leaves that element bare and puts them on the aggregate `<testsuite>`
 * inside it. Preferring the root and falling back to the first child covers both without
 * asking the caller which runner it is holding.
 */
function readJunit(path: string): Counts | null {
    if (!existsSync(path)) return null;

    const xml = readFileSync(path, "utf8");
    const root = xml.match(/<testsuites\b[^>]*>/u)?.[0];
    const attributes = root ? attributesOf(root) : {};
    const source =
        attributes.tests === undefined ? attributesOf(xml.match(/<testsuite\b[^>]*>/u)?.[0] ?? "") : attributes;

    const count = (name: string): number => Number(source[name] ?? 0);

    return {
        total: count("tests"),
        failed: count("failures") + count("errors"),
        skipped: count("skipped")
    };
}

/**
 * Phrase one suite's result the way a reader scans it: the number first, the state as colour.
 *
 * The failing message keeps BOTH figures. "3 failed" alone loses the size of the suite, which
 * is the thing the badge exists to show in the first place.
 */
function badgeFor(label: string, counts: Counts | null): Badge {
    if (counts === null) return { schemaVersion: 1, label, message: "unknown", color: "lightgrey" };

    const passed = counts.total - counts.failed - counts.skipped;

    return counts.failed > 0
        ? { schemaVersion: 1, label, message: `${counts.failed} failed, ${passed} passed`, color: "red" }
        : { schemaVersion: 1, label, message: `${passed} passed`, color: "brightgreen" };
}

/**
 * Write one endpoint document per `label=path` argument.
 *
 * Arguments rather than a hard-coded list so the workflow that knows where each runner was
 * told to write its XML is also the thing that names them, keeping one source of truth for
 * those paths.
 */
function main(): void {
    const pairs = process.argv.slice(2).map(argument => argument.split("="));

    if (pairs.length === 0) {
        console.log(" ERR  expected arguments like vitest=reports/vitest.xml");
        process.exit(1);
    }

    mkdirSync(OUTPUT_DIR, { recursive: true });

    for (const [label, path] of pairs) {
        const counts = readJunit(path);
        const badge = badgeFor(label, counts);
        const file = join(OUTPUT_DIR, `${label}.json`);

        mkdirSync(dirname(file), { recursive: true });
        writeFileSync(file, `${JSON.stringify(badge, null, 4)}\n`, "utf8");

        console.log(` OK  ${label}: ${badge.message} → ${file}`);
    }
}

main();
