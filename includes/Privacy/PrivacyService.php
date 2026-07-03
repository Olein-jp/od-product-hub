<?php
/**
 * WordPress personal data exporter and eraser integration.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Privacy;

use OD_Product_Hub\License\LicenseGenerator;

final class PrivacyService {
	private const PER_PAGE = 50;

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/** @param array<string, array<string, mixed>> $exporters @return array<string, array<string, mixed>> */
	public function register_exporter( array $exporters ): array {
		$exporters['od-product-hub'] = array(
			'exporter_friendly_name' => __( 'OD Product Hub', 'od-product-hub' ),
			'callback'               => array( $this, 'export_personal_data' ),
		);
		return $exporters;
	}

	/** @param array<string, array<string, mixed>> $erasers @return array<string, array<string, mixed>> */
	public function register_eraser( array $erasers ): array {
		$erasers['od-product-hub'] = array(
			'eraser_friendly_name' => __( 'OD Product Hub', 'od-product-hub' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/** @return array{data: list<array<string, mixed>>, done: bool} */
	public function export_personal_data( string $email_address, int $page = 1 ): array {
		$email_address = strtolower( trim( $email_address ) );
		$page          = max( 1, $page );
		if ( ! is_email( $email_address ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$data = array();
		$done = true;
		foreach ( $this->export_queries( $email_address ) as $type => $definition ) {
			$rows = $this->get_page( $definition['sql'], $definition['values'], $page );
			if ( self::PER_PAGE === count( $rows ) ) {
				$done = false;
			}
			foreach ( $rows as $row ) {
				$data[] = $this->export_item( $type, $row );
			}
		}

		return array(
			'data' => $data,
			'done' => $done,
		);
	}

	/** @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool} */
	public function erase_personal_data( string $email_address, int $page = 1 ): array {
		global $wpdb;
		$email_address = strtolower( trim( $email_address ) );
		$page          = max( 1, $page );
		if ( ! is_email( $email_address ) ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$customer_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE LOWER(email) = %s',
				$wpdb->prefix . 'odph_customers',
				$email_address
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Privacy requests require cross-table lookup by the verified request email.
		$customer_ids = array_values( array_map( 'intval', is_array( $customer_ids ) ? $customer_ids : array() ) );
		if ( array() === $customer_ids ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$license_ids = $this->license_ids( $customer_ids );
		$removed     = false;
		$done        = true;
		if ( array() !== $license_ids ) {
			foreach ( array( 'api_logs', 'downloads' ) as $table_suffix ) {
				$ids = $this->erasable_log_ids( $table_suffix, $license_ids );
				if ( self::PER_PAGE === count( $ids ) ) {
					$done = false;
				}
				if ( array() !== $ids ) {
					$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
					$sql          = "UPDATE %i SET site_url = NULL, ip_address = NULL, user_agent = NULL WHERE id IN ({$placeholders})";
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- The SQL template is internal and contains one integer placeholder per selected row.
					$result  = $wpdb->query( $wpdb->prepare( $sql, $wpdb->prefix . 'odph_' . $table_suffix, ...$ids ) );
					$removed = $removed || 0 < (int) $result;
				}
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => true,
			'messages'       => array(
				__( 'Customer, subscription, license, and delivery records required for contracts, accounting, fraud prevention, and Stripe synchronization were retained. Data managed by Stripe must be requested separately.', 'od-product-hub' ),
			),
			'done'           => $done,
		);
	}

	/** @return array<string, array{sql: string, values: list<mixed>}> */
	private function export_queries( string $email ): array {
		global $wpdb;
		$customers     = $wpdb->prefix . 'odph_customers';
		$subscriptions = $wpdb->prefix . 'odph_subscriptions';
		$licenses      = $wpdb->prefix . 'odph_licenses';
		$products      = $wpdb->prefix . 'odph_products';
		$api_logs      = $wpdb->prefix . 'odph_api_logs';
		$downloads     = $wpdb->prefix . 'odph_downloads';
		$email_logs    = $wpdb->prefix . 'odph_email_logs';
		$email_hash    = hash_hmac( 'sha256', $email, wp_salt( 'auth' ) );

		return array(
			'customer'     => array(
				'sql'    => 'SELECT c.* FROM %i c WHERE LOWER(c.email) = %s ORDER BY c.id ASC',
				'values' => array( $customers, $email ),
			),
			'subscription' => array(
				'sql'    => 'SELECT s.*, p.name AS product_name FROM %i s INNER JOIN %i c ON c.id = s.customer_id INNER JOIN %i p ON p.id = s.product_id WHERE LOWER(c.email) = %s ORDER BY s.id ASC',
				'values' => array( $subscriptions, $customers, $products, $email ),
			),
			'license'      => array(
				'sql'    => 'SELECT l.*, p.name AS product_name FROM %i l INNER JOIN %i c ON c.id = l.customer_id INNER JOIN %i p ON p.id = l.product_id WHERE LOWER(c.email) = %s ORDER BY l.id ASC',
				'values' => array( $licenses, $customers, $products, $email ),
			),
			'api_log'      => array(
				'sql'    => 'SELECT a.* FROM %i a INNER JOIN %i l ON l.id = a.license_id INNER JOIN %i c ON c.id = l.customer_id WHERE LOWER(c.email) = %s ORDER BY a.id ASC',
				'values' => array( $api_logs, $licenses, $customers, $email ),
			),
			'download'     => array(
				'sql'    => 'SELECT d.* FROM %i d INNER JOIN %i l ON l.id = d.license_id INNER JOIN %i c ON c.id = l.customer_id WHERE LOWER(c.email) = %s ORDER BY d.id ASC',
				'values' => array( $downloads, $licenses, $customers, $email ),
			),
			'email_log'    => array(
				'sql'    => 'SELECT e.* FROM %i e WHERE e.recipient_hash = %s ORDER BY e.id ASC',
				'values' => array( $email_logs, $email_hash ),
			),
		);
	}

	/** @param list<mixed> $values @return list<object> */
	private function get_page( string $sql, array $values, int $page ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal query definitions have different, fixed placeholder counts before shared pagination is appended.
			$wpdb->prepare( $sql . ' LIMIT %d OFFSET %d', ...array_merge( $values, array( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ) ) )
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Queries are fixed internal definitions; only values are variable.
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_object' ) ) : array();
	}

	/** @return array<string, mixed> */
	private function export_item( string $type, object $row ): array {
		$labels = array(
			'customer'     => __( 'OD Product Hub customer data', 'od-product-hub' ),
			'subscription' => __( 'OD Product Hub subscription data', 'od-product-hub' ),
			'license'      => __( 'OD Product Hub license data', 'od-product-hub' ),
			'api_log'      => __( 'OD Product Hub API usage data', 'od-product-hub' ),
			'download'     => __( 'OD Product Hub download data', 'od-product-hub' ),
			'email_log'    => __( 'OD Product Hub email delivery data', 'od-product-hub' ),
		);
		$fields = $this->safe_fields( $type, $row );
		$data   = array();
		foreach ( $fields as $name => $value ) {
			if ( null !== $value && '' !== (string) $value ) {
				$data[] = array(
					'name'  => $name,
					'value' => (string) $value,
				);
			}
		}
		return array(
			'group_id'    => 'odph-' . str_replace( '_', '-', $type ),
			'group_label' => $labels[ $type ],
			'item_id'     => 'odph-' . str_replace( '_', '-', $type ) . '-' . (int) $row->id,
			'data'        => $data,
		);
	}

	/** @return array<string, scalar|null> */
	private function safe_fields( string $type, object $row ): array {
		switch ( $type ) {
			case 'customer':
				return array(
					__( 'Email address', 'od-product-hub' ) => $row->email,
					__( 'Name', 'od-product-hub' )       => $row->name,
					__( 'Created at', 'od-product-hub' ) => $row->created_at,
					__( 'Updated at', 'od-product-hub' ) => $row->updated_at,
				);
			case 'subscription':
				return array(
					__( 'Product', 'od-product-hub' )    => $row->product_name,
					__( 'Subscription status', 'od-product-hub' ) => $row->stripe_status,
					__( 'Current period start', 'od-product-hub' ) => $row->current_period_start,
					__( 'Current period end', 'od-product-hub' ) => $row->current_period_end,
					__( 'Cancel at period end', 'od-product-hub' ) => (int) $row->cancel_at_period_end ? __( 'Yes', 'od-product-hub' ) : __( 'No', 'od-product-hub' ),
					__( 'Payment failed at', 'od-product-hub' ) => $row->payment_failed_at,
					__( 'Created at', 'od-product-hub' ) => $row->created_at,
				);
			case 'license':
				return array(
					__( 'Product', 'od-product-hub' )    => $row->product_name,
					__( 'License key (masked)', 'od-product-hub' ) => LicenseGenerator::mask( (string) $row->license_key ),
					__( 'Status', 'od-product-hub' )     => $row->status,
					__( 'Issued at', 'od-product-hub' )  => $row->issued_at,
					__( 'Expires at', 'od-product-hub' ) => $row->expires_at,
					__( 'Last verified at', 'od-product-hub' ) => $row->last_verified_at,
				);
			case 'api_log':
				return array(
					__( 'Action', 'od-product-hub' )      => $row->action,
					__( 'Result', 'od-product-hub' )      => $row->result,
					__( 'Site URL', 'od-product-hub' )    => $row->site_url,
					__( 'IP address', 'od-product-hub' )  => $row->ip_address,
					__( 'User-Agent', 'od-product-hub' )  => $row->user_agent,
					__( 'Error code', 'od-product-hub' )  => $row->error_code,
					__( 'Recorded at', 'od-product-hub' ) => $row->created_at,
				);
			case 'download':
				return array(
					__( 'Site URL', 'od-product-hub' )    => $row->site_url,
					__( 'Used at', 'od-product-hub' )     => $row->used_at,
					__( 'IP address', 'od-product-hub' )  => $row->ip_address,
					__( 'User-Agent', 'od-product-hub' )  => $row->user_agent,
					__( 'Result', 'od-product-hub' )      => $row->result,
					__( 'Recorded at', 'od-product-hub' ) => $row->created_at,
				);
			default:
				return array(
					__( 'Email type', 'od-product-hub' )  => $row->email_type,
					__( 'Result', 'od-product-hub' )      => $row->status,
					__( 'Error code', 'od-product-hub' )  => $row->error_code,
					__( 'Recorded at', 'od-product-hub' ) => $row->created_at,
				);
		}
	}

	/** @param list<int> $customer_ids @return list<int> */
	private function license_ids( array $customer_ids ): array {
		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $customer_ids ), '%d' ) );
		$sql          = "SELECT id FROM %i WHERE customer_id IN ({$placeholders})";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- The SQL template is internal and contains one integer placeholder per matched customer.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $wpdb->prefix . 'odph_licenses', ...$customer_ids ) );
		return array_values( array_map( 'intval', is_array( $ids ) ? $ids : array() ) );
	}

	/** @param list<int> $license_ids @return list<int> */
	private function erasable_log_ids( string $table_suffix, array $license_ids ): array {
		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $license_ids ), '%d' ) );
		$sql          = "SELECT id FROM %i WHERE license_id IN ({$placeholders}) AND (site_url IS NOT NULL OR ip_address IS NOT NULL OR user_agent IS NOT NULL) ORDER BY id ASC LIMIT %d";
		$values       = array_merge( array( $wpdb->prefix . 'odph_' . $table_suffix ), $license_ids, array( self::PER_PAGE ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- The SQL template is internal and contains one integer placeholder per matched license.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, ...$values ) );
		return array_values( array_map( 'intval', is_array( $ids ) ? $ids : array() ) );
	}
}
