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
	public const MAX_ATTEMPTS         = 5;
	private const STALE_AFTER_SECONDS = 300;

	protected function table_suffix(): string {
		return 'webhook_logs';
	}

	protected function writable_columns(): array {
		return array( 'stripe_event_id', 'event_type', 'payload', 'result', 'error_message', 'attempt_count', 'duplicate_count', 'last_attempt_at', 'last_received_at', 'created_at' );
	}

	/** @return array{status: 'claimed'|'duplicate'|'processing'|'exhausted', id: int|null, attempt: int} */
	public function claim( string $event_id, string $event_type, string $payload ): array {
		global $wpdb;
		$now    = UtcDateTime::now();
		$sql    = $wpdb->prepare(
			'INSERT IGNORE INTO %i (stripe_event_id, event_type, payload, result, attempt_count, duplicate_count, last_attempt_at, last_received_at, created_at) VALUES (%s, %s, %s, %s, 1, 0, %s, %s, %s)',
			$this->table(),
			$event_id,
			$event_type,
			$payload,
			'processing',
			$now,
			$now,
			$now
		);
		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared atomic claim is required for webhook idempotency.
		if ( false === $result ) {
			throw DatabaseException::from_last_error( 'claim webhook event' );
		}
		if ( 1 === $result ) {
			return array(
				'status'  => 'claimed',
				'id'      => (int) $wpdb->insert_id,
				'attempt' => 1,
			);
		}
		$this->mark_duplicate( $event_id );
		$existing = $this->find_by_event_id( $event_id );
		if ( ! $existing ) {
			throw new DatabaseException( 'Unable to load the claimed webhook event.' );
		}
		if ( in_array( (string) $existing->result, array( 'success', 'unsupported' ), true ) ) {
			return array(
				'status'  => 'duplicate',
				'id'      => (int) $existing->id,
				'attempt' => (int) $existing->attempt_count,
			);
		}
		if ( 'exhausted' === (string) $existing->result ) {
			return array(
				'status'  => 'exhausted',
				'id'      => (int) $existing->id,
				'attempt' => (int) $existing->attempt_count,
			);
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STALE_AFTER_SECONDS );
		$stale  = 'processing' === (string) $existing->result && ( empty( $existing->last_attempt_at ) || (string) $existing->last_attempt_at < $cutoff );
		if ( 'processing' === (string) $existing->result && ! $stale ) {
			return array(
				'status'  => 'processing',
				'id'      => (int) $existing->id,
				'attempt' => (int) $existing->attempt_count,
			);
		}
		if ( (int) $existing->attempt_count >= self::MAX_ATTEMPTS ) {
			if ( 'exhausted' !== (string) $existing->result ) {
				$this->finish( (int) $existing->id, 'exhausted', (string) $existing->error_message );
			}
			return array(
				'status'  => 'exhausted',
				'id'      => (int) $existing->id,
				'attempt' => (int) $existing->attempt_count,
			);
		}
		$reclaimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET result = 'processing', error_message = NULL, attempt_count = attempt_count + 1, last_attempt_at = %s, last_received_at = %s WHERE id = %d AND attempt_count < %d AND (result = 'error' OR (result = 'processing' AND (last_attempt_at IS NULL OR last_attempt_at < %s)))",
				$this->table(),
				$now,
				$now,
				(int) $existing->id,
				self::MAX_ATTEMPTS,
				$cutoff
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Conditional update is the atomic retry claim.
		if ( false === $reclaimed ) {
			throw DatabaseException::from_last_error( 'reclaim webhook event' );
		}
		return array(
			'status'  => 1 === $reclaimed ? 'claimed' : 'processing',
			'id'      => (int) $existing->id,
			'attempt' => 1 === $reclaimed ? (int) $existing->attempt_count + 1 : (int) $existing->attempt_count,
		);
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

	public function complete_claim( int $id, int $attempt, string $result, ?string $error = null ): bool {
		global $wpdb;
		$sql     = null === $error
			? $wpdb->prepare(
				"UPDATE %i SET result = %s, error_message = NULL, last_received_at = %s WHERE id = %d AND result = 'processing' AND attempt_count = %d",
				$this->table(),
				$result,
				UtcDateTime::now(),
				$id,
				$attempt
			)
			: $wpdb->prepare(
				"UPDATE %i SET result = %s, error_message = %s, last_received_at = %s WHERE id = %d AND result = 'processing' AND attempt_count = %d",
				$this->table(),
				$result,
				$error,
				UtcDateTime::now(),
				$id,
				$attempt
			);
		$updated = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Both branches prepare the query; attempt generation prevents a stale worker from overwriting a newer claim.
		if ( false === $updated ) {
			throw DatabaseException::from_last_error( 'complete webhook claim' );
		}
		return 1 === $updated;
	}

	public function fail( int $id, string $error ): string {
		$event  = $this->find( $id );
		$result = $event && (int) $event->attempt_count >= self::MAX_ATTEMPTS ? 'exhausted' : 'error';
		$this->finish( $id, $result, $error );
		return $result;
	}

	public function fail_claim( int $id, int $attempt, string $error ): string {
		$result = $attempt >= self::MAX_ATTEMPTS ? 'exhausted' : 'error';
		return $this->complete_claim( $id, $attempt, $result, $error ) ? $result : 'superseded';
	}

	public function request_manual_retry( int $id ): bool {
		global $wpdb;
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET result = 'error', error_message = 'manual_retry_requested', attempt_count = 0, last_attempt_at = NULL WHERE id = %d AND result IN ('error', 'exhausted')",
				$this->table(),
				$id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Manual recovery must atomically reopen only failed terminal states.
		if ( false === $result ) {
			throw DatabaseException::from_last_error( 'request webhook retry' );
		}
		return 1 === $result;
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
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE result IN ('error', 'exhausted', 'signature_error')", $this->table() ) );
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
		$allowed    = array( 'processing', 'success', 'error', 'exhausted', 'signature_error', 'unsupported' );
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
		return $this->paginate( new PaginatedQuery( 'id, stripe_event_id, event_type, result, error_message, attempt_count, duplicate_count, last_attempt_at, last_received_at, created_at', $table, $table, $conditions, new SqlFragment( 'id DESC' ) ), $page, $per_page, 'webhook logs' );
	}
}
