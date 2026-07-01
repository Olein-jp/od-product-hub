<?php
/**
 * Release package signature tests.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Tests;

use OD_Product_Hub\Release\PackageSigner;
use PHPUnit\Framework\TestCase;

final class PackageSignerTest extends TestCase {
	public function test_signed_package_verifies_and_tampering_is_rejected(): void {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			self::markTestSkipped( 'Sodium is unavailable.' );
		}
		$file = tempnam( sys_get_temp_dir(), 'odph-package-' );
		self::assertIsString( $file );
		file_put_contents( $file, 'original package' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- PHPUnit fixture.
		$keypair = sodium_crypto_sign_keypair();
		$secret  = base64_encode( sodium_crypto_sign_secretkey( $keypair ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixture for binary key transport.
		$signer  = new PackageSigner();
		$signed  = $signer->sign( $file, $secret );

		self::assertTrue( $signer->verify( $file, $signed['sha256'], $signed['signature'], $signed['public_key'] ) );
		file_put_contents( $file, 'tampered package' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- PHPUnit fixture.
		self::assertFalse( $signer->verify( $file, $signed['sha256'], $signed['signature'], $signed['public_key'] ) );
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- PHPUnit fixture cleanup.
	}
}
