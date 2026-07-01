<?php
/**
 * Administration page renderer.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Admin;

use OD_Product_Hub\Database\UtcDateTime;
use OD_Product_Hub\License\LicenseGenerator;
use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Log\ApiLogRepository;

final class LicensePage {
	public function __construct( private readonly LicenseRepository $licenses, private readonly ApiLogRepository $api_logs ) {}

	public function render(): void {
		AdminAccess::guard();
		$license_id = absint( $_GET['license_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only detail selection.
		if ( $license_id ) {
			$this->license_detail( $license_id );
			return;
		}
		$key     = strtoupper( sanitize_text_field( wp_unslash( $_GET['license_key'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only exact-key search.
		$status  = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status filter.
		$page    = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$hash    = '' === $key ? null : ( LicenseGenerator::is_valid( $key ) ? LicenseGenerator::hash( $key ) : str_repeat( '0', 64 ) );
		$result  = $this->licenses->search_admin( $hash, $status, $page );
		$allowed = array( 'active', 'inactive', 'expired', 'cancelled', 'suspended' );
		echo '<div class="wrap"><h1>ライセンス管理</h1><form method="get"><input type="hidden" name="page" value="odph-licenses"><label class="screen-reader-text" for="license-search">ライセンスキーで検索</label><input id="license-search" class="regular-text" name="license_key" value="' . esc_attr( $key ) . '" placeholder="完全なライセンスキー"> <label for="license-status">状態</label> <select id="license-status" name="status"><option value="">すべて</option>';
		foreach ( $allowed as $option ) {
			printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $option ), selected( $status, $option, false ) );
		}
		echo '</select> <button class="button">検索</button></form><p>検索はキーのハッシュを用い、平文の部分一致検索は行いません。</p><p>' . esc_html( sprintf( '全 %d 件', $result->total ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>商品</th><th>顧客</th><th>ライセンスキー</th><th>状態</th><th>発行日時</th><th>期限</th><th>最終認証</th><th>操作</th></tr></thead><tbody>';
		foreach ( $result->items as $license ) {
			$detail_url = add_query_arg(
				array(
					'page'       => 'odph-licenses',
					'license_id' => $license->id,
				),
				admin_url( 'admin.php' )
			);
			printf( '<tr><td>%1$s</td><td>%2$s</td><td><code>%3$s</code></td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td><td><a href="%8$s">詳細</a></td></tr>', esc_html( (string) $license->product_name ), esc_html( (string) $license->customer_email ), esc_html( LicenseGenerator::mask( (string) $license->license_key ) ), esc_html( (string) $license->status ), esc_html( $this->site_date( $license->issued_at ) ), esc_html( $this->site_date( $license->expires_at ) ), esc_html( $this->site_date( $license->last_verified_at ) ), esc_url( $detail_url ) );
		}
		echo '</tbody></table>';
		$this->pagination( $result->page, $result->total_pages );
		echo '</div>';
	}

	private function license_detail( int $license_id ): void {
		$license = $this->licenses->find_admin_detail( $license_id );
		if ( ! $license ) {
			wp_die( esc_html__( 'ライセンスが見つかりません。', 'od-product-hub' ), '', array( 'response' => 404 ) );
		}
		$logs = $this->api_logs->find_for_license( $license_id );
		echo '<div class="wrap"><h1>ライセンス詳細</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=odph-licenses' ) ) . '">← ライセンス一覧へ戻る</a></p>';
		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display flag set after a nonce-protected action.
			echo '<div class="notice notice-success is-dismissible"><p>ライセンス操作が完了しました。</p></div>';
		}
		echo '<table class="form-table"><tr><th>商品</th><td>' . esc_html( (string) $license->product_name ) . '（<code>' . esc_html( (string) $license->product_slug ) . '</code>）</td></tr><tr><th>顧客</th><td>' . esc_html( (string) $license->customer_name ) . '<br>' . esc_html( (string) $license->customer_email ) . '</td></tr><tr><th>ライセンスキー</th><td><code>' . esc_html( (string) $license->license_key ) . '</code><p class="description">全文はこの詳細画面でのみ表示されます。</p></td></tr><tr><th>状態</th><td>' . esc_html( (string) $license->status ) . '</td></tr><tr><th>発行日時</th><td>' . esc_html( $this->site_date( $license->issued_at ) ) . '</td></tr><tr><th>期限</th><td>' . esc_html( $this->site_date( $license->expires_at ) ) . '</td></tr><tr><th>最終認証</th><td>' . esc_html( $this->site_date( $license->last_verified_at ) ) . '</td></tr><tr><th>Stripe契約</th><td>';
		if ( $license->stripe_subscription_id ) {
			printf( '<a href="https://dashboard.stripe.com/subscriptions/%1$s" target="_blank" rel="noopener noreferrer"><code>%1$s</code></a> — %2$s（期間終了: %3$s）', esc_attr( (string) $license->stripe_subscription_id ), esc_html( (string) $license->stripe_status ), esc_html( $this->site_date( $license->current_period_end ) ) );
		} else {
			echo '—';
		}
		echo '</td></tr></table><h2>管理操作</h2><div class="odph-license-actions">';
		if ( 'suspended' === $license->status ) {
			$this->license_action_form( $license_id, 'resume', 'ライセンスを再開', 'primary' );
		} else {
			$this->license_action_form( $license_id, 'suspend', 'ライセンスを停止', 'secondary' );
		}
		$this->license_action_form( $license_id, 'reissue', 'キーを再発行', 'secondary' );
		echo '</div><h2>認証ログ</h2><table class="widefat striped"><thead><tr><th>操作</th><th>結果</th><th>サイトURL</th><th>IPアドレス</th><th>エラーコード</th><th>日時</th></tr></thead><tbody>';
		foreach ( $logs as $log ) {
			printf( '<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>', esc_html( (string) $log->action ), esc_html( (string) $log->result ), esc_html( (string) $log->site_url ), esc_html( (string) $log->ip_address ), esc_html( (string) $log->error_code ), esc_html( $this->site_date( $log->created_at ) ) );
		}
		echo '</tbody></table></div>';
	}

	private function license_action_form( int $license_id, string $operation, string $label, string $class ): void {
		echo '<form class="odph-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="odph_license_action"><input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '"><input type="hidden" name="license_operation" value="' . esc_attr( $operation ) . '">';
		wp_nonce_field( 'odph_license_' . $operation . '_' . $license_id );
		submit_button( $label, $class, 'submit', false );
		echo '</form>';
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
