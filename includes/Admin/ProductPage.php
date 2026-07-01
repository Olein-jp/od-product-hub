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
		$query      = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering.
		$status     = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering.
		$page       = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$repository = $this->products;
		$result     = $repository->search_admin( $query, $status, $page );
		$edit_id    = absint( $_GET['product_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only edit selection.
		$product    = $edit_id ? $repository->find( $edit_id ) : null;
		echo '<div class="wrap"><h1>商品管理</h1><form method="get"><input type="hidden" name="page" value="odph-products"><label class="screen-reader-text" for="product-search">商品を検索</label><input id="product-search" name="s" value="' . esc_attr( $query ) . '" placeholder="商品名・スラッグ"><select name="status"><option value="">すべての状態</option><option value="active" ' . selected( $status, 'active', false ) . '>active</option><option value="inactive" ' . selected( $status, 'inactive', false ) . '>inactive</option></select> <button class="button">絞り込む</button></form>';
		echo '<h2>' . esc_html( $product ? '商品を編集' : '商品を追加' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="odph_save_product"><input type="hidden" name="product_id" value="' . esc_attr( (string) ( $product->id ?? 0 ) ) . '">';
		wp_nonce_field( 'odph_save_product' );
		printf( '<table class="form-table"><tr><th><label for="name">商品名</label></th><td><input required class="regular-text" id="name" name="name" value="%1$s"></td></tr><tr><th><label for="description">説明</label></th><td><textarea class="large-text" id="description" name="description">%2$s</textarea></td></tr><tr><th><label for="price_description">価格説明</label></th><td><input class="regular-text" id="price_description" name="price_description" value="%3$s" placeholder="例: 月額1,980円（税込）"></td></tr><tr><th><label for="billing_description">サブスクリプション説明</label></th><td><textarea class="large-text" id="billing_description" name="billing_description" placeholder="例: 解約するまで毎月自動更新されます。">%4$s</textarea></td></tr><tr><th><label for="slug">スラッグ</label></th><td><input required pattern="[a-z0-9_-]+" id="slug" name="slug" value="%5$s"></td></tr><tr><th><label for="stripe_product_id">Stripe Product ID</label></th><td><input required pattern="prod_[A-Za-z0-9]+" id="stripe_product_id" name="stripe_product_id" value="%6$s"></td></tr><tr><th><label for="stripe_price_id">Stripe Price ID</label></th><td><input required pattern="price_[A-Za-z0-9]+" id="stripe_price_id" name="stripe_price_id" value="%7$s"></td></tr><tr><th>状態</th><td><select name="status"><option value="active" %8$s>active</option><option value="inactive" %9$s>inactive</option></select></td></tr></table><p><button class="button button-primary">%10$s</button></p></form>', esc_attr( (string) ( $product->name ?? '' ) ), esc_textarea( (string) ( $product->description ?? '' ) ), esc_attr( (string) ( $product->price_description ?? '' ) ), esc_textarea( (string) ( $product->billing_description ?? '' ) ), esc_attr( (string) ( $product->slug ?? '' ) ), esc_attr( (string) ( $product->stripe_product_id ?? '' ) ), esc_attr( (string) ( $product->stripe_price_id ?? '' ) ), selected( (string) ( $product->status ?? 'active' ), 'active', false ), selected( (string) ( $product->status ?? '' ), 'inactive', false ), esc_html( $product ? '更新' : '追加' ) );
		echo '<table class="widefat striped"><thead><tr><th>商品</th><th>スラッグ</th><th>Product ID</th><th>Price ID</th><th>状態</th><th>操作</th></tr></thead><tbody>';
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
			printf( '<tr><td>%s</td><td><code>%s</code></td><td><a href="https://dashboard.stripe.com/products/%s" target="_blank" rel="noopener noreferrer"><code>%s</code><span class="screen-reader-text">（新しいタブで開く）</span></a></td><td><code>%s</code></td><td>%s</td><td><a href="%s">編集</a> | <a href="%s">%s</a></td></tr>', esc_html( $p->name ), esc_html( $p->slug ), esc_attr( $p->stripe_product_id ), esc_html( $p->stripe_product_id ), esc_html( $p->stripe_price_id ), esc_html( $p->status ), esc_url( $edit_url ), esc_url( $status_url ), esc_html( 'active' === $p->status ? '停止' : '再開' ) );
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
