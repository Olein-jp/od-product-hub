<?php
/**
 * Public license activation, verification, and deactivation endpoints.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\API;

use OD_Product_Hub\Database\UtcDateTime;
use OD_Product_Hub\License\LicenseGenerator;
use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Log\ApiLogRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class LicenseController extends PublicController {
	private LicenseRepository $licenses;
	private ApiLogRepository $logs;
	private LicenseStatusService $status_service;

	public function __construct() {
		parent::__construct();
		$this->rest_base      = 'licenses';
		$this->licenses       = new LicenseRepository();
		$this->logs           = new ApiLogRepository();
		$this->status_service = new LicenseStatusService();
	}

	public function register_routes(): void {
		foreach ( array( 'activate', 'verify', 'deactivate' ) as $action ) {
			register_rest_route(
				$this->namespace,
				'/' . $action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $action ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => ApiSchema::license_args(),
				)
			);
		}
	}

	public function activate( WP_REST_Request $request ): WP_REST_Response {
		return $this->process( $request, 'activate' );
	}

	public function verify( WP_REST_Request $request ): WP_REST_Response {
		return $this->process( $request, 'verify' );
	}

	public function deactivate( WP_REST_Request $request ): WP_REST_Response {
		return $this->process( $request, 'deactivate' );
	}

	private function process( WP_REST_Request $request, string $action ): WP_REST_Response {
		$limit = $this->consume_limit( $request );
		if ( ! $limit['allowed'] ) {
			return $this->rate_limited( $limit );
		}
		$key  = (string) $request->get_param( 'license_key' );
		$slug = (string) $request->get_param( 'product_slug' );
		if ( ! LicenseGenerator::is_valid( $key ) ) {
			return $this->with_limit_headers( $this->failure( $request, $action, 'inactive', 'invalid_license', 'Invalid license key.' ), $limit );
		}
		$license = $this->licenses->find_for_verification( $key, $slug );
		if ( ! $license ) {
			return $this->with_limit_headers( $this->failure( $request, $action, 'inactive', 'invalid_license', 'Invalid license key or product.' ), $limit );
		}
		$decision = $this->status_service->evaluate( $license );
		if ( ! $decision->active ) {
			return $this->with_limit_headers( $this->failure( $request, $action, $decision->status, (string) $decision->error_code, $decision->message, $license ), $limit );
		}
		$checked_at = UtcDateTime::now();
		if ( 'deactivate' !== $action ) {
			$this->licenses->touch( (int) $license->id, $checked_at );
			$license->last_verified_at = $checked_at;
		}
		$this->log( $request, $action, 'success', null, $license );
		$data = array(
			'success'      => true,
			'status'       => 'active',
			'license'      => array(
				'key_masked'       => LicenseGenerator::mask( (string) $license->license_key ),
				'expires_at'       => $this->iso8601( $license->expires_at ?? null ),
				'last_verified_at' => $this->iso8601( $license->last_verified_at ?? null ),
			),
			'subscription' => array(
				'status'               => (string) $license->stripe_status,
				'current_period_end'   => $this->iso8601( $license->current_period_end ?? null ),
				'cancel_at_period_end' => (bool) $license->cancel_at_period_end,
			),
			'product'      => array(
				'slug' => (string) $license->product_slug,
				'name' => (string) $license->product_name,
			),
			'checked_at'   => $this->iso8601( $checked_at ),
			'message'      => 'activate' === $action ? 'License activated.' : 'License is active.',
		);
		if ( 'deactivate' === $action ) {
			$data['deactivated'] = true;
			$data['message']     = 'Site deactivation recorded. The license remains active.';
		}
		return $this->with_limit_headers( new WP_REST_Response( $data, 200 ), $limit );
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
		$this->logs->create(
			array(
				'license_id' => $license->id ?? null,
				'product_id' => $license->product_id ?? null,
				'action'     => $action,
				'result'     => $result,
				'site_url'   => substr( (string) ( $request->get_param( 'site_url' ) ?? '' ), 0, 255 ),
				'ip_address' => substr( $this->client_ip(), 0, 100 ),
				'user_agent' => substr( sanitize_text_field( $request->get_header( 'user-agent' ) ), 0, 500 ),
				'error_code' => $code,
			)
		);
	}

	/** @param mixed $utc */
	private function iso8601( $utc ): ?string {
		if ( ! is_string( $utc ) || '' === $utc ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $utc, new \DateTimeZone( 'UTC' ) );
		return false === $date ? null : $date->format( \DateTimeInterface::ATOM );
	}
}
