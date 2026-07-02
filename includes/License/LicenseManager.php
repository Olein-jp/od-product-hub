<?php
/**
 * Transactional administrative license operations.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\License;

use OD_Product_Hub\Database\DatabaseException;
use OD_Product_Hub\Database\UtcDateTime;
use OD_Product_Hub\Log\AdminLogRepository;
use OD_Product_Hub\Subscription\SubscriptionRepository;

final class LicenseManager {
	/** @var callable(): string */
	private $key_factory;

	/** @param null|callable(): string $key_factory */
	public function __construct( ?callable $key_factory = null ) {
		$this->key_factory = $key_factory ?? static fn(): string => ( new LicenseGenerator() )->generate();
	}

	public function suspend( int $license_id, int $user_id ): void {
		$this->transaction(
			function () use ( $license_id, $user_id ): void {
				$license = $this->license( $license_id );
				( new LicenseRepository() )->update( $license_id, array( 'status' => 'suspended' ) );
				$this->log( $user_id, 'license_suspended', $license_id, array( 'previous_status' => $license->status ) );
			}
		);
	}

	public function resume( int $license_id, int $user_id ): void {
		$this->transaction(
			function () use ( $license_id, $user_id ): void {
				$license = $this->license( $license_id );
				if ( 'suspended' !== $license->status ) {
					throw new \DomainException( esc_html__( 'Only suspended licenses can be resumed.', 'od-product-hub' ) );
				}
				$this->assert_subscription_active( $license );
				( new LicenseRepository() )->update( $license_id, array( 'status' => 'active' ) );
				$this->log( $user_id, 'license_resumed', $license_id, array( 'previous_status' => $license->status ) );
			}
		);
	}

	public function reissue( int $license_id, int $user_id ): string {
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$key = ( $this->key_factory )();
			if ( ! LicenseGenerator::is_valid( $key ) ) {
				throw new \RuntimeException( esc_html__( 'Could not generate a new license key.', 'od-product-hub' ) );
			}
			try {
				$this->transaction(
					function () use ( $license_id, $user_id, $key ): void {
						$license = $this->license( $license_id );
						$this->assert_subscription_active( $license );
						( new LicenseRepository() )->update(
							$license_id,
							array(
								'license_key'      => $key,
								'license_key_hash' => LicenseGenerator::hash( $key ),
								'status'           => 'active',
								'issued_at'        => UtcDateTime::now(),
								'last_verified_at' => null,
							)
						);
						$this->log( $user_id, 'license_reissued', $license_id, array( 'previous_key' => LicenseGenerator::mask( (string) $license->license_key ) ) );
					}
				);
				return $key;
			} catch ( DatabaseException $error ) {
				if ( 9 === $attempt ) {
					throw $error;
				}
			}
		}
		throw new \RuntimeException( esc_html__( 'Could not reissue the license key.', 'od-product-hub' ) );
	}

	private function license( int $license_id ): object {
		$license = ( new LicenseRepository() )->find_admin_detail( $license_id );
		if ( ! $license ) {
			throw new \DomainException( esc_html__( 'License not found.', 'od-product-hub' ) );
		}
		return $license;
	}

	private function assert_subscription_active( object $license ): void {
		if ( ! $license->subscription_id ) {
			throw new \DomainException( esc_html__( 'No active subscription is available.', 'od-product-hub' ) );
		}
		$subscription = ( new SubscriptionRepository() )->find( (int) $license->subscription_id );
		$active       = $subscription && in_array( $subscription->stripe_status, array( 'active', 'trialing' ), true ) && ! $subscription->payment_failed_at;
		if ( $active && $subscription->current_period_end ) {
			$active = strtotime( (string) $subscription->current_period_end . ' UTC' ) >= time();
		}
		if ( ! $active ) {
			throw new \DomainException( esc_html__( 'This operation is unavailable because the Stripe subscription is not active.', 'od-product-hub' ) );
		}
	}

	/** @param array<string, scalar|null> $details */
	private function log( int $user_id, string $action, int $license_id, array $details ): void {
		( new AdminLogRepository() )->create(
			array(
				'user_id'     => $user_id,
				'action'      => $action,
				'object_type' => 'license',
				'object_id'   => $license_id,
				'details'     => wp_json_encode( $details ),
			)
		);
	}

	/** @param callable(): void $callback */
	private function transaction( callable $callback ): void {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transaction boundary for an administrative state change.
			throw DatabaseException::from_last_error( 'start license transaction' );
		}
		try {
			$callback();
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transaction boundary for an administrative state change.
				throw DatabaseException::from_last_error( 'commit license transaction' );
			}
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required rollback after a failed administrative state change.
			throw $error;
		}
	}
}
