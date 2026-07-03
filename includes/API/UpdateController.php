<?php
/**
 * Contract-gated WordPress update metadata endpoint.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\API;

use OD_Product_Hub\License\LicenseGenerator;
use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Log\ApiLogRepository;
use OD_Product_Hub\Release\DownloadTokenService;
use OD_Product_Hub\Release\ReleasePackageValidator;
use OD_Product_Hub\Release\ReleaseRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class UpdateController extends PublicController {
	private LicenseRepository $licenses;
	private LicenseStatusService $status_service;
	private ReleaseRepository $releases;
	private DownloadTokenService $tokens;
	private ApiLogRepository $logs;

	public function __construct() {
		parent::__construct();
		$this->rest_base      = 'updates';
		$this->licenses       = new LicenseRepository();
		$this->status_service = new LicenseStatusService();
		$this->releases       = new ReleaseRepository();
		$this->tokens         = new DownloadTokenService();
		$this->logs           = new ApiLogRepository();
	}

	public function register_routes(): void {
		$args                               = ApiSchema::license_args();
		$args['plugin_version']['required'] = true;
		$args['channel']                    = array(
			'type'    => 'string',
			'enum'    => array( 'stable', 'beta' ),
			'default' => 'stable',
		);
		register_rest_route(
			$this->namespace,
			'/updates/check',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'check' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $args,
			)
		);
	}

	public function check( WP_REST_Request $request ): WP_REST_Response {
		$settings = get_option( 'odph_settings', array() );
		$limit    = $this->rate_limiter->consume( $this->client_ip() . '|' . $request->get_route(), (int) ( $settings['update_rate_limit'] ?? 20 ) );
		if ( ! $limit['allowed'] ) {
			return $this->rate_limited( $limit );
		}
		$key      = (string) $request->get_param( 'license_key' );
		$slug     = (string) $request->get_param( 'product_slug' );
		$license  = LicenseGenerator::is_valid( $key ) ? $this->licenses->find_for_verification( $key, $slug ) : null;
		$decision = $license ? $this->status_service->evaluate( $license ) : null;
		if ( ! $license || ! $decision || ! $decision->active ) {
			$this->log( $request, $license, 'failure', $decision->error_code ?? 'invalid_license' );
			return $this->with_limit_headers(
				new WP_REST_Response(
					array(
						'success'    => false,
						'error_code' => $decision->error_code ?? 'invalid_license',
						'message'    => 'An active contract is required.',
					),
					403
				),
				$limit
			);
		}
		$release = $this->releases->latest_for_product( (int) $license->product_id, (string) $request->get_param( 'channel' ) );
		if ( ! $release ) {
			$this->log( $request, $license, 'success', null );
			return $this->with_limit_headers(
				new WP_REST_Response(
					array(
						'success'          => true,
						'update_available' => false,
					),
					200
				),
				$limit
			);
		}
		if ( ! version_compare( (string) $release->version, (string) $request->get_param( 'plugin_version' ), '>' ) ) {
			$this->log( $request, $license, 'success', null );
			return $this->with_limit_headers(
				new WP_REST_Response(
					array(
						'success'          => true,
						'update_available' => false,
					),
					200
				),
				$limit
			);
		}
		$package_error = ( new ReleasePackageValidator() )->validate( $release );
		if ( null !== $package_error ) {
			$this->log( $request, $license, 'failure', $package_error );
			return $this->with_limit_headers(
				new WP_REST_Response(
					array(
						'success'    => false,
						'error_code' => $package_error,
						'message'    => 'The update package is temporarily unavailable.',
					),
					503
				),
				$limit
			);
		}
		$grant = $this->tokens->issue( (int) $release->id, (int) $license->id, (string) $request->get_param( 'site_url' ) );
		$this->log( $request, $license, 'success', null );
		return $this->with_limit_headers(
			new WP_REST_Response(
				array(
					'success'          => true,
					'update_available' => true,
					'release'          => array(
						'version'       => (string) $release->version,
						'plugin_file'   => (string) $release->plugin_file,
						'channel'       => (string) $release->channel,
						'release_notes' => (string) $release->release_notes,
						'requires_wp'   => (string) $release->requires_wp,
						'requires_php'  => (string) $release->requires_php,
						'sha256'        => (string) $release->sha256,
						'signature'     => (string) $release->signature,
						'public_key'    => (string) $release->public_key,
						'download_url'  => rest_url( $this->namespace . '/downloads/' . rawurlencode( $grant['token'] ) ),
						'expires_at'    => gmdate( DATE_ATOM, $grant['expires_at'] ),
					),
				),
				200
			),
			$limit
		);
	}

	private function log( WP_REST_Request $request, ?object $license, string $result, ?string $error ): void {
		$this->logs->create(
			array(
				'license_id' => $license->id ?? null,
				'product_id' => $license->product_id ?? null,
				'action'     => 'update_check',
				'result'     => $result,
				'site_url'   => substr( (string) $request->get_param( 'site_url' ), 0, 255 ),
				'ip_address' => substr( $this->client_ip(), 0, 100 ),
				'user_agent' => substr( sanitize_text_field( $request->get_header( 'user-agent' ) ), 0, 500 ),
				'error_code' => $error,
			)
		);
	}
}
