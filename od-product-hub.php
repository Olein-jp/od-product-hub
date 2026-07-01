<?php
/**
 * Plugin Name:       OD Product Hub
 * Description:       Stripe と連携する WordPress プロダクトの契約検証・ライセンス管理基盤です。
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.3
 * Author:            Olein Design
 * Text Domain:       od-product-hub
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package OD_Product_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OD_PRODUCT_HUB_VERSION', '0.1.0' );
define( 'OD_PRODUCT_HUB_FILE', __FILE__ );
define( 'OD_PRODUCT_HUB_PATH', plugin_dir_path( __FILE__ ) );
define( 'OD_PRODUCT_HUB_URL', plugin_dir_url( __FILE__ ) );

$odph_autoload = OD_PRODUCT_HUB_PATH . 'vendor/autoload.php';
if ( file_exists( $odph_autoload ) ) {
	require_once $odph_autoload;
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'OD_Product_Hub\\';
			if ( 0 !== strpos( $class, $prefix ) ) {
				return;
			}
			$file = OD_PRODUCT_HUB_PATH . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);
}

register_activation_hook( __FILE__, array( 'OD_Product_Hub\\Database\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OD_Product_Hub\\Database\\Installer', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		OD_Product_Hub\Plugin::instance()->register();
	}
);
