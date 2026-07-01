#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(php "$ROOT/scripts/check-version.php" --print)"
ZIP_PATH="$ROOT/dist/od-product-hub-$VERSION.zip"

"$ROOT/scripts/build-release.sh"
npx wp-env start
npx wp-env run tests-cli bash wp-content/plugins/od-product-hub/scripts/release-install-container.sh "$VERSION"

echo "Fresh WordPress release installation passed."
