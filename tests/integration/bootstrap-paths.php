<?php
/**
 * Request-context bootstrap registration checks.
 *
 * @package OD_Product_Hub
 */

use OD_Product_Hub\Admin\AdminMenu;
use OD_Product_Hub\Plugin;
use OD_Product_Hub\Privacy\PrivacyService;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration check must run via WP-CLI.' );
}

function odph_bootstrap_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message );
	}
}

/** Return whether a WordPress hook contains a callback owned by the class. */
function odph_bootstrap_has_class_callback( string $hook, string $class ): bool {
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

odph_bootstrap_assert( ! is_admin(), 'The WP-CLI integration process must not be classified as an admin request.' );
odph_bootstrap_assert( ! odph_bootstrap_has_class_callback( 'admin_menu', AdminMenu::class ), 'WP-CLI must not build or register the admin menu graph.' );
odph_bootstrap_assert( ! odph_bootstrap_has_class_callback( 'wp_privacy_personal_data_exporters', PrivacyService::class ), 'Privacy Tools hooks must remain admin-only.' );
odph_bootstrap_assert( odph_bootstrap_has_class_callback( 'rest_api_init', Plugin::class ), 'The lazy REST registration boundary must be available.' );
odph_bootstrap_assert( shortcode_exists( 'odph_checkout' ) && shortcode_exists( 'odph_my_account' ), 'Frontend shortcodes must remain registered.' );
odph_bootstrap_assert( false !== has_action( 'admin_post_odph_checkout' ), 'Authenticated checkout admin-post must remain registered.' );
odph_bootstrap_assert( false !== has_action( 'admin_post_nopriv_odph_checkout' ), 'Public checkout admin-post must remain registered.' );
odph_bootstrap_assert( false !== has_action( 'odph_cleanup_logs' ), 'Cron cleanup callback must remain registered.' );

WP_CLI::success( 'Request-context bootstrap registration checks passed.' );
