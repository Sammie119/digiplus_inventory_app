# Incident Response Runbook

For on-call triage of DigiPlus Inventory Solutions. Pairs with
[deployment.md](./deployment.md) for backup/restore and process details.

## 1. How tenant isolation actually works (read this first)

Almost every incident here is either "tenant A saw/affected tenant B's data"
or "this tenant's data looks wrong" — both require knowing the isolation
mechanism to triage correctly.

- **Single database, single schema.** Every tenant-owned table has a
  `tenant_id` column. There is no database-per-tenant separation.
- **Enforcement is a global Eloquent scope**, not per-query discipline. Models
  that use the `App\Models\Concerns\BelongsToTenant` trait get
  `App\Models\Scopes\TenantScope` auto-applied to every query.
- **The scope fails closed.** `TenantScope::apply()` filters to
  `CurrentTenant::id()` when a tenant is resolved; if none is resolved it adds
  `WHERE 1 = 0` — i.e. the query returns nothing rather than everything. A
  symptom of "no tenant context" is data silently disappearing (looks like
  "records are missing"), not a leak. Keep this in mind before assuming a
  missing-data report is data loss — check §4 first.
- **`CurrentTenant` (`app/Support/CurrentTenant.php`) is an in-memory static,
  set per request.** In HTTP requests, `App\Http\Middleware\SetCurrentTenant`
  (appended to the `web` middleware group) sets it from `$user->tenant_id`
  right after auth resolves, and also sets Spatie's permission "team" ID to
  the same value so roles/permissions are tenant-scoped too.
- **Console commands have no request, so no middleware runs.** Every
  scheduled command that touches tenant data (`RunScheduledTenantBackups`,
  `SendScheduledReports`, `SendSubscriptionRenewalReminders`) manually calls
  `CurrentTenant::set($tenantId)` before doing work and `CurrentTenant::clear()`
  in a `finally` block after. If you write or modify any console command or
  job that touches `BelongsToTenant` models, it must do the same, or every
  query in it will hit the fail-closed `1=0` branch.
- **Deliberate cross-tenant access exists in two forms**, both explicit
  opt-ins — grep for these when investigating a suspected leak:
  - `Model::withoutTenantScope()` — used by Super Admin screens/console tooling.
  - `$query->forTenant($tenantId)` — targeted cross-tenant access for a specific tenant.
  Any `BelongsToTenant` model queried *without* one of these and *without* a
  resolved `CurrentTenant` is a bug, not a feature — treat it as one.
- **A known ordering gotcha, already handled once, worth knowing about:**
  `TenantBackupDownloadController` resolves its model manually instead of via
  route-model binding, because implicit binding runs during
  `SubstituteBindings`, which fires *before* `SetCurrentTenant` sets the tenant
  context — so the tenant scope wouldn't be active yet at binding time. It
  then explicitly checks `$backup->tenant_id === Auth::user()->tenant_id`
  before allowing the download. If you add new tenant-scoped
  download/show routes using implicit route-model binding, check whether the
  same ordering issue applies — it will, for any tenant-scoped model resolved
  before the `web` middleware group's `SetCurrentTenant` has run.
- **Suspended tenants:** `App\Http\Middleware\EnsureTenantActive` blocks any
  non-super-admin user whose tenant has `status = 'suspended'` with a 403. If
  a whole tenant reports being locked out, check `tenants.status` first.

### Suspected cross-tenant data exposure — triage steps
1. Identify the model/route involved. Check whether it uses
   `BelongsToTenant` (most domain models do — items, sales, stock, customers,
   etc. — check `app/Models/*.php` for the trait).
2. If it's a controller (not a Volt/Livewire page), check whether it resolves
   models via implicit route-model binding *before* `SetCurrentTenant` could
   have run, and whether it does its own tenant ownership check afterward (see
   `TenantBackupDownloadController` as the reference pattern).
3. Check for `withoutTenantScope()`/`forTenant()` calls near the code path —
   confirm the tenant ID used is actually the requesting user's, not an
   attacker-controlled input (e.g. a route parameter) used without an
   ownership check.
4. Check `audit_logs` (see §5) for the affected records — every
   create/update/delete on an audited model is logged with the acting user
   and tenant, which tells you what was actually touched and by whom.
5. If confirmed, this is a data isolation bug — treat as a security incident:
   contain (disable the route/feature if possible), assess blast radius via
   `audit_logs`, then fix and add a regression test before reopening.

## 2. Stock counts not updating

