# GitHub Actions workflows

Three workflows live in `.github/workflows/`. Each is keyed off a different trigger so they don't step on one another.

## `ci.yml` — Continuous Integration

**Trigger:** every push to `main`, every pull request.

**Purpose:** quality gate. Runs three independent jobs in parallel:

- **PHP tests** — PHPUnit against in-memory SQLite (the `Local` testsuite from `phpunit.xml`).
- **Pint** — `composer pint` for PHP code style.
- **Frontend** — ESLint, Stylelint, `vue-tsc`, and a production Vite build to catch type errors and broken imports before merge.

CI must pass before deploys are tagged. Nothing in this workflow touches any server.

## `deploy-staging.yml` — Deploy to staging

**Trigger:** `workflow_dispatch` only — manual button in the **Actions** tab.

**Why manual?** The staging server is also used as a remote dev environment. Auto-deploying on every push to `main` would clobber in-progress work uploaded via Rsync. Triggering manually lets you decide when to snap staging to whatever's currently on `main` — typically before tagging a production release.

**What it does:** pulls the current `main` branch on the staging environment, installs PHP and Node dependencies, builds frontend assets, runs migrations, and warms the framework caches.

**Environment:** `staging` (configured under repo Settings → Environments). No protection rules — runs immediately on dispatch.

## `deploy-production.yml` — Deploy to production

**Trigger:** push of any `v*.*.*` tag (semver-style). Branch pushes do not trigger this workflow.

**Approval gate:** the `production` environment has a **Required reviewers** protection rule. After the tag is pushed, the workflow queues and pauses with a "Review pending deployment" notification. A reviewer must approve in the Actions UI before any server-side step runs. This means a malicious tag (or a mistakenly-pushed early tag) cannot auto-deploy.

**What it does:** the same pipeline as staging, but pinned to the tagged commit (`git reset --hard <tag>`) rather than `main`. The tag name is passed through to the production environment, validated against the semver regex on the server side, and used as the deploy target.

**Environment:** `production`.

## Releasing

The end-to-end release flow:

1. Bump `package.json` `version` (this is the single source of truth — see `README.md` § "Versioning").
2. Commit + push to `main`. CI runs.
3. (Optional) Trigger **Deploy to staging** manually to verify `main` works on a real environment before tagging.
4. Tag: `git tag vX.Y.Z && git push origin vX.Y.Z`.
5. Tag push triggers **Deploy to production**. Approve in the Actions tab when ready.

## Configuration

Server addresses, ports, deploy users, and SSH keys are stored as **environment-scoped** secrets and variables under repo Settings → Environments → `staging` / `production`. They are not committed to the repo. Splitting them per environment means staging and production can move independently (different host, different port, different key) without touching workflow YAML.

The actual deploy scripts that run on the server are *not* part of this repo. The workflows here only authenticate, hand control to a server-side script via SSH, and stream its output back to the Actions log. The server-side script is responsible for the actual `git`/`composer`/`npm`/`artisan` work.
