<?php
/**
 * Webhook handler and REST integration checks for wp-env.
 *
 * @package OD_Product_Hub
 */

use OD_Product_Hub\Customer\CustomerRepository;
use OD_Product_Hub\Customer\CustomerSyncService;
use OD_Product_Hub\Database\Installer;
use OD_Product_Hub\Database\UtcDateTime;
use OD_Product_Hub\License\LicenseGenerator;
use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Log\WebhookLogRepository;
use OD_Product_Hub\Product\ProductRepository;
use OD_Product_Hub\Subscription\SubscriptionRepository;
use OD_Product_Hub\Webhook\Handler\CheckoutCompletedHandler;
use OD_Product_Hub\Webhook\Handler\InvoiceHandler;
use OD_Product_Hub\Webhook\Handler\SubscriptionHandler;
use OD_Product_Hub\Webhook\WebhookController;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration check must run via WP-CLI.' );
}

add_filter( 'pre_wp_mail', '__return_true' );

/** @param mixed $actual */
function odph_webhook_assert( bool $condition, string $message, $actual = null ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message . ( null === $actual ? '' : ': ' . wp_json_encode( $actual ) ) );
	}
}

/** @param array<string, mixed> $event */
function odph_signed_request( array $event, string $secret ): WP_REST_Request {
	$payload   = (string) wp_json_encode( $event );
	$timestamp = time();
	$signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
	$request   = new WP_REST_Request( 'POST', '/od-product-hub/v1/stripe/webhook' );
	$request->set_body( $payload );
	$request->set_header( 'stripe-signature', 't=' . $timestamp . ',v1=' . $signature );
	return $request;
}

update_option( 'odph_settings', array( 'delete_on_uninstall' => 1 ), false );
Installer::uninstall();
Installer::activate();
$secret                            = 'whsec_integration_test';
$settings                          = get_option( 'odph_settings', array() );
$settings['stripe_webhook_secret'] = $secret;
update_option( 'odph_settings', $settings, false );

$products      = new ProductRepository();
$customers     = new CustomerRepository();
$subscriptions = new SubscriptionRepository();
$licenses      = new LicenseRepository();
$logs          = new WebhookLogRepository();

