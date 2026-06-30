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
				'copy'          => __( 'コピー', 'od-product-hub' ),
				'copied'        => __( 'コピー済み', 'od-product-hub' ),
				'copySuccess'   => __( 'ライセンスキーをコピーしました。', 'od-product-hub' ),
				'copyError'     => __( 'コピーできませんでした。ライセンスキーを選択してコピーしてください。', 'od-product-hub' ),
				'portalLoading' => __( 'Stripeの支払い・契約管理画面を準備しています。', 'od-product-hub' ),
			)
		);
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
			$return_url = get_permalink();
			return sprintf(
				'<p class="odph-account-login">%1$s<a href="%2$s">%3$s</a>%4$s</p>',
				esc_html__( '契約情報を確認するには', 'od-product-hub' ),
				esc_url( wp_login_url( $return_url ? $return_url : home_url( '/' ) ) ),
				esc_html__( 'ログイン', 'od-product-hub' ),
				esc_html__( 'してください。', 'od-product-hub' )
			);
		}
		$user_id  = get_current_user_id();
		$rows     = ( new LicenseRepository() )->find_for_user( $user_id );
		$customer = ( new CustomerRepository() )->find_by_user_id( $user_id );
		$settings = (array) get_option( 'odph_settings', array() );
		wp_enqueue_style( 'odph-frontend' );
		wp_enqueue_script( 'odph-frontend' );
		$html  = '<div class="odph-account"><h2>' . esc_html__( '契約中の商品', 'od-product-hub' ) . '</h2>';
		$html .= $this->portal_error_notice();
		if ( ! $rows ) {
			$html .= '<p class="odph-notice" role="status">' . esc_html__( '契約情報はまだありません。購入直後の場合は、同期が完了するまで少しお待ちください。', 'od-product-hub' ) . '</p>';
		} else {
			$html .= '<div class="odph-contract-list" role="list">';
		}
		foreach ( $rows as $row ) {
			$status_id  = 'odph-copy-status-' . absint( $row->license_id );
			$period_end = $this->format_account_date( $row->current_period_end ?? null );
			$html      .= '<article class="odph-contract" role="listitem"><h3>' . esc_html( (string) $row->product_name ) . '</h3><dl>';
			$html      .= '<dt>' . esc_html__( 'ライセンスキー', 'od-product-hub' ) . '</dt><dd><code class="odph-key">' . esc_html( (string) $row->license_key ) . '</code> <button type="button" class="odph-copy" data-license="' . esc_attr( (string) $row->license_key ) . '" aria-describedby="' . esc_attr( $status_id ) . '">' . esc_html__( 'コピー', 'od-product-hub' ) . '</button><span id="' . esc_attr( $status_id ) . '" class="screen-reader-text odph-copy-status" aria-live="polite"></span></dd>';
			$html      .= '<dt>' . esc_html__( 'ライセンス状態', 'od-product-hub' ) . '</dt><dd><span class="odph-status odph-status-license-' . esc_attr( sanitize_html_class( (string) $row->license_status ) ) . '">' . esc_html( $this->license_status_label( (string) $row->license_status ) ) . '</span></dd>';
			$html      .= '<dt>' . esc_html__( 'Stripe契約状態', 'od-product-hub' ) . '</dt><dd><span class="odph-status odph-status-stripe-' . esc_attr( sanitize_html_class( (string) ( $row->stripe_status ?? 'syncing' ) ) ) . '">' . esc_html( $this->stripe_status_label( (string) ( $row->stripe_status ?? '' ) ) ) . '</span></dd>';
			$html      .= '<dt>' . esc_html__( '次回更新・期間終了日', 'od-product-hub' ) . '</dt><dd><time>' . esc_html( $period_end ) . '</time></dd>';
			$html      .= '<dt>' . esc_html__( '契約の予定', 'od-product-hub' ) . '</dt><dd>' . ( ! empty( $row->cancel_at_period_end ) ? esc_html__( '期間終了時に解約予定', 'od-product-hub' ) : esc_html__( '自動更新予定', 'od-product-hub' ) ) . '</dd></dl></article>';
		}
		if ( $rows ) {
			$html .= '</div>';
		}
		$html .= $this->portal_controls( $settings, $customer );
		$html .= '<nav class="odph-account-links" aria-label="' . esc_attr__( 'アカウント操作', 'od-product-hub' ) . '"><a href="' . esc_url( wp_lostpassword_url() ) . '">' . esc_html__( 'パスワードを変更', 'od-product-hub' ) . '</a><span aria-hidden="true"> · </span><a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'ログアウト', 'od-product-hub' ) . '</a></nav></div>';
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
			return '<p class="odph-notice" role="status">' . esc_html__( 'Stripeでの支払い・契約管理は現在利用できません。', 'od-product-hub' ) . '</p>';
		}
		if ( ! $customer || ! preg_match( '/^cus_[A-Za-z0-9]+$/', (string) $customer->stripe_customer_id ) ) {
			return '<p class="odph-notice" role="status">' . esc_html__( 'Stripe顧客情報を同期しています。完了後に支払い・契約管理を利用できます。', 'od-product-hub' ) . '</p>';
		}
		if ( ! get_permalink( absint( $settings['account_page_id'] ?? 0 ) ) ) {
			return '<p class="odph-notice" role="status">' . esc_html__( 'Stripeでの支払い・契約管理は準備中です。', 'od-product-hub' ) . '</p>';
		}
		return '<form class="odph-portal-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="odph_portal">' . wp_nonce_field( 'odph_portal', '_wpnonce', true, false ) . '<button class="odph-button" type="submit"><span class="odph-button-label">' . esc_html__( '支払い・契約を Stripe で管理', 'od-product-hub' ) . '</span></button><span class="odph-submit-status screen-reader-text" aria-live="polite"></span></form>';
	}

	private function portal_error_notice(): string {
		if ( ! isset( $_GET['odph_portal_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only code set by the nonce-protected Portal action.
			return '';
		}
		$code     = sanitize_key( wp_unslash( $_GET['odph_portal_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'portal_disabled'                => __( 'Stripeでの支払い・契約管理は現在利用できません。', 'od-product-hub' ),
			'customer_not_synced'            => __( 'Stripe顧客情報の同期が完了していません。時間をおいて再度お試しください。', 'od-product-hub' ),
			'portal_not_configured'          => __( '支払い・契約管理ページの準備が完了していません。', 'od-product-hub' ),
			'portal_temporarily_unavailable' => __( '現在Stripeの支払い・契約管理を開始できません。時間をおいて再度お試しください。', 'od-product-hub' ),
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
			return __( '未設定', 'od-product-hub' );
		}
		$formatted = UtcDateTime::to_site( $utc, (string) get_option( 'date_format', 'Y年n月j日' ) );
		return $formatted ? $formatted : __( '未設定', 'od-product-hub' );
	}

	private function license_status_label( string $status ): string {
		$labels = array(
			'active'    => __( '有効', 'od-product-hub' ),
			'inactive'  => __( '無効', 'od-product-hub' ),
			'expired'   => __( '期限切れ', 'od-product-hub' ),
			'cancelled' => __( '解約済み', 'od-product-hub' ),
			'suspended' => __( '停止中', 'od-product-hub' ),
		);
		return $labels[ $status ] ?? __( '状態不明', 'od-product-hub' );
	}

	private function stripe_status_label( string $status ): string {
		$labels = array(
			'active'             => __( '有効', 'od-product-hub' ),
			'trialing'           => __( '試用中', 'od-product-hub' ),
			'past_due'           => __( '支払い遅延', 'od-product-hub' ),
			'unpaid'             => __( '未払い', 'od-product-hub' ),
			'canceled'           => __( '解約済み', 'od-product-hub' ),
			'incomplete'         => __( '手続き未完了', 'od-product-hub' ),
			'incomplete_expired' => __( '手続き期限切れ', 'od-product-hub' ),
			'paused'             => __( '一時停止中', 'od-product-hub' ),
		);
		return $labels[ $status ] ?? __( '同期中', 'od-product-hub' );
	}
}
