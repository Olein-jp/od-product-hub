<?php
/**
 * My Account ownership and Customer Portal integration checks for wp-env.
 *
 * @package OD_Product_Hub
 */

use OD_Product_Hub\Customer\CustomerRepository;
use OD_Product_Hub\Database\Installer;
use OD_Product_Hub\Frontend\Shortcodes;
use OD_Product_Hub\License\LicenseGenerator;
use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Product\ProductRepository;
use OD_Product_Hub\Stripe\CheckoutService;
use OD_Product_Hub\Stripe\PortalException;
use OD_Product_Hub\Subscription\SubscriptionRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration check must run via WP-CLI.' );
}

/** @param mixed $actual */
function odph_account_assert( bool $condition, string $message, $actual = null ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message . ( null === $actual ? '' : ': ' . wp_json_encode( $actual ) ) );
	}
}

/** @return int */
function odph_account_user( string $login, string $email ): int {
	$existing_id = username_exists( $login );
	if ( $existing_id ) {
		wp_delete_user( (int) $existing_id );
	}
	$user_id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => $email,
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => 'subscriber',
		)
	);
	odph_account_assert( ! is_wp_error( $user_id ), 'Account user fixture must be created' );
	return (int) $user_id;
}

$old_timezone    = get_option( 'timezone_string' );
$old_date_format = get_option( 'date_format' );
update_option( 'odph_settings', array( 'delete_on_uninstall' => 1 ), false );
Installer::uninstall();
Installer::activate();

$account_page_id = wp_insert_post(
	array(
		'post_title'   => 'Account Integration',
		'post_name'    => 'account-integration',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '[odph_my_account]',
	)
);
odph_account_assert( ! is_wp_error( $account_page_id ), 'Account page fixture must be created' );
$settings                    = get_option( 'odph_settings', array() );
$settings['portal_enabled']  = 1;
$settings['account_page_id'] = (int) $account_page_id;
update_option( 'odph_settings', $settings, false );
update_option( 'timezone_string', 'Asia/Tokyo', false );
update_option( 'date_format', 'Y年n月j日', false );

$user_a = odph_account_user( 'odph-account-a', 'account-a@example.test' );
$user_b = odph_account_user( 'odph-account-b', 'account-b@example.test' );
$user_c = odph_account_user( 'odph-account-unsynced', 'account-unsynced@example.test' );

$products      = new ProductRepository();
$customers     = new CustomerRepository();
$subscriptions = new SubscriptionRepository();
$licenses      = new LicenseRepository();

$product_a1 = $products->create(
	array(
		'name'              => 'Account Product Alpha',
		'slug'              => 'account-product-alpha',
		'description'       => '',
		'stripe_product_id' => 'prod_account_alpha',
		'stripe_price_id'   => 'price_account_alpha',
		'status'            => 'active',
	)
);
$product_a2 = $products->create(
	array(
		'name'              => 'Account Product Beta',
		'slug'              => 'account-product-beta',
		'description'       => '',
		'stripe_product_id' => 'prod_account_beta',
		'stripe_price_id'   => 'price_account_beta',
		'status'            => 'active',
	)
);
$product_b  = $products->create(
	array(
		'name'              => 'Foreign Account Product',
		'slug'              => 'foreign-account-product',
		'description'       => '',
		'stripe_product_id' => 'prod_account_foreign',
		'stripe_price_id'   => 'price_account_foreign',
		'status'            => 'active',
	)
);

$customer_a = $customers->create(
	array(
		'wp_user_id'         => $user_a,
		'stripe_customer_id' => 'cus_accounta',
		'email'              => 'account-a@example.test',
		'name'               => 'Account A',
	)
);
$customer_b = $customers->create(
	array(
		'wp_user_id'         => $user_b,
		'stripe_customer_id' => 'cus_accountb',
		'email'              => 'account-b@example.test',
		'name'               => 'Account B',
	)
);

