<?php
/**
 * Administration page renderer.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Admin;

use OD_Product_Hub\Product\ProductRepository;

final class ProductPage {
	public function __construct( private readonly ProductRepository $products ) {}

	public function render(): void {
		AdminAccess::guard();
		$query         = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering.
		$status        = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering.
		$page          = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$repository    = $this->products;
		$result        = $repository->search_admin( $query, $status, $page );
		$edit_id       = absint( $_GET['product_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only edit selection.
		$product       = $edit_id ? $repository->find( $edit_id ) : null;
		$prefix_locked = $product ? $repository->has_licenses( (int) $product->id ) : false;
		echo '<div class="wrap"><h1>' . esc_html__( 'Products', 'od-product-hub' ) . '</h1><form method="get"><input type="hidden" name="page" value="odph-products"><label class="screen-reader-text" for="product-search">' . esc_html__( 'Search products', 'od-product-hub' ) . '</label><input id="product-search" name="s" value="' . esc_attr( $query ) . '" placeholder="' . esc_attr__( 'Product name or slug', 'od-product-hub' ) . '"><select name="status"><option value="">' . esc_html__( 'All statuses', 'od-product-hub' ) . '</option><option value="active" ' . selected( $status, 'active', false ) . '>active</option><option value="inactive" ' . selected( $status, 'inactive', false ) . '>inactive</option></select> <button class="button">' . esc_html__( 'Filter', 'od-product-hub' ) . '</button></form>';
		echo '<h2>' . esc_html( $product ? __( 'Edit product', 'od-product-hub' ) : __( 'Add product', 'od-product-hub' ) ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="odph_save_product"><input type="hidden" name="product_id" value="' . esc_attr( (string) ( $product->id ?? 0 ) ) . '">';
		wp_nonce_field( 'odph_save_product' );
		printf( '<table class="form-table"><tr><th><label for="name">%11$s</label></th><td><input required class="regular-text" id="name" name="name" value="%1$s"></td></tr><tr><th><label for="description">%12$s</label></th><td><textarea class="large-text" id="description" name="description">%2$s</textarea></td></tr><tr><th><label for="price_description">%13$s</label></th><td><input class="regular-text" id="price_description" name="price_description" value="%3$s" placeholder="%14$s"></td></tr><tr><th><label for="billing_description">%15$s</label></th><td><textarea class="large-text" id="billing_description" name="billing_description" placeholder="%16$s">%4$s</textarea></td></tr><tr><th><label for="slug">%17$s</label></th><td><input required pattern="[a-z0-9_-]+" id="slug" name="slug" value="%5$s"></td></tr><tr><th><label for="stripe_product_id">Stripe Product ID</label></th><td><input required pattern="prod_[A-Za-z0-9]+" id="stripe_product_id" name="stripe_product_id" value="%6$s"></td></tr><tr><th><label for="stripe_price_id">Stripe Price ID</label></th><td><input required pattern="price_[A-Za-z0-9]+" id="stripe_price_id" name="stripe_price_id" value="%7$s"></td></tr><tr><th>%18$s</th><td><select name="status"><option value="active" %8$s>active</option><option value="inactive" %9$s>inactive</option></select></td></tr>', esc_attr( (string) ( $product->name ?? '' ) ), esc_textarea( (string) ( $product->description ?? '' ) ), esc_attr( (string) ( $product->price_description ?? '' ) ), esc_textarea( (string) ( $product->billing_description ?? '' ) ), esc_attr( (string) ( $product->slug ?? '' ) ), esc_attr( (string) ( $product->stripe_product_id ?? '' ) ), esc_attr( (string) ( $product->stripe_price_id ?? '' ) ), selected( (string) ( $product->status ?? 'active' ), 'active', false ), selected( (string) ( $product->status ?? '' ), 'inactive', false ), '', esc_html__( 'Product name', 'od-product-hub' ), esc_html__( 'Description', 'od-product-hub' ), esc_html__( 'Price description', 'od-product-hub' ), esc_attr__( 'Example: $19.80 per month, including tax', 'od-product-hub' ), esc_html__( 'Subscription description', 'od-product-hub' ), esc_attr__( 'Example: Renews monthly until canceled.', 'od-product-hub' ), esc_html__( 'Slug', 'od-product-hub' ), esc_html__( 'Status', 'od-product-hub' ) );
		echo '<tr><th><label for="license_key_prefix">' . esc_html__( 'License key prefix', 'od-product-hub' ) . '</label></th><td><input id="license_key_prefix" name="license_key_prefix" pattern="[A-Za-z0-9]{3,12}" maxlength="12" value="' . esc_attr( (string) ( $product->license_key_prefix ?? '' ) ) . '" ' . disabled( $prefix_locked, true, false ) . '><p class="description">' . esc_html__( 'Optional. Leave blank for ABCD-EFGH-JKLM-NPQR, or enter 3-12 letters and numbers for MYAPP-ABCD-EFGH-JKLM-NPQR.', 'od-product-hub' ) . '</p>';
		if ( $prefix_locked ) {
			echo '<input type="hidden" name="license_key_prefix" value="' . esc_attr( (string) $product->license_key_prefix ) . '"><p class="description">' . esc_html__( 'This prefix is locked because licenses have already been issued for this product.', 'od-product-hub' ) . '</p>';
		}
		echo '</td></tr></table><p><button class="button button-primary">' . esc_html( $product ? __( 'Update', 'od-product-hub' ) : __( 'Add', 'od-product-hub' ) ) . '</button></p></form>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Product', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Slug', 'od-product-hub' ) . '</th><th>' . esc_html__( 'License key format', 'od-product-hub' ) . '</th><th>Product ID</th><th>Price ID</th><th>' . esc_html__( 'Status', 'od-product-hub' ) . '</th><th>' . esc_html__( 'Actions', 'od-product-hub' ) . '</th></tr></thead><tbody>';
		foreach ( $result->items as $p ) {
			$edit_url   = add_query_arg(
				array(
					'page'       => 'odph-products',
					'product_id' => $p->id,
				),
				admin_url( 'admin.php' )
			);
			$next       = 'active' === $p->status ? 'inactive' : 'active';
			$status_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'     => 'odph_product_status',
						'product_id' => $p->id,
						'status'     => $next,
					),
					admin_url( 'admin-post.php' )
				),
				'odph_product_status_' . $p->id
			);
			$key_format = '' === (string) $p->license_key_prefix ? 'ABCD-EFGH-JKLM-NPQR' : (string) $p->license_key_prefix . '-ABCD-EFGH-JKLM-NPQR';
			printf( '<tr><td>%1$s</td><td><code>%2$s</code></td><td><code>%12$s</code></td><td><a href="https://dashboard.stripe.com/products/%3$s" target="_blank" rel="noopener noreferrer"><code>%4$s</code><span class="screen-reader-text">%10$s</span></a></td><td><code>%5$s</code></td><td>%6$s</td><td><a href="%7$s">%11$s</a> | <a href="%8$s">%9$s</a></td></tr>', esc_html( $p->name ), esc_html( $p->slug ), esc_attr( $p->stripe_product_id ), esc_html( $p->stripe_product_id ), esc_html( $p->stripe_price_id ), esc_html( $p->status ), esc_url( $edit_url ), esc_url( $status_url ), esc_html( 'active' === $p->status ? __( 'Deactivate', 'od-product-hub' ) : __( 'Reactivate', 'od-product-hub' ) ), esc_html__( '(opens in a new tab)', 'od-product-hub' ), esc_html__( 'Edit', 'od-product-hub' ), esc_html( $key_format ) );
		}
		echo '</tbody></table>';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'current' => $result->page,
					'total'   => max( 1, $result->total_pages ),
				)
			)
		);
		echo '</div>';
	}
}
