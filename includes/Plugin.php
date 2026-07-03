<?php
/**
 * Plugin bootstrap.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub;

use OD_Product_Hub\Admin\AdminMenu;
use OD_Product_Hub\Admin\AdminActionHandler;
use OD_Product_Hub\Admin\AdminSettings;
use OD_Product_Hub\Admin\AdminSiteHealth;
use OD_Product_Hub\Admin\CustomerPage;
use OD_Product_Hub\Admin\DashboardPage;
use OD_Product_Hub\Admin\DashboardService;
use OD_Product_Hub\Admin\LogsPage;
use OD_Product_Hub\Admin\LicensePage;
use OD_Product_Hub\Admin\ProductPage;
use OD_Product_Hub\API\RestController;
use OD_Product_Hub\Customer\CustomerRepository;
use OD_Product_Hub\CLI\ReleaseCommand;
use OD_Product_Hub\Database\Installer;
use OD_Product_Hub\Frontend\Shortcodes;
use OD_Product_Hub\Log\LogCleanupService;
use OD_Product_Hub\License\LicenseManager;
use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Log\AdminLogRepository;
use OD_Product_Hub\Log\ApiLogRepository;
use OD_Product_Hub\Log\WebhookLogRepository;
use OD_Product_Hub\Product\ProductRepository;
use OD_Product_Hub\Privacy\PrivacyService;
use OD_Product_Hub\Subscription\SubscriptionRepository;
use OD_Product_Hub\Stripe\StripeClientFactory;
use OD_Product_Hub\Webhook\PayloadRedactor;
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
		$this->admin_menu()->register();
		( new RestController() )->register();
		( new WebhookController() )->register();
		( new WebhookNotificationSubscriber() )->register();
		( new Shortcodes() )->register();
		( new PrivacyService() )->register();
		add_action( 'odph_cleanup_logs', array( new LogCleanupService(), 'run_scheduled' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			ReleaseCommand::register();
		}
	}

	private function admin_menu(): AdminMenu {
		$products       = new ProductRepository();
		$licenses       = new LicenseRepository();
		$customers      = new CustomerRepository();
		$subscriptions  = new SubscriptionRepository();
		$api_logs       = new ApiLogRepository();
		$admin_logs     = new AdminLogRepository();
		$webhook_logs   = new WebhookLogRepository();
		$cleanup        = new LogCleanupService();
		$dashboard      = new DashboardService( $subscriptions, $webhook_logs, $licenses, $api_logs );
		$logs_page      = new LogsPage( $webhook_logs, $api_logs, $admin_logs, new PayloadRedactor() );
		$dashboard_page = new DashboardPage( $dashboard );
		$product_page   = new ProductPage( $products );
		$license_page   = new LicensePage( $licenses, $api_logs );
		$customer_page  = new CustomerPage( $customers, $subscriptions, $licenses, $api_logs );
		$actions        = new AdminActionHandler(
			$products,
			$admin_logs,
			new LicenseManager(),
			$cleanup,
			$webhook_logs,
			static function (): bool {
				try {
					StripeClientFactory::create()->balance->retrieve();
					return true;
				} catch ( \Throwable $error ) {
					unset( $error );
					return false;
				}
			}
		);
		return new AdminMenu( $dashboard_page, $product_page, $license_page, $customer_page, $logs_page, $actions, new AdminSettings(), new AdminSiteHealth() );
	}
}
