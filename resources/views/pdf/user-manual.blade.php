<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('DigiPlus User Guide') }}</title>
    <style>
        @page { margin: 28px 36px 40px 36px; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #1f2937; line-height: 1.5; }

        .cover { text-align: center; padding-top: 220px; }
        .cover h1 { font-size: 30px; margin-bottom: 8px; color: #111827; }
        .cover .tagline { font-size: 14px; color: #6b7280; margin-bottom: 40px; }
        .cover .meta { font-size: 11px; color: #9ca3af; }

        .section { page-break-before: always; }
        .section:first-of-type { page-break-before: auto; }

        h1.section-title {
            font-size: 20px;
            color: #111827;
            border-bottom: 2px solid #111827;
            padding-bottom: 6px;
            margin-bottom: 4px;
        }
        h2.sub-title { font-size: 15px; color: #1f2937; margin-top: 22px; margin-bottom: 4px; }
        p { margin: 6px 0; }
        ol, ul { margin: 6px 0 10px 18px; padding: 0; }
        li { margin-bottom: 4px; }
        strong { color: #111827; }
        em { color: #4b5563; }
        code { background: #f3f4f6; padding: 1px 4px; border-radius: 3px; font-size: 11px; }

        .figure { margin: 12px 0 18px 0; text-align: center; page-break-inside: avoid; }
        .figure img {
            max-width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 4px;
        }
        .figure .caption { font-size: 10px; color: #6b7280; margin-top: 6px; font-style: italic; }

        table.roles { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.roles th, table.roles td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; vertical-align: top; }
        table.roles th { background-color: #f3f4f6; font-size: 10px; text-transform: uppercase; }

        .note {
            background: #f9fafb;
            border-left: 3px solid #9ca3af;
            padding: 8px 12px;
            margin: 10px 0;
            font-size: 11px;
            color: #374151;
        }

        .footer-note { font-size: 10px; color: #9ca3af; text-align: center; margin-top: 30px; }
    </style>
</head>
<body>

@php
    function digiplus_manual_inline_image(?string $path): ?string {
        if (! $path || ! file_exists($path)) {
            return null;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            default => 'image/png',
        };
        $data = base64_encode(file_get_contents($path));
        return "data:$mime;base64," . $data;
    }
@endphp

@php
    /** @var array<string,string> $screenshots slug => absolute file path */
    $screenshots = $screenshots ?? [];
    $img = fn (string $slug) => digiplus_manual_inline_image($screenshots[$slug] ?? null);
@endphp

{{-- Cover --}}
<div class="cover">
    <h1>{{ __('DigiPlus Inventory Solutions') }}</h1>
    <div class="tagline">{{ __('User Guide') }}</div>
    <div class="meta">{{ __('Generated') }} {{ $generatedAt }}</div>
</div>

{{-- 1. Getting started --}}
<div class="section">
    <h1 class="section-title">{{ __('Before You Start: Setting Up Your Shop') }}</h1>
    <p>{{ __('You only need to do this once, when you first get your account (or when you open a new branch or add a new product line). Do these roughly in order:') }}</p>
    <ol>
        <li><strong>{{ __('Add your branches') }}</strong> — {{ __('Administration -> Branches. Every shop starts with one main branch (it can\'t be deactivated). Add more if you have multiple locations.') }}</li>
        <li><strong>{{ __('Add your team') }}</strong> — {{ __('Administration -> Users. Create a login for each staff member and assign them a role. Each person is tied to one branch, except the Business Owner, who sees every branch.') }}</li>
        <li><strong>{{ __('Build your catalog') }}</strong> — {{ __('Inventory -> Products. Set up Categories and Units of Measure first, then create a Product for each thing you sell and add its Items (the actual sellable SKUs). Turn on batch tracking for perishable or dated items — the till will then sell the oldest expiry first automatically (FEFO).') }}</li>
        <li><strong>{{ __('Add your suppliers') }}</strong> — {{ __('Inventory -> Suppliers — so you can log purchases against them.') }}</li>
        <li><strong>{{ __('Set your tax rates') }}</strong>, {{ __('if applicable — Administration -> Tax Rates.') }}</li>
    </ol>
    <p>{{ __('Once that\'s done, your staff can open a till and start selling.') }}</p>

    <h2 class="sub-title">{{ __('Branches') }}</h2>
    <div class="figure">
        @if ($img('branches'))
            <img src="{{ $img('branches') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Administration -> Branches') }}</div>
    </div>

    <h2 class="sub-title">{{ __('Users & Roles') }}</h2>
    <div class="figure">
        @if ($img('users'))
            <img src="{{ $img('users') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Administration -> Users') }}</div>
    </div>

    <h2 class="sub-title">{{ __('Products & Items') }}</h2>
    <div class="figure">
        @if ($img('products'))
            <img src="{{ $img('products') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Inventory -> Products') }}</div>
    </div>
    <div class="figure">
        @if ($img('product-items'))
            <img src="{{ $img('product-items') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('A product\'s Items — each variant gets its own SKU, cost price, selling price, and reorder level') }}</div>
    </div>

    <h2 class="sub-title">{{ __('Suppliers') }}</h2>
    <div class="figure">
        @if ($img('suppliers'))
            <img src="{{ $img('suppliers') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Inventory -> Suppliers') }}</div>
    </div>

    <h2 class="sub-title">{{ __('Tax Rates') }}</h2>
    <div class="figure">
        @if ($img('tax-rates'))
            <img src="{{ $img('tax-rates') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Administration -> Tax Rates') }}</div>
    </div>
</div>

{{-- 2. Running the till --}}
<div class="section">
    <h1 class="section-title">{{ __('Running the Till: Making Sales') }}</h1>

    <h2 class="sub-title">{{ __('Opening a shift') }}</h2>
    <p>{{ __('A cashier must open a shift before they can sell anything. From Point of Sale, they enter their opening cash float and click Open Shift. Everything sold from then on is tied to that shift, that cashier, and that branch — this is what makes the end-of-day cash count possible.') }}</p>

    <h2 class="sub-title">{{ __('Making a sale') }}</h2>
    <ol>
        <li>{{ __('Add a line for each item — search by SKU/name or scan the barcode, then enter the quantity. For batch-tracked items, DigiPlus auto-picks the oldest expiry.') }}</li>
        <li>{{ __('Applicable tax is calculated automatically and shown as the total builds.') }}</li>
        <li>{{ __('Optionally attach a customer — required if any part of the sale is paid on credit.') }}</li>
        <li>{{ __('Add one or more payments (cash, mobile money, card, credit) — they must add up to exactly the sale total.') }}</li>
        <li>{{ __('Complete Sale. A receipt number is generated and stock is deducted immediately, with a full breakdown of every tax applied shown on the receipt.') }}</li>
    </ol>

    <div class="figure">
        @if ($img('pos'))
            <img src="{{ $img('pos') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Point of Sale — an open shift with items added to the cart') }}</div>
    </div>

    <h2 class="sub-title">{{ __('Closing a shift') }}</h2>
    <p>{{ __('At the end of the day (or shift), the cashier clicks Close Shift, counts the cash physically in the drawer, and enters it. DigiPlus compares that count against what it expects (opening float + cash sales) and shows the variance. Large variances (20 or more) automatically notify the Business Owner and the branch manager.') }}</p>
    <div class="note">{{ __('The moment a shift closes, DigiPlus emails the Business Owner a summary — sales count and total, the breakdown by payment method, and the cash variance.') }}</div>

    <h2 class="sub-title">{{ __('Sales history & receipts') }}</h2>
    <p>{{ __('Every transaction is listed under Sales — click View for the full line-item breakdown, payments, and any linked returns. A cashier can request a void (with a reason); a manager must approve it before stock is restored. Returns work the same way: request -> manager approval, choosing per line-item whether it goes back into stock.') }}</p>
    <div class="figure">
        @if ($img('sales-list'))
            <img src="{{ $img('sales-list') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Sales') }}</div>
    </div>
    <div class="figure">
        @if ($img('receipt'))
            <img src="{{ $img('receipt') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('A sale receipt, showing the full tax breakdown') }}</div>
    </div>
</div>

{{-- 3. Stock control --}}
<div class="section">
    <h1 class="section-title">{{ __('Keeping Stock Under Control') }}</h1>

    <h2 class="sub-title">{{ __('Seeing what\'s running low or out') }}</h2>
    <p>{{ __('Stock Levels lists every item with its current quantity on hand and a status badge — OK, Low, or Out of Stock — alongside its unit price and its tax-inclusive price. Batch-tracked items have a View Batches button showing each batch\'s expiry date and remaining quantity. Export CSV downloads the full list.') }}</p>
    <div class="figure">
        @if ($img('stock-levels'))
            <img src="{{ $img('stock-levels') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Stock Levels') }}</div>
    </div>

    <h2 class="sub-title">{{ __('Receiving new stock from a supplier') }}</h2>
    <p>{{ __('Purchases -> New Purchase: pick the branch and supplier, add a line per item with quantity and unit cost, and Save as a draft. When goods physically arrive, open the purchase and click Receive — this is the point stock actually increases, and the item\'s cost price is recalculated as a weighted average.') }}</p>
    <div class="figure">
        @if ($img('purchases-list'))
            <img src="{{ $img('purchases-list') }}" alt="{{ __('Purchases') }}">
        @endif
        <div class="caption">{{ __('Purchases — including a received purchase, reflected in stock and cost price') }}</div>
    </div>

    <h2 class="sub-title">{{ __('Moving stock between branches') }}</h2>
    <p>{{ __('Stock Transfers -> New Transfer: pick a source and destination branch and list items/quantities. A transfer moves through pending -> in transit -> received, so the destination branch can confirm what actually arrived.') }}</p>
    <div class="figure">
        @if ($img('stock-transfers'))
            <img src="{{ $img('stock-transfers') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Stock Transfers') }}</div>
    </div>

    <h2 class="sub-title">{{ __('Fixing stock counts (adjustments)') }}</h2>
    <p>{{ __('For anything that isn\'t a sale, purchase, or transfer — damages, write-offs, counting mistakes — use Stock Adjustments. Like voids, this needs manager approval before it changes stock, giving you an audit trail for shrinkage and damage.') }}</p>
    <div class="figure">
        @if ($img('stock-adjustments'))
            <img src="{{ $img('stock-adjustments') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Stock Adjustments') }}</div>
    </div>

    <h2 class="sub-title">{{ __('Counting stock (stock takes)') }}</h2>
    <p>{{ __('For a full physical count of a branch, use Stock Takes. A stock take moves through Draft (pre-populated with every branch item and its system quantity) -> In Progress (enter counted quantities as you walk the shelves) -> Pending Approval -> Approved (stock is adjusted to match, with a recorded variance per item) or Cancelled. Each stock take is tied to the Financial Year it was opened in.') }}</p>
    <div class="figure">
        @if ($img('stock-takings-list'))
            <img src="{{ $img('stock-takings-list') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Stock Takes') }}</div>
    </div>
    <div class="figure">
        @if ($img('stock-take-detail'))
            <img src="{{ $img('stock-take-detail') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('A stock take in progress — counted quantity vs. system quantity, side by side') }}</div>
    </div>

    <h2 class="sub-title">{{ __('Financial years') }}</h2>
    <p>{{ __('Administration -> Financial Years lets you open and close accounting periods. Closing a financial year is a checkpoint — DigiPlus won\'t let you close one while it still has a stock take in Draft, In Progress, or Pending Approval, so you can\'t lock in a period with an unreconciled count hanging over it.') }}</p>
    <div class="figure">
        @if ($img('financial-years'))
            <img src="{{ $img('financial-years') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Financial Years') }}</div>
    </div>
</div>

{{-- 4. Customers --}}
<div class="section">
    <h1 class="section-title">{{ __('Customers & Credit Sales') }}</h1>
    <p>{{ __('If you extend credit to regular customers, add them under Customers, and optionally set a credit limit. At the till, attaching a customer and choosing Credit as a payment method adds that amount to their running balance — DigiPlus blocks the sale if it would exceed their limit.') }}</p>
    <p>{{ __('Each customer\'s page has a ledger — every credit sale and payment they\'ve made, with a running balance. When they pay down what they owe, open their ledger and Record Payment. This is a simple running balance, not full accounts-receivable bookkeeping.') }}</p>
    <div class="figure">
        @if ($img('customers'))
            <img src="{{ $img('customers') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Customers') }}</div>
    </div>
    <div class="figure">
        @if ($img('customer-ledger'))
            <img src="{{ $img('customer-ledger') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('A customer\'s ledger — credit sales and payments, with a running balance') }}</div>
    </div>
</div>

{{-- 5. Reports --}}
<div class="section">
    <h1 class="section-title">{{ __('Reports & Insights') }}</h1>
    <p>{{ __('All reports live under the Reports menu, and every table can be exported as CSV or a printable PDF. Every report also respects the branch filter, so you can pull numbers for one location or all of them combined.') }}</p>

    <h2 class="sub-title">{{ __('Sales Report') }}</h2>
    <p>{{ __('Set a From/To date range and branch to get total sales count, revenue, tax, cost, margin, and margin %, plus a day-by-day breakdown.') }}</p>
    <div class="figure">
        @if ($img('reports-sales'))
            <img src="{{ $img('reports-sales') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Reports -> Sales Report') }}</div>
    </div>

    <h2 class="sub-title">{{ __('Profit & Loss') }}</h2>
    <p>{{ __('Goes a step further than the Sales Report: net revenue (after returns), cost of goods sold, gross profit, shrinkage (the cost of stock lost to write-offs and adjustments), and your resulting margin — plus a day-by-day trend and a breakdown by product category.') }}</p>
    <div class="figure">
        @if ($img('reports-profit-loss'))
            <img src="{{ $img('reports-profit-loss') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Reports -> Profit & Loss') }}</div>
    </div>

    <h2 class="sub-title">{{ __('Stock Report') }}</h2>
    <p>{{ __('Four tabs: Valuation (items held and their total cost value), Reorder Exceptions (everything Low or Out of Stock), Expiring Items (batches expiring within 30 days or already expired), and Movement History (every stock in/out event, with who did it).') }}</p>
    <div class="figure">
        @if ($img('reports-stock'))
            <img src="{{ $img('reports-stock') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Reports -> Stock Report') }}</div>
    </div>

    <h2 class="sub-title">{{ __('Item Velocity') }}</h2>
    <p>{{ __('Looks at sales over a 30/60/90-day window: Fast Movers, Slow/Dead Stock, ABC Analysis (which items drive most of your revenue), and Reorder Suggestions based on recent sales velocity.') }}</p>
    <div class="figure">
        @if ($img('reports-velocity'))
            <img src="{{ $img('reports-velocity') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Reports -> Item Velocity') }}</div>
    </div>

    <h2 class="sub-title">{{ __('Shift Report') }}</h2>
    <p>{{ __('Lists every shift (open or closed) in a date range, showing opening float, counted cash, expected cash, and the variance between them.') }}</p>
    <div class="figure">
        @if ($img('reports-shifts'))
            <img src="{{ $img('reports-shifts') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Reports -> Shift Report') }}</div>
    </div>

    <h2 class="sub-title">{{ __('Scheduled Reports') }}</h2>
    <p>{{ __('Set up a recurring Sales Report email (daily, weekly, or monthly) to any list of recipients, per branch or for the whole business. It arrives as a PDF. Available to Business Owners and Branch Managers.') }}</p>
    <div class="figure">
        @if ($img('reports-scheduled'))
            <img src="{{ $img('reports-scheduled') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Reports -> Scheduled Reports') }}</div>
    </div>
</div>

{{-- 6. Notifications --}}
<div class="section">
    <h1 class="section-title">{{ __('Staying in the Loop: Notifications') }}</h1>
    <p>{{ __('The Notifications page collects two kinds of alerts as they happen, in addition to a toast if you\'re logged in at the time:') }}</p>
    <ul>
        <li><strong>{{ __('Low Stock') }}</strong> — {{ __('an item just crossed into Low or Out of Stock. Sent to the Business Owner and the relevant branch\'s Manager/Store Keeper.') }}</li>
        <li><strong>{{ __('Large Discrepancy') }}</strong> — {{ __('a shift closed with a cash variance of 20 or more. Sent to the Business Owner and that branch\'s Manager.') }}</li>
    </ul>
    <p>{{ __('Filter by read/unread and by type, and mark everything read in one click. Clicking a notification takes you straight to the relevant page.') }}</p>
    <div class="figure">
        @if ($img('notifications'))
            <img src="{{ $img('notifications') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Notifications') }}</div>
    </div>
</div>

{{-- 7. Administration --}}
<div class="section">
    <h1 class="section-title">{{ __('Running the Business: Staff, Branches & Access') }}</h1>

    <h2 class="sub-title">{{ __('Audit Log') }}</h2>
    <p>{{ __('A full history of who created, changed, or deleted what, and when — useful when a number looks wrong and you need to know who touched it.') }}</p>
    <div class="figure">
        @if ($img('audit-log'))
            <img src="{{ $img('audit-log') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Administration -> Audit Log') }}</div>
    </div>

    <h2 class="sub-title">{{ __('Backups') }}</h2>
    <p>{{ __('Generate an on-demand snapshot of your catalog, stock, sales, and customer data as a downloadable zip. One is also generated automatically every week, and the 10 most recent are kept. This is for your own records and data portability, not a one-click "undo everything" button.') }}</p>
    <div class="figure">
        @if ($img('backups'))
            <img src="{{ $img('backups') }}" alt="{{ __('Screenshot') }}">
        @endif
        <div class="caption">{{ __('Administration -> Backups') }}</div>
    </div>

    <h2 class="sub-title">{{ __('Quick reference: roles & what each one can do') }}</h2>
    <table class="roles">
        <tr>
            <th>{{ __('Role') }}</th>
            <th>{{ __('Can do') }}</th>
        </tr>
        <tr>
            <td><strong>{{ __('Business Owner') }}</strong></td>
            <td>{{ __('Everything, across every branch — sales, stock, staff, branches, tax settings, backups, approvals, full reports and audit log') }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('Branch Manager') }}</strong></td>
            <td>{{ __('Everything above except tenant-wide settings, scoped to their own branch; approves voids/returns and stock adjustments for their branch; manages that branch\'s staff') }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('Cashier') }}</strong></td>
            <td>{{ __('Sells at the till and views their branch\'s stock and sales — no approvals, no stock changes') }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('Store Keeper') }}</strong></td>
            <td>{{ __('Manages stock (purchases, adjustments, transfers) and suppliers — no till access') }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('Auditor') }}</strong></td>
            <td>{{ __('Read-only across stock, sales, suppliers, and reports — for bookkeepers or oversight') }}</td>
        </tr>
    </table>
</div>

{{-- 8. Offline & help --}}
<div class="section">
    <h1 class="section-title">{{ __('Selling When the Internet Drops') }}</h1>
    <p>{{ __('If a cashier\'s device loses its connection mid-shift, Point of Sale switches to an offline mode automatically: it keeps working off the last-known catalog and prices cached on that device, queues each sale locally, and syncs everything back to the server the moment the connection returns. A banner on the till clearly shows when you\'re offline and how many sales are queued.') }}</p>
    <p>{{ __('Offline sales can\'t use Credit as a payment method, and tax is estimated rather than shown live. If stock had already run out elsewhere while a device was offline, a synced sale can push stock negative — DigiPlus completes the sale but flags it for stock review.') }}</p>

    <h2 class="sub-title">{{ __('Getting help') }}</h2>
    <p>{{ __('For anything not covered here — billing, your subscription plan, or an issue with the platform itself rather than your shop\'s data — that\'s handled by DigiPlus support directly, not from within your shop\'s account.') }}</p>

    <div class="footer-note">{{ __('DigiPlus Inventory Solutions — User Guide') }}</div>
</div>

</body>
</html>
