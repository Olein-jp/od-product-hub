<?php
/**
 * Stable public contract state decision.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\API;

final readonly class LicenseDecision {
	public function __construct(
		public bool $active,
		public string $status,
		public ?string $error_code,
		public string $message
	) {}
}
