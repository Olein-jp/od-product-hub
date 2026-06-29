<?php
/**
 * License persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\License;

use OD_Product_Hub\Database\AbstractRepository;
use OD_Product_Hub\Database\DatabaseException;

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

	/** @return list<object> */
	public function find_for_user( int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.license_key, l.status AS license_status, l.expires_at, p.name AS product_name,
				 s.stripe_status, s.current_period_end, s.cancel_at_period_end, c.stripe_customer_id
				 FROM %i c INNER JOIN %i l ON l.customer_id = c.id
				 INNER JOIN %i p ON p.id = l.product_id LEFT JOIN %i s ON s.id = l.subscription_id
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
