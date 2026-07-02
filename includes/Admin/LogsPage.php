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
	public function __construct(
		private readonly WebhookLogRepository $webhook_logs,
		private readonly ApiLogRepository $api_logs,
		private readonly AdminLogRepository $admin_logs,
		private readonly PayloadRedactor $redactor
	) {}

	public function render(): void {
		AdminAccess::guard();
		$tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'webhook' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
		$tab = in_array( $tab, array( 'webhook', 'api', 'admin' ), true ) ? $tab : 'webhook';
		echo '<div class="wrap"><h1>' . esc_html__( 'Operational logs', 'od-product-hub' ) . '</h1>';
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
			'admin'   => __( 'Administrative actions', 'od-product-hub' ),
		);
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Log type', 'od-product-hub' ) . '">';
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
		$rows   = $this->webhook_logs->search_admin( $query, $result, $page );
		echo '<h2>' . esc_html__( 'Webhook logs', 'od-product-hub' ) . '</h2><form method="get"><input type="hidden" name="page" value="odph-logs"><input type="hidden" name="tab" value="webhook"><label for="webhook-search">' . esc_html__( 'Event ID or type', 'od-product-hub' ) . '</label> <input id="webhook-search" name="s" value="' . esc_attr( $query ) . '"> <label for="webhook-result">' . esc_html__( 'Result', 'od-product-hub' ) . '</label> <select id="webhook-result" name="result"><option value="">' . esc_html__( 'All', 'od-product-hub' ) . '</option>';
		foreach ( array( 'processing', 'success', 'error', 'signature_error', 'unsupported' ) as $option ) {
			printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $option ), selected( $result, $option, false ) );
		}
		/* translators: %d: total number of results. */
		echo '</select> <button class="button">' . esc_html__( 'Filter', 'od-product-hub' ) . '</button></form><p>' . esc_html( sprintf( __( '%d results', 'od-product-hub' ), $rows->total ) ) . '</p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Event ID', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Type', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Result', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Duplicates', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Error', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Date', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Actions', 'od-product-hub' ) . '</th></tr></thead><tbody>';
		foreach ( $rows->items as $row ) {
			printf( '<tr><td><code>%1$s</code></td><td>%2$s</td><td>%3$s</td><td>%4$d</td><td>%5$s</td><td>%6$s</td><td><a href="%7$s">%8$s</a></td></tr>', esc_html( (string) $row->stripe_event_id ), esc_html( (string) $row->event_type ), esc_html( (string) $row->result ), absint( $row->duplicate_count ), esc_html( (string) $row->error_message ), esc_html( $this->date( $row->created_at ) ), esc_url( admin_url( 'admin.php?page=odph-logs&tab=webhook&log_id=' . absint( $row->id ) ) ), esc_html__( 'Details', 'od-product-hub' ) );
		}
		$this->empty_row( $rows, 7 );
		echo '</tbody></table>';
		$this->pagination( $rows );
	}

	private function webhook_detail( int $id ): void {
		$log = $this->webhook_logs->find( $id );
		if ( ! $log ) {
			wp_die( esc_html__( 'Webhook log not found.', 'od-product-hub' ), '', array( 'response' => 404 ) );
		}
		$masked  = $this->redactor->redact_json( (string) $log->payload );
		$decoded = json_decode( $masked, true );
		$pretty  = is_array( $decoded ) ? wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : $masked;
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=odph-logs&tab=webhook' ) ) . '">' . esc_html__( '← Back to webhook logs', 'od-product-hub' ) . '</a></p><h2>' . esc_html__( 'Webhook log details', 'od-product-hub' ) . '</h2><table class="form-table">';
		foreach ( array(
			'stripe_event_id'  => __( 'Event ID', 'od-product-hub' ),
			'event_type'       => __( 'Type', 'od-product-hub' ),
			'result'           => __( 'Result', 'od-product-hub' ),
			'duplicate_count'  => __( 'Duplicate count', 'od-product-hub' ),
			'error_message'    => __( 'Error', 'od-product-hub' ),
			'created_at'       => __( 'Received', 'od-product-hub' ),
			'last_received_at' => __( 'Last received', 'od-product-hub' ),
		) as $column => $label ) {
			$value = str_ends_with( $column, '_at' ) ? $this->date( $log->$column ?? null ) : (string) ( $log->$column ?? '' );
			printf( '<tr><th>%1$s</th><td>%2$s</td></tr>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</table><h2>' . esc_html__( 'Masked payload', 'od-product-hub' ) . '</h2><p>' . esc_html__( 'Email, address, and payment-related information is masked again when displayed.', 'od-product-hub' ) . '</p><pre class="odph-log-payload">' . esc_html( (string) $pretty ) . '</pre>';
	}

	private function api(): void {
		$action = sanitize_key( wp_unslash( $_GET['action_filter'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$result = sanitize_key( wp_unslash( $_GET['result'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$error  = sanitize_key( wp_unslash( $_GET['error_code'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$site   = esc_url_raw( wp_unslash( $_GET['site_url'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$page   = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$rows   = $this->api_logs->search_admin( $action, $result, $error, $site, $page );
		echo '<h2>' . esc_html__( 'API logs', 'od-product-hub' ) . '</h2><form method="get"><input type="hidden" name="page" value="odph-logs"><input type="hidden" name="tab" value="api"><label for="api-action">' . esc_html__( 'Action', 'od-product-hub' ) . '</label> <select id="api-action" name="action_filter"><option value="">' . esc_html__( 'All', 'od-product-hub' ) . '</option>';
		foreach ( array( 'activate', 'verify', 'deactivate' ) as $option ) {
			printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $option ), selected( $action, $option, false ) );
		}
		/* translators: %d: total number of results. */
		echo '</select> <label for="api-result">' . esc_html__( 'Result', 'od-product-hub' ) . '</label> <select id="api-result" name="result"><option value="">' . esc_html__( 'All', 'od-product-hub' ) . '</option><option value="success" ' . selected( $result, 'success', false ) . '>success</option><option value="failure" ' . selected( $result, 'failure', false ) . '>failure</option></select> <label for="api-error">' . esc_html__( 'Error code', 'od-product-hub' ) . '</label> <input id="api-error" name="error_code" value="' . esc_attr( $error ) . '"> <label for="api-site">' . esc_html__( 'Site URL', 'od-product-hub' ) . '</label> <input id="api-site" name="site_url" value="' . esc_attr( $site ) . '"> <button class="button">' . esc_html__( 'Filter', 'od-product-hub' ) . '</button></form><p>' . esc_html( sprintf( __( '%d results', 'od-product-hub' ), $rows->total ) ) . '</p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Action', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Result', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Site', 'od-product-hub' ) . '</th><th>IP</th><th>' . esc_html__( 'Error', 'od-product-hub' ) . '</th><th>' . esc_html__( 'License', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Date', 'od-product-hub' ) . '</th></tr></thead><tbody>';
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
		$rows   = $this->admin_logs->search_admin( $action, $type, $user, $page );
		/* translators: %d: total number of results. */
		echo '<h2>' . esc_html__( 'Administrative action logs', 'od-product-hub' ) . '</h2><form method="get"><input type="hidden" name="page" value="odph-logs"><input type="hidden" name="tab" value="admin"><label for="admin-action">' . esc_html__( 'Action', 'od-product-hub' ) . '</label> <input id="admin-action" name="action_filter" value="' . esc_attr( $action ) . '"> <label for="admin-object">' . esc_html__( 'Object type', 'od-product-hub' ) . '</label> <input id="admin-object" name="object_type" value="' . esc_attr( $type ) . '"> <label for="admin-user">' . esc_html__( 'User ID', 'od-product-hub' ) . '</label> <input id="admin-user" type="number" min="1" name="user_id" value="' . esc_attr( $user ? (string) $user : '' ) . '"> <button class="button">' . esc_html__( 'Filter', 'od-product-hub' ) . '</button></form><p>' . esc_html( sprintf( __( '%d results', 'od-product-hub' ), $rows->total ) ) . '</p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'User', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Action', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Object', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Object ID', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Details', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Date', 'od-product-hub' ) . '</th></tr></thead><tbody>';
		foreach ( $rows->items as $row ) {
			printf( '<tr><td>#%1$d</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td><code>%5$s</code></td><td>%6$s</td></tr>', absint( $row->user_id ), esc_html( (string) $row->action ), esc_html( (string) $row->object_type ), esc_html( (string) $row->object_id ), esc_html( (string) $row->details ), esc_html( $this->date( $row->created_at ) ) );
		}
		$this->empty_row( $rows, 6 );
		echo '</tbody></table>';
		$this->pagination( $rows );
	}

	private function cleanup_form(): void {
		$settings = (array) get_option( 'odph_settings', array() );
		$days     = absint( $settings['log_retention_days'] ?? 365 );
		/* translators: %d: log retention period in days. */
		echo '<hr><h2>' . esc_html__( 'Manual log cleanup', 'od-product-hub' ) . '</h2><p>' . wp_kses_post( sprintf( __( 'The current retention period is <strong>%d days</strong>. You can safely rerun the same cleanup performed by the daily cron task.', 'od-product-hub' ), $days ) ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="odph_cleanup_logs">';
		wp_nonce_field( 'odph_cleanup_logs' );
		submit_button( __( 'Delete logs older than the retention period', 'od-product-hub' ), 'secondary', 'submit', false );
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
		/* translators: %d: number of deleted log entries. */
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( __( 'Deleted %d log entries.', 'od-product-hub' ), array_sum( array_map( 'intval', $result ) ) ) ) );
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
			echo '<tr><td colspan="' . esc_attr( (string) $columns ) . '">' . esc_html__( 'No matching logs found.', 'od-product-hub' ) . '</td></tr>';
		}
	}

	private function date( mixed $value ): string {
		return $value ? (string) UtcDateTime::to_site( (string) $value ) : '—';
	}
}
