<?php
/**
 * REST rate limiter.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Security;

final class RateLimiter {
	public function allow( string $bucket ): bool {
		$settings = get_option( 'odph_settings', array() );
		$limit    = max( 1, min( 1000, (int) ( $settings['api_rate_limit'] ?? 60 ) ) );
		$key      = 'odph_rl_' . md5( $bucket . '|' . gmdate( 'YmdHi' ) );
		$count    = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
		return true;
	}
}
