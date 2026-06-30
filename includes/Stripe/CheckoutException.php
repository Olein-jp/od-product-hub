<?php
/**
 * Safe purchaser-facing Checkout error.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Stripe;

final class CheckoutException extends \RuntimeException {
	public function __construct( public readonly string $error_code ) {
		parent::__construct( 'Checkout could not be started.' );
	}
}
