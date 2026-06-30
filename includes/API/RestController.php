<?php
/**
 * Public REST API route registrar.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\API;

final class RestController {
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		( new LicenseController() )->register_routes();
		( new ProductController() )->register_routes();
	}
}
