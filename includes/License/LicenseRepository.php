<?php
/**
 * License persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\License;

use OD_Product_Hub\Database\AbstractRepository;
use OD_Product_Hub\Database\DatabaseException;
use OD_Product_Hub\Database\RepositoryPage;

final class LicenseRepository extends AbstractRepository {
	protected function table_suffix(): string {
		return 'licenses';
	}

	protected function writable_columns(): array {
		return array( 'product_id', 'customer_id', 'subscription_id', 'license_key', 'license_key_hash', 'status', 'issued_at', 'expires_at', 'last_verified_at', 'created_at', 'updated_at' );
	}

	public function find_for_verification( string $key, string $slug ): ?object {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT l.*, p.name AS product_name, p.slug AS product_slug, p.status AS product_status,
				 s.stripe_status, s.current_period_end, s.cancel_at_period_end, s.payment_failed_at
				 FROM %i l INNER JOIN %i p ON p.id = l.product_id
				 LEFT JOIN %i s ON s.id = l.subscription_id
				 WHERE l.license_key_hash = %s AND p.slug = %s LIMIT 1',
				$this->table(),
				$wpdb->prefix . 'odph_products',
				$wpdb->prefix . 'odph_subscriptions',
				LicenseGenerator::hash( $key ),
				$slug
			)
		);
		$this->assert_read( 'verify license' );
		return is_object( $row ) ? $row : null;
	}

	public function touch( int $id ): bool {
		return $this->update( $id, array( 'last_verified_at' => \OD_Product_Hub\Database\UtcDateTime::now() ) );
	}

	public function exists_for_subscription( int $subscription_id ): bool {
		return 0 < $this->search( array( 'subscription_id' => $subscription_id ), 1, 1 )->total;
	}

	/** @param array<string, mixed> $data */
	public function update_by_subscription( int $subscription_id, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = \OD_Product_Hub\Database\UtcDateTime::now();
		$data               = array_intersect_key( $data, array_flip( $this->writable_columns() ) );
		$result             = $wpdb->update( $this->table(), $data, array( 'subscription_id' => $subscription_id ) );
		if ( false === $result ) {
			throw DatabaseException::from_last_error( 'update licenses by subscription' );
		}
		return 0 < $result;
	}

	public function set_status_preserving_suspended( int $subscription_id, string $status ): bool {
		global $wpdb;
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = CASE WHEN status = 'suspended' THEN status ELSE %s END, updated_at = %s WHERE subscription_id = %d",
				$this->table(),
				$status,
				\OD_Product_Hub\Database\UtcDateTime::now(),
				$subscription_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic conditional update preserves administrator suspension.
		if ( false === $result ) {
			throw DatabaseException::from_last_error( 'update protected license status' );
		}
		return 0 < $result;
	}

	public function sync_subscription_state( int $subscription_id, string $status, ?string $expires_at ): bool {
		$this->update_by_subscription( $subscription_id, array( 'expires_at' => $expires_at ) );
		return $this->set_status_preserving_suspended( $subscription_id, $status );
	}

	/** @return list<object> */
	public function find_for_user( int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.id AS license_id, l.license_key, l.status AS license_status, l.expires_at, p.name AS product_name,
				 s.stripe_status, s.current_period_end, s.cancel_at_period_end, c.stripe_customer_id
				 FROM %i c INNER JOIN %i l ON l.customer_id = c.id
				 INNER JOIN %i p ON p.id = l.product_id
				 LEFT JOIN %i s ON s.id = l.subscription_id AND s.customer_id = c.id AND s.product_id = p.id
				 WHERE c.wp_user_id = %d ORDER BY l.id DESC',
				$wpdb->prefix . 'odph_customers',
				$this->table(),
				$wpdb->prefix . 'odph_products',
				$wpdb->prefix . 'odph_subscriptions',
				$user_id
			)
		);
		$this->assert_read( 'find user licenses' );
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_object' ) ) : array();
	}

	public function count_by_status( string $status ): int {
		return $this->search( array( 'status' => $status ), 1, 1 )->total;
	}

	public function search_admin( ?string $key_hash, string $status, int $page = 1, int $per_page = 20 ): RepositoryPage {
		global $wpdb;
		$allowed    = array( 'active', 'inactive', 'expired', 'cancelled', 'suspended' );
		$status     = in_array( $status, $allowed, true ) ? $status : '';
		$page       = max( 1, $page );
		$conditions = array();
		$values     = array( $this->table() );
		if ( null !== $key_hash ) {
			$conditions[] = 'l.license_key_hash = %s';
			$values[]     = $key_hash;
		}
		if ( '' !== $status ) {
			$conditions[] = 'l.status = %s';
			$values[]     = $status;
		}
		$where = $conditions ? ' WHERE ' . implode( ' AND ', $conditions ) : '';
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i l' . $where, ...$values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Conditions are fixed and dynamic values are prepared.
		$this->assert_read( 'count licenses' );
		$sql  = 'SELECT l.id, l.license_key, l.status, l.issued_at, l.expires_at, l.last_verified_at,
			p.name AS product_name, c.email AS customer_email
			FROM %i l INNER JOIN %i p ON p.id = l.product_id INNER JOIN %i c ON c.id = l.customer_id' . $where . '
			ORDER BY l.id DESC LIMIT %d OFFSET %d';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is composed only from fixed clauses and prepared placeholders.
				...array_merge(
					array( $this->table(), $wpdb->prefix . 'odph_products', $wpdb->prefix . 'odph_customers' ),
					array_slice( $values, 1 ),
					array( $per_page, ( $page - 1 ) * $per_page )
				)
			)
		);
		$this->assert_read( 'search licenses' );
		$items = is_array( $rows ) ? array_values( array_filter( $rows, 'is_object' ) ) : array();
		return new RepositoryPage( $items, $total, $page, $per_page, (int) ceil( $total / $per_page ) );
	}

	public function find_admin_detail( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT l.*, p.name AS product_name, p.slug AS product_slug, c.email AS customer_email, c.name AS customer_name,
				s.stripe_subscription_id, s.stripe_status, s.current_period_end, s.payment_failed_at
				FROM %i l INNER JOIN %i p ON p.id = l.product_id INNER JOIN %i c ON c.id = l.customer_id
				LEFT JOIN %i s ON s.id = l.subscription_id WHERE l.id = %d LIMIT 1',
				$this->table(),
				$wpdb->prefix . 'odph_products',
				$wpdb->prefix . 'odph_customers',
				$wpdb->prefix . 'odph_subscriptions',
				$id
			)
		);
		$this->assert_read( 'find license detail' );
		return is_object( $row ) ? $row : null;
	}

	/** @return list<object> */
	public function find_for_customer( int $customer_id, int $limit = 100 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.id, l.license_key, l.status, l.issued_at, l.expires_at, l.last_verified_at, p.name AS product_name
				FROM %i l INNER JOIN %i p ON p.id = l.product_id WHERE l.customer_id = %d ORDER BY l.id DESC LIMIT %d',
				$this->table(),
				$wpdb->prefix . 'odph_products',
				$customer_id,
				max( 1, min( 100, $limit ) )
			)
		);
		$this->assert_read( 'find customer licenses' );
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_object' ) ) : array();
	}

	public function issue( int $product_id, int $customer_id, int $subscription_id, ?string $expires_at = null ): string {
		$generator = new LicenseGenerator();
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$key = $generator->generate();
			try {
				$this->create(
					array(
						'product_id'       => $product_id,
						'customer_id'      => $customer_id,
						'subscription_id'  => $subscription_id,
						'license_key'      => $key,
						'license_key_hash' => LicenseGenerator::hash( $key ),
						'status'           => 'active',
						'issued_at'        => \OD_Product_Hub\Database\UtcDateTime::now(),
						'expires_at'       => $expires_at,
					)
				);
				return $key;
			} catch ( DatabaseException $error ) {
				if ( 9 === $attempt ) {
					throw $error;
				}
			}
		}
		throw new \RuntimeException( 'Unable to generate a unique license key.' );
	}
}
