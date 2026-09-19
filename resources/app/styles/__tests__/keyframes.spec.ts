// @vitest-environment node
import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

/** `resources/app` — every stylesheet and SFC below this is scanned. */
const APP_ROOT = fileURLToPath(new URL("../../", import.meta.url));

/** Extensions that can carry a `@keyframes` block. */
const STYLE_FILES = [".scss", ".vue"];

/**
 * Colour tokens are stored as `light-dark()` values, so any `map.get(c.$…)`
 * inside a keyframe resolves to one. Matching the token reference as well as
 * the literal catches the mistake in its usual disguise.
 */
const FORBIDDEN = [
    { pattern: /light-dark\(/, what: "a literal light-dark() colour" },
    { pattern: /map\.get\(\s*c\.\$/, what: "a colour token (they are light-dark() values)" }
];

/** Every `*.scss` / `*.vue` path under `resources/app`. */
function styleFiles(dir: string): string[] {
    return readdirSync(dir, { withFileTypes: true }).flatMap(entry => {
        const path = join(dir, entry.name);
        if (entry.isDirectory()) return styleFiles(path);
        return STYLE_FILES.some(ext => entry.name.endsWith(ext)) ? [path] : [];
    });
}

/**
 * Pull out every `@keyframes` body by brace-matching from the opening `{`.
 * A regex alone can't do this — keyframe bodies nest one level deep.
 */
function keyframeBlocks(source: string): { name: string; body: string }[] {
    const blocks: { name: string; body: string }[] = [];
    const opener = /@keyframes\s+([\w-]+)\s*\{/g;
    let match: RegExpExecArray | null;
    while ((match = opener.exec(source)) !== null) {
        let depth = 0;
        let end = match.index + match[0].length - 1;
        for (let i = end; i < source.length; i++) {
            if (source[i] === "{") depth++;
            else if (source[i] === "}" && --depth === 0) {
                end = i;
                break;
            }
        }
        blocks.push({ name: match[1], body: source.slice(match.index + match[0].length, end) });
    }
    return blocks;
}

describe("@keyframes", () => {
    /**
     * Chrome (through 153.0.8010.48) kills the renderer with a CHECK failure —
     * an "Aw, Snap!" with error code 5, not a JS error — when a CSS animation
     * interpolates `light-dark()`. It cost a long afternoon to track down,
     * because the crashing page's network log is all 200s.
     *
     * Hand animated colours to the keyframes as plain custom properties set on
     * the element instead, switching them with the `theme-dark` mixin. See
     * `card-just-added-flash` in `styles/components/deck/_card.scss` for the
     * shape to copy.
     */
    it("never interpolates light-dark() colours", () => {
        const offenders = styleFiles(APP_ROOT).flatMap(path =>
            keyframeBlocks(readFileSync(path, "utf8")).flatMap(({ name, body }) =>
                FORBIDDEN.filter(({ pattern }) => pattern.test(body)).map(
                    ({ what }) => `${path.slice(APP_ROOT.length)} → @keyframes ${name} uses ${what}`
                )
            )
        );

        expect(offenders).toEqual([]);
    });

    /** Guards the scanner itself — a silent zero-file walk would pass vacuously. */
    it("scans the stylesheets it is meant to", () => {
        const names = styleFiles(APP_ROOT).flatMap(path => keyframeBlocks(readFileSync(path, "utf8"))).map(b => b.name);

        // Two blocks from one .scss file, plus one from an SFC's scoped style:
        // proves the walk reaches both file types and doesn't stop at the first
        // block it finds in a file.
        expect(names).toContain("card-just-added-flash");
        expect(names).toContain("card-just-added-bounce");
        expect(names).toContain("nav-overlay-show");
    });
});
