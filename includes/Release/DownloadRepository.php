<?php
/**
 * One-time download grant persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Release;

use OD_Product_Hub\Database\AbstractRepository;
use OD_Product_Hub\Database\DatabaseException;
use OD_Product_Hub\Database\UtcDateTime;

final class DownloadRepository extends AbstractRepository {
	protected function table_suffix(): string {
		return 'downloads';
	}

	protected function writable_columns(): array {
		return array( 'release_id', 'license_id', 'token_hash', 'site_url', 'expires_at', 'used_at', 'ip_address', 'user_agent', 'result', 'created_at' );
	}

	public function find_by_token_hash( string $token_hash ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s LIMIT 1', $this->table(), $token_hash ) );
		$this->assert_read( 'find download grant' );
		return is_object( $row ) ? $row : null;
	}

	public function claim( int $id, string $ip_address = '', string $user_agent = '' ): bool {
		global $wpdb;
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET used_at = %s, result = 'downloaded', ip_address = %s, user_agent = %s WHERE id = %d AND used_at IS NULL AND expires_at >= %s",
				$this->table(),
				UtcDateTime::now(),
				substr( $ip_address, 0, 100 ),
				substr( sanitize_text_field( $user_agent ), 0, 500 ),
				$id,
				UtcDateTime::now()
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic claim prevents token replay.
		if ( false === $result ) {
			throw DatabaseException::from_last_error( 'claim download grant' );
		}
		return 1 === $result;
	}

	public function mark_result( int $id, string $result ): void {
		$this->update( $id, array( 'result' => $result ) );
	}

	public function reject_issued_for_release( int $release_id ): int {
		global $wpdb;
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET result = 'rejected' WHERE release_id = %d AND used_at IS NULL AND result = 'issued'",
				$this->table(),
				$release_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Emergency withdrawal atomically revokes all unused grants for one release.
		if ( false === $result ) {
			throw DatabaseException::from_last_error( 'reject release download grants' );
		}
		return $result;
	}
}
