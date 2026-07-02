<?php
/**
 * One-time release download endpoint.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\API;

use OD_Product_Hub\Release\DownloadRepository;
use OD_Product_Hub\Release\DownloadTokenService;
use OD_Product_Hub\Release\PackageSigner;
use OD_Product_Hub\Release\ReleaseRepository;
use OD_Product_Hub\Log\ApiLogRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class DownloadController extends PublicController {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/downloads/(?P<token>[A-Za-z0-9._-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'token' => array(
						'required'  => true,
						'type'      => 'string',
						'minLength' => 40,
						'maxLength' => 512,
					),
				),
			)
		);
	}

	/** @return never|WP_Error */
	public function download( WP_REST_Request $request ) {
		$settings = get_option( 'odph_settings', array() );
		$limit    = $this->rate_limiter->consume( $this->client_ip() . '|release_download', (int) ( $settings['update_rate_limit'] ?? 20 ) );
		if ( ! $limit['allowed'] ) {
			return new WP_Error(
				'odph_rate_limited',
				'Too many download attempts.',
				array(
					'status'      => 429,
					'Retry-After' => $limit['retry_after'],
				)
			);
		}
		$tokens = new DownloadTokenService();
		$valid  = $tokens->validate( (string) $request->get_param( 'token' ) );
		if ( null === $valid ) {
			return new WP_Error( 'odph_download_invalid', 'Download URL is invalid, expired, or already used.', array( 'status' => 403 ) );
		}
		$downloads = new DownloadRepository();
		$release   = ( new ReleaseRepository() )->find( $valid['release_id'] );
		if ( ! $release || 'published' !== (string) $release->status || ! is_file( (string) $release->package_path ) || ! ( new PackageSigner() )->verify( (string) $release->package_path, (string) $release->sha256, (string) $release->signature, (string) $release->public_key ) ) {
			$downloads->mark_result( (int) $valid['grant']->id, 'rejected' );
			$this->log( $request, $valid, $release, 'failure', 'package_invalid' );
			return new WP_Error( 'odph_package_invalid', 'Release package integrity check failed.', array( 'status' => 410 ) );
		}
		if ( ! $downloads->claim( (int) $valid['grant']->id, $this->client_ip(), $request->get_header( 'user-agent' ) ) ) {
			return new WP_Error( 'odph_download_replayed', 'Download URL has already been used.', array( 'status' => 403 ) );
		}
		$this->log( $request, $valid, $release, 'success', null );
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( (string) $release->plugin_file . '-' . (string) $release->version . '.zip' ) . '"' );
		header( 'Content-Length: ' . (string) filesize( (string) $release->package_path ) );
		readfile( (string) $release->package_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Controlled path from private release storage.
		exit;
	}

	/** @param array{grant: object, release_id: int, license_id: int} $valid */
	private function log( WP_REST_Request $request, array $valid, ?object $release, string $result, ?string $error ): void {
		( new ApiLogRepository() )->create(
			array(
				'license_id' => $valid['license_id'],
				'product_id' => $release->product_id ?? null,
				'action'     => 'download',
				'result'     => $result,
				'site_url'   => substr( (string) $valid['grant']->site_url, 0, 255 ),
				'ip_address' => substr( $this->client_ip(), 0, 100 ),
				'user_agent' => substr( sanitize_text_field( $request->get_header( 'user-agent' ) ), 0, 500 ),
				'error_code' => $error,
			)
		);
	}
}
