<?php
/**
 * UTC storage and site-time display helpers.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Database;

final class UtcDateTime {
	public static function now(): string {
		return current_time( 'mysql', true );
	}

	/** @param int|string|null $timestamp */
	public static function from_timestamp( $timestamp ): ?string {
		return $timestamp ? gmdate( 'Y-m-d H:i:s', (int) $timestamp ) : null;
	}

	public static function to_site( ?string $utc, string $format = 'Y-m-d H:i:s' ): ?string {
		if ( null === $utc || '' === $utc ) {
			return null;
		}
		return get_date_from_gmt( $utc, $format );
	}
}
