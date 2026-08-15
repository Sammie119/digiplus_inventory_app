# Deployment Runbook

Covers deploy, rollback, and backup/restore for DigiPlus Inventory Solutions.

**Scope note:** this repo has no Dockerfile, CI workflow, or Procfile — there is no
automated pipeline today. Deploys are assumed to be manual/scripted against a
traditional PHP host (Nginx or Apache + PHP-FPM, MySQL, a persistent filesystem).
If you introduce CI/CD or containers, update this doc alongside that change.

## 1. Stack summary (what actually runs)

| Concern | What this app uses |
|---|---|
| App | Laravel 13, PHP 8.3, served via PHP-FPM/Nginx (or `php artisan serve` for demos only) |
| UI | Livewire 3 + Volt — server-rendered, no separate frontend SPA/build to deploy |
| Assets | Vite (`npm run build`) — Tailwind CSS + the Reverb JS client config |
| DB | MySQL in production (`DB_CONNECTION=mysql` in the live `.env`); SQLite is only the local-dev default in `.env.example` |
| Session / Cache / Queue | All `database` driver (`sessions`, `cache`, `jobs` tables) |
| Realtime | Laravel Reverb (self-hosted WebSocket server), used today only for in-app notification toasts (`App\Events\NotificationCreated`, private channel per user). Despite the SRS's aspiration for live stock/sales dashboards, no code currently broadcasts stock or sales changes — those pages are plain server-rendered Livewire, refreshed on request/interaction, not pushed. |
| Background jobs | **None currently dispatched.** `app/Jobs` is empty and no `Mail`/event class implements `ShouldQueue` — scheduled emails are sent synchronously inside the console command that triggers them. A `queue:work` process is still good practice as a safety net for future work, but nothing today depends on it running. |
| Scheduled tasks | Laravel's scheduler, defined in `bootstrap/app.php` — requires a single cron entry on the server (see §3.4) |
| File storage | Local disk only (`storage/app/private`, Laravel's `local` filesystem disk). `AWS_*` env vars exist in `.env.example` but nothing in the app writes to S3 — backups and generated files live on the app server's own disk. |

## 2. Deploy steps

Run from the repo root on the target server, in order. If any step fails, stop —
do not continue to `migrate` on a partially-updated codebase.

```bash
# 1. Put the app in maintenance mode (uses the file driver — no DB dependency)
php artisan down --render="errors::503" --retry=60

# 2. Pull code
git fetch origin
git checkout <release-tag-or-commit>

# 3. Install dependencies
composer install --no-dev --optimize-autoloader
npm ci
npm run build

# 4. Run migrations
php artisan migrate --force

# 5. Rebuild caches
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 6. Restart long-running processes so they pick up new code
#    (process manager names are illustrative — adjust to whatever supervises them on your host)
sudo systemctl restart php-fpm
sudo supervisorctl restart reverb:*
sudo supervisorctl restart queue-worker:* 2>/dev/null || true   # only if you run one — see §1

# 7. Bring the app back
php artisan up
```

Notes:
- `--force` is required for `migrate` in production because `APP_ENV` is not `local`; this is intentional friction, not a bug to work around.
- `config:cache` caches the *current* `.env` values into a compiled file. If you change `.env` after this step, you must re-run `php artisan config:cache` (or `config:clear` if you want live `.env` reads) — a stale config cache is a common source of "I changed the env var but nothing changed" incidents.
- Reverb (`php artisan reverb:start`) is a long-running process, not a request-triggered one. It must be supervised (systemd/supervisor) and restarted on every deploy, or users stop receiving live notification toasts until it's restarted — the rest of the app keeps working normally since nothing else depends on it.

## 3. First-time / environment setup

Only needed once per environment, not on every deploy.

### 3.1 Application key & env
```bash
cp .env.example .env
# set DB_*, APP_URL, MAIL_*, REVERB_* for the real environment
php artisan key:generate
```

### 3.2 Database
```bash
php artisan migrate --force
```
There are no seeders wired for production tenant data — the first tenant/Super
Admin account is created through whatever registration/seeding flow the team
uses (check `database/seeders` before assuming one exists for your environment).

### 3.3 Storage
```bash
php artisan storage:link
chmod -R 775 storage bootstrap/cache
```
`storage/app/private` (the `local` disk root) is where **both** backup types
(§5) and any tenant-uploaded files land. This directory must:
- persist across deploys (it's not part of the git-deployed codebase),
- be on a volume that's actually backed up at the infrastructure level — see §5's warning about backups living on the same disk as the app.

### 3.4 Cron (required for scheduled reports, reminders, and backups)
Add one line to the deploy user's crontab:
```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```
Without this cron entry, none of the following silently run — there's no error
anywhere, they simply never fire:
- `reports:send-scheduled` — daily 06:00 (scheduled sales report emails)
- `subscriptions:send-renewal-reminders` — daily 07:00
- `backups:run-scheduled` — weekly, Monday 02:00 (per-tenant CSV export)
- `database:backup` — daily 03:30 (full SQL dump)

Verify it's wired up with:
```bash
php artisan schedule:list
```

### 3.5 Reverb (WebSocket server)
```bash
php artisan reverb:start --host=0.0.0.0 --port=8080
```
Run this under a process supervisor (systemd unit or Supervisor program), not
in a plain shell. Confirm `REVERB_*` env vars match on both the server side and
the `VITE_REVERB_*` values baked into the built frontend assets — a mismatch
here is the most common cause of notifications silently not appearing (see the
incident-response doc).

## 4. Rollback

There are no down-migrations tested/maintained beyond what Laravel generates by
default, and no automated rollback pipeline. Rollback is manual:

```bash
php artisan down --retry=60

# Revert code to the previous known-good commit/tag
git checkout <previous-release-tag>
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# Only run this if the bad release included migrations AND you've verified
# the down() methods are safe for your current data — see warning below
php artisan migrate:rollback --force --step=<N>

php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo systemctl restart php-fpm
sudo supervisorctl restart reverb:*
php artisan up
```

**Migration rollback warning:** this codebase's migrations were written as
forward-only feature additions (see the near-daily migration history under
`database/migrations`). Several later migrations *add* columns/tables that
earlier code doesn't know about; rolling back code without also rolling back
the schema can leave the previous release querying columns that no longer
exist, or vice versa. Prefer **rolling forward with a fix** over rolling back
the schema whenever the previous release's code is still compatible with the
current schema (i.e., the bad release only changed application code, not
migrations). Only run `migrate:rollback` when the bad release's migration is
the actual problem, and confirm the `down()` method for that specific
migration does what you expect before running it against production data.

## 5. Backup / restore

There are two independent, unrelated backup mechanisms. Read both sections —
they are not interchangeable, and **neither has an automated restore path.**

### 5.1 Full database backup (Super Admin only, all tenants)
- Command: `php artisan database:backup` (also runs daily 03:30 via cron)
- Code: `App\Actions\Backups\GenerateDatabaseBackup`
- Output: a raw `.sql` dump (`DROP TABLE IF EXISTS` + `CREATE TABLE` + `INSERT`
  for every table in the database) written to
  `storage/app/private/database-backups/<timestamp>.sql`
- Tracked in the `database_backups` table; downloadable from
  `/super-admin/database-backups` (Super Admin UI)
- Retention: the 14 most recent are kept; older ones are deleted (file + row)
  automatically on each run
- **This dump contains every tenant's data, not one.** Restoring it replaces
  the entire database, not a single tenant.

**Restore procedure (manual — no `artisan` command performs this):**
```bash
php artisan down
mysql -u <user> -p <database> < /path/to/database-backups/<timestamp>.sql
php artisan up
```
Because each table statement starts with `DROP TABLE IF EXISTS`, this is a
full destructive replace of the database — take a fresh backup of current
state *before* restoring an old one, in case the restore itself needs undoing.

### 5.2 Per-tenant data export (tenant-facing, one tenant only)
- Command: `php artisan backups:run-scheduled` (also runs weekly, Monday 02:00)
- Code: `App\Actions\Backups\GenerateTenantBackup`
- Output: a `.zip` of CSV files (one per table: items, sales, stock levels,
  purchases, customers, etc. — 23 tables), scoped to a single tenant, written
  to `storage/app/private/backups/<tenant_id>/<timestamp>.zip`
- Tracked in the `tenant_backups` table; downloadable by the tenant from their
  own **Backups** page (`/backups`)
- Retention: the 10 most recent per tenant are kept
- **This is a data export for portability, not a restore-capable backup.**
  It's CSV, not SQL, and there is no import/restore command anywhere in the
  codebase that consumes it. If a tenant needs their data restored from one of
  these, someone has to write a one-off import script against the CSVs — budget
  time for that; it is not a same-day operation today.

### 5.3 Where backups actually live — the gap to know about
Both backup types are written to the **same local disk the app runs on**
(`storage/app/private`, via the `local` filesystem disk — `AWS_*` env vars are
present but unused; nothing writes to S3). If the app server or its disk is
lost, the backups are lost with it. Until off-box replication is added, treat
this as a real gap:
- sync `storage/app/private/database-backups` and `storage/app/private/backups`
  to off-server storage (e.g. a nightly `rsync`/`aws s3 sync` cron job) as part
  of environment setup, not as an afterthought.

## 6. Post-deploy smoke check

After any deploy, before closing it out:
```bash
curl -fsS https://<app-url>/up          # Laravel's health route (bootstrap/app.php: health: '/up')
php artisan schedule:list               # confirm scheduled tasks are still registered
```
Then in the browser: log in as a tenant user, load the dashboard, and place one
test POS sale end-to-end (see the incident-response doc's stock-count checks
for what "working" looks like).
