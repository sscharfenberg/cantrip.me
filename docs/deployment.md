# Deployment

cantrip.me runs on a home server, `debbie` (Debian 13: nginx with ModSecurity and the OWASP Core Rule Set,
php8.4-fpm, Postgres 17), which it shares with another app. Production is public at `https://cantrip.me`; staging is
`https://cantrip.local`, reachable on the LAN only. Deploys are started by hand from the workstation — no GitHub
Actions workflow touches the server.

The server-side scripts and units are committed in `resources/scripts/` for review; their live copies are on the
server, and a change only takes effect once it is installed there.

## Layout

|                               | production                                              | staging                                           |
|-------------------------------|---------------------------------------------------------|---------------------------------------------------|
| Code                          | `/var/www/cantrip.prod`, a git checkout                 | `/var/www/cantrip.staging`, uploaded              |
| Owner                         | `cantrip-deploy:cantrip`, dirs 2750, files 640          | the developer's account                           |
| `storage/`, `bootstrap/cache` | `cantrip:cantrip` 2770, default ACL `g::rwX`            | the developer's account                           |
| php-fpm pool                  | `cantrip-prod`, runs as `cantrip`                       | `cantrip-staging`, runs as the developer          |
| Database                      | Postgres `cantrip_prod`                                 | Postgres `cantrip_staging`                        |
| Card images, art crops, symbols | `/var/media/cantrip/…`, shared, via `storage/app/<disk>` symlinks | the same folders                     |
| Set icons                     | `storage/app/set`, its own                              | `storage/app/set`, its own                        |
| Scheduler                     | `cantrip-prod-schedule.timer`                           | none                                              |

The runtime user is `cantrip`, not `www-data`, so the other app on the box cannot read cantrip's `.env`. nginx
(`www-data`) gets read access to `public/` and the media folders through ACLs — not to the rest of the tree.

Because the image folders are shared, `scryfall:gc-images --prune` runs on production only: staging's dataset lags,
and pruning against it would delete images production still points at.

## Server configuration

