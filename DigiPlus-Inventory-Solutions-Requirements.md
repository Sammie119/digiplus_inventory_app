# DigiPlus Inventory Solutions
## Software Requirements Specification (SRS)

**Version:** 1.1
**Type:** Multi-tenant SaaS — Retail/Shop Inventory & Sales Management
**Last Updated:** 2026-07-19 — resolved open architectural questions from v1.0 (multi-tenancy model, offline sync policy, costing method, RBAC matrix, and other items noted inline)

---

## 1. Overview

DigiPlus Inventory Solutions is a cloud-based, multi-tenant platform that lets shop and store owners manage inventory, track sales, and generate operational reports in real time. Each subscribing business (tenant) operates in an isolated workspace, with support for single-branch shops and multi-branch retail chains.

## 2. Scope

The system covers stock management, point-of-sale/sales capture, reporting, and analytics for retail and wholesale stores. It excludes full accounting/bookkeeping (ledgers, tax filing) and HR/payroll — these can be handled as future integrations. Where the system tracks customer credit (FR-2.6), this is limited to a running balance per customer and is explicitly not a full accounts-receivable ledger — see the note under FR-2.6 and §7.

## 3. User Roles

| Role | Description |
|---|---|
| Super Admin | DigiPlus platform owner; manages tenants, billing, subscriptions |
| Business Owner/Admin | Owns a tenant account; full access to that business's data |
| Branch Manager | Manages a single branch/store location |
| Cashier/Sales Attendant | Records sales, views stock levels, no admin access |
| Store Keeper | Manages stock intake, adjustments, transfers |
| Auditor/Viewer | Read-only access to reports |

Role-based access control (RBAC) with granular permissions per module.

### 3.1 Permission Matrix (default)

| Module | Super Admin | Business Owner | Branch Manager | Cashier | Store Keeper | Auditor |
|---|---|---|---|---|---|---|
| Tenant/Billing Settings | Full (platform-wide) | Full (own tenant) | None | None | None | None |
| User Management | Full | Full (own tenant) | Branch-level only | None | None | None |
| Stock Management | Full | Full | Full (own branch) | View only | Create/Edit/Adjust/Transfer (own branch) | View only |
| Sales/POS | Full | Full | Full (own branch) | Create/View own transactions | None | View only |
| Void/Refund Approval | Full | Full | Full (own branch) | Can initiate, cannot approve | None | None |
| Supplier Management | Full | Full | View (own branch) | None | Create/Edit (goods receipt) | View only |
| Reports | Full, platform-wide | Full, own tenant | Own branch | Own shift/transactions only | Stock reports, own branch | Full read-only, own tenant |

This is the default matrix; permissions remain configurable per-tenant within these role boundaries.

## 4. Functional Requirements

### 4.1 Stock Management
- FR-1.1: Real-time, itemized stock levels per item, per branch, and consolidated across branches.
- FR-1.2: Item master data — SKU/barcode, name, category, base unit of measure, optional purchase unit with a conversion factor (e.g., 1 carton = 24 pieces), cost price, selling price, tenant-level default reorder level, image. An item may optionally belong to a Product with variant attributes (e.g., size, color); each variant is its own SKU with independent stock, barcode, and price.
- FR-1.3: Stock adjustments (damages, returns, write-offs) with reason codes and approval trail.
- FR-1.4: Stock transfer between branches/warehouses with in-transit tracking.
- FR-1.5: Purchase/goods-received entry to increase stock, linked to suppliers.
- FR-1.6: Low-stock and out-of-stock alerts (in-app, email, SMS optional), evaluated against the branch-level reorder level (FR-1.9) where set.
- FR-1.7: Barcode/QR generation and scanning support for stock intake and sales.
- FR-1.8: Batch/expiry date tracking for perishable or dated goods (optional module). When enabled, POS deducts stock First-Expiry-First-Out (FEFO) by default; staff may manually override the selected batch at the point of sale (e.g., to clear a specific delivery deliberately).
- FR-1.9: Reorder level may be overridden per branch; if no branch-level override exists, the item's tenant-level default (FR-1.2) applies.
- FR-1.10: Stock is costed using weighted-average cost, recalculated automatically on each purchase/goods-received (FR-1.5). This is the basis for cost figures in stock valuation and margin reports (FR-3.1, FR-3.2).

### 4.2 Sales Management
- FR-2.1: Point-of-sale (POS) interface for recording sales transactions.
- FR-2.2: Automatic stock deduction on sale completion, following FEFO batch selection where batch tracking is enabled (FR-1.8).
- FR-2.3: Daily, weekly, and monthly sales aggregation, viewable per branch or company-wide.
- FR-2.4: Support for multiple payment methods (cash, mobile money, card, credit/on-account).
- FR-2.5: Discounts, returns, and voided-sale handling with audit trail. Voids may be initiated by the cashier but require manager/supervisor PIN approval to finalize (per the permission matrix, §3.1); this approval step is configurable off per-tenant for single-cashier operations.
- FR-2.6: Customer records (optional) for credit sales and purchase history. Credit tracking is limited to a running balance per customer (debit on credit sale, credit on payment received) — no aging schedule, interest, or general-ledger entries. This is intentionally a minimal capability; full accounts-receivable functionality is out of scope for v1 (§7).
- FR-2.7: Shift/cash-drawer reconciliation — cashiers open a shift with a starting cash float and close it with a counted-cash entry; the system computes expected cash from recorded transactions and reports the variance, tied to cashier, branch, and shift.
- FR-2.8: Sequential, gapless receipt/invoice numbering per branch (e.g., `BR01-2026-000123`), assigned atomically at sale completion. Numbers are never reused, including after a void — a void changes status, it does not delete the record.

