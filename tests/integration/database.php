<?php
/**
 * Database integration checks for wp-env's isolated tests environment.
 *
 * Run with: npm run test:database
 *
 * @package OD_Product_Hub
 */

use OD_Product_Hub\Customer\CustomerRepository;
use OD_Product_Hub\Customer\CustomerSyncService;
use OD_Product_Hub\Database\DatabaseException;
use OD_Product_Hub\Database\Installer;
use OD_Product_Hub\Database\Schema;
use OD_Product_Hub\Database\UtcDateTime;
use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Log\AdminLogRepository;
use OD_Product_Hub\Log\ApiLogRepository;
use OD_Product_Hub\Log\WebhookLogRepository;
use OD_Product_Hub\Product\ProductRepository;
use OD_Product_Hub\Subscription\SubscriptionRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration check must run via WP-CLI.' );
}

/** @param mixed $actual */
function odph_test_assert( bool $condition, string $message, $actual = null ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message . ( null === $actual ? '' : ': ' . wp_json_encode( $actual ) ) );
	}
}

global $wpdb;

// Start from a known empty schema in the disposable tests environment.
update_option( 'odph_settings', array( 'delete_on_uninstall' => 1 ), false );
Installer::uninstall();
Installer::activate();

odph_test_assert( Schema::VERSION === get_option( 'odph_schema_version' ), 'Fresh activation must save the latest schema version', get_option( 'odph_schema_version' ) );
foreach ( Schema::table_suffixes() as $suffix ) {
	$table = $wpdb->prefix . 'odph_' . $suffix;
	odph_test_assert( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'dbDelta must create ' . $suffix );
}

$product_repository      = new ProductRepository();
$customer_repository     = new CustomerRepository();
$subscription_repository = new SubscriptionRepository();
$license_repository      = new LicenseRepository();
$webhook_repository      = new WebhookLogRepository();
$api_repository          = new ApiLogRepository();
$admin_repository        = new AdminLogRepository();

$product_id      = $product_repository->create(
	array(
		'name'              => 'Integration Product',
		'slug'              => 'integration-product',
		'description'       => '',
		'stripe_product_id' => 'prod_integration',
		'stripe_price_id'   => 'price_integration',
		'status'            => 'active',
	)
);
$sync_service    = new CustomerSyncService();
$customer_id     = $sync_service->upsert_customer( 'cus_integration', 1, 'integration@example.test', 'Integration Customer' );
$subscription    = (object) array(
	'id'                   => 'sub_integration',
	'status'               => 'active',
	'current_period_start' => 1767225600,
	'current_period_end'   => 1769904000,
	'cancel_at_period_end' => false,
);
$subscription_id = $sync_service->upsert_subscription( $customer_id, $product_id, $subscription );
$license_id      = $license_repository->create(
	array(
		'product_id'       => $product_id,
		'customer_id'      => $customer_id,
		'subscription_id'  => $subscription_id,
		'license_key'      => 'ODPH-ABCD-EFGH-JKLM-NPQR',
		'license_key_hash' => hash( 'sha256', 'integration-license' ),
		'status'           => 'active',
		'issued_at'        => UtcDateTime::now(),
	)
);
$webhook_id      = $webhook_repository->create(
	array(
		'stripe_event_id' => 'evt_integration',
		'event_type'      => 'test.event',
		'payload'         => '{}',
		'result'          => 'success',
	)
);
$api_id          = $api_repository->create(
	array(
		'license_id' => $license_id,
		'product_id' => $product_id,
		'action'     => 'verify',
		'result'     => 'success',
	)
);
$admin_id        = $admin_repository->create(
	array(
		'user_id'     => 1,
		'action'      => 'integration_test',
		'object_type' => 'product',
		'object_id'   => $product_id,
	)
);

$repositories = array(
	array( $product_repository, $product_id, 'status', 'inactive' ),
	array( $customer_repository, $customer_id, 'name', 'Updated Customer' ),
	array( $subscription_repository, $subscription_id, 'stripe_status', 'trialing' ),
	array( $license_repository, $license_id, 'status', 'suspended' ),
	array( $webhook_repository, $webhook_id, 'result', 'updated' ),
	array( $api_repository, $api_id, 'result', 'updated' ),
	array( $admin_repository, $admin_id, 'action', 'updated' ),
);
foreach ( $repositories as list( $repository, $row_id, $column, $value ) ) {
	odph_test_assert( null !== $repository->find( $row_id ), 'Repository create/find failed for ' . get_class( $repository ) );
	odph_test_assert( $repository->update( $row_id, array( $column => $value ) ), 'Repository update failed for ' . get_class( $repository ) );
	odph_test_assert( 1 === $repository->search( array( $column => $value ), 1, 10 )->total, 'Repository search/pagination failed for ' . get_class( $repository ) );
}