There is **no caching layer and no real-time push** for stock levels — the
stock-levels page, dashboard, and reports all read `stock_levels.quantity_on_hand`
directly from the database on each request/Livewire interaction. So "not
updating" is almost never a cache-invalidation problem; it's one of these,
roughly in order of likelihood:

1. **Stale browser tab.** Since there's no websocket push for stock (Reverb is
   only wired up for notification toasts today, not stock/sales — see
   deployment.md §1), a page left open won't reflect another device's sale
   until it's reloaded or the Livewire component re-runs. First step, always:
   hard-refresh the page before investigating further.

2. **An offline POS sale hasn't synced yet.** The POS page
   (`resources/views/livewire/pages/pos/index.blade.php`) queues sales in the
   browser's `localStorage` (key `pos_offline_queue`) via Alpine when
   `navigator.onLine` is false, and syncs them back to the server when the
   browser detects it's online again. If a cashier's device never regains
   connectivity, has its storage cleared, or the sync call itself is failing
   silently, that sale — and its stock deduction — never reaches the server.
   Check on the cashier's device (browser dev tools → Application →
   Local Storage → `pos_offline_queue`) before assuming a server-side bug.

3. **The sale was flagged as an oversell, not blocked.** Per FR-5.6, offline
   sync doesn't reserve stock live — if a synced offline sale would drive
   `quantity_on_hand` negative, the system doesn't reject it; it sets
   `sales.offline_flagged_oversell = true` and stock still goes negative,
   pending Store Keeper review. Query for these directly, there's no
   dedicated inbox UI for them yet:
   ```php
   Sale::withoutTenantScope()->where('offline_flagged_oversell', true)->get();
   ```
   A negative `quantity_on_hand` you can't explain is very likely one of these.

4. **`CurrentTenant` wasn't resolved when the write happened.** If a
   stock-affecting write path is ever run outside a normal authenticated
   request (a custom script, a misconfigured job, a console command missing
   the `CurrentTenant::set()`/`clear()` pattern from §1), the `StockMovement`/
   `StockLevel` writes will hit the tenant scope's fail-closed path and
   effectively no-op or write to the wrong place. Check `storage/logs/laravel.log`
   around the time of the transaction for exceptions — a `DB::transaction()`
   failing partway through **will** roll back cleanly (see below), so this
   shows up as "the sale wasn't recorded at all," not partial/incorrect stock.

5. **Confirm the write is transactional (it should be).** Stock-affecting
   flows (POS sales, purchases/goods-received, stock adjustments, branch
   transfers) each wrap their `StockMovement` + `StockLevel` writes in a
   `DB::transaction()` (see `App\Actions\Sales\RecordSale` as the reference
   implementation — purchases, adjustments, and transfers follow the same
   create-movement-then-adjust-level pattern in their own Livewire/Volt
   components). If you find a *partial* update — a `StockMovement` row with no
   matching change to `StockLevel`, or vice versa — that's a bug in whichever
   code path produced it, not expected behavior; check whether that path is
   missing its transaction wrapper.

6. **Concurrent writes to the same branch/item.** `RecordSale` takes a
   `lockForUpdate()` on the `Branch` row (for atomic receipt numbering) but
   `StockLevel` updates use `firstOrCreate` + `decrement`, which is safe
   against lost updates at the DB level (`decrement` issues an atomic
   `UPDATE ... SET quantity_on_hand = quantity_on_hand - ?`) but not against
   two processes both doing `firstOrCreate` on a brand-new
   tenant/branch/item/batch combination simultaneously — check for duplicate
   `stock_levels` rows for the same `(tenant_id, branch_id, item_id, batch_id)`
   tuple as a rare edge case if counts look split across two rows.

## 3. Reports not generating

Two separate pipelines produce reports — diagnose them separately.

### 3.1 Scheduled email reports (the daily/weekly/monthly digest, FR-3.4)
Driven by `php artisan reports:send-scheduled`, which the scheduler in
`bootstrap/app.php` runs daily at 06:00.

1. **Check cron is actually running the scheduler at all.** If the server's
   crontab is missing the `* * * * * php artisan schedule:run` entry (see
   deployment.md §3.4), *nothing* fires — no error, no log entry, just
   silence. This is the single most common cause of "reports stopped coming."
   Verify with:
   ```bash
   php artisan schedule:list
   crontab -l -u <deploy-user>   # confirm the schedule:run entry exists and points at the right path
   ```
2. **Check `storage/logs/laravel.log` for `Scheduled sales report failed`.**
   Each tenant's schedule is processed in its own try/catch
   (`App\Console\Commands\SendScheduledReports`); a failure for one tenant
   (bad recipient email, mail server timeout, PDF render error) is logged and
   skipped — it does **not** stop other tenants' reports from sending. The
   command exits non-zero if *any* tenant failed, which is useful for
   alerting but doesn't tell you which tenant without reading the log.
