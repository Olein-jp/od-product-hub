<?php
/**
 * Stripe client factory.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Stripe;

use Stripe\StripeClient;

final class StripeClientFactory {
	public static function create(): StripeClient {
		if ( ! class_exists( StripeClient::class ) ) {
			throw new \RuntimeException( 'Stripe PHP SDK is not installed. Run composer install.' );
		}
		$settings = get_option( 'odph_settings', array() );
		$key      = (string) ( $settings['stripe_secret_key'] ?? '' );
		if ( '' === $key ) {
			throw new \RuntimeException( 'Stripe Secret Key is not configured.' ); }
		return new StripeClient( $key );
	}
}
