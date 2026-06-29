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
use OD_Product_Hub\License\LicenseGenerator;
use OD_Product_Hub\Log\AdminLogRepository;
use OD_Product_Hub\Log\ApiLogRepository;
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
		add_action( 'admin_post_odph_product_status', array( $this, 'change_product_status' ) );
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
		$query      = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering.
		$status     = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering.
		$page       = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$repository = new ProductRepository();
		$result     = $repository->search_admin( $query, $status, $page );
		$edit_id    = absint( $_GET['product_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only edit selection.
		$product    = $edit_id ? $repository->find( $edit_id ) : null;
		echo '<div class="wrap"><h1>商品管理</h1><form method="get"><input type="hidden" name="page" value="odph-products"><label class="screen-reader-text" for="product-search">商品を検索</label><input id="product-search" name="s" value="' . esc_attr( $query ) . '" placeholder="商品名・スラッグ"><select name="status"><option value="">すべての状態</option><option value="active" ' . selected( $status, 'active', false ) . '>active</option><option value="inactive" ' . selected( $status, 'inactive', false ) . '>inactive</option></select> <button class="button">絞り込む</button></form>';
		echo '<h2>' . esc_html( $product ? '商品を編集' : '商品を追加' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="odph_save_product"><input type="hidden" name="product_id" value="' . esc_attr( (string) ( $product->id ?? 0 ) ) . '">';
		wp_nonce_field( 'odph_save_product' );
		printf( '<table class="form-table"><tr><th><label for="name">商品名</label></th><td><input required class="regular-text" id="name" name="name" value="%1$s"></td></tr><tr><th><label for="description">説明</label></th><td><textarea class="large-text" id="description" name="description">%2$s</textarea></td></tr><tr><th><label for="slug">スラッグ</label></th><td><input required pattern="[a-z0-9_-]+" id="slug" name="slug" value="%3$s"></td></tr><tr><th><label for="stripe_product_id">Stripe Product ID</label></th><td><input required pattern="prod_[A-Za-z0-9]+" id="stripe_product_id" name="stripe_product_id" value="%4$s"></td></tr><tr><th><label for="stripe_price_id">Stripe Price ID</label></th><td><input required pattern="price_[A-Za-z0-9]+" id="stripe_price_id" name="stripe_price_id" value="%5$s"></td></tr><tr><th>状態</th><td><select name="status"><option value="active" %6$s>active</option><option value="inactive" %7$s>inactive</option></select></td></tr></table><p><button class="button button-primary">%8$s</button></p></form>', esc_attr( (string) ( $product->name ?? '' ) ), esc_textarea( (string) ( $product->description ?? '' ) ), esc_attr( (string) ( $product->slug ?? '' ) ), esc_attr( (string) ( $product->stripe_product_id ?? '' ) ), esc_attr( (string) ( $product->stripe_price_id ?? '' ) ), selected( (string) ( $product->status ?? 'active' ), 'active', false ), selected( (string) ( $product->status ?? '' ), 'inactive', false ), esc_html( $product ? '更新' : '追加' ) );
		echo '<table class="widefat striped"><thead><tr><th>商品</th><th>スラッグ</th><th>Product ID</th><th>Price ID</th><th>状態</th><th>操作</th></tr></thead><tbody>';
		foreach ( $result->items as $p ) {
			$edit_url   = add_query_arg(
				array(
					'page'       => 'odph-products',
					'product_id' => $p->id,
				),
				admin_url( 'admin.php' )
			);
			$next       = 'active' === $p->status ? 'inactive' : 'active';
			$status_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'     => 'odph_product_status',
						'product_id' => $p->id,
						'status'     => $next,
					),
					admin_url( 'admin-post.php' )
				),
				'odph_product_status_' . $p->id
			);
			printf( '<tr><td>%s</td><td><code>%s</code></td><td><a href="https://dashboard.stripe.com/products/%s" target="_blank" rel="noopener noreferrer"><code>%s</code><span class="screen-reader-text">（新しいタブで開く）</span></a></td><td><code>%s</code></td><td>%s</td><td><a href="%s">編集</a> | <a href="%s">%s</a></td></tr>', esc_html( $p->name ), esc_html( $p->slug ), esc_attr( $p->stripe_product_id ), esc_html( $p->stripe_product_id ), esc_html( $p->stripe_price_id ), esc_html( $p->status ), esc_url( $edit_url ), esc_url( $status_url ), esc_html( 'active' === $p->status ? '停止' : '再開' ) );
		}
		echo '</tbody></table>';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'current' => $result->page,
					'total'   => max( 1, $result->total_pages ),
				)
			)
		);
		echo '</div>';
	}

	public function save_product(): void {
		$this->guard();
		check_admin_referer( 'odph_save_product' );
		$name        = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$slug        = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
		$product_id  = sanitize_text_field( wp_unslash( $_POST['stripe_product_id'] ?? '' ) );
		$price_id    = sanitize_text_field( wp_unslash( $_POST['stripe_price_id'] ?? '' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$id          = absint( $_POST['product_id'] ?? 0 );
		if ( ! $name || ! preg_match( '/^[a-z0-9_-]+$/', $slug ) || ! preg_match( '/^prod_[A-Za-z0-9]+$/', $product_id ) || ! preg_match( '/^price_[A-Za-z0-9]+$/', $price_id ) ) {
			wp_die( esc_html__( '入力値が不正です。', 'od-product-hub' ), '', array( 'response' => 400 ) ); }
		try {
			$repository = new ProductRepository();
			foreach ( array( $repository->find_by_slug( $slug ), $repository->find_by_stripe_product_id( $product_id ), $repository->find_by_price( $price_id ) ) as $duplicate ) {
				if ( $duplicate && (int) $duplicate->id !== $id ) {
					wp_die( esc_html__( 'スラッグまたはStripe IDが既に使用されています。', 'od-product-hub' ), '', array( 'response' => 409 ) );
				}
			}
			$data   = array(
				'name'              => $name,
				'slug'              => $slug,
				'description'       => $description,
				'stripe_product_id' => $product_id,
				'stripe_price_id'   => $price_id,
				'status'            => in_array( $_POST['status'] ?? '', array( 'active', 'inactive' ), true ) ? $_POST['status'] : 'active',
			);
			$action = $id ? 'product_updated' : 'product_created';
			if ( $id ) {
				if ( ! $repository->find( $id ) ) {
					wp_die( esc_html__( '商品が見つかりません。', 'od-product-hub' ), '', array( 'response' => 404 ) );
				}
				$repository->update( $id, $data );
			} else {
				$id = $repository->create( $data );
			}
			( new AdminLogRepository() )->create(
				array(
					'user_id'     => get_current_user_id(),
					'action'      => $action,
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

	public function change_product_status(): void {
		$this->guard();
		$id     = absint( $_GET['product_id'] ?? 0 );
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
		check_admin_referer( 'odph_product_status_' . $id );
		if ( ! in_array( $status, array( 'active', 'inactive' ), true ) || ! ( new ProductRepository() )->find( $id ) ) {
			wp_die( esc_html__( '商品または状態が不正です。', 'od-product-hub' ), '', array( 'response' => 400 ) );
		}
		( new ProductRepository() )->update( $id, array( 'status' => $status ) );
		( new AdminLogRepository() )->create(
			array(
				'user_id'     => get_current_user_id(),
				'action'      => 'product_' . $status,
				'object_type' => 'product',
				'object_id'   => $id,
				'details'     => '{}',
			)
		);
		wp_safe_redirect( admin_url( 'admin.php?page=odph-products' ) );
		exit;
	}

	public function licenses(): void {
		$this->table_page( 'ライセンス管理', 'licenses', array( 'license_key', 'status', 'expires_at', 'last_verified_at', 'created_at' ), true ); }
	public function customers(): void {
		$this->guard();
		$customer_id = absint( $_GET['customer_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only detail selection.
		if ( $customer_id ) {
			$this->customer_detail( $customer_id );
			return;
		}
		$tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'customers' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
		echo '<div class="wrap"><h1>顧客・契約</h1><nav class="nav-tab-wrapper">';
		printf( '<a class="nav-tab %1$s" href="%2$s">顧客</a>', 'subscriptions' !== $tab ? 'nav-tab-active' : '', esc_url( admin_url( 'admin.php?page=odph-customers' ) ) );
		printf( '<a class="nav-tab %1$s" href="%2$s">サブスクリプション</a>', 'subscriptions' === $tab ? 'nav-tab-active' : '', esc_url( admin_url( 'admin.php?page=odph-customers&tab=subscriptions' ) ) );
		echo '</nav>';
		if ( 'subscriptions' === $tab ) {
			$this->subscriptions_table();
		} else {
			$this->customers_table();
		}
		echo '</div>';
	}
	public function logs(): void {
		$this->table_page( 'Webhookログ', 'webhook_logs', array( 'stripe_event_id', 'event_type', 'result', 'error_message', 'created_at' ) ); }

	private function customers_table(): void {
		$query  = sanitize_email( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering.
		$page   = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$result = ( new CustomerRepository() )->search_admin( $query, $page );
		echo '<form method="get"><input type="hidden" name="page" value="odph-customers"><label class="screen-reader-text" for="customer-search">メールアドレスで検索</label><input type="search" id="customer-search" name="s" value="' . esc_attr( $query ) . '" placeholder="メールアドレス"> <button class="button">検索</button></form>';
		echo '<p>' . esc_html( sprintf( '全 %d 件', $result->total ) ) . '</p><table class="widefat striped"><thead><tr><th>顧客</th><th>メール</th><th>WordPressユーザー</th><th>Stripe Customer</th><th>登録日時</th><th>操作</th></tr></thead><tbody>';
		foreach ( $result->items as $customer ) {
			$detail_url = add_query_arg(
				array(
					'page'        => 'odph-customers',
					'customer_id' => $customer->id,
				),
				admin_url( 'admin.php' )
			);
			$user_url   = get_edit_user_link( (int) $customer->wp_user_id );
			printf( '<tr><td>%1$s</td><td><a href="mailto:%2$s">%2$s</a></td><td>%3$s</td><td><a href="https://dashboard.stripe.com/customers/%4$s" target="_blank" rel="noopener noreferrer"><code>%4$s</code><span class="screen-reader-text">（新しいタブで開く）</span></a></td><td>%5$s</td><td><a href="%6$s">詳細</a></td></tr>', esc_html( (string) $customer->name ), esc_attr( (string) $customer->email ), $user_url ? '<a href="' . esc_url( $user_url ) . '">#' . esc_html( (string) $customer->wp_user_id ) . '</a>' : '—', esc_attr( (string) $customer->stripe_customer_id ), esc_html( (string) UtcDateTime::to_site( (string) $customer->created_at ) ), esc_url( $detail_url ) );
		}
		echo '</tbody></table>';
		$this->pagination( $result->page, $result->total_pages );
	}

	private function subscriptions_table(): void {
		$allowed = array( 'active', 'trialing', 'past_due', 'unpaid', 'canceled', 'incomplete', 'incomplete_expired', 'paused' );
		$status  = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering.
		$status  = in_array( $status, $allowed, true ) ? $status : '';
		$page    = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$result  = ( new SubscriptionRepository() )->search_admin( $status, $page );
		echo '<form method="get"><input type="hidden" name="page" value="odph-customers"><input type="hidden" name="tab" value="subscriptions"><label for="subscription-status">状態</label> <select id="subscription-status" name="status"><option value="">すべて</option>';
		foreach ( $allowed as $option ) {
			printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $option ), selected( $status, $option, false ) );
		}
		echo '</select> <button class="button">絞り込む</button></form><p>' . esc_html( sprintf( '全 %d 件', $result->total ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>商品</th><th>顧客</th><th>Stripe Subscription</th><th>状態</th><th>期間開始</th><th>期間終了</th><th>解約予約</th><th>支払い失敗</th></tr></thead><tbody>';
		foreach ( $result->items as $subscription ) {
			$customer_url = add_query_arg(
				array(
					'page'        => 'odph-customers',
					'customer_id' => $subscription->customer_id,
				),
				admin_url( 'admin.php' )
			);
			printf( '<tr><td>%1$s</td><td><a href="%2$s">%3$s<br>%4$s</a></td><td><a href="https://dashboard.stripe.com/subscriptions/%5$s" target="_blank" rel="noopener noreferrer"><code>%5$s</code><span class="screen-reader-text">（新しいタブで開く）</span></a></td><td>%6$s</td><td>%7$s</td><td>%8$s</td><td>%9$s</td><td>%10$s</td></tr>', esc_html( (string) $subscription->product_name ), esc_url( $customer_url ), esc_html( (string) $subscription->customer_name ), esc_html( (string) $subscription->customer_email ), esc_attr( (string) $subscription->stripe_subscription_id ), esc_html( (string) $subscription->stripe_status ), esc_html( $this->site_date( $subscription->current_period_start ) ), esc_html( $this->site_date( $subscription->current_period_end ) ), $subscription->cancel_at_period_end ? 'あり' : 'なし', esc_html( $this->site_date( $subscription->payment_failed_at ) ) );
		}
		echo '</tbody></table>';
		$this->pagination( $result->page, $result->total_pages );
	}

	private function customer_detail( int $customer_id ): void {
		$customer = ( new CustomerRepository() )->find( $customer_id );
		if ( ! $customer ) {
			wp_die( esc_html__( '顧客が見つかりません。', 'od-product-hub' ), '', array( 'response' => 404 ) );
		}
		$user          = get_userdata( (int) $customer->wp_user_id );
		$subscriptions = ( new SubscriptionRepository() )->find_for_customer( $customer_id );
		$licenses      = ( new LicenseRepository() )->find_for_customer( $customer_id );
		$api_logs      = ( new ApiLogRepository() )->find_for_customer( $customer_id );
		echo '<div class="wrap"><h1>顧客詳細</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=odph-customers' ) ) . '">← 顧客一覧へ戻る</a></p>';
		echo '<table class="form-table"><tr><th>名前</th><td>' . esc_html( (string) $customer->name ) . '</td></tr><tr><th>メール</th><td>' . esc_html( (string) $customer->email ) . '</td></tr><tr><th>WordPressユーザー</th><td>';
		if ( $user ) {
			printf( '<a href="%1$s">%2$s（#%3$s）</a>', esc_url( get_edit_user_link( $user->ID ) ), esc_html( $user->display_name ), esc_html( (string) $user->ID ) );
		} else {
			echo '—';
		}
		echo '</td></tr><tr><th>Stripe Customer</th><td><a href="https://dashboard.stripe.com/customers/' . esc_attr( (string) $customer->stripe_customer_id ) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html( (string) $customer->stripe_customer_id ) . '</code><span class="screen-reader-text">（新しいタブで開く）</span></a></td></tr></table>';
		echo '<h2>サブスクリプション</h2><table class="widefat striped"><thead><tr><th>商品</th><th>ID</th><th>状態</th><th>期間開始</th><th>期間終了</th><th>解約予約</th><th>支払い失敗</th></tr></thead><tbody>';
		foreach ( $subscriptions as $subscription ) {
			printf( '<tr><td>%1$s</td><td><a href="https://dashboard.stripe.com/subscriptions/%2$s" target="_blank" rel="noopener noreferrer"><code>%2$s</code></a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td></tr>', esc_html( (string) $subscription->product_name ), esc_attr( (string) $subscription->stripe_subscription_id ), esc_html( (string) $subscription->stripe_status ), esc_html( $this->site_date( $subscription->current_period_start ) ), esc_html( $this->site_date( $subscription->current_period_end ) ), $subscription->cancel_at_period_end ? 'あり' : 'なし', esc_html( $this->site_date( $subscription->payment_failed_at ) ) );
		}
		echo '</tbody></table><h2>ライセンス</h2><table class="widefat striped"><thead><tr><th>商品</th><th>キー</th><th>状態</th><th>発行日時</th><th>期限</th><th>最終認証</th></tr></thead><tbody>';
		foreach ( $licenses as $license ) {
			printf( '<tr><td>%1$s</td><td><code>%2$s</code></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>', esc_html( (string) $license->product_name ), esc_html( LicenseGenerator::mask( (string) $license->license_key ) ), esc_html( (string) $license->status ), esc_html( $this->site_date( $license->issued_at ) ), esc_html( $this->site_date( $license->expires_at ) ), esc_html( $this->site_date( $license->last_verified_at ) ) );
		}
		echo '</tbody></table><h2>最近のAPIログ</h2><table class="widefat striped"><thead><tr><th>操作</th><th>結果</th><th>サイトURL</th><th>エラーコード</th><th>日時</th></tr></thead><tbody>';
		foreach ( $api_logs as $log ) {
			printf( '<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>', esc_html( (string) $log->action ), esc_html( (string) $log->result ), esc_html( (string) $log->site_url ), esc_html( (string) $log->error_code ), esc_html( $this->site_date( $log->created_at ) ) );
		}
		echo '</tbody></table></div>';
	}

	private function site_date( mixed $value ): string {
		return $value ? (string) UtcDateTime::to_site( (string) $value ) : '—';
	}

	private function pagination( int $current, int $total ): void {
		if ( $total < 2 ) {
			return;
		}
		echo wp_kses_post(
			paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'current' => $current,
					'total'   => $total,
				)
			)
		);
	}

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
