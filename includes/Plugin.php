<?php
/**
 * Plugin bootstrap.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub;

use OD_Product_Hub\Admin\AdminMenu;
use OD_Product_Hub\API\RestController;
use OD_Product_Hub\Database\Installer;
use OD_Product_Hub\Frontend\Shortcodes;
use OD_Product_Hub\Webhook\WebhookController;

final class Plugin {
	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		Installer::maybe_upgrade();
		load_plugin_textdomain( 'od-product-hub', false, dirname( plugin_basename( OD_PRODUCT_HUB_FILE ) ) . '/languages' );
		( new AdminMenu() )->register();
		( new RestController() )->register();
		( new WebhookController() )->register();
		( new Shortcodes() )->register();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'odph_cleanup_logs' );
	}
}
