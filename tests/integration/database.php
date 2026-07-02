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
use OD_Product_Hub\License\LicenseGenerator;
use OD_Product_Hub\License\LicenseManager;
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
$webhook_columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $wpdb->prefix . 'odph_webhook_logs' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fixed integration-test table name.
odph_test_assert( in_array( 'attempt_count', $webhook_columns, true ) && in_array( 'last_attempt_at', $webhook_columns, true ), 'Webhook retry migration must create attempt tracking columns' );

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
	'current_period_start' => time() - DAY_IN_SECONDS,
	'current_period_end'   => time() + ( 30 * DAY_IN_SECONDS ),
	'cancel_at_period_end' => false,
);
$subscription_id = $sync_service->upsert_subscription( $customer_id, $product_id, $subscription );
$license_id      = $license_repository->create(
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
$product_page = $product_repository->search_admin( 'Integration Product', 'inactive', 1, 20 );
odph_test_assert( 1 === $product_page->total && 1 === count( $product_page->items ), 'Product COUNT and SELECT must share query and status conditions' );
$customer_page = $customer_repository->search_admin( 'integration@', 1, 20 );
odph_test_assert( 1 === $customer_page->total && 1 === count( $customer_page->items ), 'Customer COUNT and SELECT must share email conditions' );
$subscription_page = $subscription_repository->search_admin( 'trialing', 1, 20 );
odph_test_assert( 1 === $subscription_page->total && 1 === count( $subscription_page->items ), 'Subscription COUNT and joined SELECT must share status conditions' );
odph_test_assert( isset( $subscription_page->items[0]->customer_email, $subscription_page->items[0]->product_name ), 'Joined pagination must preserve subscription display fields' );
$normalized_page = $product_repository->search_admin( '', '', 0, 0 );
odph_test_assert( 1 === $normalized_page->page && 1 === $normalized_page->per_page, 'Pagination must normalize page and per-page lower bounds' );
$maximum_page = $product_repository->search_admin( '', '', 1, 10000 );
odph_test_assert( 100 === $maximum_page->per_page, 'Pagination must enforce the maximum per-page count' );
$past_end_page = $product_repository->search_admin( '', '', 999, 20 );
odph_test_assert( 1 === $past_end_page->total && array() === $past_end_page->items, 'Pagination past the final page must preserve total and return no items' );
$empty_page = $product_repository->search_admin( 'missing-product', 'active', 1, 20 );
odph_test_assert( 0 === $empty_page->total && array() === $empty_page->items && 0 === $empty_page->total_pages, 'Empty pagination results must remain consistent' );
$injection_page = $product_repository->search_admin( "' OR 1=1 --", '', 1, 20 );
odph_test_assert( 0 === $injection_page->total && array() === $injection_page->items, 'Search values must not alter prepared SQL conditions' );
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

$license_manager = new LicenseManager();
$license_page    = $license_repository->search_admin( LicenseGenerator::hash( 'ODPH-ABCD-EFGH-JKLM-NPQR' ), 'suspended' );
odph_test_assert( 1 === $license_page->total && 1 === count( $license_page->items ), 'License COUNT and joined SELECT must share key hash and status conditions' );
odph_test_assert( isset( $license_page->items[0]->product_name, $license_page->items[0]->customer_email ), 'Joined pagination must preserve license display fields' );
odph_test_assert( 1 === count( $api_repository->find_for_license( $license_id ) ), 'License detail must include its authentication logs' );
$nonce = wp_create_nonce( 'odph_license_suspend_' . $license_id );
odph_test_assert( 1 === wp_verify_nonce( $nonce, 'odph_license_suspend_' . $license_id ), 'License operation nonce must verify only for its action and object' );
odph_test_assert( false === wp_verify_nonce( $nonce, 'odph_license_resume_' . $license_id ), 'License operation nonce must not verify for another action' );

$license_manager->resume( $license_id, 1 );
odph_test_assert( 'active' === $license_repository->find( $license_id )->status, 'A suspended license with an active subscription must resume' );
$license_manager->suspend( $license_id, 1 );
odph_test_assert( 'suspended' === $license_repository->find( $license_id )->status, 'Suspension must persist' );
odph_test_assert(
	1 === $admin_repository->search(
		array(
			'action'    => 'license_suspended',
			'object_id' => $license_id,
		),
		1,
		10
	)->total,
	'Suspension must create an admin log'
);

$subscription_repository->update( $subscription_id, array( 'stripe_status' => 'unpaid' ) );
$resume_log_count        = $admin_repository->search(
	array(
		'action'    => 'license_resumed',
		'object_id' => $license_id,
	),
	1,
	10
)->total;
$invalid_resume_rejected = false;
try {
	$license_manager->resume( $license_id, 1 );
} catch ( DomainException $error ) {
	$invalid_resume_rejected = true;
}
odph_test_assert( $invalid_resume_rejected, 'Resume must reject an inactive Stripe subscription' );
odph_test_assert( 'suspended' === $license_repository->find( $license_id )->status, 'Rejected resume must roll back the license state' );
odph_test_assert(
	$resume_log_count === $admin_repository->search(
		array(
			'action'    => 'license_resumed',
			'object_id' => $license_id,
		),
		1,
		10
	)->total,
	'Rejected resume must not leave an admin log'
);
	$subscription_repository->update( $subscription_id, array( 'stripe_status' => 'active' ) );

	$other_product_id   = $product_repository->create(
		array(
			'name'              => 'Other Integration Product',
			'slug'              => 'other-integration-product',
			'description'       => '',
			'stripe_product_id' => 'prod_other_integration',
			'stripe_price_id'   => 'price_other_integration',
			'status'            => 'active',
		)
	);
	$collision_key      = 'ODPH-BCDE-FGHJ-KLMN-PQRS';
	$collision_id       = $license_repository->create(
		array(
			'product_id'       => $other_product_id,
			'customer_id'      => $customer_id,
			'subscription_id'  => $subscription_id,
			'license_key'      => $collision_key,
			'license_key_hash' => LicenseGenerator::hash( $collision_key ),
			'status'           => 'active',
			'issued_at'        => UtcDateTime::now(),
		)
	);
	$collision_rejected = false;
	try {
		( new LicenseManager( static fn(): string => $collision_key ) )->reissue( $license_id, 1 );
	} catch ( DatabaseException $error ) {
		$collision_rejected = true;
	}
	odph_test_assert( $collision_rejected, 'Reissue must stop after bounded duplicate-key retries' );
	odph_test_assert( 'ODPH-ABCD-EFGH-JKLM-NPQR' === $license_repository->find( $license_id )->license_key, 'Failed reissue must roll back the original key' );
	$license_repository->delete( $collision_id );
	$product_repository->delete( $other_product_id );

	$new_key = 'ODPH-CDEF-GHJK-LMNP-QRST';
	( new LicenseManager( static fn(): string => $new_key ) )->reissue( $license_id, 1 );
	odph_test_assert( null === $license_repository->find_for_verification( 'ODPH-ABCD-EFGH-JKLM-NPQR', 'integration-product' ), 'Old key must become invalid immediately after reissue' );
	odph_test_assert( null !== $license_repository->find_for_verification( $new_key, 'integration-product' ), 'Only the reissued key must verify' );
	odph_test_assert(
		1 === $admin_repository->search(
			array(
				'action'    => 'license_reissued',
				'object_id' => $license_id,
			),
			1,
			10
		)->total,
		'Reissue must create an admin log'
	);

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
	WP_CLI::success( 'Database, customer, license operations, security boundaries, and uninstall checks passed.' );
