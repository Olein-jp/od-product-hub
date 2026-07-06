<?php
/**
 * License key generation.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\License;

final class LicenseGenerator {
	private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	public function generate( string $prefix = '' ): string {
		$prefix = self::normalize_prefix( $prefix );
		if ( ! self::is_valid_prefix( $prefix ) ) {
			throw new \InvalidArgumentException( 'Invalid license key prefix.' );
		}
		$blocks = array();
		for ( $block = 0; $block < 4; $block++ ) {
			$value = '';
			for ( $char = 0; $char < 4; $char++ ) {
				$value .= self::ALPHABET[ random_int( 0, strlen( self::ALPHABET ) - 1 ) ];
			}
			$blocks[] = $value;
		}
		$key = implode( '-', $blocks );
		return '' === $prefix ? $key : $prefix . '-' . $key;
	}

	public static function is_valid( string $key ): bool {
		$key = strtoupper( trim( $key ) );
		return 1 === preg_match( '/^(?:[A-Z0-9]{3,12}-)?[A-HJ-NP-Z2-9]{4}(?:-[A-HJ-NP-Z2-9]{4}){3}$/', $key );
	}

	public static function normalize_prefix( string $prefix ): string {
		return strtoupper( trim( $prefix ) );
	}

	public static function is_valid_prefix( string $prefix ): bool {
		return '' === $prefix || 1 === preg_match( '/^[A-Z0-9]{3,12}$/', $prefix );
	}

	public static function hash( string $key ): string {
		return hash( 'sha256', strtoupper( trim( $key ) ) );
	}

	public static function mask( string $key ): string {
		$parts = explode( '-', strtoupper( trim( $key ) ) );
		if ( 4 === count( $parts ) ) {
			return $parts[0] . '-****-****-' . $parts[3];
		}
		if ( 5 === count( $parts ) ) {
			return $parts[0] . '-' . $parts[1] . '-****-****-' . $parts[4];
		}
		return '****-****-****-****';
	}
}
