<?php
/**
 * Admin log persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Log;

use OD_Product_Hub\Database\AbstractRepository;

final class AdminLogRepository extends AbstractRepository {
	protected function table_suffix(): string {
		return 'admin_logs';
	}

	protected function writable_columns(): array {
		return array( 'user_id', 'action', 'object_type', 'object_id', 'details', 'created_at' );
	}
}
