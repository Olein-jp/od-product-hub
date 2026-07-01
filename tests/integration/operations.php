<?php
/**
 * Dashboard, operational logs, retention, and large-volume pagination checks.
 *
 * @package OD_Product_Hub
 */

use OD_Product_Hub\Admin\DashboardService;
use OD_Product_Hub\Admin\LogsPage;
use OD_Product_Hub\Customer\CustomerRepository;
use OD_Product_Hub\Database\Installer;
use OD_Product_Hub\Database\UtcDateTime;
use OD_Product_Hub\License\LicenseGenerator;
use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Log\AdminLogRepository;
use OD_Product_Hub\Log\ApiLogRepository;
use OD_Product_Hub\Log\EmailLogRepository;
use OD_Product_Hub\Log\LogCleanupService;
use OD_Product_Hub\Log\WebhookLogRepository;
use OD_Product_Hub\Plugin;
use OD_Product_Hub\Product\ProductRepository;
use OD_Product_Hub\Subscription\SubscriptionRepository;
use OD_Product_Hub\Webhook\PayloadRedactor;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration check must run via WP-CLI.' );
}

/** @param mixed $actual */
function odph_operations_assert( bool $condition, string $message, $actual = null ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message . ( null === $actual ? '' : ': ' . wp_json_encode( $actual ) ) );
	}
}

function odph_operations_scheduled_event_count( string $hook ): int {
	$count = 0;
	foreach ( _get_cron_array() as $events ) {
		$count += count( $events[ $hook ] ?? array() );
	}
	return $count;
}

global $wpdb;

update_option( 'odph_settings', array( 'delete_on_uninstall' => 1 ), false );
Installer::uninstall();
Installer::activate();

$settings = (array) get_option( 'odph_settings', array() );
odph_operations_assert( 365 === (int) ( $settings['log_retention_days'] ?? 0 ), 'Default log retention must be 365 days', $settings );
Installer::deactivate();
odph_operations_assert( false === wp_next_scheduled( 'odph_cleanup_logs' ), 'Deactivation must remove the cleanup event' );

update_option( 'odph_schema_version', OD_Product_Hub\Database\Schema::VERSION, false );
Plugin::instance()->register();
$first_schedule = wp_next_scheduled( 'odph_cleanup_logs' );
odph_operations_assert( false !== $first_schedule, 'Plugin initialization must repair a missing cleanup event on an existing installation' );
odph_operations_assert( 1 === odph_operations_scheduled_event_count( 'odph_cleanup_logs' ), 'Plugin initialization must register exactly one cleanup event' );
Plugin::instance()->register();
$second_schedule = wp_next_scheduled( 'odph_cleanup_logs' );
odph_operations_assert( $first_schedule === $second_schedule, 'Repeated plugin initialization must not move the cleanup event' );
odph_operations_assert( 1 === odph_operations_scheduled_event_count( 'odph_cleanup_logs' ), 'Repeated plugin initialization must not duplicate the cleanup event' );

Installer::deactivate();
odph_operations_assert( false === wp_next_scheduled( 'odph_cleanup_logs' ), 'Deactivation must remove the repaired cleanup event' );

$schedule_failure   = static fn () => new WP_Error( 'odph_test_schedule_failure', 'Simulated scheduling failure.' );
$schedule_exception = null;
add_filter( 'pre_schedule_event', $schedule_failure );
try {
	Installer::ensure_scheduled_events();
} catch ( RuntimeException $exception ) {
	$schedule_exception = $exception;
} finally {
	remove_filter( 'pre_schedule_event', $schedule_failure );
}
odph_operations_assert( $schedule_exception instanceof RuntimeException, 'A cleanup scheduling failure must stop initialization with an exception' );
odph_operations_assert( str_contains( $schedule_exception->getMessage(), 'Simulated scheduling failure.' ), 'The scheduling exception must retain the WordPress error message' );
Installer::ensure_scheduled_events();

$products      = new ProductRepository();
$customers     = new CustomerRepository();
$subscriptions = new SubscriptionRepository();
$licenses      = new LicenseRepository();
$webhooks      = new WebhookLogRepository();
$api_logs      = new ApiLogRepository();
$admin_logs    = new AdminLogRepository();
$email_logs    = new EmailLogRepository();
$now           = UtcDateTime::now();
$old_date      = gmdate( 'Y-m-d H:i:s', time() - ( 400 * 86400 ) );

