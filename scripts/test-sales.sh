#!/usr/bin/env bash
#
# scripts/test-sales.sh — run the sales/POS feature tests.
#
# Usage:
#   scripts/test-sales.sh [artisan test options]
#
# Examples:
#   scripts/test-sales.sh              # run this area
#   scripts/test-sales.sh --parallel   # forwarded straight through to `php artisan test`
#
# Covers the till: point-of-sale checkout, offline sale queue/sync, sale
# voids & returns (request + manager approval), printable receipts, tax
# calculation, and customers (credit sales & ledger payments).

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

FILES=(
    tests/Feature/PosManagementTest.php
    tests/Feature/OfflineSaleSyncTest.php
    tests/Feature/SaleVoidAndReturnTest.php
    tests/Feature/SaleReceiptTest.php
    tests/Feature/TaxRateManagementTest.php
    tests/Feature/CustomerManagementTest.php
)

php artisan config:clear --ansi
php artisan test "${FILES[@]}" "$@"
