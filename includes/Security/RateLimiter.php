<?php
/**
 * REST rate limiter.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Security;

final class RateLimiter {
	/** @return array{allowed: bool, limit: int, remaining: int, retry_after: int, reset: int} */
	public function consume( string $bucket, ?int $configured_limit = null ): array {
		$settings = get_option( 'odph_settings', array() );
		$limit    = max( 1, min( 1000, $configured_limit ?? (int) ( $settings['api_rate_limit'] ?? 60 ) ) );
		$now      = time();
		$reset    = ( intdiv( $now, MINUTE_IN_SECONDS ) + 1 ) * MINUTE_IN_SECONDS;
		$retry    = max( 1, $reset - $now );
		$key      = 'odph_rl_' . hash( 'sha256', $bucket . '|' . intdiv( $now, MINUTE_IN_SECONDS ) );
		$count    = $this->increment( $key, $retry + MINUTE_IN_SECONDS );
		$allowed  = $count <= $limit;
		return array(
			'allowed'     => $allowed,
			'limit'       => $limit,
			'remaining'   => $allowed ? max( 0, $limit - $count ) : 0,
			'retry_after' => $retry,
			'reset'       => $reset,
		);
	}

	private function increment( string $key, int $ttl ): int {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'odph_rate_limits', $ttl );
			$count = wp_cache_incr( $key, 1, 'odph_rate_limits' );
			return false === $count ? 1 : (int) $count;
		}
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, $ttl );
		return $count;
	}

	public function allow( string $bucket ): bool {
		$result = $this->consume( $bucket );
		return $result['allowed'];
	}
}
