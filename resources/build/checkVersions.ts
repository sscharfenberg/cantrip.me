/******************************************************************************
 * check node and npm versions
 *
 * Compares the running Node and npm against `engines.node` and `engines.npm` in package.json,
 * and exits 1 when either falls short. Runs as the `preinstall` hook, and again in front of
 * `dev` and `build`. npm reads `engines` as well, but only to WARN (`EBADENGINE`) unless a
 * machine sets `engine-strict`; this script is what turns the declared versions into a
 * requirement.
 *
 * NODE BUILT-INS ONLY. npm 12 runs a project's own `preinstall` before it installs a single
 * dependency, and `npm ci` empties node_modules first — so anything imported from a package
 * here is missing at exactly the moment the check runs, and the install aborts on a
 * module-not-found error that reads like a wrong version. Colour comes from `util.styleText`;
 * the version comparison is written out below.
 *
 * ONE RANGE FORM, AND ANY OTHER IS AN ERROR. Both engines are written `^MAJOR.MINOR.PATCH`.
 * Reading that form takes a dozen lines; reading every range npm accepts is the whole `semver`
 * package. So the check reads the one form and refuses anything else outright, because a
 * requirement it could not read and let pass would be a check that no longer checks anything.
 *
 * WHICH npm. The one running this script: npm names itself in the `npm_config_user_agent` it
 * hands every script it runs. Started directly with `node` there is no such variable, and the
 * check asks `npm --version` instead — the npm first on PATH, which is the one a following
 * `npm ci` would use.
 *****************************************************************************/
import { execFileSync } from "node:child_process";
import { createRequire } from "node:module";
import { styleText } from "node:util";

/** The tools package.json's `engines` declares, and this script checks. */
type Tool = "node" | "npm";

/**
 * Whether `version` satisfies the caret range `range`: the same major, and at least the
 * minor.patch the range names. Returns `null` when the range is not `^MAJOR.MINOR.PATCH` with a
 * major of 1 or more — a `^0.x` range narrows to its minor, which this does not implement, and
 * no Node or npm the project could require is a 0.x.
 *
 * A version that is not a plain release (`27.0.0-nightly…`, `26.5.0-rc.1`) never satisfies,
 * which is also how npm reads a range that names no prerelease of its own.
 */
export const satisfiesCaret = (version: string, range: string): boolean | null => {
    const wanted = /^\^([1-9]\d*)\.(\d+)\.(\d+)$/.exec(range.trim());
    if (!wanted) return null;
    const actual = /^v?(\d+)\.(\d+)\.(\d+)$/.exec(version.trim());
    if (!actual) return false;
    const [major, minor, patch] = actual.slice(1).map(Number);
    const [wantedMajor, wantedMinor, wantedPatch] = wanted.slice(1).map(Number);
    if (major !== wantedMajor) return false;
    if (minor !== wantedMinor) return minor > wantedMinor;
    return patch >= wantedPatch;
};

/**
 * The npm version an npm user-agent string names (`npm/12.2.1 node/v26.13.0 darwin arm64 …`),
 * or `null` when the string is missing or belongs to another client. Anchored at the start
 * because pnpm and yarn set the same variable and carry an `npm/?` of their own further along,
 * which says nothing about the npm installed.
 */
export const npmVersionFromUserAgent = (userAgent: string | undefined): string | null =>
    /^npm\/(\S+)/.exec(userAgent ?? "")?.[1] ?? null;

/**
 * The version of the npm running this script, read from its user agent, or from
 * `npm --version` when the script was started without npm. `null` when neither answers, which
 * means there is no npm on PATH.
 */
const currentNpmVersion = (): string | null => {
    const fromUserAgent = npmVersionFromUserAgent(process.env.npm_config_user_agent);
    if (fromUserAgent) return fromUserAgent;
    try {
        return execFileSync("npm", ["--version"], { encoding: "utf8" }).trim();
    } catch {
        return null;
    }
};

/**
 * Print one tool's verdict and answer whether it passed. Every way of failing to read a side —
 * no range declared, a range in another form, no version found — counts as a failure, for the
 * same reason an unreadable range does.
 */
const check = (tool: Tool, current: string | null, required: string | undefined): boolean => {
    const error = styleText(["bgRed", "white"], " ERR ");
    if (required === undefined) {
        console.log([error, styleText("cyan", `package.json declares no engines.${tool}.`)].join(" "));
        return false;
    }
    const verdict = satisfiesCaret(current ?? "", required);
    if (verdict === null) {
        console.log(
            [
                error,
                styleText("cyan", `engines.${tool} is`),
                styleText("redBright", required) + styleText("cyan", ","),
                styleText("cyan", "and this check only reads"),
                styleText("yellowBright", "^MAJOR.MINOR.PATCH") + styleText("cyan", ".")
            ].join(" ")
        );
        return false;
    }
    if (current === null) {
        console.log([error, styleText("cyan", `could not determine the ${tool} version.`)].join(" "));
        return false;
    }
    if (!verdict) {
        console.log(
            [
                error,
                styleText("cyan", `current ${tool} version is`),
                styleText("yellowBright", current) + styleText("cyan", ","),
                styleText("cyan", "project requires"),
                styleText("redBright", required) + styleText("cyan", ".")
            ].join(" ")
        );
        return false;
    }
    console.log(
        [
            styleText(["bgGreen", "white"], " OK "),
            styleText("cyan", `current ${tool} version is`),
            styleText("greenBright", current) + styleText("cyan", ".")
        ].join(" ")
    );
    return true;
};

/**
 * Check both tools and exit 1 if either fails. Both are checked before deciding, so one run
 * reports everything that is wrong rather than the first thing. Called only when this file is
 * run directly, so the unit test can import the exports without the check exiting the runner.
 */
const main = (): void => {
    const require = createRequire(import.meta.url);
    const engines = (require("../../package.json") as { engines?: Partial<Record<Tool, string>> | null }).engines ?? {};
    const nodePassed = check("node", process.versions.node, engines.node);
    const npmPassed = check("npm", currentNpmVersion(), engines.npm);
    if (!nodePassed || !npmPassed) process.exit(1);
};

if (import.meta.main) main();
