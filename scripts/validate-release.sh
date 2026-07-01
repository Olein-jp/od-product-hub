#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ZIP_PATH="${1:-}"

if [[ -z "$ZIP_PATH" || ! -f "$ZIP_PATH" ]]; then
	echo "Usage: $0 path/to/od-product-hub-version.zip" >&2
	exit 1
fi

unzip -tq "$ZIP_PATH"

CONTENTS="$(unzip -Z1 "$ZIP_PATH")"
for required in \
	"od-product-hub/od-product-hub.php" \
	"od-product-hub/readme.txt" \
	"od-product-hub/LICENSE" \
	"od-product-hub/vendor/autoload.php" \
	"od-product-hub/vendor/stripe/stripe-php/init.php"; do
	if ! grep -Fxq "$required" <<<"$CONTENTS"; then
		echo "Required release file is missing: $required" >&2
		exit 1
	fi
done

if grep -Eq '^od-product-hub/(\.git|\.github|\.gitignore|\.env($|\.)|node_modules|packages|examples|tests|scripts|build|dist|\.phpunit\.cache)(/|$)|^od-product-hub/(composer\.(json|lock)|package(-lock)?\.json|phpcs\.xml\.dist|phpstan\.neon\.dist|phpunit\.xml\.dist)$' <<<"$CONTENTS"; then
	echo "Development-only content was found in the release ZIP." >&2
	exit 1
fi

if grep -Eq '(^|/)vendor/(phpunit|phpstan|squizlabs|wp-coding-standards|yoast)(/|$)' <<<"$CONTENTS"; then
	echo "Composer development dependencies were found in the release ZIP." >&2
	exit 1
fi

VALIDATE_DIR="$ROOT/build/release-validate"
rm -rf "$VALIDATE_DIR"
mkdir -p "$VALIDATE_DIR"
unzip -q "$ZIP_PATH" -d "$VALIDATE_DIR"

if grep -REn --binary-files=without-match '(sk|rk)_(live|test)_[A-Za-z0-9]{16,}|whsec_[A-Za-z0-9]{16,}' "$VALIDATE_DIR"; then
	echo "A Stripe credential-shaped value was found in the release ZIP." >&2
	exit 1
fi

find "$VALIDATE_DIR/od-product-hub" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
php "$ROOT/scripts/check-version.php"
echo "Release ZIP validated: $ZIP_PATH"