3. **Check mail delivery, not just the app.** Report emails are sent
   synchronously (`Mail::to($email)->send(...)`) inline in the command —
   there is no queue involved, so a slow/unreachable SMTP host will make the
   whole command run long and can time out under cron. Confirm `MAIL_*` env
   vars and that the mail host is reachable from the app server.
4. **Check `scheduled_reports.last_sent_at` for the tenant in question.** The
   `isDue()` check is: daily → always due; weekly → ≥7 days since
   `last_sent_at`; monthly → ≥1 month since. If a prior run updated
   `last_sent_at` but the email itself silently failed to deliver (accepted by
   your mail host but bounced downstream), the command won't retry until the
   next natural due date — there's no separate retry/backoff.

### 3.2 On-demand reports (Sales/Stock/Velocity/Shifts pages, CSV & PDF export buttons)
These run **synchronously inside the request** when a user clicks Export —
there's no queue or background job here at all (see `SalesReportData::build`
and the `exportCsv`/`exportPdf` methods on the report Volt pages).

1. If export "hangs" or the browser shows a timeout/502, it's almost always
   PHP or web-server request timeout on a large date range — DomPDF rendering
   and the underlying `Sale::with('items')` query both run in-request with no
   pagination or chunking. Check `max_execution_time` (PHP) and the proxy
   timeout (Nginx `fastcgi_read_timeout` or equivalent) against the actual
   report period requested.
2. If it fails outright, check `storage/logs/laravel.log` for a stack trace —
   these aren't caught in a try/catch the way the scheduled command is, so a
   PHP exception here surfaces as Laravel's standard error page/response.
3. Confirm this is scoped to the reporting tenant's `tenant_id` and, where
   applicable, `branch_id` — a report that returns *zero rows* rather than
   failing is more likely a tenant-scoping issue (§1) or simply an empty date
   range than a report-generation bug.

## 4. "My data disappeared" (before assuming data loss)

Given §1's fail-closed scope behavior, always rule this out before treating a
missing-data report as an actual loss incident:
1. Confirm the user is logged in as the expected tenant (not a Super Admin
   session, which has its own separate views) and that their `tenant_id`
   matches the data they expect to see.
2. Check `audit_logs` (§5) for `deleted` events on the record/table in
   question — most core models use soft deletes (`SoftDeletes` trait), so a
   "deleted" record is very likely still in the table with `deleted_at` set,
   recoverable, not gone. Confirm before restoring from backup.
3. Only after ruling out (1) and (2) — and confirming it's not a genuine hard
   delete or a bug — move to the backup/restore procedures in
   [deployment.md §5](./deployment.md#5-backup--restore). Note its restore
   caveats before you start: the full SQL backup restores *all* tenants at
   once, and the per-tenant CSV export has no automated import path.

## 5. Where to look — logs and audit trail

| Question | Where |
|---|---|
| What did a user actually do, and when? | `audit_logs` table — every create/update/delete on an audited model is logged with actor, tenant, old/new values (see `App\Models\Concerns\Auditable`). Password-like fields are redacted per-model via `auditExcept()`. |
| Did an application error occur? | `storage/logs/laravel.log` (single-file log, `LOG_CHANNEL=stack` → `LOG_STACK=single` by default) |
| Is the scheduler configured and healthy? | `php artisan schedule:list`, plus the server crontab |
| Is Reverb (notifications) actually running? | Process supervisor status (systemd/Supervisor) for the `reverb:start` process; a silently-dead Reverb process means notification toasts stop appearing but nothing else in the app is affected |
| Did a scheduled command fail for a specific tenant? | `storage/logs/laravel.log`, filtered for the command's `Log::error(...)` calls (`Scheduled tenant backup failed`, `Scheduled sales report failed`, subscription reminder failures) — all three iterate tenants independently and log+continue rather than aborting the whole run |

## 6. Escalation notes

- There is no automated alerting wired up in this repo (no Sentry/Bugsnag
  integration, no queue-failure notifications found in `app/`) — incidents are
  currently discovered via user reports or manual log review. If you're
  setting up monitoring, `storage/logs/laravel.log` and the scheduled
  commands' non-zero exit codes (§3.1, §5) are the two highest-value signals
  to wire up first.
- No restore command exists for either backup type (deployment.md §5) — if an
  incident requires restoring data, budget time for a manual `mysql` import
  (full DB) or a one-off CSV import script (single tenant); neither is a
  same-day, self-service operation today.
