# GitHub Actions workflows

One workflow lives in `.github/workflows/`: `ci.yml`. Nothing on GitHub touches a server — deploys are started from
the workstation, see [docs/deployment.md](deployment.md).

## Versioning

Single source of truth: **`package.json`** (`version` field). `config/app.php` reads it directly (frozen into the cached config at deploy time, so no per-request file reads). There is **no** `APP_VERSION` in `.env` — it isn't read.

To cut a release:

1. Bump `"version"` in `package.json` (e.g. `1.0.2` → `1.0.3`)
2. Commit and push to `main`, or merge the pull request
3. Deploy with `ct deploy --prod` once `main`'s CI is green

On the next deploy `php artisan config:cache` re-reads `package.json` and the new value is reflected in `config('app.version')`. Version bumps are decoupled from deploys — you can deploy without bumping (e.g. urgent bugfix that's user-invisible) and you can bump without deploying (until the next deploy).

## `ci.yml` — Continuous Integration

**Trigger:** every push to `main`, every pull request.

**Purpose:** quality gate. Runs independent jobs in parallel:

- **PHP tests** — PHPUnit against in-memory SQLite (the `Local` testsuite from `phpunit.xml`).
- **Pint** — `composer pint` for PHP code style.
- **Frontend build** — ESLint, Stylelint, the translation-key check, `vue-tsc`, and a production Vite build to catch type errors and broken imports before merge.
- **Frontend tests** — the Vitest suite.
- **End-to-end tests** — the Playwright suite (`npm run e2e`), with its own Postgres, build and server.
- **Publish test badges** — refreshes the README badges; runs on `main` only and is skipped on pull requests.

CI must pass before deploying: the deploy script refuses a commit whose five required checks are not green on GitHub (see [docs/deployment.md](deployment.md#deploying-production)). Nothing in this workflow touches any server.

## Branch rules on `main`

Two repository rulesets apply to `main` (Settings → Rules → Rulesets):

- **`main`** — `main` cannot be deleted or force-pushed, and every commit must be signed. **No bypass**, for anyone.
- **`main: required CI checks`** — a change to `main` needs the five CI checks above to pass: `PHP tests`, `Pint`, `Frontend build`, `Frontend tests`, `End-to-end tests`. A pull request's merge button stays blocked until they are green. `Publish test badges` is deliberately not required, since pull requests skip it. Branches do *not* have to be up to date with `main` before merging — requiring that would force a rebase of every open Dependabot PR after each merge.

  **Repository admins may bypass this ruleset.** That keeps direct pushes to `main` possible for small changes and version bumps, and lets an admin merge a red PR — but only by ticking an explicit bypass. Dependabot and contributor PRs are fully gated.

The admin bypass lives on its own ruleset on purpose: bypass is granted per ruleset, not per rule, so adding it to `main` would have let admins skip signatures and force-push protection too.

The deploy script's CI gate names the same five checks. Renaming a CI job means changing both the ruleset and `REQUIRED_CHECKS` in `resources/scripts/cantrip-prod-deploy` (and its live copy); until then the gate refuses every deploy.

Changes that touch shared code are best done on a branch with a pull request: CI then runs *before* anything reaches `main`, so a failure never leaves `main` (and therefore the next deploy) broken.
