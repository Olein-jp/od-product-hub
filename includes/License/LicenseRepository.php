<?php
/**
 * License persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\License;

final class LicenseRepository {
	/** @return object|null */
	public function find_for_verification( string $key, string $slug ) {
		global $wpdb;
		$licenses      = $wpdb->prefix . 'odph_licenses';
		$products      = $wpdb->prefix . 'odph_products';
		$subscriptions = $wpdb->prefix . 'odph_subscriptions';
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT l.*, p.name AS product_name, p.slug AS product_slug, p.status AS product_status,
			 s.stripe_status, s.current_period_end, s.cancel_at_period_end, s.payment_failed_at
			 FROM %i l INNER JOIN %i p ON p.id = l.product_id
			 LEFT JOIN %i s ON s.id = l.subscription_id
			 WHERE l.license_key_hash = %s AND p.slug = %s LIMIT 1',
				$licenses,
				$products,
				$subscriptions,
				LicenseGenerator::hash( $key ),
				$slug
			)
		);
	}

	public function touch( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'odph_licenses',
			array(
				'last_verified_at' => current_time( 'mysql', true ),
				'updated_at'       => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public function issue( int $product_id, int $customer_id, int $subscription_id, ?string $expires_at = null ): string {
		global $wpdb;
		$generator = new LicenseGenerator();
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$key = $generator->generate();
			$now = current_time( 'mysql', true );
			$ok  = $wpdb->insert(
				$wpdb->prefix . 'odph_licenses',
				array(
					'product_id'       => $product_id,
					'customer_id'      => $customer_id,
					'subscription_id'  => $subscription_id,
					'license_key'      => $key,
					'license_key_hash' => LicenseGenerator::hash( $key ),
					'status'           => 'active',
					'issued_at'        => $now,
					'expires_at'       => $expires_at,
					'created_at'       => $now,
					'updated_at'       => $now,
				)
			);
			if ( false !== $ok ) {
				return $key;
			}
		}
		throw new \RuntimeException( 'Unable to generate a unique license key.' );
	}
}
