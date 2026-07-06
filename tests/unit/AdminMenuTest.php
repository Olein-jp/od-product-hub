<?php
/**
 * Admin menu lazy-composition tests.
 *
 * @package OD_Product_Hub
 */

use OD_Product_Hub\Admin\AdminMenu;
use PHPUnit\Framework\TestCase;

final class AdminMenuTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['odph_test_actions']       = array();
		$GLOBALS['odph_test_filters']       = array();
		$GLOBALS['odph_test_menu_pages']    = array();
		$GLOBALS['odph_test_submenu_pages'] = array();
	}

	public function test_registers_admin_boundaries_without_building_services(): void {
		$created   = 0;
		$factories = array();
		foreach ( array( 'dashboard', 'products', 'licenses', 'customers', 'logs', 'actions', 'settings', 'site_health' ) as $name ) {
			$factories[ $name ] = static function () use ( &$created ): object {
				++$created;
				return new stdClass();
			};
		}

		$menu = new AdminMenu( $factories );
		$menu->register();
		$menu->menu();

		self::assertSame( 0, $created, 'Hook and menu registration must not instantiate page or repository graphs.' );
		self::assertArrayHasKey( 'admin_post_odph_save_product', $GLOBALS['odph_test_actions'] );
		self::assertArrayHasKey( 'site_status_tests', $GLOBALS['odph_test_filters'] );
		self::assertCount( 1, $GLOBALS['odph_test_menu_pages'] );
		self::assertCount( 6, $GLOBALS['odph_test_submenu_pages'] );
		self::assertSame( array( $menu, 'render_dashboard' ), $GLOBALS['odph_test_menu_pages'][0][4] );
		self::assertSame( 'od-product-hub', $GLOBALS['odph_test_submenu_pages'][0][4] );
		self::assertSame( 'odph-products', $GLOBALS['odph_test_submenu_pages'][1][4] );
		self::assertSame( 'odph-customers', $GLOBALS['odph_test_submenu_pages'][2][4] );
		self::assertSame( 'odph-licenses', $GLOBALS['odph_test_submenu_pages'][3][4] );
		self::assertArrayHasKey( 'load-toplevel_page_od-product-hub', $GLOBALS['odph_test_actions'] );
	}
}
