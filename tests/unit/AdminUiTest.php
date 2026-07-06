<?php
/**
 * Shared administration UI unit tests.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Tests\Unit;

use OD_Product_Hub\Admin\AdminUi;
use PHPUnit\Framework\TestCase;

final class AdminUiTest extends TestCase {
	public function test_page_header_escapes_content_and_keeps_one_primary_heading(): void {
		$html = AdminUi::page_header( '<Products>', 'Manage <strong>products</strong>.' );

		self::assertSame( 1, substr_count( $html, '<h1>' ) );
		self::assertStringContainsString( '&lt;Products&gt;', $html );
		self::assertStringNotContainsString( '<strong>', $html );
	}

	public function test_status_badge_restricts_tone_and_does_not_rely_on_color_alone(): void {
		$html = AdminUi::status_badge( 'Active', 'unexpected' );

		self::assertStringContainsString( 'odph-status-badge--neutral', $html );
		self::assertStringContainsString( 'aria-hidden="true"', $html );
		self::assertStringContainsString( 'Active', $html );
	}

	public function test_notice_uses_alert_role_only_for_errors(): void {
		self::assertStringContainsString( 'role="alert"', AdminUi::notice( 'Failed', 'error' ) );
		self::assertStringContainsString( 'role="status"', AdminUi::notice( 'Saved', 'success' ) );
	}

	public function test_action_group_has_an_accessible_name_and_one_primary_action(): void {
		$html = AdminUi::action_group(
			array(
				array(
					'label'   => 'Add product',
					'url'     => 'https://example.test/add',
					'primary' => true,
				),
				array(
					'label' => 'Help',
					'url'   => 'https://example.test/help',
				),
			),
			'Product actions'
		);

		self::assertStringContainsString( 'role="group"', $html );
		self::assertStringContainsString( 'aria-label="Product actions"', $html );
		self::assertSame( 1, substr_count( $html, 'button-primary' ) );
	}
}
