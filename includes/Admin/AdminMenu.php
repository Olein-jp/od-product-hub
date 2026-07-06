<?php
/**
 * Administration hook and menu composition.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Admin;

final class AdminMenu {
	/** @var array<string, object> */
	private array $services = array();

	/**
	 * @param array<string, \Closure(): object> $factories
	 */
	public function __construct( private readonly array $factories ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_odph_save_product', array( $this, 'save_product' ) );
		add_action( 'admin_post_odph_product_status', array( $this, 'change_product_status' ) );
		add_action( 'admin_post_odph_license_action', array( $this, 'license_action' ) );
		add_action( 'admin_post_odph_test_stripe', array( $this, 'test_stripe_connection' ) );
		add_action( 'admin_post_odph_vendor_license', array( $this, 'vendor_license' ) );
		add_action( 'admin_post_odph_cleanup_logs', array( $this, 'cleanup_logs' ) );
		add_action( 'admin_post_odph_retry_webhook', array( $this, 'retry_webhook' ) );
		add_action( 'admin_notices', array( $this, 'configuration_notice' ) );
		add_filter( 'site_status_tests', array( $this, 'site_status_tests' ) );
		add_filter( 'debug_information', array( $this, 'debug_information' ) );
		add_filter( 'set-screen-option', array( $this, 'save_screen_option' ), 10, 3 );
	}

	public function assets(): void {
		$screen = get_current_screen();
		if ( $screen && ( str_contains( $screen->id, 'odph' ) || str_contains( $screen->id, 'od-product-hub' ) ) ) {
			wp_enqueue_style( 'odph-admin', OD_PRODUCT_HUB_URL . 'assets/css/admin.css', array(), OD_PRODUCT_HUB_VERSION );
		}
	}

	public function menu(): void {
		$dashboard_hook = add_menu_page( 'OD Product Hub', 'OD Product Hub', 'manage_options', 'od-product-hub', array( $this, 'render_dashboard' ), 'dashicons-products', 56 );
		add_submenu_page( 'od-product-hub', __( 'Dashboard', 'od-product-hub' ), __( 'Dashboard', 'od-product-hub' ), 'manage_options', 'od-product-hub', array( $this, 'render_dashboard' ) );
		$list_screens = array(
			add_submenu_page( 'od-product-hub', __( 'Products', 'od-product-hub' ), __( 'Products', 'od-product-hub' ), 'manage_options', 'odph-products', array( $this, 'render_products' ) ) => 'odph_products_per_page',
			add_submenu_page( 'od-product-hub', __( 'Customers and subscriptions', 'od-product-hub' ), __( 'Customers and subscriptions', 'od-product-hub' ), 'manage_options', 'odph-customers', array( $this, 'render_customers' ) ) => 'odph_customers_per_page',
			add_submenu_page( 'od-product-hub', __( 'Licenses', 'od-product-hub' ), __( 'Licenses', 'od-product-hub' ), 'manage_options', 'odph-licenses', array( $this, 'render_licenses' ) ) => 'odph_licenses_per_page',
			add_submenu_page( 'od-product-hub', __( 'Logs', 'od-product-hub' ), __( 'Logs', 'od-product-hub' ), 'manage_options', 'odph-logs', array( $this, 'render_logs' ) ) => 'odph_logs_per_page',
		);
		foreach ( $list_screens as $hook => $option ) {
			add_action( 'load-' . $hook, fn() => $this->list_screen_options( $option ) );
		}
		add_submenu_page( 'od-product-hub', __( 'Settings', 'od-product-hub' ), __( 'Settings', 'od-product-hub' ), 'manage_options', 'odph-settings', array( $this, 'render_settings' ) );
		add_action( 'load-' . $dashboard_hook, array( $this, 'dashboard_help' ) );
	}

	public function list_screen_options( string $option ): void {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Items per page', 'od-product-hub' ),
				'default' => 20,
				'option'  => $option,
			)
		);
	}

	public function save_screen_option( mixed $status, string $option, mixed $value ): mixed {
		$allowed = array( 'odph_products_per_page', 'odph_customers_per_page', 'odph_licenses_per_page', 'odph_logs_per_page' );
		return in_array( $option, $allowed, true ) ? max( 1, min( 100, absint( $value ) ) ) : $status;
	}

	public function dashboard_help(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$screen->add_help_tab(
			array(
				'id'      => 'odph-dashboard-overview',
				'title'   => __( 'Dashboard overview', 'od-product-hub' ),
				'content' => '<p>' . esc_html__( 'Start with items that need attention, then use the metrics and recent activity to investigate details.', 'od-product-hub' ) . '</p>',
			)
		);
		$screen->add_help_tab(
			array(
				'id'      => 'odph-dashboard-site-health',
				'title'   => __( 'Site Health', 'od-product-hub' ),
				'content' => '<p>' . esc_html__( 'The dashboard summarizes operational checks. Open Site Health for complete diagnostics and remediation guidance.', 'od-product-hub' ) . '</p>',
			)
		);
	}

	public function render_dashboard(): void {
		$this->service( 'dashboard', DashboardPage::class )->render();
	}

	public function render_products(): void {
		$this->service( 'products', ProductPage::class )->render();
	}

	public function render_licenses(): void {
		$this->service( 'licenses', LicensePage::class )->render();
	}

	public function render_customers(): void {
		$this->service( 'customers', CustomerPage::class )->render();
	}

	public function render_logs(): void {
		$this->service( 'logs', LogsPage::class )->render();
	}

	public function register_settings(): void {
		$this->service( 'settings', AdminSettings::class )->register();
	}

	public function render_settings(): void {
		$this->service( 'settings', AdminSettings::class )->render();
	}

	public function save_product(): void {
		$this->service( 'actions', AdminActionHandler::class )->save_product();
	}

	public function change_product_status(): void {
		$this->service( 'actions', AdminActionHandler::class )->change_product_status();
	}

	public function license_action(): void {
		$this->service( 'actions', AdminActionHandler::class )->license_action();
	}

	public function test_stripe_connection(): void {
		$this->service( 'actions', AdminActionHandler::class )->test_stripe_connection();
	}

	public function vendor_license(): void {
		$this->service( 'vendor_license', ProductLicenseController::class )->handle();
	}

	public function cleanup_logs(): void {
		$this->service( 'actions', AdminActionHandler::class )->cleanup_logs();
	}

	public function retry_webhook(): void {
		$this->service( 'actions', AdminActionHandler::class )->retry_webhook();
	}

	public function configuration_notice(): void {
		$this->service( 'site_health', AdminSiteHealth::class )->configuration_notice();
	}

	/** @param array<string, mixed> $tests @return array<string, mixed> */
	public function site_status_tests( array $tests ): array {
		return $this->service( 'site_health', AdminSiteHealth::class )->tests( $tests );
	}

	/** @param array<string, mixed> $information @return array<string, mixed> */
	public function debug_information( array $information ): array {
		return $this->service( 'site_health', AdminSiteHealth::class )->debug_information( $information );
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class Expected service class.
	 * @return T
	 */
	private function service( string $name, string $class ): object {
		if ( ! isset( $this->services[ $name ] ) ) {
			$service = ( $this->factories[ $name ] )();
			if ( ! $service instanceof $class ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal factory names and class names are diagnostic text, not HTML output.
				throw new \LogicException( sprintf( 'Admin factory "%s" must return %s.', $name, $class ) );
			}
			$this->services[ $name ] = $service;
		}
		return $this->services[ $name ];
	}
}
