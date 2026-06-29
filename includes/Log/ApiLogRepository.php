<?php
/**
 * API log persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Log;

use OD_Product_Hub\Database\AbstractRepository;

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
}
