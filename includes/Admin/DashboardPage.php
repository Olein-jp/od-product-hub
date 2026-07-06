<?php
/**
 * Operational dashboard page.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Admin;

use OD_Product_Hub\Database\UtcDateTime;

final class DashboardPage {
	public function __construct( private readonly DashboardService $service, private readonly AdminSiteHealth $site_health ) {}

	public function render(): void {
		AdminAccess::guard();
		$counts = $this->service->counts();
		$recent = $this->service->recent();
		echo '<div class="wrap">';
		echo AdminUi::page_header( __( 'Dashboard', 'od-product-hub' ), __( 'Review items that need attention, key metrics, and recent operational activity.', 'od-product-hub' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUi escapes all scalar content.
		$this->attention();
		$this->metrics( $counts );
		$this->recent_activity( $recent );
		$this->quick_actions();
		echo '</div>';
	}

	private function attention(): void {
		echo '<div class="odph-dashboard-attention">';
		echo AdminUi::section_start( __( 'Needs attention', 'od-product-hub' ), __( 'Resolve critical items first. Open Site Health for complete diagnostics.', 'od-product-hub' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUi escapes all scalar content.
		try {
			$items = $this->site_health->dashboard_results();
		} catch ( \Throwable $error ) {
			unset( $error );
			echo AdminUi::notice( __( 'Operational checks could not be loaded. Open Site Health and try again.', 'od-product-hub' ), 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUi escapes all scalar content.
			echo AdminUi::section_end() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static closing tag from AdminUi.
			return;
		}
		if ( ! $items ) {
			echo AdminUi::notice( __( 'No items currently need attention.', 'od-product-hub' ), 'success' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUi escapes all scalar content.
		} else {
			echo '<ul class="odph-attention-list">';
			foreach ( $items as $item ) {
				$status = (string) ( $item['status'] ?? 'recommended' );
				$tone   = 'critical' === $status ? 'error' : 'warning';
				echo '<li class="odph-attention-item odph-attention-item--' . esc_attr( $tone ) . '">';
				echo wp_kses_post( AdminUi::status_badge( 'critical' === $status ? __( 'Critical', 'od-product-hub' ) : __( 'Recommended', 'od-product-hub' ), $tone ) );
				echo '<div class="odph-attention-content"><h3>' . esc_html( (string) ( $item['label'] ?? '' ) ) . '</h3><p>' . esc_html( (string) ( $item['description'] ?? '' ) ) . '</p>';
				if ( ! empty( $item['action_url'] ) ) {
					echo '<p><a href="' . esc_url( (string) $item['action_url'] ) . '">' . esc_html__( 'Open the relevant screen', 'od-product-hub' ) . '</a></p>';
				} else {
					echo '<p><a href="' . esc_url( admin_url( 'site-health.php?tab=direct' ) ) . '">' . esc_html__( 'Open Site Health', 'od-product-hub' ) . '</a></p>';
				}
				echo '</div></li>';
			}
			echo '</ul>';
		}
		echo AdminUi::section_end(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static closing tag from AdminUi.
		echo '</div>';
	}

	private function quick_actions(): void {
		echo AdminUi::section_start( __( 'Quick actions', 'od-product-hub' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUi escapes all scalar content.
		$actions = AdminUi::action_group(
			array(
				array(
					'label'   => __( 'Add product', 'od-product-hub' ),
					'url'     => admin_url( 'admin.php?page=odph-products' ),
					'primary' => true,
				),
				array(
					'label' => __( 'Settings', 'od-product-hub' ),
					'url'   => admin_url( 'admin.php?page=odph-settings' ),
				),
				array(
					'label' => __( 'Site Health', 'od-product-hub' ),
					'url'   => admin_url( 'site-health.php?tab=direct' ),
				),
			),
			__( 'Dashboard actions', 'od-product-hub' )
		);
		echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUi escapes all scalar content.
		echo AdminUi::section_end(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static closing tag from AdminUi.
	}

	/** @param array<string, int> $counts */
	private function metrics( array $counts ): void {
		$cards = array(
			array( __( 'Active licenses', 'od-product-hub' ), $counts['active_licenses'], admin_url( 'admin.php?page=odph-licenses&status=active' ) ),
			array( __( 'Suspended licenses', 'od-product-hub' ), $counts['suspended_licenses'], admin_url( 'admin.php?page=odph-licenses&status=suspended' ) ),
			array( __( 'Payment failures', 'od-product-hub' ), $counts['payment_failures'], admin_url( 'admin.php?page=odph-customers&tab=subscriptions&status=past_due' ) ),
			array( __( 'New subscriptions this month', 'od-product-hub' ), $counts['new_subscriptions'], admin_url( 'admin.php?page=odph-customers&tab=subscriptions' ) ),
			array( __( 'Webhook errors', 'od-product-hub' ), $counts['webhook_errors'], admin_url( 'admin.php?page=odph-logs&tab=webhook' ) ),
		);
		echo AdminUi::section_start( __( 'Key metrics', 'od-product-hub' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUi escapes all scalar content.
		echo '<div class="odph-cards">';
		foreach ( $cards as list( $label, $count, $url ) ) {
			echo AdminUi::card( (string) $label, (string) $count, (string) $url, __( 'View details', 'od-product-hub' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUi escapes all scalar content.
		}
		echo '</div>' . AdminUi::section_end(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static closing tag from AdminUi.
	}

	/** @param array{webhooks: list<object>, api: list<object>} $recent */
	private function recent_activity( array $recent ): void {
		echo AdminUi::section_start( __( 'Recent activity', 'od-product-hub' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUi escapes all scalar content.
		echo '<div class="odph-dashboard-columns">';
		$this->webhook_table( $recent['webhooks'] );
		$this->api_table( $recent['api'] );
		echo '</div>' . AdminUi::section_end(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static closing tag from AdminUi.
	}

	/** @param list<object> $rows */
	private function webhook_table( array $rows ): void {
		echo '<section class="odph-dashboard-panel"><h3>' . esc_html__( 'Recent webhook logs', 'od-product-hub' ) . '</h3><div class="odph-table-scroll"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Event', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Type', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Result', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Date', 'od-product-hub' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf( '<tr><td><a href="%1$s"><code>%2$s</code></a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>', esc_url( admin_url( 'admin.php?page=odph-logs&tab=webhook&log_id=' . absint( $row->id ) ) ), esc_html( (string) $row->stripe_event_id ), esc_html( (string) $row->event_type ), esc_html( (string) $row->result ), esc_html( $this->date( $row->created_at ) ) );
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="4">' . AdminUi::empty_state( __( 'No logs are available yet.', 'od-product-hub' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUi escapes all scalar content.
		}
		echo '</tbody></table></div><p><a href="' . esc_url( admin_url( 'admin.php?page=odph-logs&tab=webhook' ) ) . '">' . esc_html__( 'View all webhook logs', 'od-product-hub' ) . '</a></p></section>';
	}

	/** @param list<object> $rows */
	private function api_table( array $rows ): void {
		echo '<section class="odph-dashboard-panel"><h3>' . esc_html__( 'Recent API logs', 'od-product-hub' ) . '</h3><div class="odph-table-scroll"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Action', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Result', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Site', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Date', 'od-product-hub' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf( '<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>', esc_html( (string) $row->action ), esc_html( (string) $row->result ), esc_html( (string) $row->site_url ), esc_html( $this->date( $row->created_at ) ) );
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="4">' . AdminUi::empty_state( __( 'No logs are available yet.', 'od-product-hub' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUi escapes all scalar content.
		}
		echo '</tbody></table></div><p><a href="' . esc_url( admin_url( 'admin.php?page=odph-logs&tab=api' ) ) . '">' . esc_html__( 'View all API logs', 'od-product-hub' ) . '</a></p></section>';
	}

	private function date( mixed $value ): string {
		return $value ? (string) UtcDateTime::to_site( (string) $value ) : '—';
	}
}
