<?php
/**
 * Stripe webhook REST endpoint.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Webhook;

use OD_Product_Hub\Log\WebhookLogRepository;
use Stripe\Webhook;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class WebhookController {
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'route' ) );
	}

	public function route(): void {
		register_rest_route(
			'od-product-hub/v1',
			'/stripe/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/** @return WP_REST_Response|WP_Error */
	public function handle( WP_REST_Request $request ) {
		$payload        = $request->get_body();
		$masked_payload = ( new PayloadRedactor() )->redact_json( $payload );
		$signature      = (string) $request->get_header( 'stripe-signature' );
		$settings       = get_option( 'odph_settings', array() );
		$logs           = new WebhookLogRepository();
		try {
			if ( ! class_exists( Webhook::class ) ) {
				throw new \RuntimeException( 'Stripe SDK unavailable.' );
			}
			$event = Webhook::constructEvent( $payload, $signature, (string) ( $settings['stripe_webhook_secret'] ?? '' ) );
		} catch ( \Throwable $error ) {
			unset( $error );
			$logs->record_signature_failure( $masked_payload, 'signature_verification_failed' );
			return new WP_Error( 'signature_verification_failed', 'Webhook signature verification failed.', array( 'status' => 400 ) );
		}

		$event_id   = sanitize_text_field( (string) $event->id );
		$event_type = sanitize_text_field( (string) $event->type );
		$object     = $event->data->object ?? null;
		if ( '' === $event_id || '' === $event_type || ! is_object( $object ) ) {
			return new WP_Error( 'invalid_event', 'Webhook event is invalid.', array( 'status' => 400 ) );
		}

		$claim = $logs->claim( $event_id, $event_type, $masked_payload );
		if ( 'duplicate' === $claim['status'] ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'result'  => 'duplicated_event',
				),
				200
			);
		}
		if ( 'processing' === $claim['status'] ) {
			return new WP_Error( 'webhook_processing', 'Webhook event is already being processed.', array( 'status' => 409 ) );
		}
		if ( 'exhausted' === $claim['status'] ) {
			return new WP_Error( 'webhook_retry_exhausted', 'Webhook retry limit reached.', array( 'status' => 503 ) );
		}
		$log_id  = (int) $claim['id'];
		$attempt = $claim['attempt'];

		try {
			$handled = ( new WebhookDispatcher() )->dispatch( $event_type, $object );
			if ( ! $handled ) {
				if ( ! $logs->complete_claim( $log_id, $attempt, 'unsupported', 'unsupported_event_type' ) ) {
					return new WP_Error( 'webhook_claim_superseded', 'Webhook claim was superseded.', array( 'status' => 409 ) );
				}
				return new WP_REST_Response(
					array(
						'success' => true,
						'result'  => 'unsupported',
					),
					200
				);
			}
			if ( ! $logs->complete_claim( $log_id, $attempt, 'success' ) ) {
				return new WP_Error( 'webhook_claim_superseded', 'Webhook claim was superseded.', array( 'status' => 409 ) );
			}
			return new WP_REST_Response(
				array(
					'success' => true,
					'result'  => 'success',
				),
				200
			);
		} catch ( \Throwable $error ) {
			unset( $error );
			$result = $logs->fail_claim( $log_id, $attempt, 'webhook_processing_failed' );
			if ( 'superseded' === $result ) {
				return new WP_Error( 'webhook_claim_superseded', 'Webhook claim was superseded.', array( 'status' => 409 ) );
			}
			do_action( 'odph_webhook_processing_failed', $event_type, 'webhook_processing_failed' );
			if ( 'exhausted' === $result ) {
				do_action( 'odph_webhook_retry_exhausted', $event_type, 'webhook_processing_failed' );
			}
			return new WP_Error( 'exhausted' === $result ? 'webhook_retry_exhausted' : 'webhook_processing_failed', 'Webhook processing failed.', array( 'status' => 'exhausted' === $result ? 503 : 500 ) );
		}
	}
}
