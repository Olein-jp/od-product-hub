<?php
/**
 * Safe purchaser-facing Customer Portal error.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Stripe;

final class PortalException extends \RuntimeException {
	public function __construct( public readonly string $error_code ) {
		parent::__construct( 'Customer Portal could not be started.' );
	}
}
