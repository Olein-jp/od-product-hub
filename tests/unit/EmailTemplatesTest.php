<?php
/**
 * Email template unit tests.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Tests\Unit;

use OD_Product_Hub\Email\Templates;
use PHPUnit\Framework\TestCase;

final class EmailTemplatesTest extends TestCase {
	public function test_custom_template_replaces_only_allowed_plain_text_values(): void {
		$templates = new Templates(
			array(
				'email_templates' => array(
					'purchase_completed' => array(
						'subject' => '{site_name} purchase',
						'body'    => 'Key: {license_key} URL: {account_url}',
					),
				),
			)
		);
		$rendered  = $templates->render(
			'purchase_completed',
			array(
				'site_name'   => "Example\nInjected",
				'license_key' => '<b>ODPH-TEST</b>',
				'account_url' => 'https://example.test/account',
			)
		);
		self::assertSame( 'Example Injected purchase', $rendered['subject'] );
		self::assertStringContainsString( 'ODPH-TEST', $rendered['body'] );
		self::assertStringNotContainsString( '<b>', $rendered['body'] );
	}

	public function test_invalid_or_unknown_placeholders_fall_back_to_safe_defaults(): void {
		$templates = new Templates(
			array(
				'email_templates' => array(
					'payment_failed' => array(
						'subject' => '{unknown_header}',
						'body'    => '',
					),
				),
			)
		);
		$rendered  = $templates->render(
			'payment_failed',
			array(
				'site_name'   => 'Example',
				'account_url' => 'https://example.test/account',
			)
		);
		self::assertStringContainsString( 'Example', $rendered['subject'] );
		self::assertStringContainsString( 'https://example.test/account', $rendered['body'] );
		self::assertStringNotContainsString( 'unknown_header', $rendered['subject'] );
	}
}
