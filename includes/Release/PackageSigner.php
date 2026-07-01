<?php
/**
 * Ed25519 package signing and verification.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Release;

final class PackageSigner {
	/** @return array{sha256: string, signature: string, public_key: string} */
	public function sign( string $path, string $private_key ): array {
		$this->assert_sodium();
		$secret = base64_decode( $private_key, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Standard transport encoding for an Ed25519 key.
		if ( false === $secret || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $secret ) ) {
			throw new \InvalidArgumentException( 'Release signing private key is invalid.' );
		}
		$hash = hash_file( 'sha256', $path );
		if ( false === $hash ) {
			throw new \RuntimeException( 'Unable to hash release package.' );
		}
		$public = sodium_crypto_sign_publickey_from_secretkey( $secret );
		return array(
			'sha256'     => $hash,
			'signature'  => base64_encode( sodium_crypto_sign_detached( $hash, $secret ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard transport encoding for a binary signature.
			'public_key' => base64_encode( $public ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard transport encoding for an Ed25519 key.
		);
	}

	public function verify( string $path, string $sha256, string $signature, string $public_key ): bool {
		$this->assert_sodium();
		$actual    = hash_file( 'sha256', $path );
		$signature = base64_decode( $signature, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a detached signature for verification.
		$public    = base64_decode( $public_key, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a pinned Ed25519 key.
		return is_string( $actual ) && hash_equals( $sha256, $actual ) && is_string( $signature ) && is_string( $public )
			&& SODIUM_CRYPTO_SIGN_BYTES === strlen( $signature ) && SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $public )
			&& sodium_crypto_sign_verify_detached( $signature, $actual, $public );
	}

	private function assert_sodium(): void {
		if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
			throw new \RuntimeException( 'The Sodium extension is required for release signing.' );
		}
	}
}
