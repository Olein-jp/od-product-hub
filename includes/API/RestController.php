<?php
/**
 * Public contract verification API.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\API;

use OD_Product_Hub\License\LicenseGenerator;
use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Product\ProductRepository;
use OD_Product_Hub\Security\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class RestController {
	private const NAMESPACE = 'od-product-hub/v1';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		foreach ( array( 'activate', 'verify', 'deactivate' ) as $action ) {
			register_rest_route(
				self::NAMESPACE,
				'/' . $action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $action ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => $this->license_args(),
				)
			);
		}
		register_rest_route(
			self::NAMESPACE,
			'/product',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'product' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'product_slug' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/** @return array<string, array<string, mixed>> */
	private function license_args(): array {
		return array(
			'license_key'    => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => static fn( $value ) => strtoupper( sanitize_text_field( $value ) ),
			),
			'product_slug'   => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'site_url'       => array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
			),
			'plugin_version' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'wp_version'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'php_version'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	public function permission( WP_REST_Request $request ) {
		unset( $request );
		if ( ! is_ssl() && 'production' === wp_get_environment_type() ) {
			return new WP_Error( 'odph_https_required', 'HTTPS is required.', array( 'status' => 403 ) );
		}
		$ip = $this->ip_address();
		return ( new RateLimiter() )->allow( $ip ) ? true : new WP_Error( 'odph_rate_limited', 'Too many requests.', array( 'status' => 429 ) );
	}

	public function activate( WP_REST_Request $request ): WP_REST_Response {
		return $this->verify_license( $request, 'activate' ); }
	public function verify( WP_REST_Request $request ): WP_REST_Response {
		return $this->verify_license( $request, 'verify' ); }

	public function deactivate( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->verify_license( $request, 'deactivate', false );
		if ( 200 === $result->get_status() ) {
			$result->set_data(
				array(
					'success' => true,
					'status'  => 'deactivated',
					'message' => 'License deactivated on this site.',
				)
			);
		}
		return $result;
	}

	private function verify_license( WP_REST_Request $request, string $action, bool $touch = true ): WP_REST_Response {
		$key = (string) $request['license_key'];
		if ( ! LicenseGenerator::is_valid( $key ) ) {
			return $this->failure( $request, $action, 'inactive', 'invalid_license', 'Invalid license key.' );
		}
		$license = ( new LicenseRepository() )->find_for_verification( $key, (string) $request['product_slug'] );
		if ( ! $license ) {
			return $this->failure( $request, $action, 'inactive', 'invalid_license', 'Invalid license key or product.' );
		}
		if ( 'suspended' === $license->status ) {
			return $this->failure( $request, $action, 'suspended', 'license_suspended', 'License is suspended.', $license );
		}
		if ( $license->payment_failed_at ) {
			return $this->failure( $request, $action, 'inactive', 'subscription_payment_failed', 'The subscription payment has failed.', $license );
		}
		$valid_subscription = in_array( $license->stripe_status, array( 'active', 'trialing' ), true );
		if ( 'active' !== $license->status || 'active' !== $license->product_status || ! $valid_subscription ) {
			$status = in_array( $license->status, array( 'expired', 'cancelled', 'suspended' ), true ) ? $license->status : 'inactive';
			return $this->failure( $request, $action, $status, 'subscription_inactive', 'License is not active.', $license );
		}
		if ( $touch ) {
			( new LicenseRepository() )->touch( (int) $license->id ); }
		$this->log( $request, $action, 'success', null, $license );
		return new WP_REST_Response(
			array(
				'success'      => true,
				'status'       => 'active',
				'license'      => array(
					'key_masked'       => LicenseGenerator::mask( $license->license_key ),
					'expires_at'       => $license->expires_at,
					'last_verified_at' => current_time( 'c' ),
				),
				'subscription' => array(
					'status'               => $license->stripe_status,
					'current_period_end'   => $license->current_period_end,
					'cancel_at_period_end' => (bool) $license->cancel_at_period_end,
				),
				'product'      => array(
					'slug' => $license->product_slug,
					'name' => $license->product_name,
				),
				'message'      => 'activate' === $action ? 'License activated.' : 'License is active.',
			),
			200
		);
	}

	public function product( WP_REST_Request $request ): WP_REST_Response {
		$product = ( new ProductRepository() )->find_by_slug( (string) $request['product_slug'] );
		if ( ! $product || 'active' !== $product->status ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'error_code' => 'product_not_found',
					'message'    => 'Product not found.',
				),
				404
			);
		}
		return new WP_REST_Response(
			array(
				'success' => true,
				'product' => array(
					'slug'   => $product->slug,
					'name'   => $product->name,
					'status' => $product->status,
				),
			),
			200
		);
	}

	private function failure( WP_REST_Request $request, string $action, string $status, string $code, string $message, ?object $license = null ): WP_REST_Response {
		$this->log( $request, $action, 'failure', $code, $license );
		return new WP_REST_Response(
			array(
				'success'    => false,
				'status'     => $status,
				'error_code' => $code,
				'message'    => $message,
			),
			200
		);
	}

	private function log( WP_REST_Request $request, string $action, string $result, ?string $code, ?object $license ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'odph_api_logs',
			array(
				'license_id' => $license->id ?? null,
				'product_id' => $license->product_id ?? null,
				'action'     => $action,
				'result'     => $result,
				'site_url'   => (string) ( $request['site_url'] ?? '' ),
				'ip_address' => $this->ip_address(),
				'user_agent' => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
				'error_code' => $code,
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	private function ip_address(): string {
		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) ); }
}
