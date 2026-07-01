<?php
/**
 * Public REST API contract, security boundary, and performance checks.
 *
 * @package OD_Product_Hub
 */

use OD_Product_Hub\API\ClientIpResolver;
use OD_Product_Hub\Database\Installer;
use OD_Product_Hub\Database\UtcDateTime;
use OD_Product_Hub\License\LicenseGenerator;
use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Log\ApiLogRepository;
use OD_Product_Hub\Product\ProductRepository;
use OD_Product_Hub\Subscription\SubscriptionRepository;
use OD_Product_Hub\Customer\CustomerRepository;
use OD_Product_Hub\Release\DownloadRepository;
use OD_Product_Hub\Release\DownloadTokenService;
use OD_Product_Hub\Release\PackageSigner;
use OD_Product_Hub\Release\ReleaseService;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration check must run via WP-CLI.' );
}

/** @param mixed $actual */
function odph_api_assert( bool $condition, string $message, $actual = null ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message . ( null === $actual ? '' : ': ' . wp_json_encode( $actual ) ) );
	}
}

/** @param array<string, mixed> $params */
function odph_api_dispatch( WP_REST_Server $server, string $method, string $route, array $params = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, $route );
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} elseif ( 'OPTIONS' !== $method ) {
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $params ) );
	}
	$request->set_header( 'user-agent', str_repeat( 'ODPH API Contract Test ', 40 ) );
	return $server->dispatch( $request );
}

/** @return array<string, mixed> */
function odph_api_license_params( string $key = 'ODPH-ABCD-EFGH-JKLM-NPQR' ): array {
	return array(
		'license_key'    => $key,
		'product_slug'   => 'api-contract-product',
		'site_url'       => 'https://client.example.test/',
		'plugin_version' => '1.2.3',
		'wp_version'     => '7.0.0',
		'php_version'    => '8.3.12',
	);
}

global $wp_rest_server;
global $wpdb;

update_option( 'odph_settings', array( 'delete_on_uninstall' => 1 ), false );
Installer::uninstall();
Installer::activate();
$settings                        = get_option( 'odph_settings', array() );
$settings['api_rate_limit']      = 1000;
$settings['api_trusted_proxies'] = "10.0.0.0/8\n2001:db8:ffff::/48";
update_option( 'odph_settings', $settings, false );

$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
$wp_rest_server = new WP_REST_Server();
do_action( 'rest_api_init', $wp_rest_server );

$products      = new ProductRepository();
$customers     = new CustomerRepository();
$subscriptions = new SubscriptionRepository();
$licenses      = new LicenseRepository();
$api_logs      = new ApiLogRepository();

$product_id      = $products->create(
	array(
		'name'              => 'API Contract Product',
		'slug'              => 'api-contract-product',
		'description'       => 'Public description must not leak into license responses.',
		'stripe_product_id' => 'prod_api_contract',
		'stripe_price_id'   => 'price_api_contract',
		'status'            => 'active',
	)
);
$customer_id     = $customers->create(
	array(
		'wp_user_id'         => 1001,
		'stripe_customer_id' => 'cus_apicontract',
		'email'              => 'private-customer@example.test',
		'name'               => 'Private Customer',
	)
);
$subscription_id = $subscriptions->create(
	array(
		'customer_id'            => $customer_id,
		'product_id'             => $product_id,
		'stripe_subscription_id' => 'sub_apicontract',
		'stripe_status'          => 'active',
		'current_period_start'   => '2026-06-01 00:00:00',
		'current_period_end'     => '2026-07-01 00:00:00',
		'cancel_at_period_end'   => 0,
	)
);
$license_id      = $licenses->create(
	array(
		'product_id'       => $product_id,
		'customer_id'      => $customer_id,
		'subscription_id'  => $subscription_id,
		'license_key'      => 'ODPH-ABCD-EFGH-JKLM-NPQR',
		'license_key_hash' => LicenseGenerator::hash( 'ODPH-ABCD-EFGH-JKLM-NPQR' ),
		'status'           => 'active',
		'issued_at'        => UtcDateTime::now(),
	)
);