$product_id  = $products->create(
	array(
		'name'              => 'Operations Product',
		'slug'              => 'operations-product',
		'stripe_product_id' => 'prod_operations',
		'stripe_price_id'   => 'price_operations',
		'status'            => 'active',
	)
);
$customer_id = $customers->create(
	array(
		'wp_user_id'         => 2001,
		'stripe_customer_id' => 'cus_operations',
		'email'              => 'operations@example.test',
		'name'               => 'Operations Customer',
	)
);

$subscription_ids = array();
foreach ( array(
	array( 'sub_operations_active', 'active', null, $now ),
	array( 'sub_operations_failed', 'past_due', $now, $now ),
	array( 'sub_operations_old', 'canceled', null, $old_date ),
) as list( $stripe_id, $subscription_status, $failed_at, $created_at ) ) {
	$subscription_ids[] = $subscriptions->create(
		array(
			'customer_id'            => $customer_id,
			'product_id'             => $product_id,
			'stripe_subscription_id' => $stripe_id,
			'stripe_status'          => $subscription_status,
			'payment_failed_at'      => $failed_at,
			'created_at'             => $created_at,
			'updated_at'             => $created_at,
		)
	);
}

foreach ( array(
	array( 'ODPH-ABCD-EFGH-JKLM-NPQR', 'active', $subscription_ids[0] ),
	array( 'ODPH-BCDE-FGHJ-KLMN-PQRS', 'suspended', $subscription_ids[1] ),
) as list( $key, $license_status, $subscription_id ) ) {
	$licenses->create(
		array(
			'product_id'       => $product_id,
			'customer_id'      => $customer_id,
			'subscription_id'  => $subscription_id,
			'license_key'      => $key,
			'license_key_hash' => LicenseGenerator::hash( $key ),
			'status'           => $license_status,
			'issued_at'        => $now,
		)
	);
}

$sensitive_payload = (string) wp_json_encode(
	array(
		'id'   => 'evt_sensitive',
		'type' => 'invoice.payment_failed',
		'data' => array(
			'object' => array(
				'email'          => 'private@example.test',
				'address'        => array( 'line1' => '1 Secret Street' ),
				'payment_method' => 'pm_secret_value',
				'receipt_url'    => 'https://pay.example.test/private-receipt',
				'metadata'       => array( 'public_reference' => 'visible-reference' ),
			),
		),
	)
);
$masked_payload    = ( new PayloadRedactor() )->redact_json( $sensitive_payload );
foreach ( array( 'private@example.test', '1 Secret Street', 'pm_secret_value', 'private-receipt' ) as $secret ) {
	odph_operations_assert( ! str_contains( $masked_payload, $secret ), 'Payload redaction must remove personal and payment data', $secret );
}
odph_operations_assert( str_contains( $masked_payload, '[redacted]' ) && str_contains( $masked_payload, 'visible-reference' ), 'Payload redaction must retain safe operational context', $masked_payload );

$detail_id = $webhooks->create(
	array(
		'stripe_event_id'  => 'evt_sensitive',
		'event_type'       => 'invoice.payment_failed',
		'payload'          => $sensitive_payload,
		'result'           => 'success',
		'last_received_at' => $now,
	)
);
$webhooks->create(
	array(
		'stripe_event_id' => 'evt_error',
		'event_type'      => 'customer.subscription.updated',
		'payload'         => '{}',
		'result'          => 'error',
		'error_message'   => 'fixture error',
	)
);
$webhooks->create(
	array(
		'stripe_event_id' => 'evt_signature',
		'event_type'      => 'signature_verification',
		'payload'         => '{}',
		'result'          => 'signature_error',
	)
);
$webhooks->create(
	array(
		'stripe_event_id' => 'evt_old_cleanup',
		'event_type'      => 'invoice.paid',
		'payload'         => '{}',
		'result'          => 'success',
		'created_at'      => $old_date,
	)
);

$counts = ( new DashboardService() )->counts();
odph_operations_assert(
	array(
		'active_licenses'    => 1,
		'suspended_licenses' => 1,
		'payment_failures'   => 1,
		'new_subscriptions'  => 2,
		'webhook_errors'     => 2,
	) === $counts,
	'Dashboard counts must match fixtures',
	$counts
);
odph_operations_assert( 1 === $webhooks->search_admin( 'evt_error', 'error', 1 )->total, 'Webhook search must combine prefix and result filters' );

