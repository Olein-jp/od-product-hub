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
		echo '<div class="wrap"><h1>' . esc_html__( 'Licenses', 'od-product-hub' ) . '</h1><form method="get"><input type="hidden" name="page" value="odph-licenses"><label class="screen-reader-text" for="license-search">' . esc_html__( 'Search by license key', 'od-product-hub' ) . '</label><input id="license-search" class="regular-text" name="license_key" value="' . esc_attr( $key ) . '" placeholder="' . esc_attr__( 'Complete license key', 'od-product-hub' ) . '"> <label for="license-status">' . esc_html__( 'Status', 'od-product-hub' ) . '</label> <select id="license-status" name="status"><option value="">' . esc_html__( 'All', 'od-product-hub' ) . '</option>';
		foreach ( $allowed as $option ) {
			printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $option ), selected( $status, $option, false ) );
		}
		/* translators: %d: total number of results. */
		echo '</select> <button class="button">' . esc_html__( 'Search', 'od-product-hub' ) . '</button></form><p>' . esc_html__( 'Search uses the key hash and does not perform partial matching on plain-text keys.', 'od-product-hub' ) . '</p><p>' . esc_html( sprintf( __( '%d results', 'od-product-hub' ), $result->total ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Product', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Customer', 'od-product-hub' ) . '</th><th>' . esc_html__( 'License key', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Status', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Issued', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Expires', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Last verified', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Actions', 'od-product-hub' ) . '</th></tr></thead><tbody>';
		foreach ( $result->items as $license ) {
			$detail_url = add_query_arg(
				array(
					'page'       => 'odph-licenses',
					'license_id' => $license->id,
				),
				admin_url( 'admin.php' )
			);
			printf( '<tr><td>%1$s</td><td>%2$s</td><td><code>%3$s</code></td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td><td><a href="%8$s">%9$s</a></td></tr>', esc_html( (string) $license->product_name ), esc_html( (string) $license->customer_email ), esc_html( LicenseGenerator::mask( (string) $license->license_key ) ), esc_html( (string) $license->status ), esc_html( $this->site_date( $license->issued_at ) ), esc_html( $this->site_date( $license->expires_at ) ), esc_html( $this->site_date( $license->last_verified_at ) ), esc_url( $detail_url ), esc_html__( 'Details', 'od-product-hub' ) );
		}
		echo '</tbody></table>';
		$this->pagination( $result->page, $result->total_pages );
		echo '</div>';
	}

	private function license_detail( int $license_id ): void {
		$license = $this->licenses->find_admin_detail( $license_id );
		if ( ! $license ) {
			wp_die( esc_html__( 'License not found.', 'od-product-hub' ), '', array( 'response' => 404 ) );
		}
		$logs = $this->api_logs->find_for_license( $license_id );
		echo '<div class="wrap"><h1>' . esc_html__( 'License details', 'od-product-hub' ) . '</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=odph-licenses' ) ) . '">' . esc_html__( '← Back to licenses', 'od-product-hub' ) . '</a></p>';
		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display flag set after a nonce-protected action.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The license operation completed.', 'od-product-hub' ) . '</p></div>';
		}
		echo '<table class="form-table"><tr><th>' . esc_html__( 'Product', 'od-product-hub' ) . '</th><td>' . esc_html( (string) $license->product_name ) . ' (<code>' . esc_html( (string) $license->product_slug ) . '</code>)</td></tr><tr><th>' . esc_html__( 'Customer', 'od-product-hub' ) . '</th><td>' . esc_html( (string) $license->customer_name ) . '<br>' . esc_html( (string) $license->customer_email ) . '</td></tr><tr><th>' . esc_html__( 'License key', 'od-product-hub' ) . '</th><td><code>' . esc_html( (string) $license->license_key ) . '</code><p class="description">' . esc_html__( 'The complete key is shown only on this details page.', 'od-product-hub' ) . '</p></td></tr><tr><th>' . esc_html__( 'Status', 'od-product-hub' ) . '</th><td>' . esc_html( (string) $license->status ) . '</td></tr><tr><th>' . esc_html__( 'Issued', 'od-product-hub' ) . '</th><td>' . esc_html( $this->site_date( $license->issued_at ) ) . '</td></tr><tr><th>' . esc_html__( 'Expires', 'od-product-hub' ) . '</th><td>' . esc_html( $this->site_date( $license->expires_at ) ) . '</td></tr><tr><th>' . esc_html__( 'Last verified', 'od-product-hub' ) . '</th><td>' . esc_html( $this->site_date( $license->last_verified_at ) ) . '</td></tr><tr><th>' . esc_html__( 'Stripe subscription', 'od-product-hub' ) . '</th><td>';
		if ( $license->stripe_subscription_id ) {
			/* translators: 1: Stripe subscription status, 2: period end date. */
			printf( '<a href="https://dashboard.stripe.com/subscriptions/%1$s" target="_blank" rel="noopener noreferrer"><code>%1$s</code></a> — ' . esc_html__( '%2$s (period ends: %3$s)', 'od-product-hub' ), esc_attr( (string) $license->stripe_subscription_id ), esc_html( (string) $license->stripe_status ), esc_html( $this->site_date( $license->current_period_end ) ) );
		} else {
			echo '—';
		}
		echo '</td></tr></table><h2>' . esc_html__( 'Administrative actions', 'od-product-hub' ) . '</h2><div class="odph-license-actions">';
		if ( 'suspended' === $license->status ) {
			$this->license_action_form( $license_id, 'resume', __( 'Resume license', 'od-product-hub' ), 'primary' );
		} else {
			$this->license_action_form( $license_id, 'suspend', __( 'Suspend license', 'od-product-hub' ), 'secondary' );
		}
		$this->license_action_form( $license_id, 'reissue', __( 'Reissue key', 'od-product-hub' ), 'secondary' );
		echo '</div><h2>' . esc_html__( 'Verification logs', 'od-product-hub' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Action', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Result', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Site URL', 'od-product-hub' ) . '</th><th>' . esc_html__( 'IP address', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Error code', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Date', 'od-product-hub' ) . '</th></tr></thead><tbody>';
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
