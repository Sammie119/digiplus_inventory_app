# DigiPlus User Guide

A practical guide to running your shop day-to-day in DigiPlus — organized around what
you're actually trying to do, not around the menu structure.

This guide is written for the **Business Owner** (you) but also explains what your
staff can and can't do, since a lot of "how do I..." questions turn out to be
"who on my team should be doing this instead."

---

## Before you start: setting up your shop

You only need to do this once, when you first get your account (or when you open a
new branch or add a new product line). Do these roughly in order:

1. **Add your branches** — *Administration → Branches*. Every shop starts with one
   main branch (it can't be deactivated, since it's your default). Add more if you
   have multiple locations.
2. **Add your team** — *Administration → Users*. Create a login for each staff
   member and assign them a role (see [Roles](#quick-reference-roles--what-each-one-can-do)
   below). Each person is tied to one branch, except you — as Business Owner, you
   see and manage every branch.
3. **Build your catalog** — *Inventory → Products*:
   - Set up **Categories** and **Units of Measure** first (e.g. "Beverages",
     "Carton" / "Piece") — products need these.
   - Create a **Product** for each thing you sell, then open it and add its
     **Items** — the actual sellable SKUs (a product like "T-Shirt" might have
     one item per size/color). Each item gets its own SKU, barcode, cost price,
     selling price, and reorder level.
   - If an item is perishable or dated (e.g. groceries, medicine), turn on
     **batch tracking** for it — the till will then sell the oldest expiry
     first automatically (FEFO) unless a cashier overrides it.
4. **Add your suppliers** — *Inventory → Suppliers* — so you can log purchases
   against them.
5. **Set your tax rates**, if applicable — *Administration → Tax Rates*.

Once that's done, your staff can open a till and start selling.

---

## Running the till: making sales

### Opening a shift
A cashier must **open a shift** before they can sell anything. From
*Point of Sale*, they enter their **opening cash float** (how much cash is in the
drawer to start) and click **Open Shift**. Everything sold from then on is tied to
that shift, that cashier, and that branch — this is what makes the end-of-day
cash count possible.

### Making a sale
From *Point of Sale*, with a shift open:
1. Add a line for each item — search by SKU/name or scan the barcode, then enter
   the quantity. For batch-tracked items, DigiPlus auto-picks the oldest expiry
   (you can override the batch if you need to clear a specific delivery).
2. Applicable tax is calculated automatically and shown as the total builds.
3. Optionally attach a **customer** — required if any part of the sale is paid
   **on credit** (see [Customers & credit](#customers--credit-sales) below).
4. Add one or more **payments** (cash, mobile money, card, credit) — they must add
   up to exactly the sale total.
5. **Complete Sale.** A receipt number is generated and stock is deducted
   immediately. You can print or reprint the receipt any time from *Sales*.

### Closing a shift
At the end of the day (or shift), the cashier clicks **Close Shift**, counts the
cash physically in the drawer, and enters it. DigiPlus compares that count against
what it *expects* (opening float + cash sales) and shows the **variance**. Large
variances (20 or more) automatically notify you and the branch manager — see
[Reviewing till variances](#reviewing-till-cash-variances) below.

---

## Checking today's sales

The fastest view: your **Dashboard** (home page) shows, at a glance:
- Today's revenue and transaction count
- This month's revenue so far
- Low-stock and out-of-stock counts
- How many shifts are currently open
- A 7-day sales trend and your top-selling categories for the last 30 days

If you manage multiple branches, the Dashboard is consolidated across all of them
for you; a Branch Manager or Cashier only sees their own branch's numbers.

For the actual list of transactions — to look up a specific receipt, check who
sold what, or review voids/returns — go to **Sales**. Each row shows the receipt
number, branch, cashier, status, and total; click **View** for the full line-item
breakdown, payments, and any linked returns.

### Voiding a sale or handling a return
- A cashier can **request a void** on a sale (with a reason) — it doesn't take
  effect immediately. A manager (Branch Manager or you) must **approve** it before
  stock is restored and the sale is marked void. This two-step process exists so
  a cashier can't quietly erase a transaction on their own.
- **Returns** work the same way: request → manager approval. When approving a
  return, you choose per line-item whether it goes back into stock ("restock")
  or not (e.g. damaged goods).

---

## Keeping stock under control

### Seeing what's running low or out
Go to **Stock Levels** (under *Inventory*) — it lists every item with its current
quantity on hand and a status badge: **OK**, **Low** (at or below its reorder
level), or **Out of Stock**. Search by SKU or product name, and filter by branch
if you have more than one (or view "All Branches" for a consolidated count).
Batch-tracked items have a **View Batches** button showing each batch's expiry
date and remaining quantity.

The reorder level that decides "Low" comes from the item's default, unless a
specific branch has its own override — useful if one location sells through
something faster than another.

You'll also get an **in-app notification the moment an item crosses into Low or
Out of Stock** (see [Notifications](#staying-in-the-loop-notifications)) — you
don't have to go looking for it.

### Receiving new stock from a supplier
Go to **Purchases → New Purchase**:
1. Pick the branch and supplier, then add a line per item with quantity and unit
   cost. For batch-tracked items, enter the batch number and expiry date.
2. **Save** — this creates a draft you can still edit.
3. When the goods physically arrive, open the purchase and click **Receive**.
   This is the point where stock actually increases — a draft purchase does
   *not* affect stock levels. Receiving also recalculates the item's cost price
   as a weighted average of what you already had and what you just bought in,
   which is what feeds your margin figures on the Sales Report.

### Moving stock between branches
Go to **Stock Transfers → New Transfer**, pick a source and destination branch,
and list the items and quantities being sent. A transfer moves through three
states: **pending** (still editable by the sending branch) → **in transit**
(shipped, stock is out of the source branch) → **received** (the destination
branch confirms what actually arrived, which can differ from what was sent if
something was lost or damaged in transit).

### Fixing stock counts (adjustments)
For anything that isn't a sale, purchase, or transfer — damages, write-offs,
counting mistakes, stock returned to a supplier — use **Stock Adjustments**.
Submit a request with a quantity, direction (increase/decrease), and a reason
code. Like voids, this needs **manager approval** before it actually changes
stock — this is your audit trail for shrinkage and damage.

---

## Customers & credit sales

If you extend credit to regular customers, add them under **Customers**, and
optionally set a **credit limit**. At the till, attaching a customer and choosing
**Credit** as a payment method adds that amount to their running balance — DigiPlus
will block the sale if it would exceed their limit.

Each customer's page has a **ledger** — every credit sale and payment they've made,
with a running balance. When they pay down what they owe, open their ledger and
**Record Payment**. Note this is a simple running balance, not full
accounts-receivable bookkeeping (no aging, no interest) — it tells you who owes
what, not your full financial position.

---

## Reports & insights

All reports live under the **Reports** menu, and every table can be exported as
**CSV** or a printable **PDF** — handy for accountants, backups of your own, or
sharing with a business partner. Every report also respects the branch filter, so
you can pull numbers for one location or all of them combined.

### Pulling a sales report (e.g. a monthly report)
Go to **Reports → Sales Report**, set your **From/To** date range (defaults to the
current month) and branch, and you'll get:
- Total sales count, revenue, tax, cost, margin, and margin %
- A day-by-day breakdown for the period

Click **Export CSV** or **Export PDF** to download it.

### Checking stock value and what's expiring
**Reports → Stock Report** has four tabs:
- **Valuation** — how many items you're holding and their total cost value
- **Reorder Exceptions** — everything currently Low or Out of Stock, in one list
- **Expiring Items** — batches expiring within 30 days (or already expired)
- **Movement History** — every stock in/out event in a date range, with who did it

### Finding your fast and slow movers
**Reports → Item Velocity** looks at sales over a window you choose (30/60/90
days) and gives you four views:
- **Fast Movers** — your best sellers by units sold, with daily velocity
- **Slow/Dead Stock** — items sitting on the shelf with zero sales in the window
  (and when they were last sold, if ever)
- **ABC Analysis** — classifies items by how much of your revenue they drive:
  "A" items are your top ~80% of revenue, "B" the next ~15%, "C" the long tail —
  useful for deciding what deserves the most attention
- **Reorder Suggestions** — items at or below their reorder level, with a
  suggested quantity based on recent sales velocity

### Reviewing till cash variances
**Reports → Shift Report** lists every shift (open or closed) in a date range,
filterable by branch and cashier, showing opening float, counted cash, expected
cash, and the variance between them. A variance of 20 or more automatically
triggers a notification to you and the branch's manager at the moment the shift
is closed, so you're not waiting for report day to find out.

### Getting reports emailed automatically
Rather than logging in to pull the Sales Report yourself, go to
**Reports → Scheduled Reports** and set up a recurring email (daily, weekly, or
monthly) to any list of recipients, per branch or for the whole business. It
arrives as a PDF. (Available to Business Owners and Branch Managers.)

---

## Staying in the loop: notifications

The bell/notifications page (**Notifications**) collects two kinds of alerts as
they happen, in addition to landing in your browser as a toast if you're logged
in at the time:
- **Low Stock** — an item just crossed into Low or Out of Stock. Sent to you and
  the relevant branch's Manager/Store Keeper.
- **Large Discrepancy** — a shift closed with a cash variance of 20 or more.
  Sent to you and that branch's Manager.

You can filter by read/unread and by type, and mark everything read in one click.
Clicking a notification takes you straight to the relevant page (Stock Levels or
the Shift Report).

---

## Running the business: staff, branches & access

- **Administration → Users** — add staff, assign a role, and set their branch.
  You can deactivate someone who leaves (you can't deactivate your own account,
  and a tenant must always keep at least one active Business Owner, so you can't
  lock yourself out).
- **Administration → Branches** — add or deactivate locations. The main branch
  can't be deactivated, and you can't deactivate a branch that still has staff
  assigned to it — reassign them first.
- **Administration → Audit Log** — a full history of who created, changed, or
  deleted what, and when. Useful when a number looks wrong and you need to know
  who touched it.

### Quick reference: roles & what each one can do
| Role | Can do |
|---|---|
| **Business Owner** (you) | Everything, across every branch — sales, stock, staff, branches, tax settings, backups, approvals, full reports and audit log |
| **Branch Manager** | Everything above except tenant-wide settings, scoped to their own branch; approves voids/returns and stock adjustments for their branch; manages that branch's staff |
| **Cashier** | Sells at the till and views their branch's stock and sales — no approvals, no stock changes |
| **Store Keeper** | Manages stock (purchases, adjustments, transfers) and suppliers — no till access |
| **Auditor** | Read-only across stock, sales, suppliers, and reports — for bookkeepers or oversight, with nothing they can accidentally change |

---

## Protecting your data: backups

Under **Administration → Backups**, you can generate an on-demand snapshot of
your catalog, stock, sales, and customer data as a downloadable zip (a
spreadsheet-friendly CSV per table). One is also generated automatically every
week, and the 10 most recent are kept — older ones are cleared out automatically.

This is meant for your own records and data portability, not as a one-click
"undo everything" button — if you ever need a full technical restore, that's a
job for whoever manages your DigiPlus hosting, not something done from this page.

---

## Selling when the internet drops

If a cashier's device loses its connection mid-shift, *Point of Sale* switches to
an **offline mode** automatically: it keeps working off the last-known catalog and
prices cached on that device, queues each sale locally, and syncs everything back
to the server the moment the connection returns. A banner on the till clearly
shows when you're offline and how many sales are queued.

Two things to know:
- Offline sales can't use **credit** as a payment method, and tax is estimated
  rather than shown live (the exact total is confirmed once it syncs).
- If stock had already run out elsewhere while a device was offline, a synced
  sale can push stock negative. DigiPlus doesn't block this — it completes the
  sale but **flags it for stock review** (you'll see a warning icon next to it
  in the Sales list). Check these promptly so your Store Keeper can reconcile
  what actually happened.

---

## Getting help

For anything not covered here — billing, your subscription plan, or an issue with
the platform itself rather than your shop's data — that's handled by DigiPlus
support directly, not from within your shop's account.
