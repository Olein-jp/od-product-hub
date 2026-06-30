<?php
/**
 * Searchable and paginated operational log screens.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Admin;

use OD_Product_Hub\Database\RepositoryPage;
use OD_Product_Hub\Database\UtcDateTime;
use OD_Product_Hub\Log\AdminLogRepository;
use OD_Product_Hub\Log\ApiLogRepository;
use OD_Product_Hub\Log\WebhookLogRepository;
use OD_Product_Hub\Webhook\PayloadRedactor;

final class LogsPage {
	public function render(): void {
		$tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'webhook' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
		$tab = in_array( $tab, array( 'webhook', 'api', 'admin' ), true ) ? $tab : 'webhook';
		echo '<div class="wrap"><h1>運用ログ</h1>';
		$this->cleanup_notice();
		$this->tabs( $tab );
		if ( 'webhook' === $tab ) {
			$this->webhooks();
		} elseif ( 'api' === $tab ) {
			$this->api();
		} else {
			$this->admin();
		}
		$this->cleanup_form();
		echo '</div>';
	}

	private function tabs( string $current ): void {
		$tabs = array(
			'webhook' => 'Webhook',
			'api'     => 'API',
			'admin'   => '管理操作',
		);
		echo '<nav class="nav-tab-wrapper" aria-label="ログ種別">';
		foreach ( $tabs as $tab => $label ) {
			printf( '<a class="nav-tab %1$s" href="%2$s">%3$s</a>', $current === $tab ? 'nav-tab-active' : '', esc_url( admin_url( 'admin.php?page=odph-logs&tab=' . $tab ) ), esc_html( $label ) );
		}
		echo '</nav>';
	}

	private function webhooks(): void {
		$log_id = absint( $_GET['log_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only detail selection.
		if ( $log_id ) {
			$this->webhook_detail( $log_id );
			return;
		}
		$query  = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
		$result = sanitize_key( wp_unslash( $_GET['result'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$page   = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$rows   = ( new WebhookLogRepository() )->search_admin( $query, $result, $page );
		echo '<h2>Webhookログ</h2><form method="get"><input type="hidden" name="page" value="odph-logs"><input type="hidden" name="tab" value="webhook"><label for="webhook-search">イベントID・種類</label> <input id="webhook-search" name="s" value="' . esc_attr( $query ) . '"> <label for="webhook-result">結果</label> <select id="webhook-result" name="result"><option value="">すべて</option>';
		foreach ( array( 'processing', 'success', 'error', 'signature_error', 'unsupported' ) as $option ) {
			printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $option ), selected( $result, $option, false ) );
		}
		echo '</select> <button class="button">絞り込む</button></form><p>' . esc_html( sprintf( '全 %d 件', $rows->total ) ) . '</p><table class="widefat striped"><thead><tr><th>イベントID</th><th>種類</th><th>結果</th><th>重複</th><th>エラー</th><th>日時</th><th>操作</th></tr></thead><tbody>';
		foreach ( $rows->items as $row ) {
			printf( '<tr><td><code>%1$s</code></td><td>%2$s</td><td>%3$s</td><td>%4$d</td><td>%5$s</td><td>%6$s</td><td><a href="%7$s">詳細</a></td></tr>', esc_html( (string) $row->stripe_event_id ), esc_html( (string) $row->event_type ), esc_html( (string) $row->result ), absint( $row->duplicate_count ), esc_html( (string) $row->error_message ), esc_html( $this->date( $row->created_at ) ), esc_url( admin_url( 'admin.php?page=odph-logs&tab=webhook&log_id=' . absint( $row->id ) ) ) );
		}
		$this->empty_row( $rows, 7 );
		echo '</tbody></table>';
		$this->pagination( $rows );
	}

	private function webhook_detail( int $id ): void {
		$log = ( new WebhookLogRepository() )->find( $id );
		if ( ! $log ) {
			wp_die( esc_html__( 'Webhookログが見つかりません。', 'od-product-hub' ), '', array( 'response' => 404 ) );
		}
		$masked  = ( new PayloadRedactor() )->redact_json( (string) $log->payload );
		$decoded = json_decode( $masked, true );
		$pretty  = is_array( $decoded ) ? wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : $masked;
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=odph-logs&tab=webhook' ) ) . '">← Webhookログ一覧へ戻る</a></p><h2>Webhookログ詳細</h2><table class="form-table">';
		foreach ( array(
			'stripe_event_id'  => 'イベントID',
			'event_type'       => '種類',
			'result'           => '結果',
			'duplicate_count'  => '重複回数',
			'error_message'    => 'エラー',
			'created_at'       => '受信日時',
			'last_received_at' => '最終受信日時',
		) as $column => $label ) {
			$value = str_ends_with( $column, '_at' ) ? $this->date( $log->$column ?? null ) : (string) ( $log->$column ?? '' );
			printf( '<tr><th>%1$s</th><td>%2$s</td></tr>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</table><h2>マスク済みpayload</h2><p>表示時にもメール、住所、支払い関連情報を再マスクします。</p><pre class="odph-log-payload">' . esc_html( (string) $pretty ) . '</pre>';
	}

	private function api(): void {
		$action = sanitize_key( wp_unslash( $_GET['action_filter'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$result = sanitize_key( wp_unslash( $_GET['result'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$error  = sanitize_key( wp_unslash( $_GET['error_code'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$site   = esc_url_raw( wp_unslash( $_GET['site_url'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$page   = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$rows   = ( new ApiLogRepository() )->search_admin( $action, $result, $error, $site, $page );
		echo '<h2>APIログ</h2><form method="get"><input type="hidden" name="page" value="odph-logs"><input type="hidden" name="tab" value="api"><label for="api-action">操作</label> <select id="api-action" name="action_filter"><option value="">すべて</option>';
		foreach ( array( 'activate', 'verify', 'deactivate' ) as $option ) {
			printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $option ), selected( $action, $option, false ) );
		}
		echo '</select> <label for="api-result">結果</label> <select id="api-result" name="result"><option value="">すべて</option><option value="success" ' . selected( $result, 'success', false ) . '>success</option><option value="failure" ' . selected( $result, 'failure', false ) . '>failure</option></select> <label for="api-error">エラーコード</label> <input id="api-error" name="error_code" value="' . esc_attr( $error ) . '"> <label for="api-site">サイトURL</label> <input id="api-site" name="site_url" value="' . esc_attr( $site ) . '"> <button class="button">絞り込む</button></form><p>' . esc_html( sprintf( '全 %d 件', $rows->total ) ) . '</p><table class="widefat striped"><thead><tr><th>操作</th><th>結果</th><th>サイト</th><th>IP</th><th>エラー</th><th>ライセンス</th><th>日時</th></tr></thead><tbody>';
		foreach ( $rows->items as $row ) {
			$license = $row->license_id ? '<a href="' . esc_url( admin_url( 'admin.php?page=odph-licenses&license_id=' . absint( $row->license_id ) ) ) . '">#' . absint( $row->license_id ) . '</a>' : '—';
			printf( '<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td></tr>', esc_html( (string) $row->action ), esc_html( (string) $row->result ), esc_html( (string) $row->site_url ), esc_html( (string) $row->ip_address ), esc_html( (string) $row->error_code ), wp_kses_post( $license ), esc_html( $this->date( $row->created_at ) ) );
		}
		$this->empty_row( $rows, 7 );
		echo '</tbody></table>';
		$this->pagination( $rows );
	}

	private function admin(): void {
		$action = sanitize_key( wp_unslash( $_GET['action_filter'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$type   = sanitize_key( wp_unslash( $_GET['object_type'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$user   = absint( $_GET['user_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$page   = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$rows   = ( new AdminLogRepository() )->search_admin( $action, $type, $user, $page );
		echo '<h2>管理操作ログ</h2><form method="get"><input type="hidden" name="page" value="odph-logs"><input type="hidden" name="tab" value="admin"><label for="admin-action">操作</label> <input id="admin-action" name="action_filter" value="' . esc_attr( $action ) . '"> <label for="admin-object">対象種別</label> <input id="admin-object" name="object_type" value="' . esc_attr( $type ) . '"> <label for="admin-user">ユーザーID</label> <input id="admin-user" type="number" min="1" name="user_id" value="' . esc_attr( $user ? (string) $user : '' ) . '"> <button class="button">絞り込む</button></form><p>' . esc_html( sprintf( '全 %d 件', $rows->total ) ) . '</p><table class="widefat striped"><thead><tr><th>ユーザー</th><th>操作</th><th>対象</th><th>対象ID</th><th>詳細</th><th>日時</th></tr></thead><tbody>';
		foreach ( $rows->items as $row ) {
			printf( '<tr><td>#%1$d</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td><code>%5$s</code></td><td>%6$s</td></tr>', absint( $row->user_id ), esc_html( (string) $row->action ), esc_html( (string) $row->object_type ), esc_html( (string) $row->object_id ), esc_html( (string) $row->details ), esc_html( $this->date( $row->created_at ) ) );
		}
		$this->empty_row( $rows, 6 );
		echo '</tbody></table>';
		$this->pagination( $rows );
	}

	private function cleanup_form(): void {
		$settings = (array) get_option( 'odph_settings', array() );
		echo '<hr><h2>ログ保持期間の手動削除</h2><p>現在の保持期間は <strong>' . esc_html( (string) absint( $settings['log_retention_days'] ?? 365 ) ) . '日</strong>です。日次Cronと同じ処理を安全に再実行できます。</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="odph_cleanup_logs">';
		wp_nonce_field( 'odph_cleanup_logs' );
		submit_button( '保持期間より古いログを削除', 'secondary', 'submit', false );
		echo '</form>';
	}

	private function cleanup_notice(): void {
		if ( ! isset( $_GET['cleanup'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag after nonce-protected cleanup.
			return;
		}
		$result = get_transient( 'odph_cleanup_result_' . get_current_user_id() );
		if ( ! is_array( $result ) ) {
			return;
		}
		delete_transient( 'odph_cleanup_result_' . get_current_user_id() );
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( 'ログを %d 件削除しました。', array_sum( array_map( 'intval', $result ) ) ) ) );
	}

	private function pagination( RepositoryPage $rows ): void {
		if ( $rows->total_pages < 2 ) {
			return;
		}
		echo wp_kses_post(
			paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'current' => $rows->page,
					'total'   => $rows->total_pages,
				)
			)
		);
	}

	private function empty_row( RepositoryPage $rows, int $columns ): void {
		if ( ! $rows->items ) {
			echo '<tr><td colspan="' . esc_attr( (string) $columns ) . '">該当するログはありません。</td></tr>';
		}
	}

	private function date( mixed $value ): string {
		return $value ? (string) UtcDateTime::to_site( (string) $value ) : '—';
	}
}
