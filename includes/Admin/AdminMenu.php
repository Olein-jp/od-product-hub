<?php
/**
 * Administration hook and menu composition.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Admin;

final class AdminMenu {
	public function __construct(
		private readonly DashboardPage $dashboard,
		private readonly ProductPage $products,
		private readonly LicensePage $licenses,
		private readonly CustomerPage $customers,
		private readonly LogsPage $logs,
		private readonly AdminActionHandler $actions,
		private readonly AdminSettings $settings,
		private readonly AdminSiteHealth $site_health
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this->settings, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_odph_save_product', array( $this->actions, 'save_product' ) );
		add_action( 'admin_post_odph_product_status', array( $this->actions, 'change_product_status' ) );
		add_action( 'admin_post_odph_license_action', array( $this->actions, 'license_action' ) );
		add_action( 'admin_post_odph_test_stripe', array( $this->actions, 'test_stripe_connection' ) );
		add_action( 'admin_post_odph_cleanup_logs', array( $this->actions, 'cleanup_logs' ) );
		add_action( 'admin_notices', array( $this->site_health, 'configuration_notice' ) );
		add_filter( 'site_status_tests', array( $this->site_health, 'tests' ) );
	}

	public function assets(): void {
		$screen = get_current_screen();
		if ( $screen && ( str_contains( $screen->id, 'odph' ) || str_contains( $screen->id, 'od-product-hub' ) ) ) {
			wp_enqueue_style( 'odph-admin', OD_PRODUCT_HUB_URL . 'assets/css/admin.css', array(), OD_PRODUCT_HUB_VERSION );
		}
	}

	public function menu(): void {
		add_menu_page( 'OD Product Hub', 'OD Product Hub', 'manage_options', 'od-product-hub', array( $this->dashboard, 'render' ), 'dashicons-products', 56 );
		add_submenu_page( 'od-product-hub', __( 'Products', 'od-product-hub' ), __( 'Products', 'od-product-hub' ), 'manage_options', 'odph-products', array( $this->products, 'render' ) );
		add_submenu_page( 'od-product-hub', __( 'Licenses', 'od-product-hub' ), __( 'Licenses', 'od-product-hub' ), 'manage_options', 'odph-licenses', array( $this->licenses, 'render' ) );
		add_submenu_page( 'od-product-hub', __( 'Customers and subscriptions', 'od-product-hub' ), __( 'Customers and subscriptions', 'od-product-hub' ), 'manage_options', 'odph-customers', array( $this->customers, 'render' ) );
		add_submenu_page( 'od-product-hub', __( 'Logs', 'od-product-hub' ), __( 'Logs', 'od-product-hub' ), 'manage_options', 'odph-logs', array( $this->logs, 'render' ) );
		add_submenu_page( 'od-product-hub', __( 'Settings', 'od-product-hub' ), __( 'Settings', 'od-product-hub' ), 'manage_options', 'odph-settings', array( $this->settings, 'render' ) );
	}
}
