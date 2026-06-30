<?php
/**
 * Shared security and rate-limit behavior for public API controllers.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\API;

use OD_Product_Hub\Security\RateLimiter;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

abstract class PublicController extends WP_REST_Controller {
	protected const API_NAMESPACE = 'od-product-hub/v1';

	public function __construct(
		protected ?RateLimiter $rate_limiter = null,
		protected ?ClientIpResolver $ip_resolver = null
	) {
		$this->namespace    = self::API_NAMESPACE;
		$this->rate_limiter = $rate_limiter ?? new RateLimiter();
		$this->ip_resolver  = $ip_resolver ?? new ClientIpResolver();
	}

	/** @return true|WP_Error */
	public function check_permission( WP_REST_Request $request ) {
		unset( $request );
		if ( ! is_ssl() && 'production' === wp_get_environment_type() ) {
			return new WP_Error( 'odph_https_required', 'HTTPS is required.', array( 'status' => 403 ) );
		}
		return true;
	}

	/** @return array{allowed: bool, limit: int, remaining: int, retry_after: int, reset: int} */
	protected function consume_limit( WP_REST_Request $request ): array {
		return $this->rate_limiter->consume( $this->client_ip() . '|' . $request->get_route() );
	}

	/** @param array{allowed: bool, limit: int, remaining: int, retry_after: int, reset: int} $limit */
	protected function rate_limited( array $limit ): WP_REST_Response {
		return $this->with_limit_headers(
			new WP_REST_Response(
				array(
					'success'    => false,
					'error_code' => 'rate_limited',
					'message'    => 'Too many requests.',
				),
				429
			),
			$limit,
			true
		);
	}

	/** @param array{allowed: bool, limit: int, remaining: int, retry_after: int, reset: int} $limit */
	protected function with_limit_headers( WP_REST_Response $response, array $limit, bool $retry = false ): WP_REST_Response {
		$response->header( 'X-RateLimit-Limit', (string) $limit['limit'] );
		$response->header( 'X-RateLimit-Remaining', (string) $limit['remaining'] );
		$response->header( 'X-RateLimit-Reset', (string) $limit['reset'] );
		if ( $retry ) {
			$response->header( 'Retry-After', (string) $limit['retry_after'] );
		}
		return $response;
	}

	protected function client_ip(): string {
		return $this->ip_resolver->resolve();
	}
}