if ( ! defined( 'ODPH_RELEASE_STORAGE_PATH' ) ) {
	define( 'ODPH_RELEASE_STORAGE_PATH', sys_get_temp_dir() . '/odph-integration-releases' );
}
$package = sys_get_temp_dir() . '/odph-api-contract.zip';
$archive = new ZipArchive();
$archive->open( $package, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$archive->addFromString( 'api-contract/api-contract.php', "<?php\n/* Plugin Name: API Contract */\n" );
$archive->close();
$keypair    = sodium_crypto_sign_keypair();
$secret     = base64_encode( sodium_crypto_sign_secretkey( $keypair ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Integration fixture for binary key transport.
$release_id = ( new ReleaseService() )->publish(
	$package,
	array(
		'product_id'    => $product_id,
		'version'       => '2.0.0',
		'channel'       => 'stable',
		'plugin_file'   => 'api-contract/api-contract.php',
		'release_notes' => 'Integration release',
		'requires_wp'   => '6.9',
		'requires_php'  => '8.1',
	),
	$secret
);

$download_repository = new DownloadRepository();
$downloads_before    = $download_repository->search( array(), 1, 1 )->total;
foreach ( array( '2.0.0', '2.0.1' ) as $current_version ) {
	$no_update      = odph_api_dispatch( $wp_rest_server, 'POST', '/od-product-hub/v1/updates/check', array_merge( odph_api_license_params(), array( 'plugin_version' => $current_version ) ) );
	$no_update_data = $no_update->get_data();
	odph_api_assert( 200 === $no_update->get_status(), 'Same or newer client versions must produce HTTP 200', $no_update_data );
	odph_api_assert(
		array(
			'success'          => true,
			'update_available' => false,
		) === $no_update_data,
		'Same or newer client versions must receive the minimal no-update response',
		$no_update_data
	);
	odph_api_assert( $downloads_before === $download_repository->search( array(), 1, 1 )->total, 'No-update checks must not issue download grants' );
}

$malformed_update = odph_api_dispatch( $wp_rest_server, 'POST', '/od-product-hub/v1/updates/check', array_merge( odph_api_license_params(), array( 'plugin_version' => 'latest' ) ) );
odph_api_assert( 400 === $malformed_update->get_status() && 'rest_invalid_param' === $malformed_update->get_data()['code'], 'Malformed update versions must return WordPress REST 400', $malformed_update->get_data() );
$missing_version_params = odph_api_license_params();
unset( $missing_version_params['plugin_version'] );
$missing_version = odph_api_dispatch( $wp_rest_server, 'POST', '/od-product-hub/v1/updates/check', $missing_version_params );
odph_api_assert( 400 === $missing_version->get_status() && 'rest_missing_callback_param' === $missing_version->get_data()['code'], 'Update checks must require plugin_version', $missing_version->get_data() );
odph_api_assert( $downloads_before === $download_repository->search( array(), 1, 1 )->total, 'Invalid update versions must not issue download grants' );

$update      = odph_api_dispatch( $wp_rest_server, 'POST', '/od-product-hub/v1/updates/check', array_merge( odph_api_license_params(), array( 'channel' => 'stable' ) ) );
$update_data = $update->get_data();
odph_api_assert( 200 === $update->get_status() && true === $update_data['update_available'], 'Active contracts must receive update metadata', $update_data );
odph_api_assert( ! str_contains( (string) $update_data['release']['download_url'], 'ODPH-' ), 'Download URL must never expose the license key' );
odph_api_assert( $downloads_before + 1 === $download_repository->search( array(), 1, 1 )->total, 'An available release must issue exactly one download grant' );
$update_samples = array();
for ( $index = 0; $index < 7; $index++ ) {
	$started          = hrtime( true );
	$measured_update  = odph_api_dispatch( $wp_rest_server, 'POST', '/od-product-hub/v1/updates/check', odph_api_license_params() );
	$update_samples[] = ( hrtime( true ) - $started ) / 1_000_000;
	odph_api_assert( 200 === $measured_update->get_status(), 'Measured update checks must preserve behavior' );
}
sort( $update_samples );
odph_api_assert( $update_samples[6] < 500, 'Update-check p95 sample must remain below 500ms', $update_samples );
$token = basename( (string) wp_parse_url( (string) $update_data['release']['download_url'], PHP_URL_PATH ) );
$grant = ( new DownloadTokenService() )->validate( rawurldecode( $token ) );
odph_api_assert( null !== $grant && $release_id === $grant['release_id'], 'Issued download URL must contain a valid signed grant' );
odph_api_assert( $download_repository->claim( (int) $grant['grant']->id ), 'First download claim must succeed' );
odph_api_assert( ! $download_repository->claim( (int) $grant['grant']->id ), 'A download grant must be one-time use' );
$release = ( new \OD_Product_Hub\Release\ReleaseRepository() )->find( $release_id );
odph_api_assert( ( new PackageSigner() )->verify( (string) $release->package_path, (string) $release->sha256, (string) $release->signature, (string) $release->public_key ), 'Published package signature must verify' );
file_put_contents( (string) $release->package_path, 'tampered' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Tamper fixture.
odph_api_assert( ! ( new PackageSigner() )->verify( (string) $release->package_path, (string) $release->sha256, (string) $release->signature, (string) $release->public_key ), 'Tampered packages must be rejected' );

$options      = odph_api_dispatch( $wp_rest_server, 'OPTIONS', '/od-product-hub/v1/verify' );
$options_data = $options->get_data();
odph_api_assert( 200 === $options->get_status(), 'OPTIONS must succeed' );
odph_api_assert( isset( $options_data['endpoints'][0]['args']['license_key']['pattern'] ), 'OPTIONS must expose the license key JSON Schema', $options_data );
odph_api_assert( isset( $options_data['endpoints'][0]['args']['site_url']['format'] ), 'OPTIONS must expose URL format validation' );
$product_options      = odph_api_dispatch( $wp_rest_server, 'OPTIONS', '/od-product-hub/v1/product' );
$product_options_data = $product_options->get_data();
odph_api_assert( isset( $product_options_data['endpoints'][0]['args']['product_slug']['pattern'] ), 'Product OPTIONS must expose slug schema' );

$verify      = odph_api_dispatch( $wp_rest_server, 'POST', '/od-product-hub/v1/verify', odph_api_license_params() );
$verify_data = $verify->get_data();
odph_api_assert( 200 === $verify->get_status() && true === $verify_data['success'] && 'active' === $verify_data['status'], 'Active verification response contract must remain stable', $verify_data );
odph_api_assert( 'ODPH-ABCD-****-****-NPQR' === $verify_data['license']['key_masked'], 'Only a masked key may be returned' );
odph_api_assert( str_ends_with( (string) $verify_data['checked_at'], '+00:00' ), 'Response timestamps must be ISO 8601 UTC' );
$serialized_verify = (string) wp_json_encode( $verify_data );
foreach ( array( 'private-customer@example.test', 'Private Customer', 'cus_apicontract', 'sub_apicontract', 'prod_api_contract', 'price_api_contract' ) as $private_value ) {
	odph_api_assert( ! str_contains( $serialized_verify, $private_value ), 'Response must omit personal data and Stripe IDs', $private_value );
}
$touched_license = $licenses->find( $license_id );
odph_api_assert( ! empty( $touched_license->last_verified_at ), 'Successful verification must update last_verified_at' );
odph_api_assert(
	1 === $api_logs->search(
		array(
			'license_id' => $license_id,
			'action'     => 'verify',
			'result'     => 'success',
		),
		1,
		10
	)->total,
	'Successful verification must create one API log'
);

$last_verified_before_deactivate = (string) $touched_license->last_verified_at;
$deactivate                      = odph_api_dispatch( $wp_rest_server, 'POST', '/od-product-hub/v1/deactivate', odph_api_license_params() );
$deactivate_data                 = $deactivate->get_data();
odph_api_assert( 200 === $deactivate->get_status() && true === $deactivate_data['deactivated'] && 'active' === $deactivate_data['status'], 'Deactivate must record the site event while retaining active status', $deactivate_data );
odph_api_assert( 'active' === $licenses->find( $license_id )->status, 'Deactivate must never disable the license key' );
odph_api_assert( $last_verified_before_deactivate === $licenses->find( $license_id )->last_verified_at, 'Deactivate must not pretend to verify or touch the license' );
odph_api_assert(
	1 === $api_logs->search(
		array(
			'license_id' => $license_id,
			'action'     => 'deactivate',
			'result'     => 'success',
		),
		1,
		10
	)->total,
	'Deactivate must create a log entry'
);

$state_cases = array(
	array( 'suspended', 'active', null, null, 'suspended', 'license_suspended' ),
	array( 'expired', 'active', null, null, 'expired', 'license_expired' ),
	array( 'cancelled', 'active', null, null, 'cancelled', 'license_cancelled' ),
	array( 'inactive', 'active', null, null, 'inactive', 'license_inactive' ),
	array( 'active', 'past_due', '2026-06-30 00:00:00', null, 'inactive', 'payment_failed' ),
	array( 'active', 'canceled', null, null, 'cancelled', 'license_cancelled' ),
	array( 'active', 'incomplete', null, null, 'inactive', 'subscription_inactive' ),
	array( 'active', 'active', null, 'inactive', 'inactive', 'product_inactive' ),
);
foreach ( $state_cases as list( $license_status, $stripe_status, $payment_failed_at, $product_status, $expected_status, $expected_code ) ) {
	$licenses->update(
		$license_id,
		array(
			'status'     => $license_status,
			'expires_at' => null,
		)
	);
	$subscriptions->update(
		$subscription_id,
		array(
			'stripe_status'     => $stripe_status,
			'payment_failed_at' => $payment_failed_at,
		)
	);
	$products->update( $product_id, array( 'status' => $product_status ?? 'active' ) );
	$response = odph_api_dispatch( $wp_rest_server, 'POST', '/od-product-hub/v1/verify', odph_api_license_params() );
	$data     = $response->get_data();
	odph_api_assert( 200 === $response->get_status() && false === $data['success'], 'Business state failures must use the stable HTTP 200 contract', $data );
	odph_api_assert( $expected_status === $data['status'] && $expected_code === $data['error_code'], 'State service must return the expected status and error code', $data );
}

$licenses->update(
	$license_id,
	array(
		'status'     => 'active',
		'expires_at' => '2020-01-01 00:00:00',
	)
);
$subscriptions->update(
	$subscription_id,
	array(
		'stripe_status'     => 'active',
		'payment_failed_at' => null,
	)
);
$products->update( $product_id, array( 'status' => 'active' ) );
$expired_by_date = odph_api_dispatch( $wp_rest_server, 'POST', '/od-product-hub/v1/verify', odph_api_license_params() );
odph_api_assert( 'expired' === $expired_by_date->get_data()['status'] && 'license_expired' === $expired_by_date->get_data()['error_code'], 'A past expires_at must resolve to the expired contract state' );
$expired_update = odph_api_dispatch( $wp_rest_server, 'POST', '/od-product-hub/v1/updates/check', odph_api_license_params() );
odph_api_assert( 403 === $expired_update->get_status() && false === $expired_update->get_data()['success'], 'Expired contracts must not receive update metadata' );

$licenses->update(
	$license_id,
	array(
		'status'     => 'suspended',
		'expires_at' => null,
	)
);
$subscriptions->update(
	$subscription_id,
	array(
		'stripe_status'     => 'active',
		'payment_failed_at' => null,
	)
);
$products->update( $product_id, array( 'status' => 'active' ) );
$licenses->set_status_preserving_suspended( $subscription_id, 'active' );
odph_api_assert( 'suspended' === $licenses->find( $license_id )->status, 'Stripe synchronization must never clear an administrative suspension' );
$licenses->update( $license_id, array( 'status' => 'active' ) );

$logs_before_invalid = $api_logs->search( array(), 1, 1 )->total;
$invalid_requests    = array(
	odph_api_license_params( 'odph-abcd-efgh-jklm-npqr' ),
	array_merge( odph_api_license_params(), array( 'site_url' => 'https://user:pass@client.example.test/' ) ),
	array_merge( odph_api_license_params(), array( 'plugin_version' => 'latest' ) ),
	array_merge( odph_api_license_params(), array( 'product_slug' => '../invalid' ) ),
);
foreach ( $invalid_requests as $invalid_params ) {
	$invalid      = odph_api_dispatch( $wp_rest_server, 'POST', '/od-product-hub/v1/verify', $invalid_params );
	$invalid_data = $invalid->get_data();
	odph_api_assert( 400 === $invalid->get_status() && 'rest_invalid_param' === $invalid_data['code'], 'Invalid JSON Schema input must return WordPress REST 400', $invalid_data );
}
odph_api_assert( $logs_before_invalid === $api_logs->search( array(), 1, 1 )->total, 'Schema failures must not create unbounded API logs' );

$missing      = odph_api_dispatch( $wp_rest_server, 'POST', '/od-product-hub/v1/verify', odph_api_license_params( 'ODPH-BCDE-FGHJ-KLMN-PQRS' ) );
$missing_data = $missing->get_data();
odph_api_assert( 200 === $missing->get_status() && 'invalid_license' === $missing_data['error_code'], 'A well-formed unknown key must use the business error contract' );

$product      = odph_api_dispatch( $wp_rest_server, 'GET', '/od-product-hub/v1/product', array( 'product_slug' => 'api-contract-product' ) );
$product_data = $product->get_data();
odph_api_assert( 200 === $product->get_status() && true === $product_data['success'] && 'active' === $product_data['product']['status'], 'Active product contract must succeed', $product_data );
$products->update( $product_id, array( 'status' => 'inactive' ) );
$missing_product = odph_api_dispatch( $wp_rest_server, 'GET', '/od-product-hub/v1/product', array( 'product_slug' => 'api-contract-product' ) );
odph_api_assert( 404 === $missing_product->get_status() && 'product_not_found' === $missing_product->get_data()['error_code'], 'Inactive products must use the public 404 contract' );
$products->update( $product_id, array( 'status' => 'active' ) );

$resolver = new ClientIpResolver();
odph_api_assert(
	'198.51.100.20' === $resolver->resolve(
		array(
			'REMOTE_ADDR'          => '10.1.2.3',
			'HTTP_X_FORWARDED_FOR' => '198.51.100.20, 10.2.3.4',
		)
	),
	'Trusted proxy chains must resolve the first untrusted client from the right'
);
odph_api_assert(
	'192.0.2.55' === $resolver->resolve(
		array(
			'REMOTE_ADDR'          => '192.0.2.55',
			'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
		)
	),
	'Untrusted peers must never be allowed to spoof X-Forwarded-For'
);

$settings['api_rate_limit'] = 2;
update_option( 'odph_settings', $settings, false );
$_SERVER['REMOTE_ADDR'] = '203.0.113.200';
unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
$rate_log_before = $api_logs->search( array( 'action' => 'verify' ), 1, 1 )->total;
$rate_one        = odph_api_dispatch( $wp_rest_server, 'POST', '/od-product-hub/v1/verify', odph_api_license_params() );
$rate_two        = odph_api_dispatch( $wp_rest_server, 'POST', '/od-product-hub/v1/verify', odph_api_license_params() );
$rate_three      = odph_api_dispatch( $wp_rest_server, 'POST', '/od-product-hub/v1/verify', odph_api_license_params() );
odph_api_assert( 200 === $rate_one->get_status() && 200 === $rate_two->get_status(), 'Requests within the limit must succeed' );
odph_api_assert( 429 === $rate_three->get_status() && 'rate_limited' === $rate_three->get_data()['error_code'], 'Excess requests must return the stable 429 body' );
odph_api_assert( 0 < (int) $rate_three->get_headers()['Retry-After'], '429 must include Retry-After' );
odph_api_assert( '0' === $rate_three->get_headers()['X-RateLimit-Remaining'], '429 must expose zero remaining requests' );
odph_api_assert( $rate_log_before + 2 === $api_logs->search( array( 'action' => 'verify' ), 1, 1 )->total, 'Rate-limited requests must not flood API logs' );

$settings['api_rate_limit'] = 1000;
update_option( 'odph_settings', $settings, false );
$_SERVER['REMOTE_ADDR'] = '198.51.100.77';
$samples                = array();
for ( $index = 0; $index < 7; $index++ ) {
	$started   = hrtime( true );
	$measured  = odph_api_dispatch( $wp_rest_server, 'POST', '/od-product-hub/v1/verify', odph_api_license_params() );
	$samples[] = ( hrtime( true ) - $started ) / 1_000_000;
	odph_api_assert( 200 === $measured->get_status(), 'Measured verification must preserve behavior' );
}
sort( $samples );
$median_ms = $samples[ intdiv( count( $samples ), 2 ) ];
odph_api_assert( $median_ms < 500, 'Median verification time must remain below the 500ms target', $samples );

$stored_log = $wpdb->get_row( $wpdb->prepare( 'SELECT site_url, ip_address, user_agent FROM %i ORDER BY id DESC LIMIT 1', $wpdb->prefix . 'odph_api_logs' ) );
odph_api_assert( is_object( $stored_log ) && strlen( (string) $stored_log->user_agent ) <= 500, 'API log fields must be bounded' );
odph_api_assert( 'https://client.example.test/' === $stored_log->site_url, 'Sanitized site URL must be logged' );

update_option( 'odph_settings', array( 'delete_on_uninstall' => 1 ), false );
Installer::uninstall();
Installer::activate();
WP_CLI::success( sprintf( 'REST contract, schema, state, proxy, rate-limit, logging, and performance checks passed (median %.2fms).', $median_ms ) );
