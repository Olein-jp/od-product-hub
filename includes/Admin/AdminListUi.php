<?php
/**
 * Shared WordPress-style administration list helpers.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Admin;

use OD_Product_Hub\Database\RepositoryPage;

final class AdminListUi {
	public static function per_page( string $option, int $default = 20 ): int {
		$value = absint( get_user_option( $option ) );
		return max( 1, min( 100, $value ? $value : $default ) );
	}

	public static function table_start( string $caption ): string {
		return '<div class="odph-list-table"><table class="wp-list-table widefat fixed striped table-view-list"><caption class="screen-reader-text">' . esc_html( $caption ) . '</caption>';
	}

	public static function table_end(): string {
		return '</table></div>';
	}

	public static function normalize_markup( string $html, string $caption ): string {
		$html = str_replace( '<form method="get">', '<form class="odph-list-filters" method="get">', $html );
		$html = str_replace( '<table class="widefat striped">', '<div class="odph-list-table"><table class="wp-list-table widefat fixed striped table-view-list"><caption class="screen-reader-text">' . esc_html( $caption ) . '</caption>', $html );
		$html = str_replace( '</tbody></table>', '</tbody></table></div>', $html );
		$html = str_replace( '<thead><tr><th>', '<thead><tr><th class="column-primary">', $html );
		$html = str_replace( '<tbody><tr><td>', '<tbody><tr><td class="column-primary">', $html );
		return $html;
	}

	public static function summary( RepositoryPage $result ): string {
		/* translators: %s: formatted number of items. */
		return '<p class="odph-list-summary">' . esc_html( sprintf( _n( '%s item', '%s items', $result->total, 'od-product-hub' ), number_format_i18n( $result->total ) ) ) . '</p>';
	}

	public static function pagination( RepositoryPage $result ): string {
		if ( $result->total_pages < 2 ) {
			return '';
		}
		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'current'   => $result->page,
				'total'     => $result->total_pages,
				'type'      => 'list',
				'prev_text' => __( 'Previous page', 'od-product-hub' ),
				'next_text' => __( 'Next page', 'od-product-hub' ),
			)
		);
		return '<div class="tablenav bottom"><div class="tablenav-pages" aria-label="' . esc_attr__( 'Pagination', 'od-product-hub' ) . '">' . wp_kses_post( (string) $links ) . '</div><br class="clear"></div>';
	}

	public static function empty_row( int $columns, bool $filtered, string $noun ): string {
		$message = $filtered
			? sprintf( /* translators: %s: item type. */ __( 'No matching %s were found. Clear or change the filters and try again.', 'od-product-hub' ), $noun )
			: sprintf( /* translators: %s: item type. */ __( 'No %s have been added yet.', 'od-product-hub' ), $noun );
		return '<tr class="no-items"><td class="colspanchange" colspan="' . esc_attr( (string) $columns ) . '">' . wp_kses_post( AdminUi::empty_state( $message ) ) . '</td></tr>';
	}

	public static function status( string $status ): string {
		$labels = array(
			'active'             => __( 'Active', 'od-product-hub' ),
			'inactive'           => __( 'Inactive', 'od-product-hub' ),
			'suspended'          => __( 'Suspended', 'od-product-hub' ),
			'expired'            => __( 'Expired', 'od-product-hub' ),
			'cancelled'          => __( 'Cancelled', 'od-product-hub' ),
			'canceled'           => __( 'Canceled', 'od-product-hub' ),
			'trialing'           => __( 'Trialing', 'od-product-hub' ),
			'past_due'           => __( 'Past due', 'od-product-hub' ),
			'unpaid'             => __( 'Unpaid', 'od-product-hub' ),
			'incomplete'         => __( 'Incomplete', 'od-product-hub' ),
			'incomplete_expired' => __( 'Incomplete expired', 'od-product-hub' ),
			'paused'             => __( 'Paused', 'od-product-hub' ),
			'success'            => __( 'Success', 'od-product-hub' ),
			'failure'            => __( 'Failure', 'od-product-hub' ),
			'failed'             => __( 'Failed', 'od-product-hub' ),
			'processed'          => __( 'Processed', 'od-product-hub' ),
			'pending'            => __( 'Pending', 'od-product-hub' ),
		);
		$tone   = in_array( $status, array( 'active', 'success', 'processed' ), true ) ? 'success' : ( in_array( $status, array( 'failure', 'failed', 'expired', 'cancelled', 'canceled', 'unpaid' ), true ) ? 'error' : ( in_array( $status, array( 'suspended', 'past_due', 'incomplete', 'incomplete_expired', 'pending' ), true ) ? 'warning' : 'neutral' ) );
		return AdminUi::status_badge( $labels[ $status ] ?? ucwords( str_replace( '_', ' ', $status ) ), $tone );
	}
}
