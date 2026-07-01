#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

composer install --no-interaction --prefer-dist --no-progress
composer validate --strict
composer lint
composer phpstan
composer test

composer install --working-dir=packages/client-sdk --no-interaction --prefer-dist --no-progress
composer validate --working-dir=packages/client-sdk --strict
composer quality --working-dir=packages/client-sdk

composer install --working-dir=examples/sample-plugin --no-interaction --prefer-dist --no-progress
composer validate --working-dir=examples/sample-plugin --strict
php scripts/test-client-example-autoload.php
