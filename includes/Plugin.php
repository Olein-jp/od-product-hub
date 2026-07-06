<?php
/**
 * Plugin bootstrap.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub;

use OD_Product_Hub\Admin\AdminActionHandler;
use OD_Product_Hub\Admin\AdminMenu;
use OD_Product_Hub\Admin\AdminSettings;
use OD_Product_Hub\Admin\AdminSiteHealth;
use OD_Product_Hub\Admin\CustomerPage;
use OD_Product_Hub\Admin\DashboardPage;
use OD_Product_Hub\Admin\DashboardService;
use OD_Product_Hub\Admin\LicensePage;
use OD_Product_Hub\Admin\LogsPage;
use OD_Product_Hub\Admin\ProductPage;
use OD_Product_Hub\Admin\ProductLicenseController;
use OD_Product_Hub\API\RestController;
use OD_Product_Hub\CLI\ReleaseCommand;
use OD_Product_Hub\Customer\CustomerRepository;
use OD_Product_Hub\Database\Installer;
use OD_Product_Hub\Frontend\Shortcodes;
use OD_Product_Hub\License\LicenseManager;
use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Log\AdminLogRepository;
use OD_Product_Hub\Log\ApiLogRepository;
use OD_Product_Hub\Log\LogCleanupService;
use OD_Product_Hub\Log\WebhookLogRepository;
use OD_Product_Hub\Privacy\PrivacyService;
use OD_Product_Hub\Product\ProductRepository;
use OD_Product_Hub\Stripe\StripeClientFactory;
use OD_Product_Hub\Subscription\SubscriptionRepository;
use OD_Product_Hub\Webhook\PayloadRedactor;
use OD_Product_Hub\Webhook\WebhookController;
use OD_Product_Hub\Webhook\WebhookNotificationSubscriber;
use OD_Product_Hub\VendorLicense\ProductLicenseService;

final class Plugin {
	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		$this->register_common();
		$this->register_frontend();
		$this->register_rest();
		$this->register_admin();
		$this->register_cli();
	}

	private function register_common(): void {
		Installer::maybe_upgrade();
		load_plugin_textdomain( 'od-product-hub', false, dirname( plugin_basename( OD_PRODUCT_HUB_FILE ) ) . '/languages' );
		( new WebhookNotificationSubscriber() )->register();
		add_action( 'odph_cleanup_logs', array( new LogCleanupService(), 'run_scheduled' ) );
		$product_license = new ProductLicenseService();
		add_action( 'odph_verify_vendor_license', array( $product_license, 'run_scheduled' ) );
		$product_license->register_updater();
	}

	private function register_frontend(): void {
		( new Shortcodes() )->register();
	}

	private function register_rest(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ), 0 );
	}

	public function register_rest_routes(): void {
		( new RestController() )->routes();
		( new WebhookController() )->route();
	}

	private function register_admin(): void {
		if ( ! is_admin() ) {
			return;
		}
		$this->admin_menu()->register();
		( new PrivacyService() )->register();
	}

	private function register_cli(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			ReleaseCommand::register();
		}
	}

	private function admin_menu(): AdminMenu {
		return new AdminMenu(
			array(
				'dashboard'      => static function (): DashboardPage {
					$subscriptions = new SubscriptionRepository();
					$webhook_logs  = new WebhookLogRepository();
					$licenses      = new LicenseRepository();
					$api_logs      = new ApiLogRepository();
					return new DashboardPage( new DashboardService( $subscriptions, $webhook_logs, $licenses, $api_logs ), new AdminSiteHealth() );
				},
				'products'       => static fn (): ProductPage => new ProductPage( new ProductRepository() ),
				'licenses'       => static fn (): LicensePage => new LicensePage( new LicenseRepository(), new ApiLogRepository() ),
				'customers'      => static fn (): CustomerPage => new CustomerPage( new CustomerRepository(), new SubscriptionRepository(), new LicenseRepository(), new ApiLogRepository() ),
				'logs'           => static fn (): LogsPage => new LogsPage( new WebhookLogRepository(), new ApiLogRepository(), new AdminLogRepository(), new PayloadRedactor() ),
				'actions'        => static fn (): AdminActionHandler => new AdminActionHandler(
					new ProductRepository(),
					new AdminLogRepository(),
					new LicenseManager(),
					new LogCleanupService(),
					new WebhookLogRepository(),
					static function (): bool {
						try {
							StripeClientFactory::create()->balance->retrieve();
							return true;
						} catch ( \Throwable $error ) {
							unset( $error );
							return false;
						}
					}
				),
				'settings'       => static fn (): AdminSettings => new AdminSettings( new ProductLicenseService() ),
				'vendor_license' => static fn (): ProductLicenseController => new ProductLicenseController( new ProductLicenseService() ),
				'site_health'    => static fn (): AdminSiteHealth => new AdminSiteHealth(),
			)
		);
	}
}
