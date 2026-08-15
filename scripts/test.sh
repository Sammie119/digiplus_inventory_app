#!/usr/bin/env bash
#
# scripts/test.sh — run the full DigiPlus test suite (Unit + Feature).
#
# Usage:
#   scripts/test.sh [artisan test options]
#
# Examples:
#   scripts/test.sh                # run everything
#   scripts/test.sh --parallel     # run everything in parallel
#   scripts/test.sh --filter=Sale  # forwarded straight through to `php artisan test`
#
# Mirrors the `composer test` script: clears cached config first so config
# left over from a previous `artisan config:cache` doesn't leak into the
# test run (phpunit.xml sets APP_ENV=testing, sqlite :memory:, etc. either
# way), then hands off to `php artisan test`.

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

php artisan config:clear --ansi
php artisan test "$@"
