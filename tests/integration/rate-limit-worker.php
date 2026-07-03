<?php
/**
 * Independent WP-CLI worker for the rate-limit concurrency proof.
 *
 * @package OD_Product_Hub
 */

use OD_Product_Hub\Security\RateLimiter;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration worker must run via WP-CLI.' );
}

if ( ! isset( $args ) || 3 !== count( $args ) ) {
	WP_CLI::error( 'Expected bucket, timestamp, and limit arguments.' );
}

$bucket = (string) $args[0];
$now    = (int) $args[1];
$limit  = (int) $args[2];
$result = ( new RateLimiter( null, static fn(): int => $now ) )->consume( $bucket, $limit );
if ( ! $result['allowed'] ) {
	WP_CLI::error( 'Concurrent request was unexpectedly limited.' );
}
