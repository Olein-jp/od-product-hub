<?php
/**
 * Customer and subscription persistence boundary for Stripe synchronization.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Customer;

use OD_Product_Hub\Database\UtcDateTime;
use OD_Product_Hub\Subscription\SubscriptionRepository;

final class CustomerSyncService {
	public function upsert_customer( string $stripe_customer_id, int $wp_user_id, string $email, string $name ): int {
		return ( new CustomerRepository() )->upsert_by_stripe_id(
			$stripe_customer_id,
			array(
				'wp_user_id' => $wp_user_id,
				'email'      => sanitize_email( $email ),
				'name'       => sanitize_text_field( $name ),
			)
		);
	}

	public function upsert_subscription( int $customer_id, int $product_id, object $subscription ): int {
		return ( new SubscriptionRepository() )->upsert_by_stripe_id(
			(string) $subscription->id,
			array(
				'customer_id'          => $customer_id,
				'product_id'           => $product_id,
				'stripe_status'        => sanitize_key( (string) $subscription->status ),
				'current_period_start' => $this->date( $subscription->current_period_start ?? null ),
				'current_period_end'   => $this->date( $subscription->current_period_end ?? null ),
				'cancel_at_period_end' => empty( $subscription->cancel_at_period_end ) ? 0 : 1,
			)
		);
	}

	/** @param mixed $timestamp */
	private function date( $timestamp ): ?string {
		return UtcDateTime::from_timestamp( $timestamp );
	}
}
