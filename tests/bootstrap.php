<?php
/**
 * PHPUnit bootstrap and minimal WordPress function shims for pure unit tests.
 *
 * @package OD_Product_Hub
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, mixed ...$args ): void {
		unset( $hook, $args );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value ): string|false {
		return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is intentionally unavailable in pure unit tests.
	}
}
