<?php
/**
 * Synchronizes a completed Stripe Checkout Session.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Webhook\Handler;

use OD_Product_Hub\Customer\CustomerSyncService;
use OD_Product_Hub\Database\UtcDateTime;
use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Product\ProductRepository;
use OD_Product_Hub\Stripe\StripeClientFactory;

final class CheckoutCompletedHandler implements WebhookHandler {
	/** @var callable(object): object */
	private $session_loader;

	/** @param null|callable(object): object $session_loader */
	public function __construct( ?callable $session_loader = null ) {
		$this->session_loader = $session_loader ?? static fn( object $session ): object => StripeClientFactory::create()->checkout->sessions->retrieve(
			(string) $session->id,
			array( 'expand' => array( 'customer', 'subscription', 'subscription.items.data.price' ) )
		);
	}

	public function handle( string $event_type, object $object ): void {
		unset( $event_type );
		$session = ( $this->session_loader )( $object );
		$email   = sanitize_email( (string) ( $session->customer_details->email ?? $session->customer->email ?? '' ) );
		if ( ! is_email( $email ) ) {
			throw new \RuntimeException( 'Checkout customer email is missing.' );
		}
		$product = ( new ProductRepository() )->find_by_price( (string) $session->subscription->items->data[0]->price->id );
		if ( ! $product ) {
			throw new \RuntimeException( 'No product matches the Stripe Price ID.' );
		}
		$user    = get_user_by( 'email', $email );
		$created = false;
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
				throw new \RuntimeException( 'WordPress customer creation failed.' );
			}
			$user    = get_user_by( 'id', $user_id );
			$created = true;
		}
		if ( ! $user ) {
			throw new \RuntimeException( 'WordPress customer could not be loaded.' );
		}
		$customer_id     = ( new CustomerSyncService() )->upsert_customer(
			(string) $session->customer->id,
			(int) $user->ID,
			$email,
			(string) ( $session->customer_details->name ?? '' )
		);
		$subscription_id = ( new CustomerSyncService() )->upsert_subscription( $customer_id, (int) $product->id, $session->subscription );
		if ( ! ( new LicenseRepository() )->exists_for_subscription( $subscription_id ) ) {
			$key = ( new LicenseRepository() )->issue( (int) $product->id, $customer_id, $subscription_id, UtcDateTime::from_timestamp( $session->subscription->current_period_end ?? null ), (string) $product->license_key_prefix );
			do_action( 'odph_webhook_purchase_completed', $email, $key, $created, (int) $user->ID );
		}
	}

	private function unique_login( string $email ): string {
		$base  = sanitize_user( (string) strtok( $email, '@' ), true );
		$base  = '' === $base ? 'customer' : $base;
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			++$i;
			$login = $base . '-' . $i;
		}
		return $login;
	}
}
