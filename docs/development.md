# Development

## Running the dev environment

### `composer dev`

Starts all development services in parallel (via `concurrently`):

* `php artisan serve` — Laravel dev server
* `php artisan queue:listen` — Queue worker
* `php artisan pail` — Real-time log viewer
* `npm run dev` — Vite dev server

## NPM commands

| Command | Description |
|---------|-------------|
| `npm run dev` | Vite dev server (HMR) |
| `npm run build` | Lint + type-check + Vite production build + icon processing |
| `npm run lint` | ESLint + Stylelint with auto-fix, then `i18n:check` |
| `npm run i18n:check` | Translation keys used in `resources/app` but missing from `lang/*.json` |
| `npm run format` | Prettier |
| `npm run type-check` | `vue-tsc --build` |
| `npm run icons` | Process SVG icons into sprite sheet |

### Vite dev server

Ensure `.env` has `APP_ENV=local`, `APP_DEBUG=true`, and `APP_URL` pointing to the correct host. The `public/hot` file must be present for Vite HMR to work.

### Production build

Ensure `.env` has `APP_ENV=production`, `APP_DEBUG=false`, and `APP_URL` pointing to the production domain. The `public/hot` file must *not* be present.

### Translation check

`npm run i18n:check` (`resources/scripts/checkTranslations.ts`) fails when the frontend uses a translation key that `lang/de.json` or `lang/en.json` does not define. vue-i18n renders a missing key as the key itself, so without this the mistake only shows up as `pages.foo.bar` in the UI. It runs as the last step of `npm run lint`, and therefore of `npm run build` and CI.

What it checks:

* the first argument of every `t()` / `$t()` / `te()` call
* every string literal starting with a top-level lang namespace (`pages.`, `form.`, `enums.` …) — catches keys handed around before they are translated, e.g. breadcrumb `labelKey`s
* keys defined in only one of the two locales
* linked messages (`@:key`) inside the lang files that point at nothing

Dynamic keys — `` `form.fields.deck_bracket_${n}` `` or `"enums.conditions." + value` — pass if they match at least one key in each locale. That proves the pattern is not dead, not that every runtime value has a key. A key built entirely from variables (`` `${prefix}${value}` ``) cannot be checked; its prefix is, wherever it is passed in. Comments, specs and Vue directive values (`:key="card.id"`) are ignored.

`npm run i18n:check -- -v` also lists the dynamic keys it resolved and the ones it skipped.

## IDE setup

I am using IntelliJ — other IDEs probably work as well; I just don't know them.

### A) Prettier

Prettier needs to run on save.

**IntelliJ:**

* `Settings` → `Languages & Frameworks` → `Javascript` → `Prettier`
* Select `Automatic Prettier configuration`
* Run for files: `**/*.{js,ts,json,vue,scss}`
* `Run on save` must be checked

### B) ESLint

ESLint should run while editing in the IDE.

**IntelliJ:**

* `Settings` → `Languages & Frameworks` → `Javascript` → `Code Quality Tools` → `ESLint`
* Select `Automatic ESLint configuration`
* Run for files: `**/*.{js,ts,html,vue}`
* `Run on eslint --fix on save` must be checked

### C) Stylelint

Stylelint should run while editing in the IDE. Doesn't work well in `.vue` files currently.

**IntelliJ:**

* `Settings` → `Languages & Frameworks` → `Style Sheets` → `Stylelint`
* Select `Enable`
* Run for files: `**/*.{scss, vue}`
* `Run on stylelint --fix on save` must be checked
