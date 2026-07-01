#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(php "$ROOT/scripts/check-version.php" --print)"
BUILD_ROOT="$ROOT/build/release"
STAGING="$BUILD_ROOT/od-product-hub"
DIST_DIR="$ROOT/dist"
ZIP_PATH="$DIST_DIR/od-product-hub-$VERSION.zip"

rm -rf "$BUILD_ROOT"
mkdir -p "$STAGING" "$DIST_DIR"

rsync -a \
	--exclude-from="$ROOT/.distignore" \
	--exclude='/build' \
	--exclude='/dist' \
	"$ROOT/" "$STAGING/"

composer install \
	--working-dir="$STAGING" \
	--no-dev \
	--no-interaction \
	--prefer-dist \
	--classmap-authoritative

rm -f "$STAGING/composer.json" "$STAGING/composer.lock"
find "$STAGING/vendor" -type f \( -name '*.md' -o -name 'composer.json' -o -name 'justfile' -o -name '.gitignore' \) -delete
find "$STAGING" -exec touch -h -t 202001010000 {} +
rm -f "$ZIP_PATH"
(
	cd "$BUILD_ROOT"
	LC_ALL=C find od-product-hub -type f -print | LC_ALL=C sort | zip -X -q "$ZIP_PATH" -@
)

"$ROOT/scripts/validate-release.sh" "$ZIP_PATH"
echo "Release ZIP created: $ZIP_PATH"
