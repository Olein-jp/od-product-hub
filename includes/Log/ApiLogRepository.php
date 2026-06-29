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
}
