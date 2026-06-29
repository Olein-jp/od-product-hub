<?php
/**
 * Checkout and account shortcodes.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Frontend;

use OD_Product_Hub\Stripe\CheckoutService;

final class Shortcodes {
	public function register(): void {
		add_shortcode( 'odph_checkout', array( $this, 'checkout' ) );
		add_shortcode( 'odph_my_account', array( $this, 'account' ) );
		add_action( 'admin_post_nopriv_odph_checkout', array( $this, 'start_checkout' ) );
		add_action( 'admin_post_odph_checkout', array( $this, 'start_checkout' ) );
		add_action( 'admin_post_odph_portal', array( $this, 'start_portal' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets(): void {
		wp_register_style( 'odph-frontend', OD_PRODUCT_HUB_URL . 'assets/css/frontend.css', array(), OD_PRODUCT_HUB_VERSION );
		wp_register_script( 'odph-frontend', OD_PRODUCT_HUB_URL . 'assets/js/frontend.js', array(), OD_PRODUCT_HUB_VERSION, true );
	}

	/** @param array<string, mixed> $atts */
	public function checkout( array $atts ): string {
		$atts = shortcode_atts( array( 'product' => '' ), $atts, 'odph_checkout' );
		$slug = sanitize_key( (string) $atts['product'] );
		if ( ! $slug ) {
			return '<p role="alert">商品が指定されていません。</p>'; }
		wp_enqueue_style( 'odph-frontend' );
		return sprintf( '<form method="post" action="%s"><input type="hidden" name="action" value="odph_checkout"><input type="hidden" name="product_slug" value="%s">%s<button class="odph-button" type="submit">Stripe Checkout で購入する</button></form>', esc_url( admin_url( 'admin-post.php' ) ), esc_attr( $slug ), wp_nonce_field( 'odph_checkout_' . $slug, '_wpnonce', true, false ) );
	}

	public function start_checkout(): void {
		$slug = sanitize_key( wp_unslash( $_POST['product_slug'] ?? '' ) );
		check_admin_referer( 'odph_checkout_' . $slug );
		try {
			wp_redirect( ( new CheckoutService() )->checkout_url( $slug ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Stripe SDK returns the trusted Checkout URL.
			exit;
		} catch ( \Throwable $error ) {
			wp_die( esc_html( $error->getMessage() ), 'Checkout error', array( 'response' => 400 ) ); }
	}

	public function account(): string {
		if ( ! is_user_logged_in() ) {
			return sprintf( '<p>契約情報を確認するには<a href="%s">ログイン</a>してください。</p>', esc_url( wp_login_url( get_permalink() ) ) ); }
		global $wpdb;
		$user_id = get_current_user_id();
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.license_key, l.status AS license_status, l.expires_at, p.name AS product_name,
			 s.stripe_status, s.current_period_end, s.cancel_at_period_end, c.stripe_customer_id
			 FROM {$wpdb->prefix}odph_customers c INNER JOIN {$wpdb->prefix}odph_licenses l ON l.customer_id = c.id
			 INNER JOIN {$wpdb->prefix}odph_products p ON p.id = l.product_id LEFT JOIN {$wpdb->prefix}odph_subscriptions s ON s.id = l.subscription_id
			 WHERE c.wp_user_id = %d ORDER BY l.id DESC",
				$user_id
			)
		);
		wp_enqueue_style( 'odph-frontend' );
		wp_enqueue_script( 'odph-frontend' );
		if ( ! $rows ) {
			return '<div class="odph-account"><p>契約情報はまだありません。</p></div>'; }
		$html = '<div class="odph-account"><h2>契約中の商品</h2>';
		foreach ( $rows as $row ) {
			$period_end = $row->current_period_end ? $row->current_period_end : '—';
			$html      .= '<article class="odph-contract"><h3>' . esc_html( $row->product_name ) . '</h3><dl><dt>ライセンスキー</dt><dd><code class="odph-key">' . esc_html( $row->license_key ) . '</code> <button type="button" class="odph-copy" data-license="' . esc_attr( $row->license_key ) . '">コピー</button><span class="screen-reader-text odph-copy-status" aria-live="polite"></span></dd><dt>契約検証状態</dt><dd>' . esc_html( $row->license_status ) . '</dd><dt>Stripe状態</dt><dd>' . esc_html( $row->stripe_status ) . '</dd><dt>次回更新・期間終了</dt><dd>' . esc_html( $period_end ) . '</dd><dt>解約予約</dt><dd>' . ( $row->cancel_at_period_end ? 'あり' : 'なし' ) . '</dd></dl></article>';
		}
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="odph_portal">' . wp_nonce_field( 'odph_portal', '_wpnonce', true, false ) . '<button class="odph-button">支払い・契約を Stripe で管理</button></form><p><a href="' . esc_url( wp_lostpassword_url() ) . '">パスワードを変更</a> · <a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">ログアウト</a></p></div>';
		return $html;
	}

	public function start_portal(): void {
		if ( ! is_user_logged_in() ) {
			auth_redirect(); }
		check_admin_referer( 'odph_portal' );
		global $wpdb;
		$customer = $wpdb->get_row( $wpdb->prepare( "SELECT stripe_customer_id FROM {$wpdb->prefix}odph_customers WHERE wp_user_id = %d LIMIT 1", get_current_user_id() ) );
		if ( ! $customer ) {
			wp_die( 'Customer not found.', '', array( 'response' => 404 ) ); }
		try {
			wp_redirect( ( new CheckoutService() )->portal_url( $customer->stripe_customer_id ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Stripe SDK returns the trusted Portal URL.
			exit;
		} catch ( \Throwable $error ) {
			wp_die( esc_html( $error->getMessage() ), 'Portal error', array( 'response' => 400 ) ); }
	}
}
