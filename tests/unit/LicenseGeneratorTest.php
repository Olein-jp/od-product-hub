<?php
/**
 * License generator tests.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Tests\Unit;

use OD_Product_Hub\License\LicenseGenerator;
use PHPUnit\Framework\TestCase;

final class LicenseGeneratorTest extends TestCase {
	public function test_generated_key_is_valid_and_avoids_ambiguous_characters(): void {
		$key = ( new LicenseGenerator() )->generate();
		self::assertTrue( LicenseGenerator::is_valid( $key ) );
		self::assertDoesNotMatchRegularExpression( '/[O0I1]/', $key );
		self::assertMatchesRegularExpression( '/^[A-HJ-NP-Z2-9]{4}(?:-[A-HJ-NP-Z2-9]{4}){3}$/', $key );
	}

	public function test_generated_key_uses_normalized_optional_prefix(): void {
		$key = ( new LicenseGenerator() )->generate( ' myapp ' );
		self::assertMatchesRegularExpression( '/^MYAPP-[A-HJ-NP-Z2-9]{4}(?:-[A-HJ-NP-Z2-9]{4}){3}$/', $key );
		self::assertTrue( LicenseGenerator::is_valid( $key ) );
		self::assertTrue( LicenseGenerator::is_valid( 'ODPH-ABCD-EFGH-JKLM-NPQR' ) );
		self::assertTrue( LicenseGenerator::is_valid( 'ABCD-EFGH-JKLM-NPQR' ) );
		self::assertFalse( LicenseGenerator::is_valid( 'AB-ABCD-EFGH-JKLM-NPQR' ) );
		self::assertFalse( LicenseGenerator::is_valid( 'MY-APP-ABCD-EFGH-JKLM-NPQR' ) );
	}

	public function test_hash_is_case_insensitive(): void {
		self::assertSame( LicenseGenerator::hash( 'ODPH-ABCD-EFGH-JKLM-NPQR' ), LicenseGenerator::hash( 'odph-abcd-efgh-jklm-npqr' ) );
	}

	public function test_mask_hides_middle_blocks(): void {
		self::assertSame( 'ODPH-ABCD-****-****-NPQR', LicenseGenerator::mask( 'ODPH-ABCD-EFGH-JKLM-NPQR' ) );
		self::assertSame( 'ABCD-****-****-NPQR', LicenseGenerator::mask( 'ABCD-EFGH-JKLM-NPQR' ) );
		self::assertSame( 'MYAPP-ABCD-****-****-NPQR', LicenseGenerator::mask( 'MYAPP-ABCD-EFGH-JKLM-NPQR' ) );
	}

	public function test_prefix_validation_accepts_only_optional_three_to_twelve_alphanumerics(): void {
		self::assertSame( 'MYAPP2', LicenseGenerator::normalize_prefix( ' myapp2 ' ) );
		self::assertTrue( LicenseGenerator::is_valid_prefix( '' ) );
		self::assertTrue( LicenseGenerator::is_valid_prefix( 'APP' ) );
		self::assertTrue( LicenseGenerator::is_valid_prefix( 'MYAPP2026' ) );
		self::assertFalse( LicenseGenerator::is_valid_prefix( 'AB' ) );
		self::assertFalse( LicenseGenerator::is_valid_prefix( 'MY-APP' ) );
	}
}
