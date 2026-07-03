<?php
/**
 * REST rate limiter.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Security;

use OD_Product_Hub\Database\DatabaseException;

final class RateLimiter {
	private RateLimitRepository $counters;
	private \Closure $clock;

	/** @param (callable(): int)|null $clock */
	public function __construct( ?RateLimitRepository $counters = null, ?callable $clock = null ) {
		$this->counters = $counters ?? new RateLimitRepository();
		$this->clock    = \Closure::fromCallable( $clock ?? 'time' );
	}

	/** @return array{allowed: bool, limit: int, remaining: int, retry_after: int, reset: int} */
	public function consume( string $bucket, ?int $configured_limit = null ): array {
		$settings = get_option( 'odph_settings', array() );
		$limit    = max( 1, min( 1000, $configured_limit ?? (int) ( $settings['api_rate_limit'] ?? 60 ) ) );
		$now      = ( $this->clock )();
		$reset    = ( intdiv( $now, MINUTE_IN_SECONDS ) + 1 ) * MINUTE_IN_SECONDS;
		$retry    = max( 1, $reset - $now );
		$key      = hash( 'sha256', $bucket . '|' . intdiv( $now, MINUTE_IN_SECONDS ) );
		try {
			$count = $this->counters->increment( $key, gmdate( 'Y-m-d H:i:s', $reset + MINUTE_IN_SECONDS ) );
		} catch ( DatabaseException $error ) {
			unset( $error );
			$count = $limit + 1;
		}
		$allowed = $count <= $limit;
		return array(
			'allowed'     => $allowed,
			'limit'       => $limit,
			'remaining'   => $allowed ? max( 0, $limit - $count ) : 0,
			'retry_after' => $retry,
			'reset'       => $reset,
		);
	}

	public function allow( string $bucket ): bool {
		$result = $this->consume( $bucket );
		return $result['allowed'];
	}
}
