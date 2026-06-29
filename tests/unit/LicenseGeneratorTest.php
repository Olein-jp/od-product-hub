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
		self::assertDoesNotMatchRegularExpression( '/[O0I1]/', substr( $key, 5 ) );
	}

	public function test_hash_is_case_insensitive(): void {
		self::assertSame( LicenseGenerator::hash( 'ODPH-ABCD-EFGH-JKLM-NPQR' ), LicenseGenerator::hash( 'odph-abcd-efgh-jklm-npqr' ) );
	}

	public function test_mask_hides_middle_blocks(): void {
		self::assertSame( 'ODPH-ABCD-****-****-NPQR', LicenseGenerator::mask( 'ODPH-ABCD-EFGH-JKLM-NPQR' ) );
	}
}
