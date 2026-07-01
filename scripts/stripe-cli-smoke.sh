#!/usr/bin/env bash
set -euo pipefail

if [[ "${ODPH_STRIPE_TEST_MODE_CONFIRMED:-}" != "yes" ]]; then
	echo "Set ODPH_STRIPE_TEST_MODE_CONFIRMED=yes after confirming Stripe CLI is using test mode." >&2
	exit 1
fi
if ! command -v stripe >/dev/null 2>&1; then
	echo "Stripe CLI is not installed." >&2
	exit 1
fi

events=(
	checkout.session.completed
	customer.subscription.created
	customer.subscription.updated
	customer.subscription.deleted
	invoice.paid
	invoice.payment_failed
)

for event in "${events[@]}"; do
	echo "Triggering test event: $event"
	stripe trigger "$event"
done

cat <<'MESSAGE'
Stripe test events were created. Verify their Webhook/API results in OD Product Hub.
For an application-state E2E and duplicate resend, follow docs/STRIPE_CLI_TEST.md.
MESSAGE
