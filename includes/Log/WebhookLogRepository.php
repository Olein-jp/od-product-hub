<?php
/**
 * Webhook log persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Log;

use OD_Product_Hub\Database\AbstractRepository;

final class WebhookLogRepository extends AbstractRepository {
	protected function table_suffix(): string {
		return 'webhook_logs';
	}

	protected function writable_columns(): array {
		return array( 'stripe_event_id', 'event_type', 'payload', 'result', 'error_message', 'created_at' );
	}

	public function find_by_event_id( string $event_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE stripe_event_id = %s LIMIT 1', $this->table(), $event_id ) );
		$this->assert_read( 'find webhook log' );
		return is_object( $row ) ? $row : null;
	}

	public function count_by_result( string $result ): int {
		return $this->search( array( 'result' => $result ), 1, 1 )->total;
	}
}
