<?php
/**
 * Email delivery failure persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Log;

use OD_Product_Hub\Database\AbstractRepository;

final class EmailLogRepository extends AbstractRepository {
	protected function table_suffix(): string {
		return 'email_logs';
	}

	protected function writable_columns(): array {
		return array( 'email_type', 'recipient_hash', 'status', 'error_code', 'created_at' );
	}
}
