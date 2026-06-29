<?php
/**
 * Webhook infrastructure unit tests.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Webhook;

use PHPUnit\Framework\TestCase;

final class WebhookInfrastructureTest extends TestCase {
	public function test_dispatcher_routes_each_supported_event_and_rejects_unknown_events(): void {
		$handler    = new class() implements \OD_Product_Hub\Webhook\Handler\WebhookHandler {
			/** @var list<string> */
			public array $handled_types = array();

			public function handle( string $event_type, object $object ): void {
				unset( $object );
				$this->handled_types[] = $event_type;
			}
		};
		$types      = array(
			'checkout.session.completed',
			'customer.subscription.created',
			'customer.subscription.updated',
			'customer.subscription.deleted',
			'invoice.paid',
			'invoice.payment_failed',
		);
		$dispatcher = new WebhookDispatcher( array_fill_keys( $types, $handler ) );
		foreach ( $types as $type ) {
			self::assertTrue( $dispatcher->dispatch( $type, (object) array( 'id' => $type ) ) );
		}
		self::assertFalse( $dispatcher->dispatch( 'unknown.event', (object) array() ) );
		self::assertSame( $types, $handler->handled_types );
	}

	public function test_payload_redactor_removes_personal_and_payment_values_recursively(): void {
		$payload = ( new PayloadRedactor() )->redact_json(
			'{"id":"evt_1","email":"secret@example.test","data":{"object":{"card":"4242","metadata":{"safe":"yes"},"client_secret":"hidden"}}}'
		);
		self::assertStringNotContainsString( 'secret@example.test', $payload );
		self::assertStringNotContainsString( '4242', $payload );
		self::assertStringNotContainsString( 'hidden', $payload );
		self::assertStringContainsString( '"safe":"yes"', $payload );
	}
}
