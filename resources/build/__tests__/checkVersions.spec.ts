// @vitest-environment node

import { describe, expect, it } from "vitest";
import packageJson from "../../../package.json";
import { npmVersionFromUserAgent, satisfiesCaret } from "../checkVersions.ts";

/*
 * Unit tests for the pure halves of the `preinstall` version check.
 *
 * The check is the gate in front of every install, `dev` and `build`, so the two ways it can
 * go wrong both cost something: refusing a version that satisfies the range blocks all work,
 * and passing one that does not lets the project run on a toolchain it was never meant for.
 * The comparison is hand-written (the script can import nothing that `npm ci` installs), so the
 * cases below pin where a hand-written comparison usually goes wrong — numeric rather than
 * string ordering, the major as a hard boundary, prereleases — along with the refusal to read
 * any range other than the caret form, and which user agents count as npm's.
 */
describe("satisfiesCaret", () => {
    it("accepts the exact version the range names", () => {
        expect(satisfiesCaret("26.13.0", "^26.13.0")).toBe(true);
    });

    it("accepts a later minor or patch inside the same major", () => {
        expect(satisfiesCaret("26.13.4", "^26.13.0")).toBe(true);
        expect(satisfiesCaret("26.14.0", "^26.13.0")).toBe(true);
        expect(satisfiesCaret("12.3.0", "^12.2.9")).toBe(true);
    });

    it("compares fields as numbers, not as strings", () => {
        expect(satisfiesCaret("26.10.0", "^26.9.0")).toBe(true);
        expect(satisfiesCaret("26.4.10", "^26.4.9")).toBe(true);
        expect(satisfiesCaret("26.9.0", "^26.10.0")).toBe(false);
    });

    it("refuses an earlier minor or patch", () => {
        expect(satisfiesCaret("26.5.0", "^26.13.0")).toBe(false);
        expect(satisfiesCaret("12.2.0", "^12.2.1")).toBe(false);
    });

    it("refuses any other major, older or newer", () => {
        expect(satisfiesCaret("11.20.0", "^12.2.1")).toBe(false);
        expect(satisfiesCaret("13.0.0", "^12.2.1")).toBe(false);
    });

    it("reads process.version's leading v", () => {
        expect(satisfiesCaret("v26.13.0", "^26.13.0")).toBe(true);
    });

    it("refuses a prerelease or nightly build, as npm does for a range that names none", () => {
        expect(satisfiesCaret("26.14.0-rc.1", "^26.13.0")).toBe(false);
        expect(satisfiesCaret("26.14.0-nightly20261101abcdef", "^26.13.0")).toBe(false);
    });

    it.each([">=26.4.0", "~26.4.0", "26.4.0", "^26", "^26.4", "^26.4.0 || ^27.0.0", "^0.4.0", "*", ""])(
        "answers null for %j rather than guessing at it",
        range => {
            expect(satisfiesCaret("26.13.0", range)).toBeNull();
        }
    );

    it("can read both ranges package.json actually declares", () => {
        expect(satisfiesCaret("0.0.0", packageJson.engines.node)).not.toBeNull();
        expect(satisfiesCaret("0.0.0", packageJson.engines.npm)).not.toBeNull();
    });
});

describe("npmVersionFromUserAgent", () => {
    it("reads the version npm names itself with", () => {
        expect(npmVersionFromUserAgent("npm/12.2.1 node/v26.13.0 darwin arm64 workspaces/false")).toBe("12.2.1");
    });

    it("ignores the npm/? that pnpm and yarn carry in their own user agents", () => {
        expect(npmVersionFromUserAgent("pnpm/9.1.0 npm/? node/v26.13.0 darwin arm64")).toBeNull();
        expect(npmVersionFromUserAgent("yarn/1.22.22 npm/? node/v26.13.0 darwin arm64")).toBeNull();
    });

    it("answers null when there is no user agent, as when the script is run with plain node", () => {
        expect(npmVersionFromUserAgent(undefined)).toBeNull();
        expect(npmVersionFromUserAgent("")).toBeNull();
    });
});