$product_id          = $products->create(
	array(
		'name'              => 'Webhook Product',
		'slug'              => 'webhook-product',
		'description'       => '',
		'stripe_product_id' => 'prod_webhook',
		'stripe_price_id'   => 'price_webhook',
		'status'            => 'active',
	)
);
$customer_id         = ( new CustomerSyncService() )->upsert_customer( 'cus_webhook', 1, 'webhook@example.test', 'Webhook Customer' );
$subscription_object = (object) array(
	'id'                   => 'sub_webhook',
	'customer'             => 'cus_webhook',
	'status'               => 'active',
	'current_period_start' => time() - DAY_IN_SECONDS,
	'current_period_end'   => time() + ( 30 * DAY_IN_SECONDS ),
	'cancel_at_period_end' => false,
	'items'                => (object) array(
		'data' => array( (object) array( 'price' => (object) array( 'id' => 'price_webhook' ) ) ),
	),
);
$subscription_id     = ( new CustomerSyncService() )->upsert_subscription( $customer_id, $product_id, $subscription_object );
$license_key         = 'ODPH-ABCD-EFGH-JKLM-NPQR';
$license_id          = $licenses->create(
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

$subscription_handler                 = new SubscriptionHandler();
$future_deleted                       = clone $subscription_object;
$future_deleted->status               = 'canceled';
$future_deleted->cancel_at_period_end = true;
$subscription_handler->handle( 'customer.subscription.deleted', $future_deleted );
odph_webhook_assert( 'active' === $licenses->find( $license_id )->status, 'Cancellation scheduled before period end must remain active' );

$past_deleted                     = clone $future_deleted;
$past_deleted->current_period_end = time() - HOUR_IN_SECONDS;
$subscription_handler->handle( 'customer.subscription.deleted', $past_deleted );
odph_webhook_assert( 'cancelled' === $licenses->find( $license_id )->status, 'Deleted subscription after period end must cancel the license' );

$licenses->update( $license_id, array( 'status' => 'suspended' ) );
$subscription_handler->handle( 'customer.subscription.updated', $subscription_object );
odph_webhook_assert( 'suspended' === $licenses->find( $license_id )->status, 'Stripe synchronization must preserve administrator suspension' );

$invoice = (object) array( 'subscription' => 'sub_webhook' );
( new InvoiceHandler() )->handle( 'invoice.payment_failed', $invoice );
odph_webhook_assert( 'suspended' === $licenses->find( $license_id )->status, 'Payment failure must not overwrite suspension' );
odph_webhook_assert( null !== $subscriptions->find( $subscription_id )->payment_failed_at, 'Payment failure timestamp must be stored' );
$licenses->update( $license_id, array( 'status' => 'active' ) );
( new InvoiceHandler() )->handle( 'invoice.payment_failed', $invoice );
odph_webhook_assert( 'inactive' === $licenses->find( $license_id )->status, 'Payment failure must deactivate a normal license' );
( new InvoiceHandler() )->handle( 'invoice.paid', $invoice );
odph_webhook_assert( 'active' === $licenses->find( $license_id )->status, 'Paid invoice must reactivate a normal license' );
odph_webhook_assert( null === $subscriptions->find( $subscription_id )->payment_failed_at, 'Paid invoice must clear payment failure timestamp' );

$checkout_email   = 'checkout-handler@example.test';
$checkout         = (object) array(
	'id'               => 'cs_handler',
	'customer'         => (object) array(
		'id'    => 'cus_handler',
		'email' => $checkout_email,
	),
	'customer_details' => (object) array(
		'email' => $checkout_email,
		'name'  => 'Checkout Handler',
	),
	'subscription'     => (object) array(
		'id'                   => 'sub_handler',
		'status'               => 'active',
		'current_period_start' => time(),
		'current_period_end'   => time() + ( 30 * DAY_IN_SECONDS ),
		'cancel_at_period_end' => false,
		'items'                => (object) array(
			'data' => array( (object) array( 'price' => (object) array( 'id' => 'price_webhook' ) ) ),
		),
	),
);
$checkout_handler = new CheckoutCompletedHandler(
	static function ( object $session ) use ( $checkout ): object {
		unset( $session );
		return $checkout;
	}
);
$checkout_handler->handle( 'checkout.session.completed', (object) array( 'id' => 'cs_handler' ) );
$checkout_customer = $customers->find_by_stripe_id( 'cus_handler' );
odph_webhook_assert( null !== $checkout_customer, 'Checkout handler must upsert its customer' );
$checkout_subscription = $subscriptions->find_by_stripe_id( 'sub_handler' );
odph_webhook_assert( null !== $checkout_subscription, 'Checkout handler must upsert its subscription' );
odph_webhook_assert( $licenses->exists_for_subscription( (int) $checkout_subscription->id ), 'Checkout handler must issue one license' );
$checkout_handler->handle( 'checkout.session.completed', (object) array( 'id' => 'cs_handler' ) );
odph_webhook_assert( 1 === $licenses->search( array( 'subscription_id' => $checkout_subscription->id ), 1, 10 )->total, 'Repeated checkout handling must not duplicate a license' );

$controller = new WebhookController();
$event      = array(
	'id'     => 'evt_unsupported',
	'object' => 'event',
	'type'   => 'customer.personal_data',
	'data'   => array(
		'object' => array(
			'id'      => 'obj_1',
			'email'   => 'private@example.test',
			'address' => array( 'line1' => 'Secret Street' ),
		),
	),
);
$response   = $controller->handle( odph_signed_request( $event, $secret ) );
odph_webhook_assert( $response instanceof WP_REST_Response && 200 === $response->get_status(), 'Unsupported signed event must be acknowledged' );
$event_log = $logs->find_by_event_id( 'evt_unsupported' );
odph_webhook_assert( 'unsupported' === $event_log->result, 'Unsupported event must have its own log classification' );
odph_webhook_assert( ! str_contains( $event_log->payload, 'private@example.test' ) && ! str_contains( $event_log->payload, 'Secret Street' ), 'Persisted webhook payload must redact personal data' );
$duplicate_response = $controller->handle( odph_signed_request( $event, $secret ) );
odph_webhook_assert( 'duplicated_event' === $duplicate_response->get_data()['result'], 'Duplicate event must be acknowledged without dispatch' );
odph_webhook_assert( 1 === (int) $logs->find_by_event_id( 'evt_unsupported' )->duplicate_count, 'Duplicate deliveries must be counted atomically' );

$invalid_request = new WP_REST_Request( 'POST', '/od-product-hub/v1/stripe/webhook' );
$invalid_request->set_body( (string) wp_json_encode( $event ) );
$invalid_request->set_header( 'stripe-signature', 'invalid' );
$invalid_response = $controller->handle( $invalid_request );
odph_webhook_assert( is_wp_error( $invalid_response ) && 400 === $invalid_response->get_error_data()['status'], 'Invalid signature must return HTTP 400' );
odph_webhook_assert( 1 === $logs->count_by_result( 'signature_error' ), 'Signature failures must have their own log classification' );

$failed_event    = array(
	'id'     => 'evt_failed',
	'object' => 'event',
	'type'   => 'invoice.paid',
	'data'   => array(
		'object' => array(
			'id'           => 'in_failed',
			'subscription' => 'sub_missing',
		),
	),
);
$failed_response = $controller->handle( odph_signed_request( $failed_event, $secret ) );
odph_webhook_assert( is_wp_error( $failed_response ) && 500 === $failed_response->get_error_data()['status'], 'Handler failure must return HTTP 500' );
odph_webhook_assert( 'error' === $logs->find_by_event_id( 'evt_failed' )->result, 'Handler failure must be classified as an error' );
odph_webhook_assert( 1 === (int) $logs->find_by_event_id( 'evt_failed' )->attempt_count, 'First handler failure must record one attempt' );

$second_failed_response = $controller->handle( odph_signed_request( $failed_event, $secret ) );
odph_webhook_assert( is_wp_error( $second_failed_response ) && 500 === $second_failed_response->get_error_data()['status'], 'A failed event delivery must be reclaimed and retried' );
odph_webhook_assert( 2 === (int) $logs->find_by_event_id( 'evt_failed' )->attempt_count, 'Automatic retry must increment the attempt count' );

$missing_subscription           = clone $subscription_object;
$missing_subscription->id       = 'sub_missing';
$missing_subscription->customer = 'cus_webhook';
$missing_subscription->items    = (object) array( 'data' => array( (object) array( 'price' => (object) array( 'id' => 'price_webhook' ) ) ) );
( new CustomerSyncService() )->upsert_subscription( $customer_id, $product_id, $missing_subscription );
$recovered_response = $controller->handle( odph_signed_request( $failed_event, $secret ) );
odph_webhook_assert( $recovered_response instanceof WP_REST_Response && 200 === $recovered_response->get_status(), 'A retried event must recover after the transient cause is fixed' );
$recovered_log = $logs->find_by_event_id( 'evt_failed' );
odph_webhook_assert( 'success' === $recovered_log->result && 3 === (int) $recovered_log->attempt_count, 'Recovered event must retain its complete attempt history' );
$recovered_duplicate = $controller->handle( odph_signed_request( $failed_event, $secret ) );
odph_webhook_assert( 'duplicated_event' === $recovered_duplicate->get_data()['result'], 'A successful recovered event must not run again' );

$parallel_first = $logs->claim( 'evt_parallel', 'test.parallel', '{}' );
odph_webhook_assert( 'claimed' === $parallel_first['status'], 'Parallel fixture must receive its initial claim' );
$logs->fail_claim( (int) $parallel_first['id'], (int) $parallel_first['attempt'], 'temporary_failure' );
$parallel_retry = $logs->claim( 'evt_parallel', 'test.parallel', '{}' );
$parallel_busy  = $logs->claim( 'evt_parallel', 'test.parallel', '{}' );
odph_webhook_assert( 'claimed' === $parallel_retry['status'] && 'processing' === $parallel_busy['status'], 'Only one concurrent retry may own the event' );
$logs->complete_claim( (int) $parallel_retry['id'], (int) $parallel_retry['attempt'], 'success' );

$stale_first = $logs->claim( 'evt_stale', 'test.stale', '{}' );
global $wpdb;
$wpdb->update(
	$wpdb->prefix . 'odph_webhook_logs',
	array( 'last_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - ( 10 * MINUTE_IN_SECONDS ) ) ),
	array( 'id' => (int) $stale_first['id'] )
);
$stale_retry = $logs->claim( 'evt_stale', 'test.stale', '{}' );
odph_webhook_assert( 'claimed' === $stale_retry['status'], 'A stale processing event must be reclaimable' );
odph_webhook_assert( ! $logs->complete_claim( (int) $stale_first['id'], (int) $stale_first['attempt'], 'success' ), 'A stale worker must not overwrite a newer claim' );
odph_webhook_assert( $logs->complete_claim( (int) $stale_retry['id'], (int) $stale_retry['attempt'], 'success' ), 'The current claim owner must be able to finish the event' );

$exhausted = $logs->claim( 'evt_exhausted', 'test.exhausted', '{}' );
for ( $attempt = 1; $attempt <= WebhookLogRepository::MAX_ATTEMPTS; $attempt++ ) {
	$logs->fail_claim( (int) $exhausted['id'], (int) $exhausted['attempt'], 'persistent_failure' );
	if ( $attempt < WebhookLogRepository::MAX_ATTEMPTS ) {
		$exhausted = $logs->claim( 'evt_exhausted', 'test.exhausted', '{}' );
		odph_webhook_assert( 'claimed' === $exhausted['status'], 'A failed event below the limit must remain retryable' );
	}
}
$exhausted_log = $logs->find_by_event_id( 'evt_exhausted' );
odph_webhook_assert( 'exhausted' === $exhausted_log->result && WebhookLogRepository::MAX_ATTEMPTS === (int) $exhausted_log->attempt_count, 'Persistent failure must reach an explicit terminal state' );
$exhausted_delivery = $logs->claim( 'evt_exhausted', 'test.exhausted', '{}' );
odph_webhook_assert( 'exhausted' === $exhausted_delivery['status'], 'Exhausted events must reject automatic retries' );
odph_webhook_assert( $logs->request_manual_retry( (int) $exhausted_log->id ), 'An administrator must be able to reopen an exhausted event' );
$manual_retry = $logs->claim( 'evt_exhausted', 'test.exhausted', '{}' );
odph_webhook_assert( 'claimed' === $manual_retry['status'] && 1 === (int) $logs->find_by_event_id( 'evt_exhausted' )->attempt_count, 'Manual recovery must begin a new bounded retry cycle' );
$logs->complete_claim( (int) $manual_retry['id'], (int) $manual_retry['attempt'], 'success' );

$checkout_user = get_user_by( 'email', $checkout_email );
if ( $checkout_user ) {
	wp_delete_user( $checkout_user->ID );
}
remove_filter( 'pre_wp_mail', '__return_true' );
update_option( 'odph_settings', array( 'delete_on_uninstall' => 1 ), false );
Installer::uninstall();
Installer::activate();
WP_CLI::success( 'Webhook handlers, signatures, idempotency, redaction, and state transitions passed.' );
