#!/usr/bin/env bash
#
# scripts/test-reports.sh — run the reporting feature tests.
#
# Usage:
#   scripts/test-reports.sh [artisan test options]
#
# Examples:
#   scripts/test-reports.sh              # run this area
#   scripts/test-reports.sh --parallel   # forwarded straight through to `php artisan test`
#
# Covers everything under the Reports menu: Sales Report (incl. CSV/PDF
# export), Stock Report, Item Velocity (fast/slow movers, ABC analysis,
# reorder suggestions), Shift Report (till cash variance), and Scheduled
# Reports (recurring emailed sales reports).

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

FILES=(
    tests/Feature/SalesReportTest.php
    tests/Feature/SalesReportExportTest.php
    tests/Feature/StockReportTest.php
    tests/Feature/ItemVelocityReportTest.php
    tests/Feature/ShiftReportTest.php
    tests/Feature/ScheduledReportTest.php
)

php artisan config:clear --ansi
php artisan test "${FILES[@]}" "$@"
