<?php
/**
 * Webhook event handler contract.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Webhook\Handler;

interface WebhookHandler {
	public function handle( string $event_type, object $object ): void;
}
