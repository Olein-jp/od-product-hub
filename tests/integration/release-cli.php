<?php
/**
 * Release WP-CLI publication and withdrawal integration checks.
 *
 * @package OD_Product_Hub
 */

use OD_Product_Hub\API\RestController;
use OD_Product_Hub\CLI\ReleaseCommand;
use OD_Product_Hub\Customer\CustomerRepository;
use OD_Product_Hub\Database\Installer;
use OD_Product_Hub\Database\UtcDateTime;
use OD_Product_Hub\License\LicenseGenerator;
use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Log\AdminLogRepository;
use OD_Product_Hub\Product\ProductRepository;
use OD_Product_Hub\Release\DownloadRepository;
use OD_Product_Hub\Release\DownloadTokenService;
use OD_Product_Hub\Release\ReleaseRepository;
use OD_Product_Hub\Release\ReleaseService;
use OD_Product_Hub\Subscription\SubscriptionRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration check must run via WP-CLI.' );
}

/** @param mixed $actual */
function odph_release_cli_assert( bool $condition, string $message, $actual = null ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message . ( null === $actual ? '' : ': ' . wp_json_encode( $actual ) ) );
	}
}

/** @param array<string, mixed> $params */
function odph_release_cli_request( WP_REST_Server $server, array $params ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/od-product-hub/v1/updates/check' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( (string) wp_json_encode( $params ) );
	return $server->dispatch( $request );
}

update_option( 'odph_settings', array( 'delete_on_uninstall' => 1 ), false );
Installer::uninstall();
Installer::activate();
$settings                      = get_option( 'odph_settings', array() );
$settings['update_rate_limit'] = 1000;
update_option( 'odph_settings', $settings, false );