### 4.3 Reporting
- FR-3.1: **Sales Reports** — by day/week/month/custom range, by branch, by cashier, by item/category, with revenue, cost (weighted-average basis, FR-1.10), and profit margin breakdown.
- FR-3.2: **Stock Reports** — current stock valuation, stock movement history (in/out/adjustments), reorder-level exceptions (per FR-1.9), expiring/expired items.
- FR-3.3: Exportable reports (PDF, Excel/CSV).
- FR-3.4: Scheduled report delivery via email (daily/weekly/monthly digest).
- FR-3.5: Dashboard with visual charts (sales trend, top categories, stock health), updated in near real time (see NFR Performance, §5, and the broadcasting approach in §6).
- FR-3.6: Shift variance report summarizing cash reconciliation results (FR-2.7) by cashier, shift, and branch.

### 4.4 Fast/Slow-Moving Items Analysis (Optional Feature)
- FR-4.1: Identify fast-moving items by sales velocity (units sold per period) over a configurable window.
- FR-4.2: Identify slow-moving/dead stock (no sales activity within a configurable threshold, e.g., 30/60/90 days).
- FR-4.3: ABC analysis (classify items by contribution to revenue) as a stretch enhancement.
- FR-4.4: Reorder suggestions based on velocity and current stock level.

### 4.5 Additional Recommended Features
- FR-5.1: **Multi-branch/multi-store support** with branch-level and consolidated views.
- FR-5.2: **Supplier management** — supplier records, purchase history, outstanding balances.
- FR-5.3: **User activity/audit log** — who did what and when (critical for accountability in stock/cash handling).
- FR-5.4: **Notifications center** — low stock, large discrepancies, daily sales summary.
- FR-5.5: **Multi-currency / multi-tax (VAT/NHIL, etc.) support** for regional flexibility.
- FR-5.6: **Offline-capable POS** (queue transactions locally, sync when connection resumes). Offline devices do not attempt live stock reservation; sales are recorded against last-known local stock. On sync, if a transaction would drive stock negative, the system does not block it but auto-flags it for Store Keeper review/reconciliation. Non-stock conflicts (e.g., concurrent price edits) resolve last-write-wins by timestamp, with a flag raised if the conflicting edits fall within the same sync window.
- FR-5.7: **Subscription/billing management** for tenants (plan tiers, usage limits, renewal reminders). Proposed starting tiers (placeholder — pending business/pricing decision):

  | Tier | Branches | Users | Transactions/mo |
  |---|---|---|---|
  | Basic | 1 | 3 | 1,000 |
  | Standard | 5 | 15 | 10,000 |
  | Enterprise | Custom | Custom | Custom |

- FR-5.8: **Data export/backup** on demand, and scheduled tenant-level backups.
- FR-5.9: **Mobile-responsive UI** and/or companion mobile app for on-the-go stock checks and sales entry.

## 5. Non-Functional Requirements

| Category | Requirement |
|---|---|
| Performance | Stock and sales dashboards should reflect updates within 2–3 seconds of a transaction, via tenant-scoped websocket broadcasting (see §6) rather than polling |
| Scalability | Architecture must support growth from single-shop tenants to multi-branch chains without redesign |
| Availability | Target 99.5%+ uptime |
| Security | Encrypted data at rest and in transit; tenant data isolation; role-based permissions |
| Auditability | All stock and sales-altering actions logged with user, timestamp, and reason |
| Usability | Simple UI for non-technical cashiers/store staff; minimal training required |
| Data Integrity | Soft deletes on all core records (no hard deletes) to preserve historical reporting accuracy |
| Localization | Support for local currency, date formats, and (optionally) local languages |

## 6. Suggested Technical Approach

- **Backend:** Laravel (PHP), with Spatie roles/permissions package for RBAC (implementing the matrix in §3.1)
- **Database:** MySQL, with soft deletes on all models for audit-safe history
- **Multi-tenancy:** Single database with `tenant_id` scoping, enforced via a global Eloquent scope so tenant isolation can't be omitted by accident. Database-per-tenant is not pursued for v1; revisit only if a specific client requires physical data isolation for contractual/regulatory reasons.
- **Real-time updates:** Laravel Reverb (self-hosted websockets) broadcasting stock/sales events on tenant-scoped channels, feeding the dashboards in FR-3.5
- **Public-facing IDs:** UUIDs on routes/APIs exposed to clients (avoid sequential ID leakage)
- **Frontend:** Tailwind CSS with a lightweight JS framework or Livewire for reactive dashboards
- **Reporting/Export:** Server-side PDF (e.g., DomPDF) and Excel export (Laravel Excel)
- **Notifications:** Queue-based jobs for email/SMS alerts (low stock, reports)

## 7. Out of Scope (v1)
- Full accounting/general ledger and tax filing
- Full accounts-receivable ledger and aging (only a running customer balance is tracked — FR-2.6)
- Payroll/HR management
- Manufacturing/BOM (bill of materials) production tracking

---
