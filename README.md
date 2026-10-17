# DigiPlus Inventory Solutions

DigiPlus is a multi-tenant SaaS app for retail and wholesale shops. Each subscribing business (tenant) gets its own workspace to manage stock, sell at the till, and run reports, whether it runs a single shop or a chain of branches. The platform owner (Super Admin) manages tenants, plans and billing.

## Features

**Stock management**
- Live stock levels per item, per branch, and across all branches
- Items with SKUs/barcodes, categories, units of measure (e.g. 1 carton = 24 pieces) and product variants
- Stock adjustments with reason codes and approvals, branch-to-branch transfers, and goods received from suppliers
- Optional batch/expiry tracking (FEFO at the POS)
- Low-stock alerts with per-branch reorder levels
- Weighted-average costing
- Approval-driven stock takes, plus barcode/QR labels and scanning

**Sales and POS**
- POS with printable receipts that break down tax by rate
- Shifts, with a sales summary emailed to the Business Owner when a shift closes
- Voids and returns: cashiers start them, managers approve them
- Customers with a running credit balance
- Offline-capable POS that syncs sales once the connection is back

**Reports**
- Sales, stock, shift and Profit & Loss reports, with CSV/PDF export
- Fast- and slow-moving items analysis
- Scheduled report emails and financial years

**Users and access**
- Roles: Super Admin, Business Owner, Branch Manager, Cashier, Store Keeper, Auditor
- Each role's access is set by a permission matrix
- User activity audit log, plus live in-app notifications

**Platform and billing**
- Subscription plans with branch, user and transaction limits
- New tenants start on a trial license
- Expired licenses show a renewal page with Paystack checkout
- Tenant data export and a Super Admin whole-database SQL backup

## Tech stack

- Laravel 13 on PHP 8.3
- Livewire 3 + Volt, Tailwind CSS, Vite
- Laravel Reverb for real-time notifications
- Spatie Permission for team-scoped roles
- Database queue, scheduled commands, SMTP mail
- Paystack for subscription payments
- MySQL in production; SQLite (`:memory:`) for tests

## Getting started

Requirements: PHP 8.3+, Composer, Node.js/npm, and MySQL (or SQLite).

```bash
composer setup          # install deps, create .env, generate key, migrate, build assets
php artisan db:seed     # optional: load demo data
composer dev            # web server, queue worker, logs, Vite and Reverb together
```

Before running, set these in `.env`:

- **Database:** the `DB_*` settings
- **Mail:** the `MAIL_*` settings
- **Reverb:** the `REVERB_*` settings
- **Paystack:** `PAYSTACK_PUBLIC_KEY`, `PAYSTACK_SECRET_KEY` and `PAYSTACK_SUBACCOUNT_CODE`

### Demo accounts

`php artisan db:seed` creates a demo tenant ("Demo Retail Co") with two branches and a starter catalog. Every account below uses the password `password`.

| Role | Email |
|---|---|
| Super Admin | `superadmin@digiplus.test` |
| Business Owner | `owner@demo.test` |
| Branch Manager (Main) | `manager@demo.test` |
| Branch Manager (Downtown) | `downtown@demo.test` |
| Cashier | `cashier@demo.test` |

## Testing

```bash
composer test                 # full suite
scripts/test.sh --parallel    # same, with options passed to `php artisan test`
scripts/test-sales.sh         # sales/POS tests only
scripts/test-stock.sh         # stock tests only
scripts/test-reports.sh       # report tests only
```

## Documentation

- [Requirements specification (SRS)](DigiPlus-Inventory-Solutions-Requirements.md)
- [Data model / ERD](DigiPlus-Data-Model-ERD.md)
- [User guide](docs/USER_GUIDE.md), also available as a [PDF](docs/DigiPlus-User-Guide.pdf)
- [Deployment runbook](docs/runbooks/deployment.md)
- [Incident response runbook](docs/runbooks/incident-response.md)
