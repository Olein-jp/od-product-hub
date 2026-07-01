<?php
/**
 * Stripe SDK client compatibility tests.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Stripe;

use PHPUnit\Framework\TestCase;
use Stripe\Stripe;

final class StripeClientFactoryTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['odph_test_options'] );
		parent::tearDown();
	}

	public function test_factory_uses_verified_sdk_and_explicit_api_version(): void {
		$GLOBALS['odph_test_options']['odph_settings'] = array( 'stripe_secret_key' => 'rk_test_placeholder' );

		$client = StripeClientFactory::create();

		self::assertSame( '20.3.0', Stripe::VERSION );
		self::assertSame( StripeClientFactory::API_VERSION, $client->getStripeVersion() );
		self::assertSame(
			array(
				'name'    => 'OD Product Hub',
				'version' => null,
				'url'     => 'https://github.com/Olein-jp/od-product-hub',
			),
			$client->getAppInfo()
		);
		self::assertNotNull( $client->checkout->sessions );
		self::assertNotNull( $client->billingPortal->sessions ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Stripe SDK property.
		self::assertNotNull( $client->balance );
	}

	public function test_factory_rejects_missing_credentials_without_exposing_a_secret(): void {
		$GLOBALS['odph_test_options']['odph_settings'] = array();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Stripe Secret Key is not configured.' );

		StripeClientFactory::create();
	}
}
