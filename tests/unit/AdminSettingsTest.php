<?php
/**
 * Administration settings unit tests.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Tests\Unit;

use OD_Product_Hub\Admin\AdminSettings;
use OD_Product_Hub\Database\Installer;
use PHPUnit\Framework\TestCase;

final class AdminSettingsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['odph_test_options']['odph_settings'] = array_merge(
			Installer::defaults(),
			array(
				'stripe_secret_key'  => 'sk_existing',
				'log_retention_days' => 90,
				'api_rate_limit'     => 120,
			)
		);
		$GLOBALS['odph_test_settings_errors']          = array();
	}

	public function test_sanitize_preserves_secrets_and_rejects_out_of_range_numbers(): void {
		$result = ( new AdminSettings() )->sanitize(
			array(
				'stripe_secret_key'  => '',
				'success_url'        => 'https://example.test/success',
				'email_from_address' => 'admin@example.test',
				'log_retention_days' => 0,
				'api_rate_limit'     => 1001,
			)
		);

		self::assertSame( 'sk_existing', $result['stripe_secret_key'] );
		self::assertSame( 'https://example.test/success', $result['success_url'] );
		self::assertSame( 90, $result['log_retention_days'] );
		self::assertSame( 120, $result['api_rate_limit'] );
		self::assertCount( 2, $GLOBALS['odph_test_settings_errors'] );
	}

	public function test_sanitize_normalizes_flags_and_valid_numbers(): void {
		$result = ( new AdminSettings() )->sanitize(
			array(
				'portal_enabled'      => '1',
				'delete_on_uninstall' => '1',
				'email_from_address'  => '',
				'log_retention_days'  => '365',
				'api_rate_limit'      => '60',
			)
		);

		self::assertSame( 1, $result['portal_enabled'] );
		self::assertSame( 1, $result['delete_on_uninstall'] );
		self::assertSame( 365, $result['log_retention_days'] );
		self::assertSame( 60, $result['api_rate_limit'] );
	}

	public function test_section_save_preserves_settings_from_other_sections(): void {
		$result = ( new AdminSettings() )->sanitize(
			array(
				'_section'            => 'api',
				'api_rate_limit'      => '75',
				'api_trusted_proxies' => '',
			)
		);

		self::assertSame( 'sk_existing', $result['stripe_secret_key'] );
		self::assertSame( 90, $result['log_retention_days'] );
		self::assertSame( 75, $result['api_rate_limit'] );
	}
}
