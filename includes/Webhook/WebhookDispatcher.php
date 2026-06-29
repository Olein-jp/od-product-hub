<?php
/**
 * Maps Stripe event types to focused handlers.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Webhook;

use OD_Product_Hub\Webhook\Handler\CheckoutCompletedHandler;
use OD_Product_Hub\Webhook\Handler\InvoiceHandler;
use OD_Product_Hub\Webhook\Handler\SubscriptionHandler;
use OD_Product_Hub\Webhook\Handler\WebhookHandler;

final class WebhookDispatcher {
	/** @var array<string, WebhookHandler> */
	private array $handlers;

	/** @param null|array<string, WebhookHandler> $handlers */
	public function __construct( ?array $handlers = null ) {
		$this->handlers = $handlers ?? array(
			'checkout.session.completed'    => new CheckoutCompletedHandler(),
			'customer.subscription.created' => new SubscriptionHandler(),
			'customer.subscription.updated' => new SubscriptionHandler(),
			'customer.subscription.deleted' => new SubscriptionHandler(),
			'invoice.paid'                  => new InvoiceHandler(),
			'invoice.payment_failed'        => new InvoiceHandler(),
		);
	}

	public function dispatch( string $event_type, object $object ): bool {
		$handler = $this->handlers[ $event_type ] ?? null;
		if ( ! $handler ) {
			return false;
		}
		$handler->handle( $event_type, $object );
		do_action( 'odph_webhook_processed', $event_type, $object );
		return true;
	}
}
