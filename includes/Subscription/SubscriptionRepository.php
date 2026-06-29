<?php
/**
 * Subscription persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Subscription;

use OD_Product_Hub\Database\AbstractRepository;

final class SubscriptionRepository extends AbstractRepository {
	protected function table_suffix(): string {
		return 'subscriptions';
	}

	protected function writable_columns(): array {
		return array( 'customer_id', 'product_id', 'stripe_subscription_id', 'stripe_status', 'current_period_start', 'current_period_end', 'cancel_at_period_end', 'payment_failed_at', 'created_at', 'updated_at' );
	}

	public function find_by_stripe_id( string $stripe_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE stripe_subscription_id = %s LIMIT 1', $this->table(), $stripe_id ) );
		$this->assert_read( 'find subscription' );
		return is_object( $row ) ? $row : null;
	}

	/** @param array<string, mixed> $data */
	public function upsert_by_stripe_id( string $stripe_id, array $data ): int {
		$subscription                   = $this->find_by_stripe_id( $stripe_id );
		$data['stripe_subscription_id'] = $stripe_id;
		if ( $subscription ) {
			$this->update( (int) $subscription->id, $data );
			return (int) $subscription->id;
		}
		return $this->create( $data );
	}

	public function count_payment_failures(): int {
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE payment_failed_at IS NOT NULL', $this->table() ) );
		$this->assert_read( 'count payment failures' );
		return $count;
	}
}
