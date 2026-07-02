<?php
/**
 * Shared administration authorization boundary.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Admin;

final class AdminAccess {
	public static function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'od-product-hub' ), '', array( 'response' => 403 ) );
		}
	}
}
