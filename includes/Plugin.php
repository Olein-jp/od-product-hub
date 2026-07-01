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
use OD_Product_Hub\Log\LogCleanupService;
use OD_Product_Hub\Webhook\WebhookController;
use OD_Product_Hub\Webhook\WebhookNotificationSubscriber;

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
		( new WebhookNotificationSubscriber() )->register();
		( new Shortcodes() )->register();
		add_action( 'odph_cleanup_logs', array( new LogCleanupService(), 'run_scheduled' ) );
	}
}
