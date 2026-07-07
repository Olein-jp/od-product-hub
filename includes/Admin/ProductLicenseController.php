<?php
/**
 * Non-rendering product license administration actions.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Admin;

use OD_Product_Hub\VendorLicense\ProductLicenseService;

final class ProductLicenseController {
	public function __construct( private readonly ProductLicenseService $license ) {}

	public function handle(): void {
		AdminAccess::guard();
		$operation = sanitize_key( wp_unslash( $_POST['license_operation'] ?? '' ) );
		if ( ! in_array( $operation, array( 'activate', 'verify', 'deactivate' ), true ) ) {
			wp_die( esc_html__( 'The product license operation is invalid.', 'od-product-hub' ), '', array( 'response' => 400 ) );
		}
		check_admin_referer( 'odph_vendor_license_' . $operation );
		if ( 'activate' === $operation ) {
			$result = $this->license->activate( sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) ) );
		} elseif ( 'verify' === $operation ) {
			$result = $this->license->verify( true );
		} else {
			$result = $this->license->deactivate();
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                  => 'odph-settings',
					'tab'                   => 'license',
					'vendor_license_result' => sanitize_key( $result->status ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
