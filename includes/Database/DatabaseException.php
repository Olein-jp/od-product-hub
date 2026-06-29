<?php
/**
 * Safe database exception.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Database;

final class DatabaseException extends \RuntimeException {
	public static function from_last_error( string $operation ): self {
		global $wpdb;
		$error = (string) $wpdb->last_error;
		error_log( sprintf( 'OD Product Hub database error during %s: %s', $operation, $error ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Database details are intentionally limited to the server log.
		return new self( 'データベース処理に失敗しました。時間をおいて再度お試しください。' );
	}
}
