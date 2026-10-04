/******************************************************************************
 * check translations
 *
 * Finds translation keys the frontend uses that `lang/de.json` or `lang/en.json` does not
 * define. vue-i18n renders a missing key as the key itself, so the mistake never throws — it
 * just ships `form.fields.deck_bracket_hint` as tooltip text.
 *
 * WHAT COUNTS AS A USE. Every string literal in `resources/app` whose text starts with one of
 * the top-level namespaces of the lang files (`pages.`, `form.`, `enums.` …), plus the first
 * argument of every `t()` / `$t()` / `te()` call whatever it starts with. Scanning literals,
 * not just calls, is what catches keys handed around before they are translated — breadcrumb
 * `labelKey`s, `messageKey` fields, props like `translationPrefix`.
 *
 * WHAT DOES NOT. Comments, and the values of Vue directive attributes — `:key="card.id"` is an
 * expression whose first word happens to be the `card` namespace, not a string. Literals
 * *inside* a binding (`:label="$t('header.currency.' + cur)"`) are still read.
 *
 * DYNAMIC KEYS. A template literal such as `form.fields.deck_bracket_${n}` becomes a pattern
 * (each `${…}` matches one or more characters), and so does a literal ending in `.` — the
 * prefix half of `"enums.conditions." + value`. Either passes if it matches at least one key
 * in each locale. It cannot prove that every runtime value has a key — only that the pattern
 * is not dead. A literal that *starts* with `${…}` has no static anchor and is listed as
 * unverifiable rather than guessed at.
 *
 * NAMESPACES. Outside a translate call a literal may name a whole subtree rather than a leaf —
 * `labelFor(format, "enums.card_formats")` hands the namespace on to be joined with a key.
 * That passes when the subtree exists. Inside `t()` it does not: translating an object
 * renders the key.
 *
 * ALSO CHECKED: keys present in one locale but not the other, and linked messages (`@:key`)
 * inside the lang files that point at nothing.
 *
 * Specs are skipped — they assert on the key echo and would only repeat what their component
 * already uses. Exits 1 when anything is missing, so it can gate CI.
 *
 * usage: node ./resources/scripts/checkTranslations.ts [-v]
 *   -v  also list the dynamic patterns that resolved and the unverifiable ones
 *****************************************************************************/
import { readdirSync, readFileSync } from "node:fs";
import { join, relative } from "node:path";
import chalk from "chalk";

const root = join(import.meta.dirname, "../..");
const appDir = join(root, "resources/app");
const langDir = join(appDir, "lang");
const locales = ["de", "en"] as const;
const verbose = process.argv.includes("-v");

type Locale = (typeof locales)[number];

/** One place in the source that uses a key or key pattern. */
type Usage = {
    /** The key, or the template literal text for a dynamic key. */
    key: string;
    /** `null` for a static key; the compiled pattern for a dynamic one. */
    pattern: RegExp | null;
    /** Whether it was the argument of a translate call, which must name a leaf. */
    translated: boolean;
    file: string;
    line: number;
};

/** A finding that fails the run. */
type Problem = { usage: Usage; missingIn: Locale[] };

/**
 * Flattens nested messages into their dotted leaf keys. Only leaves count — `t()` on an
 * intermediate object returns the key, not text.
 */
function flatten(messages: Record<string, unknown>, prefix = "", into = new Map<string, string>()) {
    for (const [name, value] of Object.entries(messages)) {
        const key = prefix ? `${prefix}.${name}` : name;
        if (value !== null && typeof value === "object") {
            flatten(value as Record<string, unknown>, key, into);
        } else {
            into.set(key, String(value));
        }
    }
    return into;
}

/** Every `.vue` / `.ts` file under `dir`, minus specs, the test kit and type declarations. */
function sourceFiles(dir: string): string[] {
    return readdirSync(dir, { withFileTypes: true }).flatMap(entry => {
        const path = join(dir, entry.name);
        if (entry.isDirectory()) {
            return ["__tests__", "test", "lang"].includes(entry.name) ? [] : sourceFiles(path);
        }
        return /\.(vue|ts)$/.test(entry.name) && !entry.name.endsWith(".d.ts") ? [path] : [];
    });
}

