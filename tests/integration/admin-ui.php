<?php
/**
 * Shared administration UI integration checks.
 *
 * @package OD_Product_Hub
 */

use OD_Product_Hub\Admin\AdminMenu;
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

$dashboard = new DashboardPage(
	new DashboardService(
		new SubscriptionRepository(),
		new WebhookLogRepository(),
		new LicenseRepository(),
		new ApiLogRepository()
	)
);
ob_start();
$dashboard->render();
$dashboard_html = (string) ob_get_clean();
odph_admin_ui_assert( 1 === substr_count( $dashboard_html, '<h1>' ), 'The dashboard must render exactly one primary heading.' );
odph_admin_ui_assert( 5 === substr_count( $dashboard_html, 'odph-card-link' ), 'The dashboard must render all operational metrics with the shared card primitive.' );
odph_admin_ui_assert( str_contains( $dashboard_html, 'odph-empty-state' ) || str_contains( $dashboard_html, '<tbody><tr>' ), 'The dashboard must render recent activity or its accessible empty state.' );

$_GET = array();
ob_start();
( new ProductPage( new ProductRepository() ) )->render();
$product_html = (string) ob_get_clean();
odph_admin_ui_assert( 1 === substr_count( $product_html, '<h1>' ), 'The products screen must render exactly one primary heading.' );
odph_admin_ui_assert( 3 === substr_count( $product_html, 'class="odph-section"' ), 'The products screen must use shared sections for search, editing, and the list.' );
odph_admin_ui_assert( str_contains( $product_html, 'odph-status-badge' ) || str_contains( $product_html, 'odph-empty-state' ), 'The products screen must render a shared status badge or empty state.' );

WP_CLI::success( 'Shared administration UI integration checks passed.' );
