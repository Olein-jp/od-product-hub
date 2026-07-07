<?php
/**
 * Shared administration UI integration checks.
 *
 * @package OD_Product_Hub
 */

use OD_Product_Hub\Admin\AdminMenu;
use OD_Product_Hub\Admin\AdminListUi;
use OD_Product_Hub\Admin\AdminSiteHealth;
use OD_Product_Hub\Admin\AdminSettings;
use OD_Product_Hub\Admin\DashboardPage;
use OD_Product_Hub\Admin\DashboardService;
use OD_Product_Hub\Admin\ProductPage;
use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Log\ApiLogRepository;
use OD_Product_Hub\Log\WebhookLogRepository;
use OD_Product_Hub\Product\ProductRepository;
use OD_Product_Hub\Subscription\SubscriptionRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration check must run via WP-CLI.' );
}

function odph_admin_ui_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message );
	}
}

$administrator = get_user_by( 'login', 'admin' );
odph_admin_ui_assert( false !== $administrator, 'The wp-env administrator fixture must exist.' );
wp_set_current_user( (int) $administrator->ID );

set_current_screen( 'toplevel_page_od-product-hub' );
( new AdminMenu( array() ) )->assets();
odph_admin_ui_assert( wp_style_is( 'odph-admin', 'enqueued' ), 'The shared admin stylesheet must be enqueued on OD Product Hub screens.' );
$site_health = new AdminSiteHealth();
ob_start();
$site_health->configuration_notice();
odph_admin_ui_assert( '' === (string) ob_get_clean(), 'The legacy configuration notice must not duplicate dashboard attention items.' );

$dashboard = new DashboardPage(
	new DashboardService(
		new SubscriptionRepository(),
		new WebhookLogRepository(),
		new LicenseRepository(),
		new ApiLogRepository()
	),
	$site_health
);
ob_start();
$dashboard->render();
$dashboard_html = (string) ob_get_clean();
odph_admin_ui_assert( 1 === substr_count( $dashboard_html, '<h1>' ), 'The dashboard must render exactly one primary heading.' );
odph_admin_ui_assert( 5 === substr_count( $dashboard_html, 'odph-card-link' ), 'The dashboard must render all operational metrics with the shared card primitive.' );
odph_admin_ui_assert( str_contains( $dashboard_html, 'odph-attention-list' ) || str_contains( $dashboard_html, 'notice-success' ), 'The dashboard must prioritize actionable Site Health summaries.' );
odph_admin_ui_assert( str_contains( $dashboard_html, 'odph-action-group' ), 'The dashboard must expose a labeled quick-action group.' );
odph_admin_ui_assert( false !== strpos( $dashboard_html, 'odph-dashboard-attention' ) && strpos( $dashboard_html, 'odph-dashboard-attention' ) < strpos( $dashboard_html, 'odph-cards' ), 'Items that need attention must appear before metrics in the DOM.' );
odph_admin_ui_assert( str_contains( $dashboard_html, 'odph-empty-state' ) || str_contains( $dashboard_html, '<tbody><tr>' ), 'The dashboard must render recent activity or its accessible empty state.' );

$_GET = array();
ob_start();
( new ProductPage( new ProductRepository() ) )->render();
$product_html = (string) ob_get_clean();
odph_admin_ui_assert( 1 === substr_count( $product_html, '<h1>' ), 'The products screen must render exactly one primary heading.' );
odph_admin_ui_assert( 3 === substr_count( $product_html, 'class="odph-section"' ), 'The products screen must use shared sections for search, editing, and the list.' );
odph_admin_ui_assert( str_contains( $product_html, 'odph-status-badge' ) || str_contains( $product_html, 'odph-empty-state' ), 'The products screen must render a shared status badge or empty state.' );
odph_admin_ui_assert( str_contains( $product_html, '<caption class="screen-reader-text">' ), 'The products list must include an accessible table caption.' );
odph_admin_ui_assert( str_contains( $product_html, 'wp-list-table widefat fixed striped table-view-list' ), 'The products list must follow WordPress list-table markup.' );
odph_admin_ui_assert( str_contains( $product_html, 'class="column-primary"' ), 'The products list must identify its primary responsive column.' );
odph_admin_ui_assert( str_contains( $product_html, 'method="post"' ) && str_contains( $product_html, 'odph_product_status' ), 'Product status changes must use a nonce-protected POST form.' );

$empty_html = AdminListUi::empty_row( 3, true, 'items' );
odph_admin_ui_assert( str_contains( $empty_html, 'No matching items' ), 'Filtered zero-result states must be distinct from an empty collection.' );
odph_admin_ui_assert( 100 === ( new AdminMenu( array() ) )->save_screen_option( false, 'odph_products_per_page', 999 ), 'List screen page-size preferences must be allow-listed and bounded.' );
odph_admin_ui_assert( false === ( new AdminMenu( array() ) )->save_screen_option( false, 'unrelated_option', 50 ), 'Unrelated screen options must be left untouched.' );

$_GET = array( 'tab' => 'payment' );
ob_start();
( new AdminSettings() )->render();
$settings_html = (string) ob_get_clean();
odph_admin_ui_assert( 6 === substr_count( $settings_html, 'class="nav-tab ' ), 'The settings screen must expose all task-oriented sections.' );
odph_admin_ui_assert( str_contains( $settings_html, 'odph_settings[_section]' ), 'Settings submissions must identify their section to preserve unrelated values.' );
odph_admin_ui_assert( str_contains( $settings_html, 'type="password"' ) && ! str_contains( $settings_html, 'sk_existing' ), 'Stored secrets must never be rendered into the settings HTML.' );
odph_admin_ui_assert( str_contains( $settings_html, 'aria-live="polite"' ), 'The webhook copy action must announce its result.' );
odph_admin_ui_assert( str_contains( $settings_html, 'odph_test_stripe' ), 'The Stripe connection test must remain separate from the settings save form.' );

WP_CLI::success( 'Shared administration UI integration checks passed.' );
