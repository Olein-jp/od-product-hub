<?php
/**
 * WordPress personal data exporter and eraser integration checks.
 *
 * Run with: npm run test:privacy
 *
 * @package OD_Product_Hub
 */

use OD_Product_Hub\Customer\CustomerRepository;
use OD_Product_Hub\Database\Installer;
use OD_Product_Hub\Database\UtcDateTime;
use OD_Product_Hub\License\LicenseGenerator;
use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Log\ApiLogRepository;
use OD_Product_Hub\Privacy\PrivacyService;
use OD_Product_Hub\Product\ProductRepository;
use OD_Product_Hub\Release\DownloadRepository;
use OD_Product_Hub\Subscription\SubscriptionRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration check must run via WP-CLI.' );
}

/** @param mixed $actual */
function odph_privacy_assert( bool $condition, string $message, $actual = null ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message . ( null === $actual ? '' : ': ' . wp_json_encode( $actual ) ) );
	}
}

global $wpdb;
update_option( 'odph_settings', array( 'delete_on_uninstall' => 1 ), false );
Installer::uninstall();
Installer::activate();

$email         = 'privacy@example.test';
$customers     = new CustomerRepository();
$subscriptions = new SubscriptionRepository();
$licenses      = new LicenseRepository();
$api_logs      = new ApiLogRepository();
$downloads     = new DownloadRepository();
$product_id    = ( new ProductRepository() )->create(
	array(
		'name'              => 'Privacy Product',
		'slug'              => 'privacy-product',
		'stripe_product_id' => 'prod_privacy',
		'stripe_price_id'   => 'price_privacy',
		'status'            => 'active',
	)
);

$license_ids = array();
foreach ( array( 'cus_privacy_one', 'cus_privacy_two' ) as $index => $stripe_customer_id ) {
	$customer_id     = $customers->create(
		array(
			'wp_user_id'         => 0,
			'stripe_customer_id' => $stripe_customer_id,
			'email'              => $email,
			'name'               => 'Privacy Person ' . $index,
		)
	);
	$subscription_id = $subscriptions->create(
		array(
			'customer_id'            => $customer_id,
			'product_id'             => $product_id,
			'stripe_subscription_id' => 'sub_privacy_' . $index,
			'stripe_status'          => 'active',
			'cancel_at_period_end'   => 0,
		)
	);
	$key             = 0 === $index ? 'ODPH-ABCD-EFGH-JKLM-NPQR' : 'ODPH-BCDE-FGHJ-KLMN-PQRS';
	$license_ids[]   = $licenses->create(
		array(
			'product_id'       => $product_id,
			'customer_id'      => $customer_id,
			'subscription_id'  => $subscription_id,
			'license_key'      => $key,
			'license_key_hash' => LicenseGenerator::hash( $key ),
			'status'           => 'active',
			'issued_at'        => UtcDateTime::now(),
		)
	);
}

for ( $i = 0; $i < 51; $i++ ) {
	$api_logs->create(
		array(
			'license_id' => $license_ids[ $i % 2 ],
			'product_id' => $product_id,
			'action'     => 'verify',
			'result'     => 'success',
			'site_url'   => 'https://customer.example/' . $i,
			'ip_address' => '192.0.2.1',
			'user_agent' => 'Privacy integration test',
		)
	);
}
$downloads->create(
	array(
		'release_id' => 1,
		'license_id' => $license_ids[0],
		'token_hash' => hash( 'sha256', 'secret-download-token' ),
		'site_url'   => 'https://download.example',
		'expires_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
		'ip_address' => '192.0.2.2',
		'user_agent' => 'Download integration test',
		'result'     => 'issued',
	)
);
$wpdb->insert(
	$wpdb->prefix . 'odph_email_logs',
	array(
		'email_type'     => 'payment_failed',
		'recipient_hash' => hash_hmac( 'sha256', $email, wp_salt( 'auth' ) ),
		'status'         => 'failed',
		'error_code'     => 'wp_mail_failed',
		'created_at'     => UtcDateTime::now(),
	)
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Integration fixture exercises the hashed-recipient export path.

$service               = new PrivacyService();
$export_1              = $service->export_personal_data( $email, 1 );
$export_2              = $service->export_personal_data( $email, 2 );
$json                  = wp_json_encode( array( $export_1, $export_2 ) );
$customer_export_count = count(
	array_filter(
		$export_1['data'],
		static fn( array $item ): bool => 'odph-customer' === $item['group_id']
	)
);
odph_privacy_assert( false === $export_1['done'] && true === $export_2['done'], 'Exporter must paginate more than 50 matching records' );
odph_privacy_assert( 2 === $customer_export_count, 'Exporter must include every customer sharing the email address', $customer_export_count );
odph_privacy_assert( false === str_contains( (string) $json, 'ODPH-ABCD-EFGH-JKLM-NPQR' ), 'Exporter must not expose a plaintext license key' );
odph_privacy_assert( false === str_contains( (string) $json, hash( 'sha256', 'secret-download-token' ) ), 'Exporter must not expose a download token hash' );
odph_privacy_assert( str_contains( (string) $json, 'ODPH-ABCD-****-****-NPQR' ), 'Exporter may identify a license only by a masked key' );

$erase_1 = $service->erase_personal_data( $email, 1 );
$erase_2 = $service->erase_personal_data( $email, 2 );
odph_privacy_assert( true === $erase_1['items_removed'] && true === $erase_1['items_retained'] && false === $erase_1['done'], 'First eraser batch must anonymize logs and report retained contract data' );
odph_privacy_assert( true === $erase_2['items_removed'] && true === $erase_2['done'], 'Second eraser batch must finish remaining logs without an offset gap' );
$personal_log_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}odph_api_logs WHERE site_url IS NOT NULL OR ip_address IS NOT NULL OR user_agent IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fixed integration assertion.
odph_privacy_assert( 0 === $personal_log_count, 'Eraser must remove personal fields from all API log pages', $personal_log_count );
$download = $downloads->find( 1 );
odph_privacy_assert( null === $download->site_url && null === $download->ip_address && null === $download->user_agent, 'Eraser must anonymize download records' );
odph_privacy_assert( 2 === $customers->search( array( 'email' => $email ), 1, 10 )->total, 'Eraser must retain customer rows needed for Stripe synchronization' );
$already_erased = $service->erase_personal_data( $email, 3 );
odph_privacy_assert( false === $already_erased['items_removed'] && true === $already_erased['items_retained'] && true === $already_erased['done'], 'Repeated erasure must be safe and report retained data' );
$missing = $service->erase_personal_data( 'missing@example.test', 1 );
odph_privacy_assert( false === $missing['items_removed'] && false === $missing['items_retained'] && true === $missing['done'], 'Unknown email must complete without changes' );
odph_privacy_assert( false !== has_filter( 'wp_privacy_personal_data_exporters' ) && false !== has_filter( 'wp_privacy_personal_data_erasers' ), 'Plugin must register both WordPress privacy hooks' );

WP_CLI::success( 'OD Product Hub privacy integration checks passed.' );
