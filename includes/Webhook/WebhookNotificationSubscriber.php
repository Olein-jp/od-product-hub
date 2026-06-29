<?php
/**
 * Default synchronous notifications behind replaceable action boundaries.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Webhook;

final class WebhookNotificationSubscriber {
	public function register(): void {
		add_action( 'odph_webhook_purchase_completed', array( $this, 'purchase_completed' ), 10, 4 );
		add_action( 'odph_webhook_payment_failed', array( $this, 'payment_failed' ) );
		add_action( 'odph_webhook_processing_failed', array( $this, 'processing_failed' ), 10, 2 );
	}

	public function purchase_completed( string $email, string $key, bool $created, int $user_id ): void {
		if ( $created ) {
			wp_new_user_notification( $user_id, null, 'user' );
		}
		wp_mail( $email, 'ご購入ありがとうございます', "契約手続きが完了しました。\nライセンスキー: {$key}\nマイページからも確認できます。" );
	}

	public function payment_failed( object $customer ): void {
		wp_mail( (string) $customer->email, 'お支払いを確認できませんでした', 'Stripe Customer Portal でお支払い方法をご確認ください。' );
	}

	public function processing_failed( string $event_type, string $error_code ): void {
		wp_mail( get_option( 'admin_email' ), '[OD Product Hub] Webhook error', $event_type . ': ' . $error_code );
	}
}
