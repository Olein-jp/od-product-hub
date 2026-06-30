<?php
/**
 * Product persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Product;

use OD_Product_Hub\Database\AbstractRepository;
use OD_Product_Hub\Database\RepositoryPage;

final class ProductRepository extends AbstractRepository {
	protected function table_suffix(): string {
		return 'products';
	}

	protected function writable_columns(): array {
		return array( 'name', 'slug', 'description', 'price_description', 'billing_description', 'stripe_product_id', 'stripe_price_id', 'status', 'created_at', 'updated_at' );
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

	public function search_admin( string $query, string $status, int $page = 1, int $per_page = 20 ): RepositoryPage {
		global $wpdb;
		$page       = max( 1, $page );
		$conditions = array();
		$values     = array( $this->table() );
		if ( '' !== $query ) {
			$conditions[] = '(name LIKE %s OR slug LIKE %s)';
			$like         = '%' . $wpdb->esc_like( $query ) . '%';
			$values[]     = $like;
			$values[]     = $like;
		}
		if ( in_array( $status, array( 'active', 'inactive' ), true ) ) {
			$conditions[] = 'status = %s';
			$values[]     = $status;
		}
		$where = $conditions ? ' WHERE ' . implode( ' AND ', $conditions ) : '';
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i' . $where, ...$values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Conditions are fixed and values are prepared dynamically.
		$this->assert_read( 'count products' );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d', ...array_merge( $values, array( $per_page, ( $page - 1 ) * $per_page ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Conditions are fixed and values are prepared dynamically.
		$this->assert_read( 'search products' );
		$items = is_array( $rows ) ? array_values( array_filter( $rows, 'is_object' ) ) : array();
		return new RepositoryPage( $items, $total, $page, $per_page, (int) ceil( $total / $per_page ) );
	}

	private function find_one_by( string $column, string $value ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE %i = %s LIMIT 1', $this->table(), $column, $value ) );
		$this->assert_read( 'find product' );
		return is_object( $row ) ? $row : null;
	}
}
