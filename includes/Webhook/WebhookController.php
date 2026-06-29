<?php
/**
 * Stripe webhook endpoint and synchronization.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Webhook;

use OD_Product_Hub\Customer\CustomerRepository;
use OD_Product_Hub\Customer\CustomerSyncService;
use OD_Product_Hub\Database\UtcDateTime;
use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Log\WebhookLogRepository;
use OD_Product_Hub\Product\ProductRepository;
use OD_Product_Hub\Stripe\StripeClientFactory;
use OD_Product_Hub\Subscription\SubscriptionRepository;
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
		$existing_log = ( new WebhookLogRepository() )->find_by_event_id( (string) $event->id );
		if ( $existing_log ) {
			( new WebhookLogRepository() )->update( (int) $existing_log->id, array( 'result' => 'duplicated_event' ) );
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
		$customer_id = ( new CustomerSyncService() )->upsert_customer(
			(string) $session->customer->id,
			(int) $user->ID,
			$email,
			(string) ( $session->customer_details->name ?? '' )
		);
		$product     = ( new ProductRepository() )->find_by_price( (string) $session->subscription->items->data[0]->price->id );
		if ( ! $product ) {
			throw new \RuntimeException( 'No product matches the Stripe Price ID.' ); }
		$subscription_id = $this->upsert_subscription( $customer_id, (int) $product->id, $session->subscription );
		if ( ! ( new LicenseRepository() )->exists_for_subscription( $subscription_id ) ) {
			$key = ( new LicenseRepository() )->issue( (int) $product->id, $customer_id, $subscription_id, $this->date( $session->subscription->current_period_end ?? null ) );
			wp_mail( $email, 'ご購入ありがとうございます', "契約手続きが完了しました。\nライセンスキー: {$key}\nマイページからも確認できます。" );
		}
	}

	private function subscription( object $subscription, bool $deleted ): void {
		$customer = ( new CustomerRepository() )->find_by_stripe_id( (string) $subscription->customer );
		$product  = ( new ProductRepository() )->find_by_price( (string) $subscription->items->data[0]->price->id );
		if ( ! $customer || ! $product ) {
			throw new \RuntimeException( 'Subscription customer or product is not registered.' ); }
		$id     = $this->upsert_subscription( (int) $customer->id, (int) $product->id, $subscription );
		$status = $deleted ? 'cancelled' : $this->license_status( (string) $subscription->status, (bool) $subscription->cancel_at_period_end, (int) ( $subscription->current_period_end ?? 0 ) );
		( new LicenseRepository() )->update_by_subscription(
			$id,
			array(
				'status'     => $status,
				'expires_at' => $this->date( $subscription->current_period_end ?? null ),
			)
		);
	}

	private function invoice( object $invoice, bool $failed ): void {
		$stripe_id    = is_object( $invoice->subscription ?? null ) ? $invoice->subscription->id : (string) ( $invoice->subscription ?? '' );
		$subscription = ( new SubscriptionRepository() )->find_by_stripe_id( (string) $stripe_id );
		if ( ! $subscription ) {
			throw new \RuntimeException( 'Invoice subscription is not registered.' ); }
		( new SubscriptionRepository() )->update(
			(int) $subscription->id,
			array(
				'payment_failed_at' => $failed ? UtcDateTime::now() : null,
			)
		);
		( new LicenseRepository() )->update_by_subscription(
			(int) $subscription->id,
			array(
				'status' => $failed ? 'inactive' : 'active',
			)
		);
		if ( $failed ) {
			$customer = ( new CustomerRepository() )->find( (int) $subscription->customer_id );
			if ( $customer ) {
				wp_mail( $customer->email, 'お支払いを確認できませんでした', 'Stripe Customer Portal でお支払い方法をご確認ください。' );
			}
		}
	}

	private function upsert_subscription( int $customer_id, int $product_id, object $subscription ): int {
		return ( new CustomerSyncService() )->upsert_subscription( $customer_id, $product_id, $subscription );
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
		return UtcDateTime::from_timestamp( $timestamp ); }
	private function license_status( string $stripe_status, bool $cancel_at_end, int $end ): string {
		if ( $cancel_at_end && $end > time() ) {
			return 'active';
		} if ( 'canceled' === $stripe_status ) {
			return 'cancelled';
		} return in_array( $stripe_status, array( 'active', 'trialing' ), true ) ? 'active' : ( 'unpaid' === $stripe_status ? 'expired' : 'inactive' ); }
	private function log( string $id, string $type, string $payload, string $result, ?string $error ): void {
		( new WebhookLogRepository() )->create(
			array(
				'stripe_event_id' => $id,
				'event_type'      => $type,
				'payload'         => $payload,
				'result'          => $result,
				'error_message'   => $error,
			)
		); }
}
