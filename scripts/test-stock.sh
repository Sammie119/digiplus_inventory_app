#!/usr/bin/env bash
#
# scripts/test-stock.sh — run the stock/inventory feature tests.
#
# Usage:
#   scripts/test-stock.sh [artisan test options]
#
# Examples:
#   scripts/test-stock.sh              # run this area
#   scripts/test-stock.sh --parallel   # forwarded straight through to `php artisan test`
#
# Covers the catalog and physical stock paths: products/categories/units of
# measure, barcode lookup & labels, suppliers, purchases (goods receipt),
# stock levels, stock adjustments, and branch-to-branch stock transfers.
# The Stock Report (under the Reports menu) lives in test-reports.sh instead.

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

FILES=(
    tests/Feature/CategoryManagementTest.php
    tests/Feature/UnitOfMeasureManagementTest.php
    tests/Feature/ProductManagementTest.php
    tests/Feature/ItemBarcodeSearchTest.php
    tests/Feature/ItemBarcodeLabelTest.php
    tests/Feature/SupplierManagementTest.php
    tests/Feature/PurchaseManagementTest.php
    tests/Feature/StockLevelsTest.php
    tests/Feature/StockAdjustmentManagementTest.php
    tests/Feature/StockTransferManagementTest.php
)

php artisan config:clear --ansi
php artisan test "${FILES[@]}" "$@"