$admin_logs->create(
	array(
		'user_id'     => 1,
		'action'      => 'product_updated',
		'object_type' => 'product',
		'object_id'   => $product_id,
		'details'     => '{}',
	)
);
odph_operations_assert( 1 === $admin_logs->search_admin( 'product_updated', 'product', 1, 1 )->total, 'Admin log filters must work together' );

$previous_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The integration test temporarily supplies read-only detail parameters.
$_GET         = array(
	'page'   => 'odph-logs',
	'tab'    => 'webhook',
	'log_id' => $detail_id,
);
ob_start();
( new LogsPage() )->render();
$detail_html = (string) ob_get_clean();
$_GET        = $previous_get;
odph_operations_assert( str_contains( $detail_html, 'マスク済みpayload' ) && str_contains( $detail_html, '[redacted]' ), 'Webhook detail must show a clearly labelled masked payload' );
foreach ( array( 'private@example.test', '1 Secret Street', 'pm_secret_value', 'private-receipt' ) as $secret ) {
	odph_operations_assert( ! str_contains( $detail_html, $secret ), 'Webhook detail must not reveal sensitive payload values', $secret );
}

$bulk_total = 10000;
$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The test transaction makes the required 10k fixture practical.
for ( $index = 0; $index < $bulk_total; $index++ ) {
	$api_logs->create(
		array(
			'action'     => 'verify',
			'result'     => 'success',
			'site_url'   => 'https://bulk.example.test/site-' . $index,
			'ip_address' => '192.0.2.1',
			'created_at' => $old_date,
		)
	);
}
$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Completes the isolated large-volume fixture transaction.

$bulk_page = $api_logs->search_admin( 'verify', 'success', '', 'https://bulk.example.test/', 500, 20 );
odph_operations_assert( $bulk_total === $bulk_page->total, 'API pagination must count the 10k filtered fixture exactly', $bulk_page->total );
odph_operations_assert( 20 === count( $bulk_page->items ) && 500 === $bulk_page->total_pages, 'API pagination must return only the requested 20-row page', array( count( $bulk_page->items ), $bulk_page->total_pages ) );

$admin_logs->create(
	array(
		'user_id'     => 1,
		'action'      => 'old_admin',
		'object_type' => 'logs',
		'details'     => '{}',
		'created_at'  => $old_date,
	)
);
$email_logs->create(
	array(
		'email_type'     => 'old_email',
		'recipient_hash' => hash( 'sha256', 'old@example.test' ),
		'status'         => 'failed',
		'created_at'     => $old_date,
	)
);

$cleanup = new LogCleanupService();
$deleted = $cleanup->run();
odph_operations_assert( 1 === $deleted['webhook_logs'], 'Default retention must remove the old webhook fixture', $deleted );
odph_operations_assert( $bulk_total === $deleted['api_logs'], 'Cleanup must process the 10k API fixtures in bounded batches', $deleted );
odph_operations_assert( 1 === $deleted['admin_logs'] && 1 === $deleted['email_logs'], 'Cleanup must cover admin and email logs', $deleted );
odph_operations_assert( 0 === array_sum( $cleanup->run() ), 'Cleanup must be idempotent when repeated' );

$settings                       = (array) get_option( 'odph_settings', array() );
$settings['log_retention_days'] = 30;
update_option( 'odph_settings', $settings, false );
$api_logs->create(
	array(
		'action'     => 'verify',
		'result'     => 'failure',
		'site_url'   => 'https://retention.example.test/old',
		'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( 31 * 86400 ) ),
	)
);
$api_logs->create(
	array(
		'action'     => 'verify',
		'result'     => 'failure',
		'site_url'   => 'https://retention.example.test/recent',
		'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( 29 * 86400 ) ),
	)
);
$changed_retention = $cleanup->run();
odph_operations_assert( 1 === $changed_retention['api_logs'], 'Changing retention to 30 days must remove the 31-day fixture only', $changed_retention );
odph_operations_assert( 1 === $api_logs->search_admin( 'verify', 'failure', '', 'https://retention.example.test/recent', 1 )->total, 'A 29-day log must remain after 30-day retention cleanup' );

WP_CLI::success( 'Dashboard, searchable logs, payload masking, 10k pagination, Cron self-repair, and retention cleanup passed.' );
