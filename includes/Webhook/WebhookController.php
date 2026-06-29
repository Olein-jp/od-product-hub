<?php
/**
 * Stripe webhook endpoint and synchronization.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Webhook;

use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Product\ProductRepository;
use OD_Product_Hub\Stripe\StripeClientFactory;
use Stripe\Webhook;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class WebhookController {
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'route' ) ); }

	public function route(): void {
		register_rest_route(
			'od-product-hub/v1',
			'/stripe/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function handle( WP_REST_Request $request ) {
		$payload   = $request->get_body();
		$signature = (string) $request->get_header( 'stripe-signature' );
		$settings  = get_option( 'odph_settings', array() );
		try {
			if ( ! class_exists( Webhook::class ) ) {
				throw new \RuntimeException( 'Stripe PHP SDK is not installed.' ); }
			$event = Webhook::constructEvent( $payload, $signature, (string) ( $settings['stripe_webhook_secret'] ?? '' ) );
		} catch ( \Throwable $error ) {
			$this->log( 'unknown', 'unknown', $payload, 'error', 'signature_verification_failed: ' . $error->getMessage() );
			return new WP_Error( 'signature_verification_failed', 'Webhook signature verification failed.', array( 'status' => 400 ) );
		}
		if ( $this->exists( (string) $event->id ) ) {
			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . 'odph_webhook_logs',
				array( 'result' => 'duplicated_event' ),
				array( 'stripe_event_id' => (string) $event->id )
			);
			return new WP_REST_Response(
				array(
					'success' => true,
					'result'  => 'duplicated_event',
				),
				200
			);
		}
		try {
			$this->dispatch( (string) $event->type, $event->data->object );
			$this->log( (string) $event->id, (string) $event->type, $payload, 'success', null );
			return new WP_REST_Response( array( 'success' => true ), 200 );
		} catch ( \Throwable $error ) {
			$this->log( (string) $event->id, (string) $event->type, $payload, 'error', $error->getMessage() );
			wp_mail( get_option( 'admin_email' ), '[OD Product Hub] Webhook error', $event->type . ': ' . $error->getMessage() );
			return new WP_Error( 'webhook_processing_failed', 'Webhook processing failed.', array( 'status' => 500 ) );
		}
	}

	private function dispatch( string $type, object $object ): void {
		if ( 'checkout.session.completed' === $type ) {
			$this->checkout_completed( $object );
			return; }
		if ( str_starts_with( $type, 'customer.subscription.' ) ) {
			$this->subscription( $object, 'customer.subscription.deleted' === $type );
			return; }
		if ( in_array( $type, array( 'invoice.paid', 'invoice.payment_failed' ), true ) ) {
			$this->invoice( $object, 'invoice.payment_failed' === $type ); }
	}

	private function checkout_completed( object $session ): void {
		$session = StripeClientFactory::create()->checkout->sessions->retrieve( (string) $session->id, array( 'expand' => array( 'customer', 'subscription', 'subscription.items.data.price' ) ) );
		$email   = sanitize_email( (string) ( $session->customer_details->email ?? $session->customer->email ?? '' ) );
		if ( ! is_email( $email ) ) {
			throw new \RuntimeException( 'Checkout customer email is missing.' ); }
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			$user_id = wp_insert_user(
				array(
					'user_login'   => $this->unique_login( $email ),
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 32 ),
					'role'         => 'subscriber',
					'display_name' => sanitize_text_field( (string) ( $session->customer_details->name ?? '' ) ),
				)
			);
			if ( is_wp_error( $user_id ) ) {
				throw new \RuntimeException( esc_html( $user_id->get_error_message() ) ); }
			$user = get_user_by( 'id', $user_id );
			wp_new_user_notification( $user_id, null, 'user' );
		}
		$customer_id = $this->upsert_customer( (int) $user->ID, (string) $session->customer->id, $email, (string) ( $session->customer_details->name ?? '' ) );
		$product     = ( new ProductRepository() )->find_by_price( (string) $session->subscription->items->data[0]->price->id );
		if ( ! $product ) {
			throw new \RuntimeException( 'No product matches the Stripe Price ID.' ); }
		$subscription_id = $this->upsert_subscription( $customer_id, (int) $product->id, $session->subscription );
		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}odph_licenses WHERE subscription_id = %d LIMIT 1", $subscription_id ) );
		if ( ! $exists ) {
			$key = ( new LicenseRepository() )->issue( (int) $product->id, $customer_id, $subscription_id, $this->date( $session->subscription->current_period_end ?? null ) );
			wp_mail( $email, 'ご購入ありがとうございます', "契約手続きが完了しました。\nライセンスキー: {$key}\nマイページからも確認できます。" );
		}
	}

	private function subscription( object $subscription, bool $deleted ): void {
		global $wpdb;
		$customer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}odph_customers WHERE stripe_customer_id = %s LIMIT 1", (string) $subscription->customer ) );
		$product  = ( new ProductRepository() )->find_by_price( (string) $subscription->items->data[0]->price->id );
		if ( ! $customer || ! $product ) {
			throw new \RuntimeException( 'Subscription customer or product is not registered.' ); }
		$id     = $this->upsert_subscription( (int) $customer->id, (int) $product->id, $subscription );
		$status = $deleted ? 'cancelled' : $this->license_status( (string) $subscription->status, (bool) $subscription->cancel_at_period_end, (int) ( $subscription->current_period_end ?? 0 ) );
		$wpdb->update(
			$wpdb->prefix . 'odph_licenses',
			array(
				'status'     => $status,
				'expires_at' => $this->date( $subscription->current_period_end ?? null ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'subscription_id' => $id )
		);
	}

	private function invoice( object $invoice, bool $failed ): void {
		global $wpdb;
		$stripe_id    = is_object( $invoice->subscription ?? null ) ? $invoice->subscription->id : (string) ( $invoice->subscription ?? '' );
		$subscription = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}odph_subscriptions WHERE stripe_subscription_id = %s LIMIT 1", $stripe_id ) );
		if ( ! $subscription ) {
			throw new \RuntimeException( 'Invoice subscription is not registered.' ); }
		$wpdb->update(
			$wpdb->prefix . 'odph_subscriptions',
			array(
				'payment_failed_at' => $failed ? current_time( 'mysql', true ) : null,
				'updated_at'        => current_time( 'mysql', true ),
			),
			array( 'id' => $subscription->id )
		);
		$wpdb->update(
			$wpdb->prefix . 'odph_licenses',
			array(
				'status'     => $failed ? 'inactive' : 'active',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'subscription_id' => $subscription->id )
		);
		if ( $failed ) {
			$email = $wpdb->get_var( $wpdb->prepare( "SELECT email FROM {$wpdb->prefix}odph_customers WHERE id = %d", $subscription->customer_id ) );
			wp_mail( $email, 'お支払いを確認できませんでした', 'Stripe Customer Portal でお支払い方法をご確認ください。' ); }
	}

	private function upsert_customer( int $user_id, string $stripe_id, string $email, string $name ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'odph_customers';
		$id    = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE stripe_customer_id = %s', $table, $stripe_id ) );
		$now   = current_time( 'mysql', true );
		$data  = array(
			'wp_user_id'         => $user_id,
			'stripe_customer_id' => $stripe_id,
			'email'              => $email,
			'name'               => sanitize_text_field( $name ),
			'updated_at'         => $now,
		);
		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) );
			return (int) $id; }
		$data['created_at'] = $now;
		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id;
	}

	private function upsert_subscription( int $customer_id, int $product_id, object $subscription ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'odph_subscriptions';
		$id    = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE stripe_subscription_id = %s', $table, (string) $subscription->id ) );
		$now   = current_time( 'mysql', true );
		$data  = array(
			'customer_id'            => $customer_id,
			'product_id'             => $product_id,
			'stripe_subscription_id' => (string) $subscription->id,
			'stripe_status'          => sanitize_key( (string) $subscription->status ),
			'current_period_start'   => $this->date( $subscription->current_period_start ?? null ),
			'current_period_end'     => $this->date( $subscription->current_period_end ?? null ),
			'cancel_at_period_end'   => empty( $subscription->cancel_at_period_end ) ? 0 : 1,
			'updated_at'             => $now,
		);
		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) );
			return (int) $id; }
		$data['created_at'] = $now;
		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id;
	}

	private function unique_login( string $email ): string {
		$base  = sanitize_user( (string) strtok( $email, '@' ), true );
		$base  = '' === $base ? 'customer' : $base;
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			++$i;
			$login = $base . '-' . $i;
		} return $login; }
	private function date( $timestamp ): ?string {
		return $timestamp ? gmdate( 'Y-m-d H:i:s', (int) $timestamp ) : null; }
	private function license_status( string $stripe_status, bool $cancel_at_end, int $end ): string {
		if ( $cancel_at_end && $end > time() ) {
			return 'active';
		} if ( 'canceled' === $stripe_status ) {
			return 'cancelled';
		} return in_array( $stripe_status, array( 'active', 'trialing' ), true ) ? 'active' : ( 'unpaid' === $stripe_status ? 'expired' : 'inactive' ); }
	private function exists( string $event_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}odph_webhook_logs WHERE stripe_event_id = %s", $event_id ) ); }
	private function log( string $id, string $type, string $payload, string $result, ?string $error ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'odph_webhook_logs',
			array(
				'stripe_event_id' => $id,
				'event_type'      => $type,
				'payload'         => $payload,
				'result'          => $result,
				'error_message'   => $error,
				'created_at'      => current_time( 'mysql', true ),
			)
		); }
}
