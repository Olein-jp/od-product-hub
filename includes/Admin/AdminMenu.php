<?php
/**
 * Administration pages.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Admin;

use OD_Product_Hub\Database\Installer;

final class AdminMenu {
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_odph_save_product', array( $this, 'save_product' ) );
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
		$current['portal_enabled']      = empty( $input['portal_enabled'] ) ? 0 : 1;
		$current['success_url']         = esc_url_raw( $input['success_url'] ?? '' );
		$current['cancel_url']          = esc_url_raw( $input['cancel_url'] ?? '' );
		$current['account_page_id']     = absint( $input['account_page_id'] ?? 0 );
		$current['email_from_name']     = sanitize_text_field( $input['email_from_name'] ?? '' );
		$current['email_from_address']  = sanitize_email( $input['email_from_address'] ?? '' );
		$current['log_retention_days']  = max( 1, min( 3650, absint( $input['log_retention_days'] ?? 365 ) ) );
		$current['api_rate_limit']      = max( 1, min( 1000, absint( $input['api_rate_limit'] ?? 60 ) ) );
		$current['delete_on_uninstall'] = empty( $input['delete_on_uninstall'] ) ? 0 : 1;
		return $current;
	}

	public function dashboard(): void {
		$this->guard();
		global $wpdb;
		$counts = array(
			'有効ライセンス'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}odph_licenses WHERE status = 'active'" ),
			'停止ライセンス'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}odph_licenses WHERE status = 'suspended'" ),
			'支払い失敗'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}odph_subscriptions WHERE payment_failed_at IS NOT NULL" ),
			'Webhookエラー' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}odph_webhook_logs WHERE result = 'error'" ),
		);
		echo '<div class="wrap"><h1>OD Product Hub</h1><p>GPLコードの利用制限ではなく、有効な契約者向けサービスの契約検証基盤です。</p><div class="odph-cards">';
		foreach ( $counts as $label => $count ) {
			printf( '<div class="card"><h2>%s</h2><p class="odph-count">%d</p></div>', esc_html( $label ), esc_html( (string) $count ) ); }
		echo '</div></div>';
	}

	public function products(): void {
		$this->guard();
		global $wpdb;
		$products = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}odph_products ORDER BY id DESC" );
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
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$wpdb->prefix . 'odph_products',
			array(
				'name'              => $name,
				'slug'              => $slug,
				'description'       => '',
				'stripe_product_id' => $product_id,
				'stripe_price_id'   => $price_id,
				'status'            => in_array( $_POST['status'] ?? '', array( 'active', 'inactive' ), true ) ? $_POST['status'] : 'active',
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
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
		global $wpdb;
		$table_name = $wpdb->prefix . 'odph_' . $table;
		$rows       = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 100', $table_name ) );
		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1><table class="widefat striped"><thead><tr>';
		foreach ( $columns as $column ) {
			echo '<th>' . esc_html( $column ) . '</th>';
		} echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( $columns as $column ) {
				$value = (string) $row->$column;
				if ( $mask && 'license_key' === $column ) {
					$value = \OD_Product_Hub\License\LicenseGenerator::mask( $value );
				} echo '<td>' . esc_html( $value ) . '</td>';
			} echo '</tr>'; }
		echo '</tbody></table></div>';
	}

	public function settings_page(): void {
		$this->guard();
		$s = get_option( 'odph_settings', Installer::defaults() );
		echo '<div class="wrap"><h1>OD Product Hub 設定</h1><form method="post" action="options.php">';
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
		submit_button();
		echo '</form></div>';
	}

	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '権限がありません。', 'od-product-hub' ), '', array( 'response' => 403 ) ); } }
}
