<?php
/**
 * Central contract status evaluation.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\API;

use OD_Product_Hub\Database\UtcDateTime;

final class LicenseStatusService {
	public function evaluate( object $license ): LicenseDecision {
		$license_status = (string) ( $license->status ?? 'inactive' );
		$stripe_status  = (string) ( $license->stripe_status ?? '' );
		if ( 'suspended' === $license_status ) {
			return new LicenseDecision( false, 'suspended', 'license_suspended', 'License is suspended.' );
		}
		if ( 'expired' === $license_status || $this->is_past( $license->expires_at ?? null ) ) {
			return new LicenseDecision( false, 'expired', 'license_expired', 'License has expired.' );
		}
		if ( 'cancelled' === $license_status || in_array( $stripe_status, array( 'canceled', 'cancelled' ), true ) ) {
			return new LicenseDecision( false, 'cancelled', 'license_cancelled', 'License has been cancelled.' );
		}
		if ( ! empty( $license->payment_failed_at ) || in_array( $stripe_status, array( 'past_due', 'unpaid' ), true ) ) {
			return new LicenseDecision( false, 'inactive', 'payment_failed', 'The subscription payment has failed.' );
		}
		if ( 'active' !== (string) ( $license->product_status ?? '' ) ) {
			return new LicenseDecision( false, 'inactive', 'product_inactive', 'Product is not active.' );
		}
		if ( 'active' !== $license_status ) {
			return new LicenseDecision( false, 'inactive', 'license_inactive', 'License is not active.' );
		}
		if ( ! in_array( $stripe_status, array( 'active', 'trialing' ), true ) ) {
			return new LicenseDecision( false, 'inactive', 'subscription_inactive', 'Subscription is not active.' );
		}
		return new LicenseDecision( true, 'active', null, 'License is active.' );
	}

	/** @param mixed $utc */
	private function is_past( $utc ): bool {
		return is_string( $utc ) && '' !== $utc && $utc < UtcDateTime::now();
	}
}
