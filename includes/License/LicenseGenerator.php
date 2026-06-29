<?php
/**
 * License key generation.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\License;

final class LicenseGenerator {
	private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	public function generate(): string {
		$blocks = array();
		for ( $block = 0; $block < 4; $block++ ) {
			$value = '';
			for ( $char = 0; $char < 4; $char++ ) {
				$value .= self::ALPHABET[ random_int( 0, strlen( self::ALPHABET ) - 1 ) ];
			}
			$blocks[] = $value;
		}
		return 'ODPH-' . implode( '-', $blocks );
	}

	public static function is_valid( string $key ): bool {
		return 1 === preg_match( '/^ODPH-[A-HJ-NP-Z2-9]{4}(?:-[A-HJ-NP-Z2-9]{4}){3}$/', $key );
	}

	public static function hash( string $key ): string {
		return hash( 'sha256', strtoupper( trim( $key ) ) );
	}

	public static function mask( string $key ): string {
		$parts = explode( '-', $key );
		return 5 === count( $parts ) ? $parts[0] . '-' . $parts[1] . '-****-****-' . $parts[4] : 'ODPH-****-****-****-****';
	}
}
