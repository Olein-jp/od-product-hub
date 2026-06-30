<?php
/**
 * API log persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Log;

use OD_Product_Hub\Database\AbstractRepository;
use OD_Product_Hub\Database\RepositoryPage;

final class ApiLogRepository extends AbstractRepository {
	protected function table_suffix(): string {
		return 'api_logs';
	}

	protected function writable_columns(): array {
		return array( 'license_id', 'product_id', 'action', 'result', 'site_url', 'ip_address', 'user_agent', 'error_code', 'created_at' );
	}

	/** @return list<object> */
	public function find_for_customer( int $customer_id, int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.* FROM %i a INNER JOIN %i l ON l.id = a.license_id WHERE l.customer_id = %d ORDER BY a.id DESC LIMIT %d',
				$this->table(),
				$wpdb->prefix . 'odph_licenses',
				$customer_id,
				max( 1, min( 100, $limit ) )
			)
		);
		$this->assert_read( 'find customer api logs' );
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_object' ) ) : array();
	}

	/** @return list<object> */
	public function find_for_license( int $license_id, int $limit = 100 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE license_id = %d ORDER BY id DESC LIMIT %d',
				$this->table(),
				$license_id,
				max( 1, min( 100, $limit ) )
			)
		);
		$this->assert_read( 'find license api logs' );
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_object' ) ) : array();
	}

	/** @return list<object> */
	public function recent( int $limit = 5 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, action, result, site_url, error_code, created_at FROM %i ORDER BY id DESC LIMIT %d', $this->table(), max( 1, min( 20, $limit ) ) ) );
		$this->assert_read( 'recent api logs' );
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_object' ) ) : array();
	}

	public function search_admin( string $action, string $result, string $error_code, string $site_url, int $page = 1, int $per_page = 20 ): RepositoryPage {
		global $wpdb;
		$actions    = array( 'activate', 'verify', 'deactivate' );
		$results    = array( 'success', 'failure' );
		$action     = in_array( $action, $actions, true ) ? $action : '';
		$result     = in_array( $result, $results, true ) ? $result : '';
		$error_code = sanitize_key( $error_code );
		$site_url   = trim( $site_url );
		$page       = max( 1, $page );
		$per_page   = max( 1, min( 100, $per_page ) );
		$conditions = array();
		$values     = array( $this->table() );
		foreach ( array(
			'action'     => $action,
			'result'     => $result,
			'error_code' => $error_code,
		) as $column => $value ) {
			if ( '' !== $value ) {
				$conditions[] = $column . ' = %s';
				$values[]     = $value;
			}
		}
		if ( '' !== $site_url ) {
			$conditions[] = 'site_url LIKE %s';
			$values[]     = $wpdb->esc_like( $site_url ) . '%';
		}
		$where = $conditions ? ' WHERE ' . implode( ' AND ', $conditions ) : '';
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i' . $where, ...$values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Conditions are allowlisted and values are prepared dynamically.
		$this->assert_read( 'count api logs' );
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Optional allowlisted conditions add a dynamic number of prepared values.
			$wpdb->prepare(
				'SELECT id, license_id, product_id, action, result, site_url, ip_address, error_code, created_at FROM %i' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Conditions are allowlisted and values are prepared.
				...array_merge( $values, array( $per_page, ( $page - 1 ) * $per_page ) )
			)
		);
		$this->assert_read( 'search api logs' );
		$items = is_array( $rows ) ? array_values( array_filter( $rows, 'is_object' ) ) : array();
		return new RepositoryPage( $items, $total, $page, $per_page, (int) ceil( $total / $per_page ) );
	}
}
