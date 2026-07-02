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
		echo '<div class="wrap"><h1>' . esc_html__( 'Customers and subscriptions', 'od-product-hub' ) . '</h1><nav class="nav-tab-wrapper">';
		printf( '<a class="nav-tab %1$s" href="%2$s">%3$s</a>', 'subscriptions' !== $tab ? 'nav-tab-active' : '', esc_url( admin_url( 'admin.php?page=odph-customers' ) ), esc_html__( 'Customers', 'od-product-hub' ) );
		printf( '<a class="nav-tab %1$s" href="%2$s">%3$s</a>', 'subscriptions' === $tab ? 'nav-tab-active' : '', esc_url( admin_url( 'admin.php?page=odph-customers&tab=subscriptions' ) ), esc_html__( 'Subscriptions', 'od-product-hub' ) );
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
		echo '<form method="get"><input type="hidden" name="page" value="odph-customers"><label class="screen-reader-text" for="customer-search">' . esc_html__( 'Search by email address', 'od-product-hub' ) . '</label><input type="search" id="customer-search" name="s" value="' . esc_attr( $query ) . '" placeholder="' . esc_attr__( 'Email address', 'od-product-hub' ) . '"> <button class="button">' . esc_html__( 'Search', 'od-product-hub' ) . '</button></form>';
		/* translators: %d: total number of results. */
		echo '<p>' . esc_html( sprintf( __( '%d results', 'od-product-hub' ), $result->total ) ) . '</p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Customer', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Email', 'od-product-hub' ) . '</th><th>' . esc_html__( 'WordPress user', 'od-product-hub' ) . '</th><th>Stripe Customer</th><th>' . esc_html__( 'Registered', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Actions', 'od-product-hub' ) . '</th></tr></thead><tbody>';
		foreach ( $result->items as $customer ) {
			$detail_url = add_query_arg(
				array(
					'page'        => 'odph-customers',
					'customer_id' => $customer->id,
				),
				admin_url( 'admin.php' )
			);
			$user_url   = get_edit_user_link( (int) $customer->wp_user_id );
			printf( '<tr><td>%1$s</td><td><a href="mailto:%2$s">%2$s</a></td><td>%3$s</td><td><a href="https://dashboard.stripe.com/customers/%4$s" target="_blank" rel="noopener noreferrer"><code>%4$s</code><span class="screen-reader-text">%7$s</span></a></td><td>%5$s</td><td><a href="%6$s">%8$s</a></td></tr>', esc_html( (string) $customer->name ), esc_attr( (string) $customer->email ), $user_url ? '<a href="' . esc_url( $user_url ) . '">#' . esc_html( (string) $customer->wp_user_id ) . '</a>' : '—', esc_attr( (string) $customer->stripe_customer_id ), esc_html( (string) UtcDateTime::to_site( (string) $customer->created_at ) ), esc_url( $detail_url ), esc_html__( '(opens in a new tab)', 'od-product-hub' ), esc_html__( 'Details', 'od-product-hub' ) );
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
		echo '<form method="get"><input type="hidden" name="page" value="odph-customers"><input type="hidden" name="tab" value="subscriptions"><label for="subscription-status">' . esc_html__( 'Status', 'od-product-hub' ) . '</label> <select id="subscription-status" name="status"><option value="">' . esc_html__( 'All', 'od-product-hub' ) . '</option>';
		foreach ( $allowed as $option ) {
			printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $option ), selected( $status, $option, false ) );
		}
		/* translators: %d: total number of results. */
		echo '</select> <button class="button">' . esc_html__( 'Filter', 'od-product-hub' ) . '</button></form><p>' . esc_html( sprintf( __( '%d results', 'od-product-hub' ), $result->total ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Product', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Customer', 'od-product-hub' ) . '</th><th>Stripe Subscription</th><th>' . esc_html__( 'Status', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Period start', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Period end', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Scheduled cancellation', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Payment failure', 'od-product-hub' ) . '</th></tr></thead><tbody>';
		foreach ( $result->items as $subscription ) {
			$customer_url = add_query_arg(
				array(
					'page'        => 'odph-customers',
					'customer_id' => $subscription->customer_id,
				),
				admin_url( 'admin.php' )
			);
			printf( '<tr><td>%1$s</td><td><a href="%2$s">%3$s<br>%4$s</a></td><td><a href="https://dashboard.stripe.com/subscriptions/%5$s" target="_blank" rel="noopener noreferrer"><code>%5$s</code><span class="screen-reader-text">%11$s</span></a></td><td>%6$s</td><td>%7$s</td><td>%8$s</td><td>%9$s</td><td>%10$s</td></tr>', esc_html( (string) $subscription->product_name ), esc_url( $customer_url ), esc_html( (string) $subscription->customer_name ), esc_html( (string) $subscription->customer_email ), esc_attr( (string) $subscription->stripe_subscription_id ), esc_html( (string) $subscription->stripe_status ), esc_html( $this->site_date( $subscription->current_period_start ) ), esc_html( $this->site_date( $subscription->current_period_end ) ), $subscription->cancel_at_period_end ? esc_html__( 'Yes', 'od-product-hub' ) : esc_html__( 'No', 'od-product-hub' ), esc_html( $this->site_date( $subscription->payment_failed_at ) ), esc_html__( '(opens in a new tab)', 'od-product-hub' ) );
		}
		echo '</tbody></table>';
		$this->pagination( $result->page, $result->total_pages );
	}

	private function customer_detail( int $customer_id ): void {
		$customer = $this->customers->find( $customer_id );
		if ( ! $customer ) {
			wp_die( esc_html__( 'Customer not found.', 'od-product-hub' ), '', array( 'response' => 404 ) );
		}
		$user          = get_userdata( (int) $customer->wp_user_id );
		$subscriptions = $this->subscriptions->find_for_customer( $customer_id );
		$licenses      = $this->licenses->find_for_customer( $customer_id );
		$api_logs      = $this->api_logs->find_for_customer( $customer_id );
		echo '<div class="wrap"><h1>' . esc_html__( 'Customer details', 'od-product-hub' ) . '</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=odph-customers' ) ) . '">' . esc_html__( '← Back to customers', 'od-product-hub' ) . '</a></p>';
		echo '<table class="form-table"><tr><th>' . esc_html__( 'Name', 'od-product-hub' ) . '</th><td>' . esc_html( (string) $customer->name ) . '</td></tr><tr><th>' . esc_html__( 'Email', 'od-product-hub' ) . '</th><td>' . esc_html( (string) $customer->email ) . '</td></tr><tr><th>' . esc_html__( 'WordPress user', 'od-product-hub' ) . '</th><td>';
		if ( $user ) {
			printf( '<a href="%1$s">%2$s（#%3$s）</a>', esc_url( get_edit_user_link( $user->ID ) ), esc_html( $user->display_name ), esc_html( (string) $user->ID ) );
		} else {
			echo '—';
		}
		echo '</td></tr><tr><th>Stripe Customer</th><td><a href="https://dashboard.stripe.com/customers/' . esc_attr( (string) $customer->stripe_customer_id ) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html( (string) $customer->stripe_customer_id ) . '</code><span class="screen-reader-text">' . esc_html__( '(opens in a new tab)', 'od-product-hub' ) . '</span></a></td></tr></table>';
		echo '<h2>' . esc_html__( 'Subscriptions', 'od-product-hub' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Product', 'od-product-hub' ) . '</th><th>ID</th><th>' . esc_html__( 'Status', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Period start', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Period end', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Scheduled cancellation', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Payment failure', 'od-product-hub' ) . '</th></tr></thead><tbody>';
		foreach ( $subscriptions as $subscription ) {
			printf( '<tr><td>%1$s</td><td><a href="https://dashboard.stripe.com/subscriptions/%2$s" target="_blank" rel="noopener noreferrer"><code>%2$s</code></a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td></tr>', esc_html( (string) $subscription->product_name ), esc_attr( (string) $subscription->stripe_subscription_id ), esc_html( (string) $subscription->stripe_status ), esc_html( $this->site_date( $subscription->current_period_start ) ), esc_html( $this->site_date( $subscription->current_period_end ) ), $subscription->cancel_at_period_end ? esc_html__( 'Yes', 'od-product-hub' ) : esc_html__( 'No', 'od-product-hub' ), esc_html( $this->site_date( $subscription->payment_failed_at ) ) );
		}
		echo '</tbody></table><h2>' . esc_html__( 'Licenses', 'od-product-hub' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Product', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Key', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Status', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Issued', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Expires', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Last verified', 'od-product-hub' ) . '</th></tr></thead><tbody>';
		foreach ( $licenses as $license ) {
			printf( '<tr><td>%1$s</td><td><code>%2$s</code></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>', esc_html( (string) $license->product_name ), esc_html( LicenseGenerator::mask( (string) $license->license_key ) ), esc_html( (string) $license->status ), esc_html( $this->site_date( $license->issued_at ) ), esc_html( $this->site_date( $license->expires_at ) ), esc_html( $this->site_date( $license->last_verified_at ) ) );
		}
		echo '</tbody></table><h2>' . esc_html__( 'Recent API logs', 'od-product-hub' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Action', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Result', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Site URL', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Error code', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Date', 'od-product-hub' ) . '</th></tr></thead><tbody>';
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
