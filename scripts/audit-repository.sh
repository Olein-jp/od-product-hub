#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

for forbidden in .env wp-config.php; do
	if git ls-files --error-unmatch "$forbidden" >/dev/null 2>&1; then
		echo "Forbidden environment file is tracked: $forbidden" >&2
		exit 1
	fi
done

if git ls-files | grep -Eq '(^|/)(dist|build|coverage)/|\.(sql|sqlite|log|zip)$'; then
	echo "A generated archive, database, or log file is tracked." >&2
	exit 1
fi

if git grep -IEn '(sk|rk)_live_[A-Za-z0-9]{16,}|-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----' -- ':!composer.lock' ':!package-lock.json'; then
	echo "A production credential-shaped value was found in tracked files." >&2
	exit 1
fi

echo "Tracked repository content passed the secret and generated-file audit."
