<?php
/**
 * Checkout and account shortcodes.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Frontend;

use OD_Product_Hub\Customer\CustomerRepository;
use OD_Product_Hub\Database\UtcDateTime;
use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Product\ProductRepository;
use OD_Product_Hub\Stripe\CheckoutException;
use OD_Product_Hub\Stripe\CheckoutService;
use OD_Product_Hub\Stripe\PortalException;

final class Shortcodes {
	public function register(): void {
		add_shortcode( 'odph_checkout', array( $this, 'checkout' ) );
		add_shortcode( 'odph_my_account', array( $this, 'account' ) );
		add_shortcode( 'odph_checkout_success', array( $this, 'checkout_success' ) );
		add_shortcode( 'odph_checkout_cancel', array( $this, 'checkout_cancel' ) );
		add_action( 'admin_post_nopriv_odph_checkout', array( $this, 'start_checkout' ) );
		add_action( 'admin_post_odph_checkout', array( $this, 'start_checkout' ) );
		add_action( 'admin_post_odph_portal', array( $this, 'start_portal' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets(): void {
		wp_register_style( 'odph-frontend', OD_PRODUCT_HUB_URL . 'assets/css/frontend.css', array(), OD_PRODUCT_HUB_VERSION );
		wp_register_script( 'odph-frontend', OD_PRODUCT_HUB_URL . 'assets/js/frontend.js', array(), OD_PRODUCT_HUB_VERSION, true );
		wp_localize_script(
			'odph-frontend',
			'odphFrontend',
			array(
				'copy'                  => __( 'Copy', 'od-product-hub' ),
				'copied'                => __( 'Copied', 'od-product-hub' ),
				'copySuccess'           => __( 'License key copied.', 'od-product-hub' ),
				'copyError'             => __( 'Could not copy the license key. Select the key and copy it manually.', 'od-product-hub' ),
				'checkoutButtonLoading' => __( 'Preparing checkout…', 'od-product-hub' ),
				'checkoutLoading'       => __( 'Preparing Stripe Checkout.', 'od-product-hub' ),
				'portalLoading'         => __( 'Preparing Stripe billing and subscription management.', 'od-product-hub' ),
			)
		);
	}

	/** @param array<string, mixed> $atts */
	public function checkout( array $atts ): string {
		$atts = shortcode_atts( array( 'product' => '' ), $atts, 'odph_checkout' );
		$slug = sanitize_key( (string) $atts['product'] );
		if ( ! $slug ) {
			return '<p role="alert">' . esc_html__( 'No product was specified.', 'od-product-hub' ) . '</p>'; }
		$product = ( new ProductRepository() )->find_by_slug( $slug );
		if ( ! $product || 'active' !== $product->status ) {
			return '<p role="alert">' . esc_html__( 'This product is currently unavailable.', 'od-product-hub' ) . '</p>';
		}
		wp_enqueue_style( 'odph-frontend' );
		wp_enqueue_script( 'odph-frontend' );
		$error = '';
		if ( isset( $_GET['odph_checkout_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only code set by the nonce-protected Checkout action.
			$code     = sanitize_key( wp_unslash( $_GET['odph_checkout_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$messages = array(
				'product_unavailable'              => __( 'This product is currently unavailable.', 'od-product-hub' ),
				'checkout_not_configured'          => __( 'Checkout is not configured. Contact the site administrator.', 'od-product-hub' ),
				'checkout_temporarily_unavailable' => __( 'Checkout is temporarily unavailable. Please try again later.', 'od-product-hub' ),
			);
			$error    = isset( $messages[ $code ] ) ? '<p class="odph-alert" role="alert">' . esc_html( $messages[ $code ] ) . '</p>' : '';
		}
		$return_url = get_permalink();
		return sprintf( '<article class="odph-checkout"><h2>%1$s</h2>%2$s%3$s%4$s%5$s<form class="odph-checkout-form" method="post" action="%6$s"><input type="hidden" name="action" value="odph_checkout"><input type="hidden" name="product_slug" value="%7$s"><input type="hidden" name="return_url" value="%8$s">%9$s<button class="odph-button" type="submit"><span class="odph-button-label">%10$s</span></button><span class="odph-submit-status screen-reader-text" aria-live="polite"></span></form></article>', esc_html( (string) $product->name ), $error, '' !== (string) $product->description ? '<p class="odph-product-description">' . nl2br( esc_html( (string) $product->description ) ) . '</p>' : '', '' !== (string) $product->price_description ? '<p class="odph-price">' . esc_html( (string) $product->price_description ) . '</p>' : '', '' !== (string) $product->billing_description ? '<p class="odph-billing-description">' . nl2br( esc_html( (string) $product->billing_description ) ) . '</p>' : '', esc_url( admin_url( 'admin-post.php' ) ), esc_attr( $slug ), esc_url( $return_url ? $return_url : home_url( '/' ) ), wp_nonce_field( 'odph_checkout_' . $slug, '_wpnonce', true, false ), esc_html__( 'Purchase with Stripe Checkout', 'od-product-hub' ) );
	}

	public function start_checkout(): void {
		$slug = sanitize_key( wp_unslash( $_POST['product_slug'] ?? '' ) );
		check_admin_referer( 'odph_checkout_' . $slug );
		$return_url = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['return_url'] ?? '' ) ), home_url( '/' ) );
		try {
			wp_redirect( ( new CheckoutService() )->checkout_url( $slug ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Stripe SDK returns the trusted Checkout URL.
			exit;
		} catch ( CheckoutException $error ) {
			wp_safe_redirect( add_query_arg( 'odph_checkout_error', $error->error_code, $return_url ) );
			exit;
		}
	}

	/** @param array<string, mixed> $atts */
	public function checkout_success( array $atts = array() ): string {
		unset( $atts );
		$settings = (array) get_option( 'odph_settings', array() );
		$page_id  = absint( $settings['account_page_id'] ?? 0 );
		$url      = $page_id ? get_permalink( $page_id ) : home_url( '/' );
		wp_enqueue_style( 'odph-frontend' );
		return '<section class="odph-result odph-result-success" role="status"><h2>' . esc_html__( 'Thank you for your purchase', 'od-product-hub' ) . '</h2><p>' . esc_html__( 'Your subscription is being processed. You can find your license information in your email and account page.', 'od-product-hub' ) . '</p><p><a class="odph-button" href="' . esc_url( $url ) . '">' . esc_html__( 'View account', 'od-product-hub' ) . '</a></p></section>';
	}

	/** @param array<string, mixed> $atts */
	public function checkout_cancel( array $atts = array() ): string {
		$atts       = shortcode_atts( array( 'return_url' => home_url( '/' ) ), $atts, 'odph_checkout_cancel' );
		$return_url = wp_validate_redirect( esc_url_raw( (string) $atts['return_url'] ), home_url( '/' ) );
		wp_enqueue_style( 'odph-frontend' );
		return '<section class="odph-result odph-result-cancel"><h2>' . esc_html__( 'Checkout was canceled', 'od-product-hub' ) . '</h2><p>' . esc_html__( 'You have not been charged. You can review the product and restart checkout at any time.', 'od-product-hub' ) . '</p><p><a class="odph-button" href="' . esc_url( $return_url ) . '">' . esc_html__( 'Return to product', 'od-product-hub' ) . '</a></p></section>';
	}

	public function account(): string {
		if ( ! is_user_logged_in() ) {
			$return_url = get_permalink();
			return sprintf(
				'<p class="odph-account-login">%1$s<a href="%2$s">%3$s</a>%4$s</p>',
				esc_html__( 'To view your subscription, ', 'od-product-hub' ),
				esc_url( wp_login_url( $return_url ? $return_url : home_url( '/' ) ) ),
				esc_html__( 'log in', 'od-product-hub' ),
				esc_html__( '.', 'od-product-hub' )
			);
		}
		$user_id  = get_current_user_id();
		$rows     = ( new LicenseRepository() )->find_for_user( $user_id );
		$customer = ( new CustomerRepository() )->find_by_user_id( $user_id );
		$settings = (array) get_option( 'odph_settings', array() );
		wp_enqueue_style( 'odph-frontend' );
		wp_enqueue_script( 'odph-frontend' );
		$html  = '<div class="odph-account"><h2>' . esc_html__( 'Subscribed products', 'od-product-hub' ) . '</h2>';
		$html .= $this->portal_error_notice();
		if ( ! $rows ) {
			$html .= '<p class="odph-notice" role="status">' . esc_html__( 'No subscription information is available yet. If you just purchased, wait a moment for synchronization to complete.', 'od-product-hub' ) . '</p>';
		} else {
			$html .= '<div class="odph-contract-list" role="list">';
		}
		foreach ( $rows as $row ) {
			$status_id  = 'odph-copy-status-' . absint( $row->license_id );
			$period_end = $this->format_account_date( $row->current_period_end ?? null );
			$html      .= '<article class="odph-contract" role="listitem"><h3>' . esc_html( (string) $row->product_name ) . '</h3><dl>';
			$html      .= '<dt>' . esc_html__( 'License key', 'od-product-hub' ) . '</dt><dd><code class="odph-key">' . esc_html( (string) $row->license_key ) . '</code> <button type="button" class="odph-copy" data-license="' . esc_attr( (string) $row->license_key ) . '" aria-describedby="' . esc_attr( $status_id ) . '">' . esc_html__( 'Copy', 'od-product-hub' ) . '</button><span id="' . esc_attr( $status_id ) . '" class="screen-reader-text odph-copy-status" aria-live="polite"></span></dd>';
			$html      .= '<dt>' . esc_html__( 'License status', 'od-product-hub' ) . '</dt><dd><span class="odph-status odph-status-license-' . esc_attr( sanitize_html_class( (string) $row->license_status ) ) . '">' . esc_html( $this->license_status_label( (string) $row->license_status ) ) . '</span></dd>';
			$html      .= '<dt>' . esc_html__( 'Stripe subscription status', 'od-product-hub' ) . '</dt><dd><span class="odph-status odph-status-stripe-' . esc_attr( sanitize_html_class( (string) ( $row->stripe_status ?? 'syncing' ) ) ) . '">' . esc_html( $this->stripe_status_label( (string) ( $row->stripe_status ?? '' ) ) ) . '</span></dd>';
			$html      .= '<dt>' . esc_html__( 'Next renewal or end date', 'od-product-hub' ) . '</dt><dd><time>' . esc_html( $period_end ) . '</time></dd>';
			$html      .= '<dt>' . esc_html__( 'Subscription schedule', 'od-product-hub' ) . '</dt><dd>' . ( ! empty( $row->cancel_at_period_end ) ? esc_html__( 'Scheduled to cancel at period end', 'od-product-hub' ) : esc_html__( 'Scheduled to renew automatically', 'od-product-hub' ) ) . '</dd></dl></article>';
		}
		if ( $rows ) {
			$html .= '</div>';
		}
		$html .= $this->portal_controls( $settings, $customer );
		$html .= '<nav class="odph-account-links" aria-label="' . esc_attr__( 'Account actions', 'od-product-hub' ) . '"><a href="' . esc_url( wp_lostpassword_url() ) . '">' . esc_html__( 'Change password', 'od-product-hub' ) . '</a><span aria-hidden="true"> · </span><a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Log out', 'od-product-hub' ) . '</a></nav></div>';
		return $html;
	}

	public function start_portal(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
			auth_redirect();
		}
		check_admin_referer( 'odph_portal' );
		try {
			wp_redirect( ( new CheckoutService() )->portal_url_for_current_user() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Stripe SDK returns the trusted Portal URL.
			exit;
		} catch ( PortalException $error ) {
			wp_safe_redirect( add_query_arg( 'odph_portal_error', $error->error_code, $this->account_url() ) );
			exit;
		}
	}

	/** @param array<string, mixed> $settings */
	private function portal_controls( array $settings, ?object $customer ): string {
		if ( empty( $settings['portal_enabled'] ) ) {
			return '<p class="odph-notice" role="status">' . esc_html__( 'Stripe billing and subscription management is currently unavailable.', 'od-product-hub' ) . '</p>';
		}
		if ( ! $customer || ! preg_match( '/^cus_[A-Za-z0-9]+$/', (string) $customer->stripe_customer_id ) ) {
			return '<p class="odph-notice" role="status">' . esc_html__( 'Your Stripe customer information is being synchronized. Billing and subscription management will be available when it completes.', 'od-product-hub' ) . '</p>';
		}
		if ( ! get_permalink( absint( $settings['account_page_id'] ?? 0 ) ) ) {
			return '<p class="odph-notice" role="status">' . esc_html__( 'Stripe billing and subscription management is being configured.', 'od-product-hub' ) . '</p>';
		}
		return '<form class="odph-portal-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="odph_portal">' . wp_nonce_field( 'odph_portal', '_wpnonce', true, false ) . '<button class="odph-button" type="submit"><span class="odph-button-label">' . esc_html__( 'Manage billing and subscription in Stripe', 'od-product-hub' ) . '</span></button><span class="odph-submit-status screen-reader-text" aria-live="polite"></span></form>';
	}

	private function portal_error_notice(): string {
		if ( ! isset( $_GET['odph_portal_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only code set by the nonce-protected Portal action.
			return '';
		}
		$code     = sanitize_key( wp_unslash( $_GET['odph_portal_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'portal_disabled'                => __( 'Stripe billing and subscription management is currently unavailable.', 'od-product-hub' ),
			'customer_not_synced'            => __( 'Your Stripe customer information has not finished synchronizing. Please try again later.', 'od-product-hub' ),
			'portal_not_configured'          => __( 'The billing and subscription management page is not configured.', 'od-product-hub' ),
			'portal_temporarily_unavailable' => __( 'Stripe billing and subscription management is temporarily unavailable. Please try again later.', 'od-product-hub' ),
		);
		return isset( $messages[ $code ] ) ? '<p class="odph-alert" role="alert">' . esc_html( $messages[ $code ] ) . '</p>' : '';
	}

	private function account_url(): string {
		$settings = (array) get_option( 'odph_settings', array() );
		$url      = get_permalink( absint( $settings['account_page_id'] ?? 0 ) );
		return $url ? $url : home_url( '/' );
	}

	private function format_account_date( ?string $utc ): string {
		if ( ! $utc ) {
			return __( 'Not set', 'od-product-hub' );
		}
		$formatted = UtcDateTime::to_site( $utc, (string) get_option( 'date_format', 'F j, Y' ) );
		return $formatted ? $formatted : __( 'Not set', 'od-product-hub' );
	}

	private function license_status_label( string $status ): string {
		$labels = array(
			'active'    => __( 'Active', 'od-product-hub' ),
			'inactive'  => __( 'Inactive', 'od-product-hub' ),
			'expired'   => __( 'Expired', 'od-product-hub' ),
			'cancelled' => __( 'Canceled', 'od-product-hub' ),
			'suspended' => __( 'Suspended', 'od-product-hub' ),
		);
		return $labels[ $status ] ?? __( 'Unknown status', 'od-product-hub' );
	}

	private function stripe_status_label( string $status ): string {
		$labels = array(
			'active'             => __( 'Active', 'od-product-hub' ),
			'trialing'           => __( 'Trialing', 'od-product-hub' ),
			'past_due'           => __( 'Past due', 'od-product-hub' ),
			'unpaid'             => __( 'Unpaid', 'od-product-hub' ),
			'canceled'           => __( 'Canceled', 'od-product-hub' ),
			'incomplete'         => __( 'Incomplete', 'od-product-hub' ),
			'incomplete_expired' => __( 'Incomplete expired', 'od-product-hub' ),
			'paused'             => __( 'Paused', 'od-product-hub' ),
		);
		return $labels[ $status ] ?? __( 'Synchronizing', 'od-product-hub' );
	}
}