$duplicate_rejected = false;
try {
	$product_repository->create(
		array(
			'name'              => 'Duplicate Product',
			'slug'              => 'integration-product',
			'description'       => '',
			'stripe_product_id' => 'prod_duplicate',
			'stripe_price_id'   => 'price_duplicate',
			'status'            => 'active',
		)
	);
} catch ( DatabaseException $error ) {
	$duplicate_rejected = true;
}
odph_test_assert( $duplicate_rejected, 'Unique constraints must be surfaced as a safe DatabaseException' );
odph_test_assert( 1 === $customer_repository->search_admin( 'integration@', 1, 20 )->total, 'Customer email search must find the synchronized customer' );
odph_test_assert( 1 === $subscription_repository->search_admin( 'trialing', 1, 20 )->total, 'Subscription status filtering must use synchronized state' );
odph_test_assert( 1 === count( $subscription_repository->find_for_customer( $customer_id ) ), 'Customer detail must include subscriptions' );
odph_test_assert( 1 === count( $license_repository->find_for_customer( $customer_id ) ), 'Customer detail must include licenses' );
odph_test_assert( 1 === count( $api_repository->find_for_customer( $customer_id ) ), 'Customer detail must include API logs through owned licenses' );

$subscriber_id = wp_insert_user(
	array(
		'user_login' => 'odph-customer-boundary',
		'user_email' => 'boundary@example.test',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'subscriber',
	)
);
odph_test_assert( ! is_wp_error( $subscriber_id ), 'Subscriber fixture must be created' );
wp_set_current_user( (int) $subscriber_id );
odph_test_assert( ! current_user_can( 'manage_options' ), 'Customers must not cross the admin capability boundary' );
wp_set_current_user( 1 );
odph_test_assert( current_user_can( 'manage_options' ), 'Administrators must retain customer management access' );
wp_delete_user( (int) $subscriber_id );

// A migration from the previous version must preserve existing data.
update_option( 'odph_schema_version', '1.0.0', false );
Installer::migrate();
odph_test_assert( null !== $product_repository->find( $product_id ), 'Incremental migration must preserve existing rows' );
odph_test_assert( Schema::VERSION === get_option( 'odph_schema_version' ), 'Incremental migration must update the schema version' );

update_option( 'timezone_string', 'Asia/Tokyo', false );
odph_test_assert( '2026-01-01 09:00:00' === UtcDateTime::to_site( '2026-01-01 00:00:00' ), 'UTC values must be converted only for site-time display' );

// Uninstall must preserve data unless explicitly enabled.
update_option( 'odph_settings', array( 'delete_on_uninstall' => 0 ), false );
Installer::uninstall();
odph_test_assert( null !== $product_repository->find( $product_id ), 'Uninstall must preserve data when deletion is disabled' );

foreach ( array_reverse( $repositories ) as list( $repository, $row_id ) ) {
	odph_test_assert( $repository->delete( $row_id ), 'Repository delete failed for ' . get_class( $repository ) );
	odph_test_assert( null === $repository->find( $row_id ), 'Deleted row is still readable for ' . get_class( $repository ) );
}
$product_repository->create(
	array(
		'name'              => 'Uninstall Sentinel',
		'slug'              => 'uninstall-sentinel',
		'description'       => '',
		'stripe_product_id' => 'prod_uninstall',
		'stripe_price_id'   => 'price_uninstall',
		'status'            => 'active',
	)
);

update_option( 'odph_settings', array( 'delete_on_uninstall' => 1 ), false );
Installer::uninstall();
$products_table = $wpdb->prefix . 'odph_products';
odph_test_assert( $products_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $products_table ) ), 'Uninstall must drop data when deletion is enabled' );

// Leave the tests environment usable for subsequent checks.
Installer::activate();
WP_CLI::success( 'Database migration, repository, uniqueness, timezone, and uninstall checks passed.' );
