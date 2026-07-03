<?php
/**
 * Atomic database-backed rate-limit counters.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Security;

use OD_Product_Hub\Database\AbstractRepository;
use OD_Product_Hub\Database\DatabaseException;
use OD_Product_Hub\Database\UtcDateTime;

final class RateLimitRepository extends AbstractRepository {
	protected function table_suffix(): string {
		return 'rate_limits';
	}

	protected function writable_columns(): array {
		return array( 'bucket_hash', 'request_count', 'expires_at', 'created_at', 'updated_at' );
	}

	public function increment( string $bucket_hash, string $expires_at ): int {
		global $wpdb;
		$now    = UtcDateTime::now();
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (bucket_hash, request_count, expires_at, created_at, updated_at) VALUES (%s, 1, %s, %s, %s) ON DUPLICATE KEY UPDATE request_count = LAST_INSERT_ID(request_count + 1), expires_at = VALUES(expires_at), updated_at = VALUES(updated_at)',
				$this->table(),
				$bucket_hash,
				$expires_at,
				$now,
				$now
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic upsert is required across PHP workers.
		if ( false === $result ) {
			throw DatabaseException::from_last_error( 'increment rate limit' );
		}
		if ( 1 === $result ) {
			return 1;
		}
		$count = $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Connection-local value from the immediately preceding atomic upsert.
		if ( ! is_numeric( $count ) || 1 > (int) $count ) {
			throw DatabaseException::from_last_error( 'read rate limit increment' );
		}
		return (int) $count;
	}

	public function count_for_hash( string $bucket_hash ): int {
		global $wpdb;
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT request_count FROM %i WHERE bucket_hash = %s', $this->table(), $bucket_hash ) );
		$this->assert_read( 'read rate limit count' );
		return null === $count ? 0 : (int) $count;
	}

	public function delete_expired( string $now, int $limit = 1000 ): int {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %s ORDER BY id ASC LIMIT %d', $this->table(), $now, max( 1, min( 10000, $limit ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Bounded expiry cleanup avoids unbounded counter growth.
		if ( false === $result ) {
			throw DatabaseException::from_last_error( 'cleanup rate limits' );
		}
		return $result;
	}
}
