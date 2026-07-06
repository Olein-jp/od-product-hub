<?php
/**
 * Non-rendering administration action handlers.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Admin;

use OD_Product_Hub\Database\DatabaseException;
use OD_Product_Hub\License\LicenseManager;
use OD_Product_Hub\Log\AdminLogRepository;
use OD_Product_Hub\Log\LogCleanupService;
use OD_Product_Hub\Log\WebhookLogRepository;
use OD_Product_Hub\Product\ProductRepository;

final class AdminActionHandler {
	/** @var callable(): bool */
	private $stripe_connection_test;

	/** @param callable(): bool $stripe_connection_test */
	public function __construct(
		private readonly ProductRepository $products,
		private readonly AdminLogRepository $logs,
		private readonly LicenseManager $licenses,
		private readonly LogCleanupService $cleanup,
		private readonly WebhookLogRepository $webhook_logs,
		callable $stripe_connection_test
	) {
		$this->stripe_connection_test = $stripe_connection_test;
	}

	public function save_product(): void {
		AdminAccess::guard();
		check_admin_referer( 'odph_save_product' );
		$data = $this->normalize_product_input( $_POST );
		if ( null === $data ) {
			wp_die( esc_html__( 'The submitted data is invalid.', 'od-product-hub' ), '', array( 'response' => 400 ) );
		}
		$id = absint( $_POST['product_id'] ?? 0 );
		try {
			foreach ( array( $this->products->find_by_slug( $data['slug'] ), $this->products->find_by_stripe_product_id( $data['stripe_product_id'] ), $this->products->find_by_price( $data['stripe_price_id'] ) ) as $duplicate ) {
				if ( $duplicate && (int) $duplicate->id !== $id ) {
					wp_die( esc_html__( 'The slug or Stripe ID is already in use.', 'od-product-hub' ), '', array( 'response' => 409 ) );
				}
			}
			$action = $id ? 'product_updated' : 'product_created';
			if ( $id ) {
				$product = $this->products->find( $id );
				if ( ! $product ) {
					wp_die( esc_html__( 'Product not found.', 'od-product-hub' ), '', array( 'response' => 404 ) );
				}
				if ( $this->products->has_licenses( $id ) && (string) $product->license_key_prefix !== $data['license_key_prefix'] ) {
					wp_die( esc_html__( 'The license key prefix cannot be changed after a license has been issued.', 'od-product-hub' ), '', array( 'response' => 409 ) );
				}
				$this->products->update( $id, $data );
			} else {
				$id = $this->products->create( $data );
			}
			$this->logs->create(
				array(
					'user_id'     => get_current_user_id(),
					'action'      => $action,
					'object_type' => 'product',
					'object_id'   => $id,
					'details'     => wp_json_encode( array( 'slug' => $data['slug'] ) ),
				)
			);
		} catch ( DatabaseException $error ) {
			wp_die( esc_html( $error->getMessage() ), '', array( 'response' => 500 ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=odph-products' ) );
		exit;
	}

	/** @param array<string, mixed> $input @return array<string, string>|null */
	public function normalize_product_input( array $input ): ?array {
		$name       = sanitize_text_field( wp_unslash( $input['name'] ?? '' ) );
		$slug       = sanitize_key( wp_unslash( $input['slug'] ?? '' ) );
		$product_id = sanitize_text_field( wp_unslash( $input['stripe_product_id'] ?? '' ) );
		$price_id   = sanitize_text_field( wp_unslash( $input['stripe_price_id'] ?? '' ) );
		$prefix     = \OD_Product_Hub\License\LicenseGenerator::normalize_prefix( sanitize_text_field( wp_unslash( $input['license_key_prefix'] ?? '' ) ) );
		if ( ! $name || ! preg_match( '/^[a-z0-9_-]+$/', $slug ) || ! preg_match( '/^prod_[A-Za-z0-9]+$/', $product_id ) || ! preg_match( '/^price_[A-Za-z0-9]+$/', $price_id ) || ! \OD_Product_Hub\License\LicenseGenerator::is_valid_prefix( $prefix ) ) {
			return null;
		}
		return array(
			'name'                => $name,
			'slug'                => $slug,
			'description'         => sanitize_textarea_field( wp_unslash( $input['description'] ?? '' ) ),
			'price_description'   => sanitize_text_field( wp_unslash( $input['price_description'] ?? '' ) ),
			'billing_description' => sanitize_textarea_field( wp_unslash( $input['billing_description'] ?? '' ) ),
			'license_key_prefix'  => $prefix,
			'stripe_product_id'   => $product_id,
			'stripe_price_id'     => $price_id,
			'status'              => in_array( $input['status'] ?? '', array( 'active', 'inactive' ), true ) ? (string) $input['status'] : 'active',
		);
	}

	public function change_product_status(): void {
		AdminAccess::guard();
		$id     = absint( $_GET['product_id'] ?? 0 );
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
		check_admin_referer( 'odph_product_status_' . $id );
		if ( ! in_array( $status, array( 'active', 'inactive' ), true ) || ! $this->products->find( $id ) ) {
			wp_die( esc_html__( 'The product or status is invalid.', 'od-product-hub' ), '', array( 'response' => 400 ) );
		}
		$this->products->update( $id, array( 'status' => $status ) );
		$this->logs->create(
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

	public function license_action(): void {
		AdminAccess::guard();
		$license_id = absint( $_POST['license_id'] ?? 0 );
		$operation  = sanitize_key( wp_unslash( $_POST['license_operation'] ?? '' ) );
		if ( ! in_array( $operation, array( 'suspend', 'resume', 'reissue' ), true ) ) {
			wp_die( esc_html__( 'The license operation is invalid.', 'od-product-hub' ), '', array( 'response' => 400 ) );
		}
		check_admin_referer( 'odph_license_' . $operation . '_' . $license_id );
		try {
			if ( 'suspend' === $operation ) {
				$this->licenses->suspend( $license_id, get_current_user_id() );
			} elseif ( 'resume' === $operation ) {
				$this->licenses->resume( $license_id, get_current_user_id() );
			} else {
				$this->licenses->reissue( $license_id, get_current_user_id() );
			}
		} catch ( \DomainException | DatabaseException $error ) {
			wp_die( esc_html( $error->getMessage() ), '', array( 'response' => 409 ) );
		} catch ( \Throwable $error ) {
			unset( $error );
			wp_die( esc_html__( 'The license operation failed.', 'od-product-hub' ), '', array( 'response' => 500 ) );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'odph-licenses',
					'license_id' => $license_id,
					'updated'    => $operation,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function cleanup_logs(): void {
		AdminAccess::guard();
		check_admin_referer( 'odph_cleanup_logs' );
		$result = $this->cleanup->run();
		$this->logs->create(
			array(
				'user_id'     => get_current_user_id(),
				'action'      => 'logs_cleaned',
				'object_type' => 'logs',
				'details'     => wp_json_encode( $result ),
			)
		);
		set_transient( 'odph_cleanup_result_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=odph-logs&cleanup=1' ) );
		exit;
	}

	public function retry_webhook(): void {
		AdminAccess::guard();
		$log_id = absint( $_POST['log_id'] ?? 0 );
		check_admin_referer( 'odph_retry_webhook_' . $log_id );
		$event = $this->webhook_logs->find( $log_id );
		if ( ! $event || ! $this->webhook_logs->request_manual_retry( $log_id ) ) {
			wp_die( esc_html__( 'This webhook cannot be retried.', 'od-product-hub' ), '', array( 'response' => 409 ) );
		}
		$this->logs->create(
			array(
				'user_id'     => get_current_user_id(),
				'action'      => 'webhook_retry_requested',
				'object_type' => 'webhook_log',
				'object_id'   => $log_id,
				'details'     => wp_json_encode( array( 'stripe_event_id' => (string) $event->stripe_event_id ) ),
			)
		);
		wp_safe_redirect( admin_url( 'admin.php?page=odph-logs&tab=webhook&log_id=' . $log_id . '&retry=1' ) );
		exit;
	}

	public function test_stripe_connection(): void {
		AdminAccess::guard();
		check_admin_referer( 'odph_test_stripe' );
		$result                      = ( $this->stripe_connection_test )() ? 'success' : 'error';
		$state                       = (array) get_option( 'odph_operational_state', array() );
		$now                         = gmdate( 'Y-m-d H:i:s' );
		$state['stripe_last_result'] = $result;
		$state['stripe_last_test']   = $now;
		if ( 'success' === $result ) {
			$state['stripe_last_success'] = $now;
		}
		update_option( 'odph_operational_state', $state, false );
		wp_safe_redirect( add_query_arg( 'stripe_test', $result, admin_url( 'admin.php?page=odph-settings' ) ) );
		exit;
	}
}
