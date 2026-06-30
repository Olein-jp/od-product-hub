<?php
/**
 * Default synchronous notifications behind replaceable action boundaries.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Webhook;

use OD_Product_Hub\Email\Mailer;

final class WebhookNotificationSubscriber {
	private Mailer $mailer;

	public function __construct( ?Mailer $mailer = null ) {
		$this->mailer = $mailer ?? new Mailer();
	}

	public function register(): void {
		add_action( 'odph_webhook_purchase_completed', array( $this, 'purchase_completed' ), 10, 4 );
		add_action( 'odph_webhook_payment_failed', array( $this, 'payment_failed' ) );
		add_action( 'odph_webhook_processing_failed', array( $this, 'processing_failed' ), 10, 2 );
	}

	public function purchase_completed( string $email, string $key, bool $created, int $user_id ): void {
		if ( $created ) {
			$this->mailer->new_user( $user_id );
		}
		$this->mailer->purchase_completed( $email, $key );
	}

	public function payment_failed( object $customer ): void {
		$this->mailer->payment_failed( (string) $customer->email );
	}

	public function processing_failed( string $event_type, string $error_code ): void {
		$this->mailer->webhook_failed( $event_type, $error_code );
	}
}
