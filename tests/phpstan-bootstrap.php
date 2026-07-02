<?php
/**
 * Constants defined by WordPress and the plugin bootstrap at runtime.
 *
 * @package OD_Product_Hub
 */

define( 'ABSPATH', __DIR__ . '/wordpress/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'WP_CONTENT_DIR', dirname( __DIR__ ) . '/wp-content' );
define( 'OD_PRODUCT_HUB_FILE', dirname( __DIR__ ) . '/od-product-hub.php' );
define( 'OD_PRODUCT_HUB_URL', 'https://example.test/wp-content/plugins/od-product-hub/' );
define( 'OD_PRODUCT_HUB_VERSION', '0.1.0' );

class WP_CLI {
	/** @param class-string $command */
	public static function add_command( string $name, string $command ): void {}
	public static function success( string $message ): void {}
	public static function error( string $message ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Static-analysis stub only.
		throw new RuntimeException( $message );
	}
}
