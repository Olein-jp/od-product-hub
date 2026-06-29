<?php
/**
 * Synchronizes Stripe invoice payment state.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Webhook\Handler;

use OD_Product_Hub\Customer\CustomerRepository;
use OD_Product_Hub\Database\UtcDateTime;
use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Subscription\SubscriptionRepository;

final class InvoiceHandler implements WebhookHandler {
	public function handle( string $event_type, object $invoice ): void {
		$failed          = 'invoice.payment_failed' === $event_type;
		$subscription_id = is_object( $invoice->subscription ?? null ) ? (string) $invoice->subscription->id : (string) ( $invoice->subscription ?? '' );
		$subscription    = ( new SubscriptionRepository() )->find_by_stripe_id( $subscription_id );
		if ( ! $subscription ) {
			throw new \RuntimeException( 'Invoice subscription is not registered.' );
		}
		( new SubscriptionRepository() )->update(
			(int) $subscription->id,
			array( 'payment_failed_at' => $failed ? UtcDateTime::now() : null )
		);
		( new LicenseRepository() )->set_status_preserving_suspended( (int) $subscription->id, $failed ? 'inactive' : 'active' );
		if ( $failed ) {
			$customer = ( new CustomerRepository() )->find( (int) $subscription->customer_id );
			if ( $customer ) {
				do_action( 'odph_webhook_payment_failed', $customer );
			}
		}
	}
}
