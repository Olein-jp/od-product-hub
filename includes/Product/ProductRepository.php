<?php
/**
 * Product persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Product;

use OD_Product_Hub\Database\AbstractRepository;
use OD_Product_Hub\Database\PaginatedQuery;
use OD_Product_Hub\Database\RepositoryPage;
use OD_Product_Hub\Database\SqlFragment;

final class ProductRepository extends AbstractRepository {
	protected function table_suffix(): string {
		return 'products';
	}

	protected function writable_columns(): array {
		return array( 'name', 'slug', 'description', 'price_description', 'billing_description', 'license_key_prefix', 'stripe_product_id', 'stripe_price_id', 'status', 'created_at', 'updated_at' );
	}

	public function find_by_slug( string $slug ): ?object {
		return $this->find_one_by( 'slug', $slug );
	}

	public function find_by_price( string $price_id ): ?object {
		return $this->find_one_by( 'stripe_price_id', $price_id );
	}

	public function find_by_stripe_product_id( string $product_id ): ?object {
		return $this->find_one_by( 'stripe_product_id', $product_id );
	}

	public function has_licenses( int $product_id ): bool {
		global $wpdb;
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE product_id = %d', $wpdb->prefix . 'odph_licenses', $product_id ) );
		$this->assert_read( 'count product licenses' );
		return 0 < (int) $count;
	}

	public function search_admin( string $query, string $status, int $page = 1, int $per_page = 20 ): RepositoryPage {
		global $wpdb;
		$conditions = array();
		if ( '' !== $query ) {
			$like         = '%' . $wpdb->esc_like( $query ) . '%';
			$conditions[] = new SqlFragment( '(name LIKE %s OR slug LIKE %s)', array( $like, $like ) );
		}
		if ( in_array( $status, array( 'active', 'inactive' ), true ) ) {
			$conditions[] = new SqlFragment( 'status = %s', array( $status ) );
		}
		$table = new SqlFragment( '%i', array( $this->table() ) );
		return $this->paginate( new PaginatedQuery( '*', $table, $table, $conditions, new SqlFragment( 'id DESC' ) ), $page, $per_page, 'products' );
	}

	private function find_one_by( string $column, string $value ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE %i = %s LIMIT 1', $this->table(), $column, $value ) );
		$this->assert_read( 'find product' );
		return is_object( $row ) ? $row : null;
	}
}
