# GitHub Actions workflows

Three workflows live in `.github/workflows/`. Each is keyed off a different trigger so they don't step on one another.

## Versioning

Single source of truth: **`package.json`** (`version` field). `config/app.php` reads it directly (frozen into the cached config at deploy time, so no per-request file reads). There is **no** `APP_VERSION` in `.env` — it isn't read.

To cut a release:

1. Bump `"version"` in `package.json` (e.g. `1.0.2` → `1.0.3`)
2. Commit and push to `main`
3. Trigger **Deploy to production** from the Actions tab and approve when prompted

On the next deploy `php artisan config:cache` re-reads `package.json` and the new value is reflected in `config('app.version')`. Version bumps are decoupled from deploys — you can deploy without bumping (e.g. urgent bugfix that's user-invisible) and you can bump without deploying (until the next manual dispatch).

## `ci.yml` — Continuous Integration

**Trigger:** every push to `main`, every pull request.

**Purpose:** quality gate. Runs three independent jobs in parallel:

- **PHP tests** — PHPUnit against in-memory SQLite (the `Local` testsuite from `phpunit.xml`).
- **Pint** — `composer pint` for PHP code style.
- **Frontend** — ESLint, Stylelint, `vue-tsc`, and a production Vite build to catch type errors and broken imports before merge.

CI must pass before deploying. Nothing in this workflow touches any server.

## `deploy-staging.yml` — Deploy to staging

**Trigger:** `workflow_dispatch` only — manual button in the **Actions** tab.

**Why manual?** The staging server is also used as a remote dev environment. Auto-deploying on every push to `main` would clobber in-progress work uploaded via Rsync. Triggering manually lets you decide when to snap staging to whatever's currently on `main` — typically before deploying to production.

**What it does:** pulls the current `main` branch on the staging environment, installs PHP and Node dependencies, builds frontend assets, runs migrations, and warms the framework caches.

**Environment:** `staging` (configured under repo Settings → Environments). No protection rules — runs immediately on dispatch.

## `deploy-production.yml` — Deploy to production

**Trigger:** `workflow_dispatch` only — manual button in the **Actions** tab. Pick the branch you want to deploy from (typically `main`); the workflow captures that branch's HEAD commit SHA at dispatch time.

**Approval gate:** the `production` environment has a **Required reviewers** protection rule. After dispatch, the workflow queues and pauses with a "Review pending deployment" notification. A reviewer must approve in the Actions UI before any server-side step runs. The reviewer gate is the actual security control — anyone with `actions: write` can queue a deploy, but only an approved reviewer can land it.

**What it does:** the same pipeline as staging, but pinned to the commit SHA captured at dispatch time (`git reset --hard <sha>`) rather than `main`. This means a reviewer-approval delay doesn't get poisoned by a subsequent push to `main` — what was reviewed is what deploys. The SHA is passed through to the production environment, validated as 40 hex chars on the server side, and used as the deploy target.

**Environment:** `production`.

**Audit trail:** every run records a GitHub Deployment under the repo's **Deployments** tab — timestamp, commit SHA, actor, status. No tags are created; the Deployments view is the source of truth for "what's in prod right now".

## End-to-end release flow

1. (Optional) Bump `package.json` `version` if the release is user-visible (see [Versioning](#versioning) above).
2. Commit + push to `main`. CI runs.
3. (Optional) Trigger **Deploy to staging** manually to verify `main` works on a real environment before deploying to prod.
4. Trigger **Deploy to production** manually. Approve in the Actions tab when prompted.

## Configuration

Server addresses, ports, deploy users, and SSH keys are stored as **environment-scoped** secrets and variables under repo Settings → Environments → `staging` / `production`. They are not committed to the repo. Splitting them per environment means staging and production can move independently (different host, different port, different key) without touching workflow YAML.

The actual deploy scripts that run on the server are *not* part of this repo. The workflows here only authenticate, hand control to a server-side script via SSH, and stream its output back to the Actions log. The server-side script is responsible for the actual `git`/`composer`/`npm`/`artisan` work.
