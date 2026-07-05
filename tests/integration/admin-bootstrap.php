<?php
/**
 * Admin-context bootstrap registration checks.
 *
 * @package OD_Product_Hub
 */

use OD_Product_Hub\Admin\AdminMenu;
use OD_Product_Hub\Privacy\PrivacyService;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration check must run via WP-CLI.' );
}

function odph_admin_bootstrap_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message );
	}
}

/** Return whether a WordPress hook contains a callback owned by the class. */
function odph_admin_bootstrap_has_class_callback( string $hook, string $class ): bool {
	global $wp_filter;
	if ( ! isset( $wp_filter[ $hook ] ) ) {
		return false;
	}
	foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
		foreach ( $callbacks as $definition ) {
			$callback = $definition['function'] ?? null;
			if ( is_array( $callback ) && isset( $callback[0] ) && $callback[0] instanceof $class ) {
				return true;
			}
		}
	}
	return false;
}

odph_admin_bootstrap_assert( is_admin(), 'The explicit admin context must be classified as an admin request.' );
odph_admin_bootstrap_assert( odph_admin_bootstrap_has_class_callback( 'admin_menu', AdminMenu::class ), 'Admin menu hooks must be registered in the admin context.' );
odph_admin_bootstrap_assert( odph_admin_bootstrap_has_class_callback( 'admin_post_odph_save_product', AdminMenu::class ), 'Admin action proxies must remain registered.' );
odph_admin_bootstrap_assert( odph_admin_bootstrap_has_class_callback( 'site_status_tests', AdminMenu::class ), 'Site Health proxies must remain registered.' );
odph_admin_bootstrap_assert( odph_admin_bootstrap_has_class_callback( 'wp_privacy_personal_data_exporters', PrivacyService::class ), 'Privacy Tools hooks must remain registered in the admin context.' );

do_action( 'admin_menu' );
odph_admin_bootstrap_assert( isset( $GLOBALS['admin_page_hooks']['od-product-hub'] ), 'The existing top-level admin menu slug must remain registered.' );

WP_CLI::success( 'Admin-context bootstrap registration checks passed.' );
