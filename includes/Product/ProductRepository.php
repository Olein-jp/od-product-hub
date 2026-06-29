<?php
/**
 * Product persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Product;

final class ProductRepository {
	/** @return object|null */
	public function find_by_slug( string $slug ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}odph_products WHERE slug = %s LIMIT 1", $slug ) );
	}

	/** @return object|null */
	public function find_by_price( string $price_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}odph_products WHERE stripe_price_id = %s LIMIT 1", $price_id ) );
	}
}
