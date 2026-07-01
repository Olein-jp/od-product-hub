<?php
/**
 * Administration page renderer.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Admin;

use OD_Product_Hub\Customer\CustomerRepository;
use OD_Product_Hub\Database\UtcDateTime;
use OD_Product_Hub\License\LicenseGenerator;
use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Log\ApiLogRepository;
use OD_Product_Hub\Subscription\SubscriptionRepository;

final class CustomerPage {
	public function __construct( private readonly CustomerRepository $customers, private readonly SubscriptionRepository $subscriptions, private readonly LicenseRepository $licenses, private readonly ApiLogRepository $api_logs ) {}

	public function render(): void {
		AdminAccess::guard();
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

	private function customers_table(): void {
		$query  = sanitize_email( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering.
		$page   = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$result = $this->customers->search_admin( $query, $page );
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
		$result  = $this->subscriptions->search_admin( $status, $page );
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
		$customer = $this->customers->find( $customer_id );
		if ( ! $customer ) {
			wp_die( esc_html__( '顧客が見つかりません。', 'od-product-hub' ), '', array( 'response' => 404 ) );
		}
		$user          = get_userdata( (int) $customer->wp_user_id );
		$subscriptions = $this->subscriptions->find_for_customer( $customer_id );
		$licenses      = $this->licenses->find_for_customer( $customer_id );
		$api_logs      = $this->api_logs->find_for_customer( $customer_id );
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
}