$subscription_a1 = $subscriptions->create(
	array(
		'customer_id'            => $customer_a,
		'product_id'             => $product_a1,
		'stripe_subscription_id' => 'sub_account_a1',
		'stripe_status'          => 'active',
		'current_period_end'     => '2026-01-31 18:00:00',
		'cancel_at_period_end'   => 0,
	)
);
$subscription_a2 = $subscriptions->create(
	array(
		'customer_id'            => $customer_a,
		'product_id'             => $product_a2,
		'stripe_subscription_id' => 'sub_account_a2',
		'stripe_status'          => 'past_due',
		'current_period_end'     => '2026-02-28 18:00:00',
		'cancel_at_period_end'   => 1,
	)
);
$subscription_b  = $subscriptions->create(
	array(
		'customer_id'            => $customer_b,
		'product_id'             => $product_b,
		'stripe_subscription_id' => 'sub_account_b',
		'stripe_status'          => 'unpaid',
		'current_period_end'     => '2026-03-31 15:00:00',
		'cancel_at_period_end'   => 0,
	)
);

$license_fixtures = array(
	array( $product_a1, $customer_a, $subscription_a1, 'ODPH-AAAA-BBBB-CCCC-DDDD', 'active' ),
	array( $product_a2, $customer_a, $subscription_a2, 'ODPH-EEEE-FFFF-GGGG-HHHH', 'suspended' ),
	array( $product_b, $customer_b, $subscription_b, 'ODPH-JJJJ-KKKK-LLLL-MMMM', 'active' ),
);
foreach ( $license_fixtures as list( $product_id, $customer_id, $subscription_id, $key, $license_status ) ) {
	$licenses->create(
		array(
			'product_id'       => $product_id,
			'customer_id'      => $customer_id,
			'subscription_id'  => $subscription_id,
			'license_key'      => $key,
			'license_key_hash' => LicenseGenerator::hash( $key ),
			'status'           => $license_status,
			'issued_at'        => '2026-01-01 00:00:00',
		)
	);
}

$shortcodes = new Shortcodes();
wp_set_current_user( 0 );
$guest_html = $shortcodes->account();
odph_account_assert( str_contains( $guest_html, 'wp-login.php' ), 'Guests must receive the standard WordPress login route' );
odph_account_assert( ! str_contains( $guest_html, 'ODPH-AAAA' ), 'Guests must never receive contract data' );

wp_set_current_user( $user_a );
$account_a_html = $shortcodes->account();
odph_account_assert( str_contains( $account_a_html, 'Account Product Alpha' ) && str_contains( $account_a_html, 'Account Product Beta' ), 'A user must see all of their products and contracts' );
odph_account_assert( str_contains( $account_a_html, 'ODPH-AAAA-BBBB-CCCC-DDDD' ) && str_contains( $account_a_html, 'ODPH-EEEE-FFFF-GGGG-HHHH' ), 'A user must see all of their license keys' );
odph_account_assert( ! str_contains( $account_a_html, 'Foreign Account Product' ) && ! str_contains( $account_a_html, 'ODPH-JJJJ-KKKK-LLLL-MMMM' ), 'A user must never see another user contract or key' );
odph_account_assert( str_contains( $account_a_html, '2026年2月1日' ), 'UTC period end must use the site timezone and configured date format' );
odph_account_assert( str_contains( $account_a_html, '支払い遅延' ) && str_contains( $account_a_html, '期間終了時に解約予定' ), 'Account states must be communicated with text rather than color alone' );
odph_account_assert( str_contains( $account_a_html, 'aria-live="polite"' ) && str_contains( $account_a_html, 'aria-describedby=' ), 'License copy controls must expose screen reader feedback' );
odph_account_assert( str_contains( $account_a_html, 'odph-portal-form' ) && str_contains( $account_a_html, '_wpnonce' ), 'Synced users must receive a nonce-protected Portal form' );
odph_account_assert( str_contains( $account_a_html, 'パスワードを変更' ) && str_contains( $account_a_html, 'ログアウト' ), 'Account navigation must include password and logout routes' );

wp_set_current_user( $user_b );
$account_b_html = $shortcodes->account();
odph_account_assert( str_contains( $account_b_html, 'Foreign Account Product' ) && ! str_contains( $account_b_html, 'Account Product Alpha' ), 'Ownership filtering must work independently for another user' );

