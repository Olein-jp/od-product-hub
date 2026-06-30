<?php
/**
 * Stripe Checkout and Customer Portal session creation.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Stripe;

use OD_Product_Hub\Customer\CustomerRepository;
use OD_Product_Hub\Product\ProductRepository;

final class CheckoutService {
	/** @var callable(array<string, mixed>): object */
	private $checkout_creator;

	/** @var callable(array<string, mixed>): object */
	private $portal_creator;

	/**
	 * @param null|callable(array<string, mixed>): object $checkout_creator
	 * @param null|callable(array<string, mixed>): object $portal_creator
	 */
	public function __construct( ?callable $checkout_creator = null, ?callable $portal_creator = null ) {
		$this->checkout_creator = $checkout_creator ?? static fn( array $args ): object => StripeClientFactory::create()->checkout->sessions->create( $args );
		$this->portal_creator   = $portal_creator ?? static fn( array $args ): object => StripeClientFactory::create()->billingPortal->sessions->create( $args );
	}

	public function checkout_url( string $product_slug ): string {
		$product  = ( new ProductRepository() )->find_by_slug( $product_slug );
		$settings = (array) get_option( 'odph_settings', array() );
		if ( ! $product || 'active' !== $product->status || ! preg_match( '/^price_[A-Za-z0-9]+$/', (string) $product->stripe_price_id ) ) {
			throw new CheckoutException( 'product_unavailable' );
		}
		$success_url = $this->configured_url( (string) ( $settings['success_url'] ?? '' ) );
		$cancel_url  = $this->configured_url( (string) ( $settings['cancel_url'] ?? '' ) );
		if ( ! $success_url || ! $cancel_url ) {
			throw new CheckoutException( 'checkout_not_configured' );
		}
		$args = array(
			'mode'                => 'subscription',
			'line_items'          => array(
				array(
					'price'    => $product->stripe_price_id,
					'quantity' => 1,
				),
			),
			'success_url'         => $success_url . ( str_contains( $success_url, '?' ) ? '&' : '?' ) . 'session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'          => $cancel_url,
			'client_reference_id' => (string) $product->id,
			'metadata'            => array(
				'odph_product_id'   => (string) $product->id,
				'odph_product_slug' => $product->slug,
			),
		);
		$this->apply_customer_context( $args );
		try {
			$session = ( $this->checkout_creator )( $args );
			$url     = (string) ( $session->url ?? '' );
			if ( ! $this->trusted_stripe_url( $url, 'checkout.stripe.com' ) ) {
				throw new \RuntimeException( 'Unexpected Checkout URL.' );
			}
			return $url;
		} catch ( CheckoutException $error ) {
			throw $error;
		} catch ( \Throwable $error ) {
			$this->log_internal_error( 'checkout_session_failed', $error );
			throw new CheckoutException( 'checkout_temporarily_unavailable' );
		}
	}

	public function portal_url_for_current_user(): string {
		try {
			$settings = (array) get_option( 'odph_settings', array() );
			if ( empty( $settings['portal_enabled'] ) ) {
				throw new PortalException( 'portal_disabled' );
			}
			$user_id = get_current_user_id();
			if ( 0 === $user_id ) {
				throw new PortalException( 'login_required' );
			}
			$customer    = ( new CustomerRepository() )->find_by_user_id( $user_id );
			$customer_id = $customer ? (string) $customer->stripe_customer_id : '';
			$return_url  = get_permalink( (int) ( $settings['account_page_id'] ?? 0 ) );
			if ( ! preg_match( '/^cus_[A-Za-z0-9]+$/', $customer_id ) ) {
				throw new PortalException( 'customer_not_synced' );
			}
			if ( ! $return_url ) {
				throw new PortalException( 'portal_not_configured' );
			}
			$session = ( $this->portal_creator )(
				array(
					'customer'   => $customer_id,
					'return_url' => $return_url,
				)
			);
			$url     = (string) ( $session->url ?? '' );
			if ( ! $this->trusted_stripe_url( $url, 'billing.stripe.com' ) ) {
				throw new \RuntimeException( 'Unexpected Portal URL.' );
			}
			return $url;
		} catch ( PortalException $error ) {
			throw $error;
		} catch ( \Throwable $error ) {
			$this->log_internal_error( 'portal_session_failed', $error );
			throw new PortalException( 'portal_temporarily_unavailable' );
		}
	}

	/** @param array<string, mixed> $args */
	private function apply_customer_context( array &$args ): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$user_id  = get_current_user_id();
		$customer = ( new CustomerRepository() )->find_by_user_id( $user_id );
		if ( $customer && preg_match( '/^cus_[A-Za-z0-9]+$/', (string) $customer->stripe_customer_id ) ) {
			$args['customer'] = (string) $customer->stripe_customer_id;
		} else {
			$user = get_userdata( $user_id );
			if ( $user && is_email( $user->user_email ) ) {
				$args['customer_email'] = (string) $user->user_email;
			}
		}
		$args['metadata']['odph_wp_user_id'] = (string) $user_id;
	}

	private function configured_url( string $url ): ?string {
		$url   = esc_url_raw( $url, array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );
		if (
			'' === $url ||
			false === $parts ||
			empty( $parts['host'] ) ||
			! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ||
			isset( $parts['user'] ) ||
			isset( $parts['pass'] )
		) {
			return null;
		}
		$scheme = (string) $parts['scheme'];
		if ( 'production' === wp_get_environment_type() && 'https' !== $scheme ) {
			return null;
		}
		return $url;
	}

	private function trusted_stripe_url( string $url, string $host ): bool {
		return 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && wp_parse_url( $url, PHP_URL_HOST ) === $host;
	}

	private function log_internal_error( string $code, \Throwable $error ): void {
		error_log( sprintf( 'OD Product Hub %s (%s).', $code, get_class( $error ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Internal class only; no Stripe message or secret is logged.
	}
}
