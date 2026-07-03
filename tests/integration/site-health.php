<?php
/**
 * Site Health production dependency integration checks.
 *
 * Run with: npm run test:site-health
 *
 * @package OD_Product_Hub
 */

use OD_Product_Hub\Admin\AdminSiteHealth;
use OD_Product_Hub\Database\Installer;
use OD_Product_Hub\Database\UtcDateTime;
use OD_Product_Hub\Log\LogCleanupService;
use OD_Product_Hub\Log\WebhookLogRepository;
use OD_Product_Hub\Product\ProductRepository;
use OD_Product_Hub\Release\ReleaseRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration check must run via WP-CLI.' );
}

/** @param mixed $actual */
function odph_health_assert( bool $condition, string $message, $actual = null ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message . ( null === $actual ? '' : ': ' . wp_json_encode( $actual ) ) );
	}
}

update_option( 'odph_settings', array( 'delete_on_uninstall' => 1 ), false );
Installer::uninstall();
Installer::activate();

$storage = sys_get_temp_dir() . '/odph-site-health-storage';
if ( ! is_dir( $storage ) ) {
	mkdir( $storage, 0700, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable integration-test directory outside the Web root.
}
if ( ! defined( 'ODPH_RELEASE_STORAGE_PATH' ) ) {
	define( 'ODPH_RELEASE_STORAGE_PATH', $storage );
}

$success_page = wp_insert_post(
	array(
		'post_title'   => 'Checkout success',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '[odph_checkout_success]',
	)
);
$cancel_page  = wp_insert_post(
	array(
		'post_title'   => 'Checkout cancel',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '[odph_checkout_cancel]',
	)
);
$account_page = wp_insert_post(
	array(
		'post_title'   => 'Account',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '[odph_my_account]',
	)
);
odph_health_assert( ! is_wp_error( $success_page ) && ! is_wp_error( $cancel_page ) && ! is_wp_error( $account_page ), 'Site Health page fixtures must be created' );

$settings = array_merge(
	Installer::defaults(),
	array(
		'stripe_secret_key'      => 'sk_test_site_health_secret',
		'stripe_publishable_key' => 'pk_test_site_health_public',
		'stripe_webhook_secret'  => 'whsec_site_health_secret',
		'success_url'            => get_permalink( (int) $success_page ),
		'cancel_url'             => get_permalink( (int) $cancel_page ),
		'account_page_id'        => (int) $account_page,
	)
);
update_option( 'odph_settings', $settings, false );
$now = UtcDateTime::now();
update_option(
	'odph_operational_state',
	array(
		'stripe_last_result'   => 'success',
		'stripe_last_test'     => $now,
		'stripe_last_success'  => $now,
		'cleanup_last_success' => $now,
	),
	false
);
Installer::ensure_scheduled_events();
$webhooks   = new WebhookLogRepository();
$healthy_id = $webhooks->create(
	array(
		'stripe_event_id'  => 'evt_health_success',
		'event_type'       => 'checkout.session.completed',
		'payload'          => '{}',
		'result'           => 'success',
		'last_received_at' => $now,
	)
);
$health     = new AdminSiteHealth();
$tests      = $health->tests(
	array(
		'direct' => array(),
		'async'  => array(),
	)
);
odph_health_assert( 5 === count( $tests['direct'] ), 'Each production dependency must be an independent Site Health test', array_keys( $tests['direct'] ) );
foreach ( array( 'stripe_https_status', 'webhook_status', 'cron_status', 'update_delivery_status', 'customer_pages_status' ) as $method ) {
	$result = $health->{$method}();
	odph_health_assert( 'good' === $result['status'], 'Healthy fixture must not produce a warning for ' . $method, $result );
}

$state                       = get_option( 'odph_operational_state', array() );
$state['stripe_last_result'] = 'error';
update_option( 'odph_operational_state', $state, false );
odph_health_assert( 'recommended' === $health->stripe_https_status()['status'], 'Failed manual Stripe result must be diagnosed without making a new external request' );
$state['stripe_last_result']  = 'success';
$state['stripe_last_success'] = UtcDateTime::now();
update_option( 'odph_operational_state', $state, false );
odph_health_assert( 'good' === $health->stripe_https_status()['status'], 'Stripe diagnosis must recover after a successful manual result' );

for ( $index = 0; $index < 3; $index++ ) {
	$webhooks->create(
		array(
			'stripe_event_id' => 'evt_health_error_' . $index,
			'event_type'      => 'invoice.payment_failed',
			'payload'         => '{}',
			'result'          => 'error',
			'error_message'   => 'integration_failure',
		)
	);
}
odph_health_assert( 'critical' === $health->webhook_status()['status'], 'Three consecutive Webhook errors must be critical' );
$recovery_id = $webhooks->create(
	array(
		'stripe_event_id'  => 'evt_health_recovery',
		'event_type'       => 'customer.subscription.updated',
		'payload'          => '{}',
		'result'           => 'success',
		'last_received_at' => UtcDateTime::now(),
	)
);
odph_health_assert( 'good' === $health->webhook_status()['status'], 'Webhook diagnosis must recover after a successful event' );
$stale_id = $webhooks->create(
	array(
		'stripe_event_id' => 'evt_health_stale',
		'event_type'      => 'test.stale',
		'payload'         => '{}',
		'result'          => 'processing',
		'last_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ),
	)
);
odph_health_assert( 'critical' === $health->webhook_status()['status'], 'Stale Webhook processing must be critical' );
$webhooks->finish( $stale_id, 'success' );
odph_health_assert( 'good' === $health->webhook_status()['status'], 'Stale Webhook diagnosis must recover after completion' );

wp_clear_scheduled_hook( 'odph_cleanup_logs' );
odph_health_assert( 'critical' === $health->cron_status()['status'], 'Missing cleanup Cron must be critical' );
Installer::ensure_scheduled_events();
odph_health_assert( 'good' === $health->cron_status()['status'], 'Cron diagnosis must recover after schedule repair' );
( new LogCleanupService() )->run();
$state = get_option( 'odph_operational_state', array() );
odph_health_assert( ! empty( $state['cleanup_last_success'] ), 'Successful cleanup must persist its health timestamp' );

wp_update_post(
	array(
		'ID'           => (int) $account_page,
		'post_content' => 'Missing shortcode',
	)
);
odph_health_assert( 'critical' === $health->customer_pages_status()['status'], 'Missing account shortcode must be critical' );
wp_update_post(
	array(
		'ID'           => (int) $account_page,
		'post_content' => '[odph_my_account]',
	)
);
odph_health_assert( 'good' === $health->customer_pages_status()['status'], 'Page diagnosis must recover after shortcode restoration' );

$product_id = ( new ProductRepository() )->create(
	array(
		'name'              => 'Health Product',
		'slug'              => 'health-product',
		'stripe_product_id' => 'prod_health',
		'stripe_price_id'   => 'price_health',
		'status'            => 'active',
	)
);
$releases   = new ReleaseRepository();
$release_id = $releases->create(
	array(
		'product_id'   => $product_id,
		'version'      => '1.0.0',
		'channel'      => 'stable',
		'plugin_file'  => 'health/health.php',
		'package_path' => $storage . '/missing.zip',
		'sha256'       => str_repeat( '0', 64 ),
		'signature'    => 'invalid',
		'public_key'   => 'invalid',
		'status'       => 'published',
		'published_at' => UtcDateTime::now(),
	)
);
odph_health_assert( 'critical' === $health->update_delivery_status()['status'], 'Invalid published package must be critical' );
$missing_result = $health->update_delivery_status();
odph_health_assert( str_contains( (string) $missing_result['label'], 'missing' ), 'Missing packages must have a distinct Site Health diagnosis', $missing_result );
file_put_contents( $storage . '/missing.zip', 'tampered' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Invalid integrity fixture.
$integrity_result = $health->update_delivery_status();
odph_health_assert( 'critical' === $integrity_result['status'] && str_contains( (string) $integrity_result['label'], 'integrity' ), 'Integrity failures must have a distinct Site Health diagnosis', $integrity_result );
unlink( $storage . '/missing.zip' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable integration fixture cleanup.
$releases->withdraw( $release_id );
odph_health_assert( 'good' === $health->update_delivery_status()['status'], 'Update diagnosis must recover after invalid release withdrawal' );

$debug      = $health->debug_information( array() );
$serialized = (string) wp_json_encode( $debug );
foreach ( array( 'sk_test_site_health_secret', 'whsec_site_health_secret', $storage, 'missing.zip' ) as $sensitive ) {
	odph_health_assert( ! str_contains( $serialized, $sensitive ), 'Site Health debug information must not expose secrets or paths', $sensitive );
}

$webhooks->delete( $healthy_id );
$webhooks->delete( $recovery_id );
wp_delete_post( (int) $success_page, true );
wp_delete_post( (int) $cancel_page, true );
wp_delete_post( (int) $account_page, true );

WP_CLI::success( 'OD Product Hub Site Health diagnostics passed.' );
