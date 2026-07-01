<?php
/**
 * Idempotent retention cleanup for operational logs.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Log;

use OD_Product_Hub\Database\DatabaseException;

final class LogCleanupService {
	private const SECONDS_PER_DAY = 86400;
	private const BATCH_SIZE      = 1000;
	private const MAX_BATCHES     = 100;
	private const TABLES          = array( 'webhook_logs', 'api_logs', 'admin_logs', 'email_logs', 'downloads' );

	/** @return array<string, int> */
	public function run( ?int $retention_days = null ): array {
		$settings       = (array) get_option( 'odph_settings', array() );
		$retention_days = max( 1, min( 3650, $retention_days ?? (int) ( $settings['log_retention_days'] ?? 365 ) ) );
		$cutoff         = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * self::SECONDS_PER_DAY ) );
		$deleted        = array();
		foreach ( self::TABLES as $suffix ) {
			$deleted[ $suffix ] = $this->delete_batches( $suffix, $cutoff );
		}
		return $deleted;
	}

	public function run_scheduled(): void {
		$this->run();
	}

	private function delete_batches( string $suffix, string $cutoff ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'odph_' . $suffix;
		$total = 0;
		for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
			$result = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s ORDER BY id ASC LIMIT %d', $table, $cutoff, self::BATCH_SIZE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fixed-size retention batches avoid loading log rows.
			if ( false === $result ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The suffix comes from the fixed TABLES list and is only used in the internal exception label.
				throw DatabaseException::from_last_error( 'cleanup ' . $suffix );
			}
			$total += $result;
			if ( $result < self::BATCH_SIZE ) {
				break;
			}
		}
		return $total;
	}
}
