<?php
/**
 * Subscription persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Subscription;

use OD_Product_Hub\Database\AbstractRepository;
use OD_Product_Hub\Database\PaginatedQuery;
use OD_Product_Hub\Database\RepositoryPage;
use OD_Product_Hub\Database\SqlFragment;

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

	public function count_created_since( string $utc ): int {
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at >= %s', $this->table(), $utc ) );
		$this->assert_read( 'count recent subscriptions' );
		return $count;
	}

	public function search_admin( string $status, int $page = 1, int $per_page = 20 ): RepositoryPage {
		global $wpdb;
		$allowed    = array( 'active', 'trialing', 'past_due', 'unpaid', 'canceled', 'incomplete', 'incomplete_expired', 'paused' );
		$status     = in_array( $status, $allowed, true ) ? $status : '';
		$conditions = '' === $status ? array() : array( new SqlFragment( 's.stripe_status = %s', array( $status ) ) );
		return $this->paginate(
			new PaginatedQuery(
				's.*, c.email AS customer_email, c.name AS customer_name, p.name AS product_name',
				new SqlFragment( '%i s', array( $this->table() ) ),
				new SqlFragment( '%i s INNER JOIN %i c ON c.id = s.customer_id INNER JOIN %i p ON p.id = s.product_id', array( $this->table(), $wpdb->prefix . 'odph_customers', $wpdb->prefix . 'odph_products' ) ),
				$conditions,
				new SqlFragment( 's.id DESC' )
			),
			$page,
			$per_page,
			'subscriptions'
		);
	}

	/** @return list<object> */
	public function find_for_customer( int $customer_id, int $limit = 100 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.*, p.name AS product_name FROM %i s INNER JOIN %i p ON p.id = s.product_id WHERE s.customer_id = %d ORDER BY s.id DESC LIMIT %d',
				$this->table(),
				$wpdb->prefix . 'odph_products',
				$customer_id,
				max( 1, min( 100, $limit ) )
			)
		);
		$this->assert_read( 'find customer subscriptions' );
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_object' ) ) : array();
	}
}
