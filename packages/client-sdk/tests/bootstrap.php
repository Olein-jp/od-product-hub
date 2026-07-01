<?php

declare(strict_types=1);

if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {
		public function __construct( public string $code = '', public string $message = '' ) {}
	}
}

/** @param mixed $response */
function is_wp_error( $response ): bool {
	return $response instanceof WP_Error;
}

/** @param array<string, mixed> $args @return array<string, mixed>|WP_Error */
function wp_safe_remote_post( string $url, array $args ) {
	$GLOBALS['odph_client_test_requests'][] = array( 'url' => $url, 'args' => $args );
	return array_shift( $GLOBALS['odph_client_test_responses'] );
}

/** @param array<string, mixed>|WP_Error $response */
function wp_remote_retrieve_response_code( $response ): int {
	return $response instanceof WP_Error ? 0 : (int) ( $response['response']['code'] ?? 0 );
}

/** @param array<string, mixed>|WP_Error $response */
function wp_remote_retrieve_body( $response ): string {
	return $response instanceof WP_Error ? '' : (string) ( $response['body'] ?? '' );
}

/** @param mixed $value */
function wp_json_encode( $value ): string|false {
	return json_encode( $value );
}

function get_bloginfo( string $show = '' ): string {
	return 'version' === $show ? '7.0.0' : '';
}

/** @param callable|array{object|string, string}|string $callback */
function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['odph_client_test_filters'][] = compact( 'hook', 'callback', 'priority', 'accepted_args' );
	return true;
}

function download_url( string $url, int $timeout = 300 ): string|WP_Error {
	unset( $url, $timeout );
	return $GLOBALS['odph_client_test_download'] ?? new WP_Error( 'missing_download', 'No test download configured.' );
}

function wp_delete_file( string $file ): bool {
	$GLOBALS['odph_client_test_deleted'][] = $file;
	return ! file_exists( $file ) || unlink( $file );
}

function odph_client_test_reset_wordpress(): void {
	$GLOBALS['odph_client_test_requests']  = array();
	$GLOBALS['odph_client_test_responses'] = array();
	$GLOBALS['odph_client_test_filters']   = array();
	$GLOBALS['odph_client_test_deleted']   = array();
	unset( $GLOBALS['odph_client_test_download'] );
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'OD_Product_Hub_Client\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$file = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);
