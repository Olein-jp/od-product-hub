<?php
/**
 * Customer persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Customer;

use OD_Product_Hub\Database\AbstractRepository;

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

	/** @param int|string $value */
	private function find_one( string $column, $value ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE %i = %s LIMIT 1', $this->table(), $column, (string) $value ) );
		$this->assert_read( 'find customer' );
		return is_object( $row ) ? $row : null;
	}
}
