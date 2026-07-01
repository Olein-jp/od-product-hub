#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(php "$ROOT/scripts/check-version.php" --print)"
ZIP_PATH="$ROOT/dist/od-product-hub-$VERSION.zip"

"$ROOT/scripts/build-release.sh" >/dev/null
FIRST="$(php -r 'echo hash_file("sha256", $argv[1]);' "$ZIP_PATH")"
"$ROOT/scripts/build-release.sh" >/dev/null
SECOND="$(php -r 'echo hash_file("sha256", $argv[1]);' "$ZIP_PATH")"

if [[ "$FIRST" != "$SECOND" ]]; then
	echo "Release ZIP is not reproducible: $FIRST != $SECOND" >&2
	exit 1
fi

echo "Reproducible release SHA-256: $FIRST"
