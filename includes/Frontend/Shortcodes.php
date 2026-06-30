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
use OD_Product_Hub\Stripe\CheckoutService;
use OD_Product_Hub\Stripe\CheckoutException;
use OD_Product_Hub\Product\ProductRepository;

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
	}

	/** @param array<string, mixed> $atts */
	public function checkout( array $atts ): string {
		$atts = shortcode_atts( array( 'product' => '' ), $atts, 'odph_checkout' );
		$slug = sanitize_key( (string) $atts['product'] );
		if ( ! $slug ) {
			return '<p role="alert">商品が指定されていません。</p>'; }
		$product = ( new ProductRepository() )->find_by_slug( $slug );
		if ( ! $product || 'active' !== $product->status ) {
			return '<p role="alert">この商品は現在購入できません。</p>';
		}
		wp_enqueue_style( 'odph-frontend' );
		wp_enqueue_script( 'odph-frontend' );
		$error = '';
		if ( isset( $_GET['odph_checkout_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only code set by the nonce-protected Checkout action.
			$code     = sanitize_key( wp_unslash( $_GET['odph_checkout_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$messages = array(
				'product_unavailable'              => 'この商品は現在購入できません。',
				'checkout_not_configured'          => '購入手続きの準備が完了していません。管理者へお問い合わせください。',
				'checkout_temporarily_unavailable' => '現在購入手続きを開始できません。時間をおいて再度お試しください。',
			);
			$error    = isset( $messages[ $code ] ) ? '<p class="odph-alert" role="alert">' . esc_html( $messages[ $code ] ) . '</p>' : '';
		}
		$return_url = get_permalink();
		return sprintf( '<article class="odph-checkout"><h2>%1$s</h2>%2$s%3$s%4$s%5$s<form class="odph-checkout-form" method="post" action="%6$s"><input type="hidden" name="action" value="odph_checkout"><input type="hidden" name="product_slug" value="%7$s"><input type="hidden" name="return_url" value="%8$s">%9$s<button class="odph-button" type="submit"><span class="odph-button-label">Stripe Checkout で購入する</span></button><span class="odph-submit-status screen-reader-text" aria-live="polite"></span></form></article>', esc_html( (string) $product->name ), $error, '' !== (string) $product->description ? '<p class="odph-product-description">' . nl2br( esc_html( (string) $product->description ) ) . '</p>' : '', '' !== (string) $product->price_description ? '<p class="odph-price">' . esc_html( (string) $product->price_description ) . '</p>' : '', '' !== (string) $product->billing_description ? '<p class="odph-billing-description">' . nl2br( esc_html( (string) $product->billing_description ) ) . '</p>' : '', esc_url( admin_url( 'admin-post.php' ) ), esc_attr( $slug ), esc_url( $return_url ? $return_url : home_url( '/' ) ), wp_nonce_field( 'odph_checkout_' . $slug, '_wpnonce', true, false ) );
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
		return '<section class="odph-result odph-result-success" role="status"><h2>ご購入ありがとうございます</h2><p>契約処理を受け付けました。ライセンス情報はメールとマイページからご確認いただけます。</p><p><a class="odph-button" href="' . esc_url( $url ) . '">マイページを確認する</a></p></section>';
	}

	/** @param array<string, mixed> $atts */
	public function checkout_cancel( array $atts = array() ): string {
		$atts       = shortcode_atts( array( 'return_url' => home_url( '/' ) ), $atts, 'odph_checkout_cancel' );
		$return_url = wp_validate_redirect( esc_url_raw( (string) $atts['return_url'] ), home_url( '/' ) );
		wp_enqueue_style( 'odph-frontend' );
		return '<section class="odph-result odph-result-cancel"><h2>購入手続きはキャンセルされました</h2><p>料金は発生していません。内容をご確認のうえ、いつでも購入手続きを再開できます。</p><p><a class="odph-button" href="' . esc_url( $return_url ) . '">購入ページへ戻る</a></p></section>';
	}

	public function account(): string {
		if ( ! is_user_logged_in() ) {
			return sprintf( '<p>契約情報を確認するには<a href="%s">ログイン</a>してください。</p>', esc_url( wp_login_url( get_permalink() ) ) ); }
		$user_id = get_current_user_id();
		$rows    = ( new LicenseRepository() )->find_for_user( $user_id );
		wp_enqueue_style( 'odph-frontend' );
		wp_enqueue_script( 'odph-frontend' );
		if ( ! $rows ) {
			return '<div class="odph-account"><p>契約情報はまだありません。</p></div>'; }
		$html = '<div class="odph-account"><h2>契約中の商品</h2>';
		foreach ( $rows as $row ) {
			$period_end = $row->current_period_end ? UtcDateTime::to_site( $row->current_period_end ) : '—';
			$html      .= '<article class="odph-contract"><h3>' . esc_html( $row->product_name ) . '</h3><dl><dt>ライセンスキー</dt><dd><code class="odph-key">' . esc_html( $row->license_key ) . '</code> <button type="button" class="odph-copy" data-license="' . esc_attr( $row->license_key ) . '">コピー</button><span class="screen-reader-text odph-copy-status" aria-live="polite"></span></dd><dt>契約検証状態</dt><dd>' . esc_html( $row->license_status ) . '</dd><dt>Stripe状態</dt><dd>' . esc_html( $row->stripe_status ) . '</dd><dt>次回更新・期間終了</dt><dd>' . esc_html( $period_end ) . '</dd><dt>解約予約</dt><dd>' . ( $row->cancel_at_period_end ? 'あり' : 'なし' ) . '</dd></dl></article>';
		}
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="odph_portal">' . wp_nonce_field( 'odph_portal', '_wpnonce', true, false ) . '<button class="odph-button">支払い・契約を Stripe で管理</button></form><p><a href="' . esc_url( wp_lostpassword_url() ) . '">パスワードを変更</a> · <a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">ログアウト</a></p></div>';
		return $html;
	}

	public function start_portal(): void {
		if ( ! is_user_logged_in() ) {
			auth_redirect(); }
		check_admin_referer( 'odph_portal' );
		$customer = ( new CustomerRepository() )->find_by_user_id( get_current_user_id() );
		if ( ! $customer ) {
			wp_die( 'Customer not found.', '', array( 'response' => 404 ) ); }
		try {
			wp_redirect( ( new CheckoutService() )->portal_url( $customer->stripe_customer_id ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Stripe SDK returns the trusted Portal URL.
			exit;
		} catch ( \Throwable $error ) {
			wp_die( esc_html( $error->getMessage() ), 'Portal error', array( 'response' => 400 ) ); }
	}
}
