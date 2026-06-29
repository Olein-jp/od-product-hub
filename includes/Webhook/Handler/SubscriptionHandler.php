<?php
/**
 * Synchronizes Stripe subscription lifecycle events.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Webhook\Handler;

use OD_Product_Hub\Customer\CustomerRepository;
use OD_Product_Hub\Customer\CustomerSyncService;
use OD_Product_Hub\Database\UtcDateTime;
use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Product\ProductRepository;

final class SubscriptionHandler implements WebhookHandler {
	public function handle( string $event_type, object $subscription ): void {
		$customer = ( new CustomerRepository() )->find_by_stripe_id( (string) $subscription->customer );
		$product  = ( new ProductRepository() )->find_by_price( (string) $subscription->items->data[0]->price->id );
		if ( ! $customer || ! $product ) {
			throw new \RuntimeException( 'Subscription customer or product is not registered.' );
		}
		$id      = ( new CustomerSyncService() )->upsert_subscription( (int) $customer->id, (int) $product->id, $subscription );
		$end     = (int) ( $subscription->current_period_end ?? 0 );
		$deleted = 'customer.subscription.deleted' === $event_type;
		$status  = $this->license_status( (string) $subscription->status, (bool) $subscription->cancel_at_period_end, $end, $deleted );
		( new LicenseRepository() )->sync_subscription_state( $id, $status, UtcDateTime::from_timestamp( 0 === $end ? null : $end ) );
	}

	private function license_status( string $stripe_status, bool $cancel_at_end, int $period_end, bool $deleted ): string {
		if ( $period_end > time() && ( $cancel_at_end || $deleted ) ) {
			return 'active';
		}
		if ( $deleted || 'canceled' === $stripe_status ) {
			return 'cancelled';
		}
		if ( in_array( $stripe_status, array( 'active', 'trialing' ), true ) ) {
			return 'active';
		}
		return 'unpaid' === $stripe_status ? 'expired' : 'inactive';
	}
}
