<?php
/**
 * Resolve the release signing key without exposing it as a CLI argument.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Release;

final class ReleaseKeyProvider {
	public function resolve(): string {
		$key = defined( 'ODPH_RELEASE_PRIVATE_KEY' ) ? (string) ODPH_RELEASE_PRIVATE_KEY : '';
		$env = getenv( 'ODPH_RELEASE_PRIVATE_KEY' );
		if ( is_string( $env ) && '' !== trim( $env ) ) {
			$key = $env;
		}
		/**
		 * Filters the Ed25519 private key used only in memory during publication.
		 *
		 * @param string $key Base64-encoded private key, or an empty string.
		 */
		$key = (string) apply_filters( 'odph_release_private_key', $key );
		if ( '' === trim( $key ) ) {
			throw new \RuntimeException( 'Release signing key is not configured.' );
		}
		return trim( $key );
	}
}
