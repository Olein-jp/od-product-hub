<?php
/**
 * Webhook log persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Log;

use OD_Product_Hub\Database\AbstractRepository;
use OD_Product_Hub\Database\DatabaseException;
use OD_Product_Hub\Database\PaginatedQuery;
use OD_Product_Hub\Database\RepositoryPage;
use OD_Product_Hub\Database\SqlFragment;
use OD_Product_Hub\Database\UtcDateTime;

final class WebhookLogRepository extends AbstractRepository {
	protected function table_suffix(): string {
		return 'webhook_logs';
	}

	protected function writable_columns(): array {
		return array( 'stripe_event_id', 'event_type', 'payload', 'result', 'error_message', 'duplicate_count', 'last_received_at', 'created_at' );
	}

	public function claim( string $event_id, string $event_type, string $payload ): ?int {
		global $wpdb;
		$now    = UtcDateTime::now();
		$sql    = $wpdb->prepare(
			'INSERT IGNORE INTO %i (stripe_event_id, event_type, payload, result, duplicate_count, last_received_at, created_at) VALUES (%s, %s, %s, %s, 0, %s, %s)',
			$this->table(),
			$event_id,
			$event_type,
			$payload,
			'processing',
			$now,
			$now
		);
		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared atomic claim is required for webhook idempotency.
		if ( false === $result ) {
			throw DatabaseException::from_last_error( 'claim webhook event' );
		}
		if ( 1 === $result ) {
			return (int) $wpdb->insert_id;
		}
		$this->mark_duplicate( $event_id );
		return null;
	}

	public function finish( int $id, string $result, ?string $error = null ): void {
		$this->update(
			$id,
			array(
				'result'           => $result,
				'error_message'    => $error,
				'last_received_at' => UtcDateTime::now(),
			)
		);
	}

	public function record_signature_failure( string $payload, string $error ): void {
		$this->create(
			array(
				'stripe_event_id'  => 'signature_' . wp_generate_uuid4(),
				'event_type'       => 'signature_verification',
				'payload'          => $payload,
				'result'           => 'signature_error',
				'error_message'    => $error,
				'last_received_at' => UtcDateTime::now(),
			)
		);
	}

	private function mark_duplicate( string $event_id ): void {
		global $wpdb;
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET duplicate_count = duplicate_count + 1, last_received_at = %s WHERE stripe_event_id = %s',
				$this->table(),
				UtcDateTime::now(),
				$event_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic duplicate counter for a unique webhook event.
		if ( false === $result ) {
			throw DatabaseException::from_last_error( 'mark duplicate webhook event' );
		}
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

	public function count_errors(): int {
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE result IN ('error', 'signature_error')", $this->table() ) );
		$this->assert_read( 'count webhook errors' );
		return $count;
	}

	/** @return list<object> */
	public function recent( int $limit = 5 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Optional fixed conditions add a dynamic number of prepared values.
			$wpdb->prepare(
				'SELECT id, stripe_event_id, event_type, result, error_message, duplicate_count, created_at FROM %i ORDER BY id DESC LIMIT %d',
				$this->table(),
				max( 1, min( 20, $limit ) )
			)
		);
		$this->assert_read( 'recent webhook logs' );
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_object' ) ) : array();
	}

	public function search_admin( string $query, string $result, int $page = 1, int $per_page = 20 ): RepositoryPage {
		global $wpdb;
		$allowed    = array( 'processing', 'success', 'error', 'signature_error', 'unsupported' );
		$result     = in_array( $result, $allowed, true ) ? $result : '';
		$query      = trim( $query );
		$conditions = array();
		if ( '' !== $query ) {
			$prefix       = $wpdb->esc_like( $query ) . '%';
			$conditions[] = new SqlFragment( '(stripe_event_id LIKE %s OR event_type LIKE %s)', array( $prefix, $prefix ) );
		}
		if ( '' !== $result ) {
			$conditions[] = new SqlFragment( 'result = %s', array( $result ) );
		}
		$table = new SqlFragment( '%i', array( $this->table() ) );
		return $this->paginate( new PaginatedQuery( 'id, stripe_event_id, event_type, result, error_message, duplicate_count, last_received_at, created_at', $table, $table, $conditions, new SqlFragment( 'id DESC' ) ), $page, $per_page, 'webhook logs' );
	}
}