wp_set_current_user( $user_c );
$unsynced_html = $shortcodes->account();
odph_account_assert( str_contains( $unsynced_html, 'Stripe顧客情報を同期しています' ) && ! str_contains( $unsynced_html, 'odph-portal-form' ), 'Unsynced customers must receive guidance instead of a Portal button' );

$captured_portal_args = array();
$portal_creator       = static function ( array $args ) use ( &$captured_portal_args ): object {
	$captured_portal_args = $args;
	return (object) array( 'url' => 'https://billing.stripe.com/p/session/test_portal' );
};
$portal_service       = new CheckoutService( null, $portal_creator );

wp_set_current_user( $user_a );
$portal_url = $portal_service->portal_url_for_current_user();
odph_account_assert( 'https://billing.stripe.com/p/session/test_portal' === $portal_url, 'Portal must return only a trusted Stripe Billing URL' );
odph_account_assert( 'cus_accounta' === $captured_portal_args['customer'], 'Portal must resolve the current user Customer ID internally' );
odph_account_assert( get_permalink( (int) $account_page_id ) === $captured_portal_args['return_url'], 'Portal must return to the configured account page' );

wp_set_current_user( $user_b );
$portal_service->portal_url_for_current_user();
odph_account_assert( 'cus_accountb' === $captured_portal_args['customer'], 'Switching users must switch the Portal Customer ID' );

wp_set_current_user( $user_c );
$unsynced_rejected = false;
try {
	$portal_service->portal_url_for_current_user();
} catch ( PortalException $error ) {
	$unsynced_rejected = 'customer_not_synced' === $error->error_code;
}
odph_account_assert( $unsynced_rejected, 'Portal must reject a user without a synchronized Customer ID' );

$settings['portal_enabled'] = 0;
update_option( 'odph_settings', $settings, false );
wp_set_current_user( $user_a );
$disabled_html     = $shortcodes->account();
$disabled_rejected = false;
try {
	$portal_service->portal_url_for_current_user();
} catch ( PortalException $error ) {
	$disabled_rejected = 'portal_disabled' === $error->error_code;
}
odph_account_assert( str_contains( $disabled_html, '現在利用できません' ) && ! str_contains( $disabled_html, 'odph-portal-form' ), 'Disabled Portal must be explained without rendering its form' );
odph_account_assert( $disabled_rejected, 'Disabled Portal must reject session creation' );

$settings['portal_enabled'] = 1;
update_option( 'odph_settings', $settings, false );
wp_set_current_user( 0 );
$guest_rejected = false;
try {
	$portal_service->portal_url_for_current_user();
} catch ( PortalException $error ) {
	$guest_rejected = 'login_required' === $error->error_code;
}
odph_account_assert( $guest_rejected, 'Portal service must reject guests' );

wp_set_current_user( $user_a );
$unsafe_portal   = new CheckoutService(
	null,
	static fn( array $args ): object => (object) array( 'url' => 'https://attacker.example/portal?customer=' . rawurlencode( (string) $args['customer'] ) )
);
$unsafe_rejected = false;
try {
	$unsafe_portal->portal_url_for_current_user();
} catch ( PortalException $error ) {
	$unsafe_rejected = 'portal_temporarily_unavailable' === $error->error_code && ! str_contains( $error->getMessage(), 'attacker.example' );
}
odph_account_assert( $unsafe_rejected, 'Untrusted Portal URLs must map to a safe purchaser-facing error' );

wp_set_current_user( 0 );
foreach ( array( $user_a, $user_b, $user_c ) as $user_id ) {
	wp_delete_user( $user_id );
}
wp_delete_post( (int) $account_page_id, true );
update_option( 'timezone_string', $old_timezone, false );
update_option( 'date_format', $old_date_format, false );
update_option( 'odph_settings', array( 'delete_on_uninstall' => 1 ), false );
Installer::uninstall();
Installer::activate();
WP_CLI::success( 'Account ownership, multiple contracts, accessible controls, localized dates, and current-user Portal boundaries passed.' );
