<?php
/**
 * Operational dashboard page.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Admin;

use OD_Product_Hub\Database\UtcDateTime;

final class DashboardPage {
	public function __construct( private readonly DashboardService $service ) {}

	public function render(): void {
		AdminAccess::guard();
		$counts = $this->service->counts();
		$recent = $this->service->recent();
		$cards  = array(
			array( __( 'Active licenses', 'od-product-hub' ), $counts['active_licenses'], admin_url( 'admin.php?page=odph-licenses&status=active' ) ),
			array( __( 'Suspended licenses', 'od-product-hub' ), $counts['suspended_licenses'], admin_url( 'admin.php?page=odph-licenses&status=suspended' ) ),
			array( __( 'Payment failures', 'od-product-hub' ), $counts['payment_failures'], admin_url( 'admin.php?page=odph-customers&tab=subscriptions&status=past_due' ) ),
			array( __( 'New subscriptions this month', 'od-product-hub' ), $counts['new_subscriptions'], admin_url( 'admin.php?page=odph-customers&tab=subscriptions' ) ),
			array( __( 'Webhook errors', 'od-product-hub' ), $counts['webhook_errors'], admin_url( 'admin.php?page=odph-logs&tab=webhook' ) ),
		);
		echo '<div class="wrap">';
		echo AdminUi::page_header( 'OD Product Hub', __( 'Review the operational status of subscriptions, licenses, webhooks, and the API.', 'od-product-hub' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUi escapes all scalar content.
		echo AdminUi::section_start( __( 'Status', 'od-product-hub' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUi escapes all scalar content.
		echo '<div class="odph-cards">';
		foreach ( $cards as list( $label, $count, $url ) ) {
			echo AdminUi::card( (string) $label, (string) $count, (string) $url, __( 'View details', 'od-product-hub' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUi escapes all scalar content.
		}
		echo '</div>' . AdminUi::section_end(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static closing tag from AdminUi.
		echo '<div class="odph-dashboard-columns">';
		$this->webhook_table( $recent['webhooks'] );
		$this->api_table( $recent['api'] );
		echo '</div></div>';
	}

	/** @param list<object> $rows */
	private function webhook_table( array $rows ): void {
		echo AdminUi::section_start( __( 'Recent webhook logs', 'od-product-hub' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUi escapes all scalar content.
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Event', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Type', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Result', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Date', 'od-product-hub' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf( '<tr><td><a href="%1$s"><code>%2$s</code></a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>', esc_url( admin_url( 'admin.php?page=odph-logs&tab=webhook&log_id=' . absint( $row->id ) ) ), esc_html( (string) $row->stripe_event_id ), esc_html( (string) $row->event_type ), esc_html( (string) $row->result ), esc_html( $this->date( $row->created_at ) ) );
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="4">' . AdminUi::empty_state( __( 'No logs are available yet.', 'od-product-hub' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUi escapes all scalar content.
		}
		echo '</tbody></table><p><a href="' . esc_url( admin_url( 'admin.php?page=odph-logs&tab=webhook' ) ) . '">' . esc_html__( 'View all webhook logs', 'od-product-hub' ) . '</a></p>' . AdminUi::section_end(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static closing tag from AdminUi.
	}

	/** @param list<object> $rows */
	private function api_table( array $rows ): void {
		echo AdminUi::section_start( __( 'Recent API logs', 'od-product-hub' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUi escapes all scalar content.
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Action', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Result', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Site', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Date', 'od-product-hub' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf( '<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>', esc_html( (string) $row->action ), esc_html( (string) $row->result ), esc_html( (string) $row->site_url ), esc_html( $this->date( $row->created_at ) ) );
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="4">' . AdminUi::empty_state( __( 'No logs are available yet.', 'od-product-hub' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUi escapes all scalar content.
		}
		echo '</tbody></table><p><a href="' . esc_url( admin_url( 'admin.php?page=odph-logs&tab=api' ) ) . '">' . esc_html__( 'View all API logs', 'od-product-hub' ) . '</a></p>' . AdminUi::section_end(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static closing tag from AdminUi.
	}

	private function date( mixed $value ): string {
		return $value ? (string) UtcDateTime::to_site( (string) $value ) : '—';
	}
}