const escapeRegExp = (text: string) => text.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

/** `form.fields.deck_bracket_${n}` → /^form\.fields\.deck_bracket_.+$/ */
const templateToPattern = (template: string) =>
    new RegExp(
        "^" +
            template
                .split(/\$\{[^}]*\}/)
                .map(escapeRegExp)
                .join(".+") +
            "$"
    );

const lineOf = (source: string, index: number) => source.slice(0, index).split("\n").length;

/**
 * Blanks out comments, keeping their newlines so line numbers still match the file. A line
 * comment needs whitespace (or the start of a line) before its `//`, which leaves `https://`
 * inside strings alone.
 */
const stripComments = (source: string) =>
    source.replace(/\/\*[\s\S]*?\*\/|<!--[\s\S]*?-->|(?<=^|\s)\/\/[^\n]*/g, comment => comment.replace(/[^\n]/g, " "));

/** The text right before a `"` opens a Vue directive's value: `:key=`, `@click=`, `v-if=`, `#item=`. */
const directiveValueOpener = /(?:^|\s)(?:[:@#][\w.:-]*|v-[\w.:-]+)(?:\[[^\]]*\])?=$/;

/******************************************************************************
 * load the lang files
 *****************************************************************************/
const messages = Object.fromEntries(
    locales.map(locale => [locale, flatten(JSON.parse(readFileSync(join(langDir, `${locale}.json`), "utf8")))])
) as Record<Locale, Map<string, string>>;

const namespaces = [...new Set(locales.flatMap(locale => [...messages[locale].keys()].map(k => k.split(".")[0])))];
const ns = namespaces.map(escapeRegExp).join("|");

/** A quoted literal that starts with a namespace: 'pages.x', "pages.x", `pages.${x}`. */
const namespacedLiteral = new RegExp(
    `(['"])((?:${ns})\\.[\\w.-]+)\\1|\`((?:${ns})\\.(?:[^\`\\\\$]|\\$\\{[^}]*\\})*)\``,
    "g"
);
/** The first argument of a translate call, whatever it starts with. */
const translateCall = /(?<![\w.])\$?t[ce]?\(\s*(?:(['"])([^'"\n]*)\1|`((?:[^`\\$]|\$\{[^}]*\})*)`)/g;

/******************************************************************************
 * collect usages
 *****************************************************************************/
const usages = new Map<string, Usage>();
const unverifiable: Usage[] = [];

const record = (key: string, translated: boolean, file: string, line: number) => {
    const id = `${file}:${line}:${key}`;
    const existing = usages.get(id);
    if (existing) {
        // Found by both scans — the translate call's stricter leaf rule wins.
        existing.translated ||= translated;
        return;
    }
    if (key.startsWith("${")) {
        unverifiable.push({ key, pattern: null, translated, file, line });
    } else if (key.includes("${")) {
        usages.set(id, { key, pattern: templateToPattern(key), translated, file, line });
    } else if (key.endsWith(".")) {
        usages.set(id, { key, pattern: templateToPattern(key + "${}"), translated, file, line });
    } else {
        usages.set(id, { key, pattern: null, translated, file, line });
    }
};

for (const path of sourceFiles(appDir)) {
    const source = stripComments(readFileSync(path, "utf8"));
    const file = relative(root, path);
    for (const match of source.matchAll(namespacedLiteral)) {
        if (match[1] === '"' && directiveValueOpener.test(source.slice(Math.max(0, match.index - 80), match.index))) {
            continue;
        }
        record(match[2] ?? match[3], false, file, lineOf(source, match.index));
    }
    for (const match of source.matchAll(translateCall)) {
        const key = match[2] ?? match[3];
        // `t("")`, `t("/decks/…")` and friends are not keys — a translate call on a URL is
        // the name collision with `fetch`-style helpers, not a translation.
        if (key && /^[\w$]/.test(key) && !key.includes(" ") && !key.includes("/")) {
            record(key, true, file, lineOf(source, match.index));
        }
    }
}

/******************************************************************************
 * check usages
 *****************************************************************************/
const problems: Problem[] = [];
const resolved: Usage[] = [];

for (const usage of usages.values()) {
    const missingIn = locales.filter(locale => {
        const keys = [...messages[locale].keys()];
        if (usage.pattern) {
            return !keys.some(key => usage.pattern!.test(key));
        }
        if (messages[locale].has(usage.key)) {
            return false;
        }
        return usage.translated || !keys.some(key => key.startsWith(usage.key + "."));
    });
    if (missingIn.length) {
        problems.push({ usage, missingIn });
    } else if (usage.pattern) {
        resolved.push(usage);
    }
}

/** Keys defined in one locale only. */
const asymmetric = locales.flatMap(locale => {
    const others = locales.filter(other => other !== locale);
    return [...messages[locale].keys()]
        .filter(key => others.some(other => !messages[other].has(key)))
        .map(key => ({ key, onlyIn: locale }));
});

/** `@:key` / `@.lower:key` links inside the lang files that point at nothing. */
const brokenLinks = locales.flatMap(locale =>
    [...messages[locale]].flatMap(([key, value]) =>
        [...value.matchAll(/@(?:\.\w+)?:\{?'?([\w.-]+)'?\}?/g)]
            .filter(match => !messages[locale].has(match[1]))
            .map(match => ({ locale, key, target: match[1] }))
    )
);

/******************************************************************************
 * report
 *****************************************************************************/
const where = (usage: Usage) => chalk.gray(`${usage.file}:${usage.line}`);

if (problems.length) {
    console.log(chalk.bgRed.white(" MISSING "), chalk.cyan(`${problems.length} key(s) used but not defined:`));
    for (const { usage, missingIn } of problems.sort((a, b) => a.usage.file.localeCompare(b.usage.file))) {
        const label = usage.pattern
            ? chalk.magentaBright(usage.key) + chalk.gray(" (no key matches)")
            : chalk.redBright(usage.key);
        console.log(`  ${label} ${chalk.yellow(`[${missingIn.join(", ")}]`)} ${where(usage)}`);
    }
}
if (asymmetric.length) {
    console.log(chalk.bgRed.white(" LOCALE "), chalk.cyan(`${asymmetric.length} key(s) defined in one locale only:`));
    for (const { key, onlyIn } of asymmetric) {
        console.log(`  ${chalk.redBright(key)} ${chalk.yellow(`[only ${onlyIn}]`)}`);
    }
}
if (brokenLinks.length) {
    console.log(chalk.bgRed.white(" LINK "), chalk.cyan(`${brokenLinks.length} linked message(s) point at nothing:`));
    for (const { locale, key, target } of brokenLinks) {
        console.log(`  ${chalk.redBright(`@:${target}`)} in ${key} ${chalk.yellow(`[${locale}]`)}`);
    }
}
if (verbose) {
    console.log(chalk.bgBlue.white(" DYNAMIC "), chalk.cyan(`${resolved.length} pattern(s) matched at least one key:`));
    for (const usage of resolved) {
        console.log(`  ${chalk.greenBright(usage.key)} ${where(usage)}`);
    }
    console.log(
        chalk.bgBlue.white(" SKIPPED "),
        chalk.cyan(`${unverifiable.length} literal(s) with no static prefix:`)
    );
    for (const usage of unverifiable) {
        console.log(`  ${chalk.gray(usage.key)} ${where(usage)}`);
    }
}

const failed = problems.length + asymmetric.length + brokenLinks.length > 0;
console.log(
    [
        chalk[failed ? "bgRed" : "bgGreen"].white(failed ? " ERR " : " OK "),
        chalk.cyan(`${usages.size} key usage(s) checked against`),
        chalk.cyan(locales.join(" + ") + ","),
        chalk.cyan(`${resolved.length} dynamic,`),
        chalk.cyan(`${unverifiable.length} unverifiable.`)
    ].join(" ")
);
process.exit(failed ? 1 : 0);
