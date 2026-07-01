<?php
/**
 * Checkout service and shortcode integration checks for wp-env.
 *
 * @package OD_Product_Hub
 */

use OD_Product_Hub\Customer\CustomerRepository;
use OD_Product_Hub\Database\Installer;
use OD_Product_Hub\Frontend\Shortcodes;
use OD_Product_Hub\Product\ProductRepository;
use OD_Product_Hub\Stripe\CheckoutException;
use OD_Product_Hub\Stripe\CheckoutService;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration check must run via WP-CLI.' );
}

/** @param mixed $actual */
function odph_checkout_assert( bool $condition, string $message, $actual = null ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message . ( null === $actual ? '' : ': ' . wp_json_encode( $actual ) ) );
	}
}

update_option( 'odph_settings', array( 'delete_on_uninstall' => 1 ), false );
Installer::uninstall();
Installer::activate();
$settings                = get_option( 'odph_settings', array() );
$settings['success_url'] = 'https://example.test/checkout/success';
$settings['cancel_url']  = 'https://example.test/checkout/cancel';
update_option( 'odph_settings', $settings, false );

$products   = new ProductRepository();
$product_id = $products->create(
	array(
		'name'                => 'Checkout Product',
		'slug'                => 'checkout-product',
		'description'         => 'Product description',
		'price_description'   => 'Monthly 1,980 JPY',
		'billing_description' => 'Renews monthly until cancelled.',
		'stripe_product_id'   => 'prod_checkout',
		'stripe_price_id'     => 'price_checkout',
		'status'              => 'active',
	)
);

$captured_args = array();
$creator       = static function ( array $args ) use ( &$captured_args ): object {
	$captured_args = $args;
	return (object) array( 'url' => 'https://checkout.stripe.com/c/pay/test_session' );
};
$service       = new CheckoutService( $creator );
wp_set_current_user( 0 );
$url = $service->checkout_url( 'checkout-product' );
odph_checkout_assert( 'https://checkout.stripe.com/c/pay/test_session' === $url, 'Checkout must return only a trusted Stripe URL' );
odph_checkout_assert( 'subscription' === $captured_args['mode'], 'Checkout must use subscription mode' );
odph_checkout_assert( 'price_checkout' === $captured_args['line_items'][0]['price'], 'Checkout must use the configured Price ID' );
odph_checkout_assert( 'checkout-product' === $captured_args['metadata']['odph_product_slug'], 'Checkout must include product metadata' );
odph_checkout_assert( str_contains( $captured_args['success_url'], 'session_id={CHECKOUT_SESSION_ID}' ), 'Success URL must include the Checkout Session placeholder' );
odph_checkout_assert( ! isset( $captured_args['payment_method_types'] ), 'Checkout must preserve Stripe dynamic payment methods' );
odph_checkout_assert( ! isset( $captured_args['customer_email'] ) && ! isset( $captured_args['customer'] ), 'Guest Checkout must not receive invented customer data' );

$existing_user_id = username_exists( 'odph-checkout-user' );
if ( $existing_user_id ) {
	wp_delete_user( (int) $existing_user_id );
}
$user_id = wp_insert_user(
	array(
		'user_login' => 'odph-checkout-user',
		'user_email' => 'checkout-user@example.test',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'subscriber',
	)
);
odph_checkout_assert( ! is_wp_error( $user_id ), 'Checkout user fixture must be created' );
wp_set_current_user( (int) $user_id );
$service->checkout_url( 'checkout-product' );
odph_checkout_assert( 'checkout-user@example.test' === $captured_args['customer_email'], 'Logged-in user email must be supplied when no Stripe Customer exists' );
odph_checkout_assert( (string) $user_id === $captured_args['metadata']['odph_wp_user_id'], 'Logged-in user ID must be metadata only' );

$customer_id = ( new CustomerRepository() )->create(
	array(
		'wp_user_id'         => (int) $user_id,
		'stripe_customer_id' => 'cus_checkout',
		'email'              => 'checkout-user@example.test',
		'name'               => 'Checkout User',
	)
);
$service->checkout_url( 'checkout-product' );
odph_checkout_assert( 'cus_checkout' === $captured_args['customer'], 'Owned Stripe Customer must take precedence over customer_email' );
odph_checkout_assert( ! isset( $captured_args['customer_email'] ), 'Checkout must not send both customer and customer_email' );

$shortcode = ( new Shortcodes() )->checkout( array( 'product' => 'checkout-product' ) );
odph_checkout_assert( str_contains( $shortcode, 'Checkout Product' ), 'Checkout shortcode must display the product name' );
odph_checkout_assert( str_contains( $shortcode, 'Product description' ) && str_contains( $shortcode, 'Monthly 1,980 JPY' ), 'Checkout shortcode must display product and price descriptions' );
odph_checkout_assert( str_contains( $shortcode, 'Renews monthly until cancelled.' ), 'Checkout shortcode must explain subscription billing' );
odph_checkout_assert( str_contains( $shortcode, 'odph-checkout-form' ) && str_contains( $shortcode, 'aria-live' ), 'Checkout form must expose accessible submission feedback' );

$products->update( $product_id, array( 'status' => 'inactive' ) );
$inactive_rejected = false;
try {
	$service->checkout_url( 'checkout-product' );
} catch ( CheckoutException $error ) {
	$inactive_rejected = 'product_unavailable' === $error->error_code;
}
odph_checkout_assert( $inactive_rejected, 'Inactive products must never create a Checkout Session' );
$products->update( $product_id, array( 'status' => 'active' ) );

$settings['success_url'] = 'javascript:alert(1)';
update_option( 'odph_settings', $settings, false );
$invalid_url_rejected = false;
try {
	$service->checkout_url( 'checkout-product' );
} catch ( CheckoutException $error ) {
	$invalid_url_rejected = 'checkout_not_configured' === $error->error_code;
}
odph_checkout_assert( $invalid_url_rejected, 'Invalid success or cancel URLs must be rejected before Stripe is called' );
$settings['success_url'] = 'https://example.test/checkout/success';
update_option( 'odph_settings', $settings, false );

$api_failure  = new CheckoutService(
	static function ( array $args ): object {
		unset( $args );
		throw new RuntimeException( 'Sensitive Stripe details must not reach the purchaser.' );
	}
);
$safe_failure = false;
try {
	$api_failure->checkout_url( 'checkout-product' );
} catch ( CheckoutException $error ) {
	$safe_failure = 'checkout_temporarily_unavailable' === $error->error_code && ! str_contains( $error->getMessage(), 'Sensitive' );
}
odph_checkout_assert( $safe_failure, 'Stripe API failures must map to a safe purchaser-facing error' );

$purchase_url = home_url( '/purchase' );
$success_html = ( new Shortcodes() )->checkout_success();
$cancel_html  = ( new Shortcodes() )->checkout_cancel( array( 'return_url' => $purchase_url ) );
odph_checkout_assert( str_contains( $success_html, 'マイページ' ) && str_contains( $success_html, 'メール' ), 'Success view must explain email and account follow-up' );
odph_checkout_assert( str_contains( $cancel_html, '購入ページへ戻る' ) && str_contains( $cancel_html, $purchase_url ), 'Cancel view must return the purchaser to the same-site purchase page' );

( new CustomerRepository() )->delete( $customer_id );
wp_delete_user( (int) $user_id );
update_option( 'odph_settings', array( 'delete_on_uninstall' => 1 ), false );
Installer::uninstall();
Installer::activate();
WP_CLI::success( 'Checkout session contract, purchaser UI, URL validation, and safe errors passed.' );
