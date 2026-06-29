<?php
/**
 * Product persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Product;

use OD_Product_Hub\Database\AbstractRepository;

final class ProductRepository extends AbstractRepository {
	protected function table_suffix(): string {
		return 'products';
	}

	protected function writable_columns(): array {
		return array( 'name', 'slug', 'description', 'stripe_product_id', 'stripe_price_id', 'status', 'created_at', 'updated_at' );
	}

	public function find_by_slug( string $slug ): ?object {
		return $this->find_one_by( 'slug', $slug );
	}

	public function find_by_price( string $price_id ): ?object {
		return $this->find_one_by( 'stripe_price_id', $price_id );
	}

	private function find_one_by( string $column, string $value ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE %i = %s LIMIT 1', $this->table(), $column, $value ) );
		$this->assert_read( 'find product' );
		return is_object( $row ) ? $row : null;
	}
}
