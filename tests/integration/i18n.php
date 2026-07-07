<?php
/** Integration test for the bundled Japanese translation catalog. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mo = WP_PLUGIN_DIR . '/od-product-hub/languages/od-product-hub-ja.mo';

if ( ! file_exists( $mo ) ) {
	WP_CLI::error( 'Japanese MO file is missing.' );
}

switch_to_locale( 'ja' );
unload_textdomain( 'od-product-hub' );
if ( ! load_textdomain( 'od-product-hub', $mo, 'ja' ) ) {
	WP_CLI::error( 'Japanese text domain could not be loaded.' );
}

if ( '商品' !== __( 'Products', 'od-product-hub' )
	|| '顧客・契約' !== __( 'Customers and subscriptions', 'od-product-hub' )
	|| 'Stripe Checkout へ進む' !== __( 'Continue to Stripe Checkout', 'od-product-hub' )
	|| 'マイページ' !== __( 'My account', 'od-product-hub' )
	|| 'パスワードを変更' !== __( 'Change password', 'od-product-hub' )
	|| '購入完了メール' !== __( 'Purchase completed email', 'od-product-hub' )
	|| 'Stripeでの支払い・契約管理は現在利用できません。' !== __( 'Stripe billing and subscription management is currently unavailable.', 'od-product-hub' )
) {
	WP_CLI::error( 'Japanese translation mismatch.' );
}

unload_textdomain( 'od-product-hub' );
restore_previous_locale();
WP_CLI::success( 'Bundled Japanese translations loaded successfully.' );
