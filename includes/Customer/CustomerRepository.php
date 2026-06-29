<?php
/**
 * Customer persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Customer;

use OD_Product_Hub\Database\AbstractRepository;
use OD_Product_Hub\Database\RepositoryPage;

final class CustomerRepository extends AbstractRepository {
	protected function table_suffix(): string {
		return 'customers';
	}

	protected function writable_columns(): array {
		return array( 'wp_user_id', 'stripe_customer_id', 'email', 'name', 'created_at', 'updated_at' );
	}

	public function find_by_stripe_id( string $stripe_id ): ?object {
		return $this->find_one( 'stripe_customer_id', $stripe_id );
	}

	public function find_by_user_id( int $user_id ): ?object {
		return $this->find_one( 'wp_user_id', $user_id );
	}

	/** @param array<string, mixed> $data */
	public function upsert_by_stripe_id( string $stripe_id, array $data ): int {
		$customer                   = $this->find_by_stripe_id( $stripe_id );
		$data['stripe_customer_id'] = $stripe_id;
		if ( $customer ) {
			$this->update( (int) $customer->id, $data );
			return (int) $customer->id;
		}
		return $this->create( $data );
	}

	public function search_admin( string $email, int $page = 1, int $per_page = 20 ): RepositoryPage {
		global $wpdb;
		$page   = max( 1, $page );
		$email  = trim( $email );
		$where  = '';
		$values = array( $this->table() );
		if ( '' !== $email ) {
			$where = ' WHERE email LIKE %s';
			// Prefix search allows the existing email index to remain usable.
			$values[] = $wpdb->esc_like( $email ) . '%';
		}
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i' . $where, ...$values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The condition is fixed and the value is prepared.
		$this->assert_read( 'count customers' );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d', ...array_merge( $values, array( $per_page, ( $page - 1 ) * $per_page ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The condition is fixed and dynamic values are prepared.
		$this->assert_read( 'search customers' );
		$items = is_array( $rows ) ? array_values( array_filter( $rows, 'is_object' ) ) : array();
		return new RepositoryPage( $items, $total, $page, $per_page, (int) ceil( $total / $per_page ) );
	}

	/** @param int|string $value */
	private function find_one( string $column, $value ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE %i = %s LIMIT 1', $this->table(), $column, (string) $value ) );
		$this->assert_read( 'find customer' );
		return is_object( $row ) ? $row : null;
	}
}