$storage = sys_get_temp_dir() . '/odph-release-cli-' . wp_generate_uuid4();
define( 'ODPH_RELEASE_STORAGE_PATH', $storage );
$package = sys_get_temp_dir() . '/odph-release-cli.zip';
$archive = new ZipArchive();
$archive->open( $package, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$archive->addFromString( 'release-cli/release-cli.php', "<?php\n/* Plugin Name: Release CLI */\n" );
$archive->close();

$products        = new ProductRepository();
$product_id      = $products->create(
	array(
		'name'              => 'Release CLI Product',
		'slug'              => 'release-cli-product',
		'description'       => '',
		'stripe_product_id' => 'prod_release_cli',
		'stripe_price_id'   => 'price_release_cli',
		'status'            => 'active',
	)
);
$customers       = new CustomerRepository();
$customer_id     = $customers->create(
	array(
		'wp_user_id'         => 2001,
		'stripe_customer_id' => 'cus_release_cli',
		'email'              => 'release-cli@example.test',
		'name'               => 'Release CLI',
	)
);
$subscriptions   = new SubscriptionRepository();
$subscription_id = $subscriptions->create(
	array(
		'customer_id'            => $customer_id,
		'product_id'             => $product_id,
		'stripe_subscription_id' => 'sub_release_cli',
		'stripe_status'          => 'active',
		'current_period_start'   => '2026-07-01 00:00:00',
		'current_period_end'     => '2026-08-01 00:00:00',
		'cancel_at_period_end'   => 0,
	)
);
$license_key     = 'ODPH-ABCD-EFGH-JKLM-NPQR';
( new LicenseRepository() )->create(
	array(
		'product_id'       => $product_id,
		'customer_id'      => $customer_id,
		'subscription_id'  => $subscription_id,
		'license_key'      => $license_key,
		'license_key_hash' => LicenseGenerator::hash( $license_key ),
		'status'           => 'active',
		'issued_at'        => UtcDateTime::now(),
	)
);

$keypair    = sodium_crypto_sign_keypair();
$secret     = base64_encode( sodium_crypto_sign_secretkey( $keypair ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Ephemeral integration key.
$key_filter = static fn(): string => $secret;
add_filter( 'odph_release_private_key', $key_filter );

$command = new ReleaseCommand();
$command->publish(
	array( $package ),
	array(
		'product'      => 'release-cli-product',
		'version'      => '1.2.0',
		'channel'      => 'stable',
		'plugin-file'  => 'release-cli/release-cli.php',
		'requires-wp'  => '7.0',
		'requires-php' => '8.3',
		'notes'        => 'CLI integration release',
	)
);

$releases = new ReleaseRepository();
$release  = $releases->find_by_identity( $product_id, '1.2.0', 'stable' );
odph_release_cli_assert( null !== $release && 'published' === $release->status, 'CLI publish must create a published release' );
odph_release_cli_assert( ! str_contains( (string) wp_json_encode( $release ), $secret ), 'Release persistence must not contain the private key' );
$command->list_(
	array(),
	array(
		'product' => 'release-cli-product',
		'format'  => 'json',
	)
);
$command->show( array( (string) $release->id ), array( 'format' => 'json' ) );

global $wp_rest_server;
$wp_rest_server = new WP_REST_Server();
( new RestController() )->routes();
do_action( 'rest_api_init', $wp_rest_server );
$params    = array(
	'license_key'    => $license_key,
	'product_slug'   => 'release-cli-product',
	'site_url'       => 'https://release-client.example.test/',
	'plugin_version' => '1.0.0',
	'wp_version'     => '7.0',
	'php_version'    => '8.3',
	'channel'        => 'stable',
);
$available = odph_release_cli_request( $wp_rest_server, $params );
odph_release_cli_assert( true === $available->get_data()['update_available'], 'Published CLI release must be returned by updates/check', $available->get_data() );
$downloads = new DownloadRepository();
$grant     = $downloads->search(
	array(
		'release_id' => $release->id,
		'result'     => 'issued',
	),
	1,
	1
)->items[0] ?? null;
odph_release_cli_assert( null !== $grant, 'Update check must issue a download grant before withdrawal' );
$token = basename( (string) wp_parse_url( (string) $available->get_data()['release']['download_url'], PHP_URL_PATH ) );

$command->withdraw( array( (string) $release->id ), array() );
$withdrawn = $releases->find( (int) $release->id );
odph_release_cli_assert( 'withdrawn' === $withdrawn->status, 'CLI withdraw must stop the release' );
odph_release_cli_assert( 'rejected' === $downloads->find( (int) $grant->id )->result, 'Withdrawal must revoke unused grants' );
odph_release_cli_assert( 0 === ( new ReleaseService() )->withdraw( (int) $release->id ), 'Withdrawal must be safe to retry after tokens are revoked' );
$token_service = new DownloadTokenService();
odph_release_cli_assert( null === $token_service->validate( rawurldecode( $token ) ), 'Withdrawal must invalidate an already issued token' );
$unavailable = odph_release_cli_request( $wp_rest_server, $params );
odph_release_cli_assert( false === $unavailable->get_data()['update_available'], 'Withdrawn release must disappear from updates/check', $unavailable->get_data() );

$stored_zips        = glob( $storage . '/*.zip' );
$zip_count          = is_array( $stored_zips ) ? count( $stored_zips ) : 0;
$duplicate_rejected = false;
try {
	( new ReleaseService() )->publish(
		$package,
		array(
			'product_id'  => $product_id,
			'version'     => '1.2.0',
			'channel'     => 'stable',
			'plugin_file' => 'release-cli/release-cli.php',
		),
		$secret
	);
} catch ( DomainException $error ) {
	unset( $error );
	$duplicate_rejected = true;
}
$stored_zips = glob( $storage . '/*.zip' );
odph_release_cli_assert( true === $duplicate_rejected && ( is_array( $stored_zips ) ? count( $stored_zips ) : 0 ) === $zip_count, 'Duplicate publication must be rejected before copying a ZIP' );

$downgrade_rejected = false;
try {
	( new ReleaseService() )->publish(
		$package,
		array(
			'product_id'  => $product_id,
			'version'     => '1.1.0',
			'channel'     => 'stable',
			'plugin_file' => 'release-cli/release-cli.php',
		),
		$secret
	);
} catch ( DomainException $error ) {
	unset( $error );
	$downgrade_rejected = true;
}
odph_release_cli_assert( true === $downgrade_rejected, 'A channel must not publish an older version after a newer one' );

$signing_failed = false;
try {
	( new ReleaseService() )->publish(
		$package,
		array(
			'product_id'  => $product_id,
			'version'     => '1.3.0',
			'channel'     => 'stable',
			'plugin_file' => 'release-cli/release-cli.php',
		),
		'invalid-key'
	);
} catch ( InvalidArgumentException $error ) {
	unset( $error );
	$signing_failed = true;
}
$stored_zips = glob( $storage . '/*.zip' );
odph_release_cli_assert( true === $signing_failed && ( is_array( $stored_zips ) ? count( $stored_zips ) : 0 ) === $zip_count, 'Signing failure must roll back the copied ZIP' );

$invalid_metadata = array(
	array(
		'version'     => 'latest',
		'channel'     => 'stable',
		'plugin_file' => 'release-cli/release-cli.php',
	),
	array(
		'version'     => '1.4.0',
		'channel'     => 'nightly',
		'plugin_file' => 'release-cli/release-cli.php',
	),
	array(
		'version'     => '1.4.0',
		'channel'     => 'stable',
		'plugin_file' => '../release-cli.php',
	),
	array(
		'version'     => '1.4.0',
		'channel'     => 'stable',
		'plugin_file' => 'release-cli/release-cli.php',
		'requires_wp' => 'latest',
	),
);
foreach ( $invalid_metadata as $metadata ) {
	$invalid_rejected = false;
	try {
		( new ReleaseService() )->publish( $package, array_merge( array( 'product_id' => $product_id ), $metadata ), $secret );
	} catch ( InvalidArgumentException $error ) {
		unset( $error );
		$invalid_rejected = true;
	}
	odph_release_cli_assert( true === $invalid_rejected, 'Invalid release metadata must be rejected before publication', $metadata );
}

$unsafe_package = sys_get_temp_dir() . '/odph-release-cli-unsafe.zip';
$unsafe_archive = new ZipArchive();
$unsafe_archive->open( $unsafe_package, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$unsafe_archive->addFromString( 'release-cli/release-cli.php', "<?php\n/* Plugin Name: Unsafe */\n" );
$unsafe_archive->addFromString( '../outside.php', '<?php' );
$unsafe_archive->close();
$unsafe_rejected = false;
try {
	( new ReleaseService() )->publish(
		$unsafe_package,
		array(
			'product_id'  => $product_id,
			'version'     => '1.4.0',
			'channel'     => 'stable',
			'plugin_file' => 'release-cli/release-cli.php',
		),
		$secret
	);
} catch ( InvalidArgumentException $error ) {
	unset( $error );
	$unsafe_rejected = true;
}
odph_release_cli_assert( true === $unsafe_rejected, 'ZIP path traversal entries must be rejected' );

$audit_rows = ( new AdminLogRepository() )->search( array( 'object_type' => 'release' ), 1, 20 )->items;
odph_release_cli_assert( 4 === count( $audit_rows ), 'Publish, list, show, and withdraw must each create an audit log' );
odph_release_cli_assert( ! str_contains( (string) wp_json_encode( $audit_rows ), $secret ), 'Audit logs must never contain the private key' );

remove_filter( 'odph_release_private_key', $key_filter );
update_option( 'odph_settings', array( 'delete_on_uninstall' => 1 ), false );
Installer::uninstall();
Installer::activate();
WP_CLI::success( 'Release CLI publish, inspect, withdrawal, rollback, and audit checks passed.' );
