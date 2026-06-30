<?php
/**
 * Operational dashboard page.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Admin;

use OD_Product_Hub\Database\UtcDateTime;

final class DashboardPage {
	public function render(): void {
		$service = new DashboardService();
		$counts  = $service->counts();
		$recent  = $service->recent();
		$cards   = array(
			array( '有効ライセンス', $counts['active_licenses'], admin_url( 'admin.php?page=odph-licenses&status=active' ) ),
			array( '停止ライセンス', $counts['suspended_licenses'], admin_url( 'admin.php?page=odph-licenses&status=suspended' ) ),
			array( '支払い失敗', $counts['payment_failures'], admin_url( 'admin.php?page=odph-customers&tab=subscriptions&status=past_due' ) ),
			array( '今月の新規契約', $counts['new_subscriptions'], admin_url( 'admin.php?page=odph-customers&tab=subscriptions' ) ),
			array( 'Webhookエラー', $counts['webhook_errors'], admin_url( 'admin.php?page=odph-logs&tab=webhook' ) ),
		);
		echo '<div class="wrap"><h1>OD Product Hub</h1><p>契約、ライセンス、Webhook、APIの運用状態を確認できます。</p><div class="odph-cards">';
		foreach ( $cards as list( $label, $count, $url ) ) {
			printf( '<a class="card odph-dashboard-card" href="%1$s"><h2>%2$s</h2><p class="odph-count">%3$d</p><span>詳細を確認</span></a>', esc_url( $url ), esc_html( $label ), (int) $count );
		}
		echo '</div><div class="odph-dashboard-columns">';
		$this->webhook_table( $recent['webhooks'] );
		$this->api_table( $recent['api'] );
		echo '</div></div>';
	}

	/** @param list<object> $rows */
	private function webhook_table( array $rows ): void {
		echo '<section><h2>最近のWebhookログ</h2><table class="widefat striped"><thead><tr><th>イベント</th><th>種類</th><th>結果</th><th>日時</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf( '<tr><td><a href="%1$s"><code>%2$s</code></a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>', esc_url( admin_url( 'admin.php?page=odph-logs&tab=webhook&log_id=' . absint( $row->id ) ) ), esc_html( (string) $row->stripe_event_id ), esc_html( (string) $row->event_type ), esc_html( (string) $row->result ), esc_html( $this->date( $row->created_at ) ) );
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="4">ログはまだありません。</td></tr>';
		}
		echo '</tbody></table><p><a href="' . esc_url( admin_url( 'admin.php?page=odph-logs&tab=webhook' ) ) . '">Webhookログをすべて表示</a></p></section>';
	}

	/** @param list<object> $rows */
	private function api_table( array $rows ): void {
		echo '<section><h2>最近のAPIログ</h2><table class="widefat striped"><thead><tr><th>操作</th><th>結果</th><th>サイト</th><th>日時</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf( '<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>', esc_html( (string) $row->action ), esc_html( (string) $row->result ), esc_html( (string) $row->site_url ), esc_html( $this->date( $row->created_at ) ) );
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="4">ログはまだありません。</td></tr>';
		}
		echo '</tbody></table><p><a href="' . esc_url( admin_url( 'admin.php?page=odph-logs&tab=api' ) ) . '">APIログをすべて表示</a></p></section>';
	}

	private function date( mixed $value ): string {
		return $value ? (string) UtcDateTime::to_site( (string) $value ) : '—';
	}
}
