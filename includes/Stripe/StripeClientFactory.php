<?php
/**
 * Stripe client factory.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Stripe;

use Stripe\StripeClient;

final class StripeClientFactory {
	public const API_VERSION = '2026-06-24.dahlia';

	public static function create(): StripeClient {
		if ( ! class_exists( StripeClient::class ) ) {
			throw new \RuntimeException( 'Stripe PHP SDK is not installed. Run composer install.' );
		}
		$settings = get_option( 'odph_settings', array() );
		$key      = (string) ( $settings['stripe_secret_key'] ?? '' );
		if ( '' === $key ) {
			throw new \RuntimeException( 'Stripe Secret Key is not configured.' ); }
		return new StripeClient(
			array(
				'api_key'        => $key,
				'stripe_version' => self::API_VERSION,
				'app_info'       => array(
					'name'    => 'OD Product Hub',
					'version' => defined( 'OD_PRODUCT_HUB_VERSION' ) ? OD_PRODUCT_HUB_VERSION : null,
					'url'     => 'https://github.com/Olein-jp/od-product-hub',
				),
			)
		);
	}
}
