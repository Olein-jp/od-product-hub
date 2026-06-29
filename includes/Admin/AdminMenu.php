<?php
/**
 * Administration pages.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Admin;

use OD_Product_Hub\Customer\CustomerRepository;
use OD_Product_Hub\Database\AbstractRepository;
use OD_Product_Hub\Database\DatabaseException;
use OD_Product_Hub\Database\Installer;
use OD_Product_Hub\Database\UtcDateTime;
use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Log\AdminLogRepository;
use OD_Product_Hub\Log\WebhookLogRepository;
use OD_Product_Hub\Product\ProductRepository;
use OD_Product_Hub\Subscription\SubscriptionRepository;
use OD_Product_Hub\Stripe\StripeClientFactory;

final class AdminMenu {
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_odph_save_product', array( $this, 'save_product' ) );
		add_action( 'admin_post_odph_test_stripe', array( $this, 'test_stripe_connection' ) );
		add_action( 'admin_notices', array( $this, 'configuration_notice' ) );
		add_filter( 'site_status_tests', array( $this, 'site_health_tests' ) );
	}

	public function assets(): void {
		$screen = get_current_screen();
		if ( $screen && ( str_contains( $screen->id, 'odph' ) || str_contains( $screen->id, 'od-product-hub' ) ) ) {
			wp_enqueue_style( 'odph-admin', OD_PRODUCT_HUB_URL . 'assets/css/admin.css', array(), OD_PRODUCT_HUB_VERSION );
		}
	}

	public function menu(): void {
		add_menu_page( 'OD Product Hub', 'OD Product Hub', 'manage_options', 'od-product-hub', array( $this, 'dashboard' ), 'dashicons-products', 56 );
		add_submenu_page( 'od-product-hub', '商品管理', '商品管理', 'manage_options', 'odph-products', array( $this, 'products' ) );
		add_submenu_page( 'od-product-hub', 'ライセンス管理', 'ライセンス管理', 'manage_options', 'odph-licenses', array( $this, 'licenses' ) );
		add_submenu_page( 'od-product-hub', '顧客・契約', '顧客・契約', 'manage_options', 'odph-customers', array( $this, 'customers' ) );
		add_submenu_page( 'od-product-hub', 'ログ', 'ログ', 'manage_options', 'odph-logs', array( $this, 'logs' ) );
		add_submenu_page( 'od-product-hub', '設定', '設定', 'manage_options', 'odph-settings', array( $this, 'settings_page' ) );
	}

	public function settings(): void {
		register_setting(
			'odph_settings_group',
			'odph_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => Installer::defaults(),
			)
		);
	}

	/** @param mixed $input @return array<string, mixed> */
	public function sanitize_settings( $input ): array {
		$current = get_option( 'odph_settings', Installer::defaults() );
		$input   = is_array( $input ) ? $input : array();
		foreach ( array( 'stripe_secret_key', 'stripe_publishable_key', 'stripe_webhook_secret' ) as $secret ) {
			if ( ! empty( $input[ $secret ] ) ) {
				$current[ $secret ] = sanitize_text_field( $input[ $secret ] ); }
		}
		$current['portal_enabled']  = empty( $input['portal_enabled'] ) ? 0 : 1;
		$current['success_url']     = $this->sanitize_url_setting( 'success_url', $input['success_url'] ?? '', $current );
		$current['cancel_url']      = $this->sanitize_url_setting( 'cancel_url', $input['cancel_url'] ?? '', $current );
		$current['account_page_id'] = absint( $input['account_page_id'] ?? 0 );
		$current['email_from_name'] = sanitize_text_field( $input['email_from_name'] ?? '' );
		$email                      = sanitize_email( $input['email_from_address'] ?? '' );
		if ( '' !== (string) ( $input['email_from_address'] ?? '' ) && ! is_email( $email ) ) {
			add_settings_error( 'odph_settings', 'invalid_email', __( '送信元メールアドレスが不正です。以前の値を維持しました。', 'od-product-hub' ) );
		} else {
			$current['email_from_address'] = $email;
		}
		$current['log_retention_days']  = $this->bounded_integer( 'log_retention_days', $input, $current, 1, 3650 );
		$current['api_rate_limit']      = $this->bounded_integer( 'api_rate_limit', $input, $current, 1, 1000 );
		$current['delete_on_uninstall'] = empty( $input['delete_on_uninstall'] ) ? 0 : 1;
		return $current;
	}

	/** @param mixed $value @param array<string, mixed> $current */
	private function sanitize_url_setting( string $key, $value, array $current ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$url = esc_url_raw( $value, array( 'http', 'https' ) );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			add_settings_error( 'odph_settings', 'invalid_' . $key, __( 'URLが不正です。以前の値を維持しました。', 'od-product-hub' ) );
			return (string) ( $current[ $key ] ?? '' );
		}
		return $url;
	}

	/** @param array<string, mixed> $input @param array<string, mixed> $current */
	private function bounded_integer( string $key, array $input, array $current, int $minimum, int $maximum ): int {
		$value = filter_var( $input[ $key ] ?? null, FILTER_VALIDATE_INT );
		if ( false === $value || $value < $minimum || $value > $maximum ) {
			add_settings_error( 'odph_settings', 'invalid_' . $key, __( '数値が許容範囲外です。以前の値を維持しました。', 'od-product-hub' ) );
			return (int) ( $current[ $key ] ?? $minimum );
		}
		return $value;
	}

	public function dashboard(): void {
		$this->guard();
		$counts = array(
			'有効ライセンス'    => ( new LicenseRepository() )->count_by_status( 'active' ),
			'停止ライセンス'    => ( new LicenseRepository() )->count_by_status( 'suspended' ),
			'支払い失敗'      => ( new SubscriptionRepository() )->count_payment_failures(),
			'Webhookエラー' => ( new WebhookLogRepository() )->count_by_result( 'error' ),
		);
		echo '<div class="wrap"><h1>OD Product Hub</h1><p>GPLコードの利用制限ではなく、有効な契約者向けサービスの契約検証基盤です。</p><div class="odph-cards">';
		foreach ( $counts as $label => $count ) {
			printf( '<div class="card"><h2>%s</h2><p class="odph-count">%d</p></div>', esc_html( $label ), esc_html( (string) $count ) ); }
		echo '</div></div>';
	}

	public function products(): void {
		$this->guard();
		$products = ( new ProductRepository() )->search( array(), 1, 100 )->items;
		echo '<div class="wrap"><h1>商品管理</h1><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="odph_save_product">';
		wp_nonce_field( 'odph_save_product' );
		echo '<table class="form-table"><tr><th><label for="name">商品名</label></th><td><input required class="regular-text" id="name" name="name"></td></tr><tr><th><label for="slug">スラッグ</label></th><td><input required pattern="[A-Za-z0-9_-]+" id="slug" name="slug"></td></tr><tr><th><label for="stripe_product_id">Stripe Product ID</label></th><td><input required pattern="prod_.+" id="stripe_product_id" name="stripe_product_id"></td></tr><tr><th><label for="stripe_price_id">Stripe Price ID</label></th><td><input required pattern="price_.+" id="stripe_price_id" name="stripe_price_id"></td></tr><tr><th>状態</th><td><select name="status"><option value="active">active</option><option value="inactive">inactive</option></select></td></tr></table><p><button class="button button-primary">商品を追加</button></p></form>';
		echo '<table class="widefat striped"><thead><tr><th>商品</th><th>スラッグ</th><th>Product ID</th><th>Price ID</th><th>状態</th></tr></thead><tbody>';
		foreach ( $products as $p ) {
			printf( '<tr><td>%s</td><td><code>%s</code></td><td><code>%s</code></td><td><code>%s</code></td><td>%s</td></tr>', esc_html( $p->name ), esc_html( $p->slug ), esc_html( $p->stripe_product_id ), esc_html( $p->stripe_price_id ), esc_html( $p->status ) ); }
		echo '</tbody></table></div>';
	}

	public function save_product(): void {
		$this->guard();
		check_admin_referer( 'odph_save_product' );
		$name       = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$slug       = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
		$product_id = sanitize_text_field( wp_unslash( $_POST['stripe_product_id'] ?? '' ) );
		$price_id   = sanitize_text_field( wp_unslash( $_POST['stripe_price_id'] ?? '' ) );
		if ( ! $name || ! preg_match( '/^[a-z0-9_-]+$/', $slug ) || ! str_starts_with( $product_id, 'prod_' ) || ! str_starts_with( $price_id, 'price_' ) ) {
			wp_die( esc_html__( '入力値が不正です。', 'od-product-hub' ), '', array( 'response' => 400 ) ); }
		try {
			$id = ( new ProductRepository() )->create(
				array(
					'name'              => $name,
					'slug'              => $slug,
					'description'       => '',
					'stripe_product_id' => $product_id,
					'stripe_price_id'   => $price_id,
					'status'            => in_array( $_POST['status'] ?? '', array( 'active', 'inactive' ), true ) ? $_POST['status'] : 'active',
				)
			);
			( new AdminLogRepository() )->create(
				array(
					'user_id'     => get_current_user_id(),
					'action'      => 'product_created',
					'object_type' => 'product',
					'object_id'   => $id,
					'details'     => wp_json_encode( array( 'slug' => $slug ) ),
				)
			);
		} catch ( DatabaseException $error ) {
			wp_die( esc_html( $error->getMessage() ), '', array( 'response' => 500 ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=odph-products' ) );
		exit;
	}

	public function licenses(): void {
		$this->table_page( 'ライセンス管理', 'licenses', array( 'license_key', 'status', 'expires_at', 'last_verified_at', 'created_at' ), true ); }
	public function customers(): void {
		$this->table_page( '顧客管理', 'customers', array( 'email', 'name', 'wp_user_id', 'stripe_customer_id', 'created_at' ) ); }
	public function logs(): void {
		$this->table_page( 'Webhookログ', 'webhook_logs', array( 'stripe_event_id', 'event_type', 'result', 'error_message', 'created_at' ) ); }

	/** @param string[] $columns */
	private function table_page( string $title, string $table, array $columns, bool $mask = false ): void {
		$this->guard();
		$repository = $this->repository( $table );
		$rows       = $repository->search( array(), 1, 100 )->items;
		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1><table class="widefat striped"><thead><tr>';
		foreach ( $columns as $column ) {
			echo '<th>' . esc_html( $column ) . '</th>';
		} echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( $columns as $column ) {
				$value = (string) ( $row->$column ?? '' );
				if ( $mask && 'license_key' === $column ) {
					$value = \OD_Product_Hub\License\LicenseGenerator::mask( $value );
				} elseif ( str_ends_with( $column, '_at' ) && '' !== $value ) {
					$value = (string) UtcDateTime::to_site( $value );
				} echo '<td>' . esc_html( $value ) . '</td>';
			} echo '</tr>'; }
		echo '</tbody></table></div>';
	}

	public function settings_page(): void {
		$this->guard();
		$s = get_option( 'odph_settings', Installer::defaults() );
		echo '<div class="wrap"><h1>OD Product Hub 設定</h1>';
		settings_errors( 'odph_settings' );
		// The value is only a display flag set by the nonce-protected connection test redirect.
		if ( isset( $_GET['stripe_test'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$success = 'success' === sanitize_key( wp_unslash( $_GET['stripe_test'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-' . ( $success ? 'success' : 'error' ) . ' is-dismissible"><p>' . esc_html( $success ? __( 'Stripeへ正常に接続できました。', 'od-product-hub' ) : __( 'Stripeへ接続できませんでした。キーまたは通信環境を確認してください。', 'od-product-hub' ) ) . '</p></div>';
		}
		echo '<form method="post" action="options.php">';
		settings_fields( 'odph_settings_group' );
		$fields = array(
			'stripe_secret_key'      => 'Stripe Secret Key',
			'stripe_publishable_key' => 'Stripe Publishable Key',
			'stripe_webhook_secret'  => 'Stripe Webhook Secret',
			'success_url'            => '購入完了URL',
			'cancel_url'             => 'キャンセルURL',
			'email_from_name'        => '送信元名',
			'email_from_address'     => '送信元メール',
			'log_retention_days'     => 'ログ保持日数',
			'api_rate_limit'         => 'APIレート/分',
		);
		echo '<table class="form-table">';
		foreach ( $fields as $key => $label ) {
			$secret = str_contains( $key, 'key' ) || str_contains( $key, 'secret' );
			$value  = $secret ? '' : (string) ( $s[ $key ] ?? '' );
			printf( '<tr><th><label for="odph_%1$s">%2$s</label></th><td><input class="regular-text" type="%3$s" id="odph_%1$s" name="odph_settings[%1$s]" value="%4$s" placeholder="%5$s"></td></tr>', esc_attr( $key ), esc_html( $label ), $secret ? 'password' : 'text', esc_attr( $value ), esc_attr( $secret && ! empty( $s[ $key ] ) ? '設定済み（変更時のみ入力）末尾 ' . substr( $s[ $key ], -4 ) : '' ) ); }
		echo '<tr><th>Customer Portal</th><td><label><input type="checkbox" name="odph_settings[portal_enabled]" value="1" ' . checked( ! empty( $s['portal_enabled'] ), true, false ) . '> 有効化</label></td></tr><tr><th>Webhook URL</th><td><code>' . esc_html( rest_url( 'od-product-hub/v1/stripe/webhook' ) ) . '</code></td></tr></table>';
		printf( '<p><label><input type="checkbox" name="odph_settings[delete_on_uninstall]" value="1" %s> アンインストール時に全データを削除する</label></p>', checked( ! empty( $s['delete_on_uninstall'] ), true, false ) );
		submit_button();
		echo '</form><hr><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="odph_test_stripe">';
		wp_nonce_field( 'odph_test_stripe' );
		submit_button( __( 'Stripe接続をテスト', 'od-product-hub' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	public function test_stripe_connection(): void {
		$this->guard();
		check_admin_referer( 'odph_test_stripe' );
		$result = 'error';
		try {
			StripeClientFactory::create()->balance->retrieve();
			$result = 'success';
		} catch ( \Throwable $error ) {
			unset( $error );
		}
		wp_safe_redirect( add_query_arg( 'stripe_test', $result, admin_url( 'admin.php?page=odph-settings' ) ) );
		exit;
	}

	public function configuration_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! str_starts_with( (string) get_current_screen()?->id, 'toplevel_page_od-product-hub' ) ) {
			return;
		}
		$result = $this->configuration_status();
		if ( 'good' !== $result['status'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $result['description'] ) . '</p></div>';
		}
	}

	/** @param array<string, mixed> $tests @return array<string, mixed> */
	public function site_health_tests( array $tests ): array {
		$tests['direct']['odph_configuration'] = array(
			'label' => __( 'OD Product Hub の本番設定', 'od-product-hub' ),
			'test'  => array( $this, 'configuration_status' ),
		);
		return $tests;
	}

	/** @return array<string, mixed> */
	public function configuration_status(): array {
		$settings = get_option( 'odph_settings', array() );
		$missing  = empty( $settings['stripe_secret_key'] ) || empty( $settings['stripe_publishable_key'] ) || empty( $settings['stripe_webhook_secret'] );
		$https    = is_ssl() || 'local' === wp_get_environment_type() || 'development' === wp_get_environment_type();
		$good     = ! $missing && $https;
		return array(
			'label'       => $good ? __( 'Stripe設定とHTTPSを確認しました', 'od-product-hub' ) : __( 'OD Product Hub の設定を確認してください', 'od-product-hub' ),
			'status'      => $good ? 'good' : 'recommended',
			'badge'       => array(
				'label' => 'OD Product Hub',
				'color' => 'blue',
			),
			'description' => $good ? __( '本番運用に必要なStripe設定とHTTPSが有効です。', 'od-product-hub' ) : __( 'Stripeの3つのキーを設定し、本番環境ではHTTPSを有効にしてください。', 'od-product-hub' ),
			'actions'     => sprintf( '<p><a href="%s">%s</a></p>', esc_url( admin_url( 'admin.php?page=odph-settings' ) ), esc_html__( '設定画面を開く', 'od-product-hub' ) ),
			'test'        => 'odph_configuration',
		);
	}

	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '権限がありません。', 'od-product-hub' ), '', array( 'response' => 403 ) ); } }

	private function repository( string $table ): AbstractRepository {
		return match ( $table ) {
			'products' => new ProductRepository(),
			'customers' => new CustomerRepository(),
			'licenses' => new LicenseRepository(),
			'webhook_logs' => new WebhookLogRepository(),
			default => throw new \InvalidArgumentException( 'Unsupported repository.' ),
		};
	}
}
