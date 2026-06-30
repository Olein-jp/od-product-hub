<?php
/**
 * Public product information endpoint.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\API;

use OD_Product_Hub\Product\ProductRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class ProductController extends PublicController {
	public function __construct() {
		parent::__construct();
		$this->rest_base = 'product';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'product' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array( 'product_slug' => ApiSchema::product_slug() ),
			)
		);
	}

	public function product( WP_REST_Request $request ): WP_REST_Response {
		$limit = $this->consume_limit( $request );
		if ( ! $limit['allowed'] ) {
			return $this->rate_limited( $limit );
		}
		$product = ( new ProductRepository() )->find_by_slug( (string) $request->get_param( 'product_slug' ) );
		if ( ! $product || 'active' !== $product->status ) {
			return $this->with_limit_headers(
				new WP_REST_Response(
					array(
						'success'    => false,
						'error_code' => 'product_not_found',
						'message'    => 'Product not found.',
					),
					404
				),
				$limit
			);
		}
		return $this->with_limit_headers(
			new WP_REST_Response(
				array(
					'success' => true,
					'product' => array(
						'slug'   => (string) $product->slug,
						'name'   => (string) $product->name,
						'status' => 'active',
					),
				),
				200
			),
			$limit
		);
	}
}
