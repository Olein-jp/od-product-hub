<?php
/**
 * Stripe Checkout and Customer Portal.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Stripe;

use OD_Product_Hub\Product\ProductRepository;

final class CheckoutService {
	public function checkout_url( string $product_slug ): string {
		$product  = ( new ProductRepository() )->find_by_slug( $product_slug );
		$settings = get_option( 'odph_settings', array() );
		if ( ! $product || 'active' !== $product->status ) {
			throw new \InvalidArgumentException( 'Product is not available.' ); }
		$session = StripeClientFactory::create()->checkout->sessions->create(
			array(
				'mode'                => 'subscription',
				'line_items'          => array(
					array(
						'price'    => $product->stripe_price_id,
						'quantity' => 1,
					),
				),
				'success_url'         => $settings['success_url'] . ( str_contains( (string) $settings['success_url'], '?' ) ? '&' : '?' ) . 'session_id={CHECKOUT_SESSION_ID}',
				'cancel_url'          => $settings['cancel_url'],
				'client_reference_id' => (string) $product->id,
				'metadata'            => array(
					'odph_product_id'   => (string) $product->id,
					'odph_product_slug' => $product->slug,
				),
			)
		);
		return (string) $session->url;
	}

	public function portal_url( string $customer_id ): string {
		$settings = get_option( 'odph_settings', array() );
		$session  = StripeClientFactory::create()->billingPortal->sessions->create(
			array(
				'customer'   => $customer_id,
				'return_url' => get_permalink( (int) ( $settings['account_page_id'] ?? 0 ) ),
			)
		);
		return (string) $session->url;
	}
}
