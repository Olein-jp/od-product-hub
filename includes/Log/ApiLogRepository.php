<?php
/**
 * API log persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Log;

use OD_Product_Hub\Database\AbstractRepository;
use OD_Product_Hub\Database\PaginatedQuery;
use OD_Product_Hub\Database\RepositoryPage;
use OD_Product_Hub\Database\SqlFragment;

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
		$conditions = array();
		foreach ( array(
			'action'     => $action,
			'result'     => $result,
			'error_code' => $error_code,
		) as $column => $value ) {
			if ( '' !== $value ) {
				$conditions[] = new SqlFragment( '%i = %s', array( $column, $value ) );
			}
		}
		if ( '' !== $site_url ) {
			$conditions[] = new SqlFragment( 'site_url LIKE %s', array( $wpdb->esc_like( $site_url ) . '%' ) );
		}
		$table = new SqlFragment( '%i', array( $this->table() ) );
		return $this->paginate( new PaginatedQuery( 'id, license_id, product_id, action, result, site_url, ip_address, error_code, created_at', $table, $table, $conditions, new SqlFragment( 'id DESC' ) ), $page, $per_page, 'api logs' );
	}
}
