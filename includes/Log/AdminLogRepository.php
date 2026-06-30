<?php
/**
 * Admin log persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Log;

use OD_Product_Hub\Database\AbstractRepository;
use OD_Product_Hub\Database\RepositoryPage;

final class AdminLogRepository extends AbstractRepository {
	protected function table_suffix(): string {
		return 'admin_logs';
	}

	protected function writable_columns(): array {
		return array( 'user_id', 'action', 'object_type', 'object_id', 'details', 'created_at' );
	}

	public function search_admin( string $action, string $object_type, int $user_id, int $page = 1, int $per_page = 20 ): RepositoryPage {
		global $wpdb;
		$action      = sanitize_key( $action );
		$object_type = sanitize_key( $object_type );
		$page        = max( 1, $page );
		$per_page    = max( 1, min( 100, $per_page ) );
		$conditions  = array();
		$values      = array( $this->table() );
		foreach ( array(
			'action'      => $action,
			'object_type' => $object_type,
		) as $column => $value ) {
			if ( '' !== $value ) {
				$conditions[] = $column . ' = %s';
				$values[]     = $value;
			}
		}
		if ( 0 < $user_id ) {
			$conditions[] = 'user_id = %d';
			$values[]     = $user_id;
		}
		$where = $conditions ? ' WHERE ' . implode( ' AND ', $conditions ) : '';
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i' . $where, ...$values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Conditions are allowlisted and values are prepared dynamically.
		$this->assert_read( 'count admin logs' );
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Optional allowlisted conditions add a dynamic number of prepared values.
			$wpdb->prepare(
				'SELECT id, user_id, action, object_type, object_id, details, created_at FROM %i' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Conditions are allowlisted and values are prepared.
				...array_merge( $values, array( $per_page, ( $page - 1 ) * $per_page ) )
			)
		);
		$this->assert_read( 'search admin logs' );
		$items = is_array( $rows ) ? array_values( array_filter( $rows, 'is_object' ) ) : array();
		return new RepositoryPage( $items, $total, $page, $per_page, (int) ceil( $total / $per_page ) );
	}
}