The server is shared with another app. Global settings — `/etc/php/8.4/fpm/conf.d/`, nginx's `http` block, the
shared ModSecurity rule set — are that app's; cantrip sets what it needs in its own files, all named `cantrip*`
(which is also how the nightly [backup](#backups) finds them).

**Postgres.** Postgres listens on `localhost` only. Each environment has one database and one login role that owns
only that database and connects with a password (`scram-sha-256`). The databases use the ICU root locale, so names
sort the same whatever the language:

```sql
CREATE ROLE cantrip_prod LOGIN PASSWORD '…';
CREATE DATABASE cantrip_prod OWNER cantrip_prod TEMPLATE template0 ENCODING 'UTF8'
    LOCALE_PROVIDER icu ICU_LOCALE 'und' LOCALE 'en_GB.UTF-8';
```

The `citext` and `unaccent` extensions come from the `adapt_schema_to_postgres` migration; both are trusted
extensions, so the owner may create them.

The cluster is shared with the other app, and its memory settings live in `/etc/postgresql/17/main/conf.d/cantrip.conf`:
`shared_buffers` 2 GB, `effective_cache_size` 16 GB, `work_mem` 16 MB, `maintenance_work_mem` 512 MB. All databases
together are well under 1 GB, so they fit in `shared_buffers` whole, including the nightly Scryfall sync's shadow
tables. `shared_buffers` changes only on a restart (`systemctl restart postgresql@17-main`, a few seconds without a
database for both apps), the others on a reload.

**php-fpm.** Each environment has its own pool (`/etc/php/8.4/fpm/pool.d/cantrip-{prod,staging}.conf`) and socket.
The other app's global ini turns file uploads off for every pool, so both cantrip pools turn them back on
(`php_admin_flag[file_uploads] = on`); without that, every CSV upload answers 422 "The file field is required."
The request body limit is 3 MB in three places that must agree: `client_max_body_size` in nginx,
`post_max_size`/`upload_max_filesize` in the pool, and `SecRequestBodyLimit` in the WAF exclusions. The app itself
accepts CSV files up to 2 MB (`config/cantrip.php`).

Both pools restrict PHP's file access with `open_basedir` to the site tree, the shared image folders
(`/var/media/cantrip/`, where the `storage/app/<disk>` links lead), `/tmp/` (uploads, dompdf, `tempnam`), the pool's
session folder and, on production, `/var/log/php-cantrip/`. Each path ends in `/`, since `open_basedir` compares
prefixes. Laravel turns the warning a refused path raises into an exception, so **a new place the app reads or
writes outside these folders answers 500** until the pool's list includes it. Artisan on the command line (the
scheduler, deploys) is not affected.

**nginx.** `/etc/nginx/sites-available/cantrip.{prod,staging}`. Production redirects HTTP and `www.cantrip.me` to
`https://cantrip.me`, speaks HTTP/2, serves `/build` (cached for a year) and the image folders (30 days) without
PHP, and limits PHP requests per client IP (`conf.d/cantrip-limits.conf`: 30 per second, bursts of 60, then 429).
Staging answers on the LAN only, under `cantrip.local` (published over mDNS), with a self-signed certificate.

**HTTP/3.** Production also speaks HTTP/3 (QUIC on UDP 443) and offers it with an `Alt-Svc` header; a browser that
cannot reach UDP 443 stays on HTTP/2. Only cantrip listens on UDP 443, so the vhost carries the default server for
it, which refuses a handshake for any other name, and the one `reuseport` an address and port allows. UDP 443 is
open in the server's firewall (`/etc/nftables.conf`, IPv4) and forwarded by the router. nginx hands ModSecurity no
`Host` header for HTTP/3 and the Core Rule Set allows HTTP versions up to 2.0 only, so the WAF exclusions switch
those two protocol checks off for HTTP/3 requests; without that, every HTTP/3 request is refused. The access log
names the protocol of each request (`HTTP/3.0`), and DevTools shows it in the Network panel's Protocol column.

**DNS and TLS.** The zone is on Cloudflare, records DNS-only (no proxy). `ddclient` on the server keeps the A
records of `cantrip.me` and `www.cantrip.me` on the home connection's changing IPv4 address; there are no AAAA
records. The Let's Encrypt certificate is issued through the DNS-01 challenge against Cloudflare, so neither
issuing nor renewing it (`certbot.timer`, which reloads nginx) depends on port 80; cantrip uses port 80 only to
redirect to HTTPS, but the other app's certificate renews over it. ddclient and certbot each use their own
Cloudflare token, scoped to this zone.

## Deploying production

From the workstation, with [`ct`](#the-workstation-tool-ct):

```bash
ct deploy --check --prod      # would main deploy? (CI gate only, changes nothing)
ct deploy --prod              # deploy origin/main
ct deploy <sha> --prod        # deploy a specific commit — the rollback
```

`ct deploy` first refuses, on the workstation, while `HEAD` has commits `origin/main` lacks: the deploy would report
success with older code. The check fails closed (no clone, no reachable `origin`: no deploy) and is skipped for a
SHA, which is a deliberate choice. Then it starts `/usr/local/bin/cantrip-prod-deploy` on the server as
`cantrip-deploy` (`ssh -t debbie sudo -u cantrip-deploy cantrip-prod-deploy …`); every other guard is in that script:

1. **Lock.** A second deploy while one runs is refused.
2. **Clean tree.** A hand-edited checkout is refused rather than silently reset.
3. **CI gate.** GitHub's check runs for the exact commit (public repo, no token) must show the five checks the
   `main: required CI checks` ruleset requires as successful. CI runs on every push to `main`, so every `main`
   commit has them. `--skip-ci` deploys a commit CI never ran on and says so loudly.
4. **Maintenance mode** with the 503 page pre-rendered, so it survives the code swap. A site already in maintenance
   before the deploy stays in maintenance after it.
5. `git checkout` (a SHA is checked out detached; the next plain deploy returns to `main`), `composer install
   --no-dev`, `npm ci`, `npm run type-check`, `npm run build-only`, `npm run icons` — not `npm run build`, whose lint
   `--fix` would rewrite tracked files on the server.
6. The `public/` links of `config/filesystems.php` and nginx's read ACL on `public/`.
7. `migrate --force` and the config, route, view and event caches, as `cantrip`; a php-fpm reload.
8. **Health check** through nginx on the server; then maintenance mode is lifted.

**A failed deploy leaves the site in maintenance mode on purpose** — new code against a half-applied migration is
worse than a maintenance page. Fix the cause and deploy again, or roll back to the last good SHA. To lift maintenance
without deploying: `ct artisan up --prod`.

composer and npm run as `cantrip-deploy`, never as root: their install scripts are third-party code. The only
privileged steps are allowlisted in `/etc/sudoers.d/cantrip-deploy`: artisan as `cantrip`, and the php-fpm reload.

## Release flow

1. (Optional) Bump `package.json` `version` if the release is user-visible — see [Versioning](github-actions.md#versioning).
2. Merge a pull request once its CI is green, or push to `main` (admin bypass). CI runs on `main`.
3. `ct deploy --prod` once `main`'s checks are green.

## Raising Node or npm in `engines`

`package.json#engines` is a requirement, not a hint: the `preinstall` hook (`resources/build/checkVersions.ts`) exits
1 when the running Node or npm is outside its range, and npm 12 runs that hook before `npm ci` installs anything. The
deploy runs `nvm use 26` (`NODE_MAJOR` in the script) — the newest Node 26 installed for `cantrip-deploy`.

**A commit that raises `engines` needs the new versions on the server before it is deployed**, or `npm ci` fails
after maintenance mode is already on. Install them first, Node and npm in one go — every Node nvm installs brings its
own npm (Node 26 bundles npm 11), and `nvm use 26` would pick up a new 26.x whose npm is still 11:

```bash
ssh -t debbie sudo -u cantrip-deploy -H bash -c 'cd && . ~/.nvm/nvm.sh && nvm install 26.14.0 && npm install --global "npm@^12.2.1" && node -v && npm -v'
```

A new Node major also needs `NODE_MAJOR` changed in `cantrip-prod-deploy`. Old versions go with `nvm uninstall`.

## Scheduler

`cantrip-prod-schedule.timer` starts `cantrip-prod-schedule.service` every minute: one `artisan schedule:run` as
`cantrip`. While a run is still going — `scryfall:update` takes about 5 minutes in the foreground — the next
triggers are skipped, not queued; nothing else is scheduled in that window. Staging runs no scheduler. Scheduled
tasks: [docs/artisan-commands.md](artisan-commands.md#scheduled-tasks).

The server's clock runs on UTC, and a systemd timer (`OnCalendar=`) fires in UTC unless it names a zone. The Laravel
scheduler fires in the app timezone, Europe/Berlin. So a scheduled task and a UTC timer move by an hour against each
other when daylight saving time starts or ends.

## Backups

- **00:15 Berlin time** (Laravel scheduler) `db:backup` writes a `pg_dump` to `storage/app/db-backups` (7 days),
  readable by the `cantrip` group only.
- **01:30 UTC** plus a random delay of up to 15 minutes (systemd timer; 02:30 Berlin time in winter, 03:30 in summer):
  `cantrip-backup.timer` runs restic as root and copies the dumps, `.env` (its `APP_KEY` decrypts the 2FA
  secrets), prod's set icons and the server's cantrip configuration (nginx vhosts and WAF exclusions, php-fpm pools,
  Postgres settings, systemd units, sudoers, ddclient, certbot, the scripts in `/usr/local`, the credentials in
  `/root/cantrip`) into two repositories: one on the server's USB disk, one in Backblaze B2 (region EU Central). The
  card images, art crops and symbols (`/var/media/cantrip`) go to the USB disk only, as a snapshot of their own (tag
  `cantrip-media`): Scryfall could serve them again, but not without a quarter of a million requests. Both
  repositories are encrypted by restic with one password, which also lives outside the server. Retention: 7 daily,
  5 weekly, 12 monthly snapshots per tag; on Sundays restic reads back a tenth of the data.
- The B2 key cannot delete. What restic removes is only hidden, and the bucket deletes hidden versions after 90 days:
  a break-in on the server can hide the off-site copy, not destroy it.
- A failed run alerts through `cantrip-backup-failed.service` (Discord and a push notification).

**Restore:** as root on the server (or any machine with restic, the B2 key and the password),
`restic -r <repository> snapshots`, then `restic -r <repository> restore latest --tag cantrip-prod --target /tmp/restore`, then
`php artisan db:restore --file=<the restored dump>` — see [docs/artisan-commands.md](artisan-commands.md#php-artisan-dbrestore).

## Staging

Staging is the remote development server. Code reaches it as an upload of the working tree, with `vendor/` and
`public/build` (plus the icon sprite) built on the workstation; exclude `public/hot`, `.env` and `storage/`. No Vite
dev server runs there. `ct artisan …` runs artisan on staging; Scryfall syncs there are started by hand
(`ct artisan scryfall:update`).

## The workstation tool `ct`

`resources/scripts/ct` (macOS, bash 3.2) runs commands against either site over ssh; the target flag may appear
anywhere, and **staging is the default** — production is only touched with `--prod`.

```bash
install -m 755 resources/scripts/ct ~/.local/bin/ct
install -m 644 resources/scripts/_ct ~/.oh-my-zsh/custom/completions/_ct   # then: rm -f ~/.zcompdump* && exec zsh

ct artisan migrate:status          # staging
ct artisan down --prod             # production, as cantrip
ct tinker --prod
ct logs -f --scryfall --prod       # app.log; --scryfall, --schedule, --cron
ct shell --prod
ct status --prod                   # deployed commit, maintenance mode, scheduler
ct deploy --prod
```

Destructive artisan commands (`migrate:fresh`, `db:wipe`, `db:restore`, `db:copy-from-mariadb`, …) ask for the target's
name to be typed first, on both targets: Laravel's own prompt never fires at the far end of ssh.

`ct deploy --prod` looks for the local clone at `~/workbench/4.Private/cantrip.me`; set `CANTRIP_REPO` when it lives
elsewhere.

## WAF

ModSecurity with the OWASP Core Rule Set guards production and staging. cantrip's exclusions — the request fields that
carry user free text — live on the server, not in this repository. **A new free-text field must be added there**, or
its users get 403s.

- The exclusions are `/etc/nginx/modsec/cantrip.conf`, loaded only by cantrip's two vhosts (`modsecurity on;` and
  `modsecurity_rules_file` inside the PHP location); the other app's ModSecurity files stay untouched.
- A field goes into the rule that lists the free-text fields **in both spellings**: `ARGS:<field>` for query strings
  and form posts, and `ARGS:json.<field>` for JSON bodies, which is how libmodsecurity names JSON keys. An element of
  a JSON array is `json.<field>.array_<n>.<key>`; `ctl` targets take no regular expression, so a route whose array
  rows carry free text is excluded as a whole.
- A rule in the vhost-loaded file runs **before** the shared rules of the same phase, so it cannot override a variable
  a shared rule sets later; remove the offending rule for the case instead.
- Test a change in a throwaway nginx on the server bound to `127.0.0.1` with the same rule set, never first on a
  live vhost; then `nginx -t` and `systemctl reload nginx`.
- A 403 on a route whose `authorize()` passes is the WAF: the ModSecurity audit log on the server names the rule.
- **A daily report, no bans.** At 07:00 Berlin time `cantrip-waf-report.timer` lists the requests the WAF refused on
  production since its last run. A refused request that carried cantrip's session cookie was almost certainly the
  app's own, i.e. a false positive, and those are reported like the Scryfall alerts, by mail to `APP_CONTACT` and to
  Discord: path, rule, and the request field the rule matched in, never the value or the client. Refusals without
  the cookie (scanners, bots) are only counted. The full list is in `journalctl -u cantrip-waf-report`, with each
  entry's audit-log id. No fail2ban jail acts on WAF refusals: the request is refused either way, and a jail would
  turn a false positive into a ban of a real user.
