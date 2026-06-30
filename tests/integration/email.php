<?php
/**
 * Mailer and webhook notification integration checks for wp-env.
 *
 * @package OD_Product_Hub
 */

use OD_Product_Hub\Database\Installer;
use OD_Product_Hub\Email\Mailer;
use OD_Product_Hub\Email\Templates;
use OD_Product_Hub\Log\EmailLogRepository;
use OD_Product_Hub\Webhook\WebhookNotificationSubscriber;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration check must run via WP-CLI.' );
}

/** @param mixed $actual */
function odph_email_assert( bool $condition, string $message, $actual = null ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message . ( null === $actual ? '' : ': ' . wp_json_encode( $actual ) ) );
	}
}

update_option( 'odph_settings', array( 'delete_on_uninstall' => 1 ), false );
Installer::uninstall();
Installer::activate();
$settings                       = get_option( 'odph_settings', array() );
$settings['email_from_name']    = 'OD Product Hub Test';
$settings['email_from_address'] = 'mailer@example.test';
$settings['email_templates']    = Templates::defaults();
$settings['email_templates']['purchase_completed'] = array(
	'subject' => 'Custom {site_name} purchase',
	'body'    => 'License: {license_key} Account: {account_url}',
);
update_option( 'odph_settings', $settings, false );

$existing_user_id = wp_insert_user(
	array(
		'user_login' => 'odph-existing-mail-user',
		'user_email' => 'existing-mail@example.test',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'subscriber',
	)
);
$new_user_id      = wp_insert_user(
	array(
		'user_login' => 'odph-new-mail-user',
		'user_email' => 'new-mail@example.test',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'subscriber',
	)
);
odph_email_assert( ! is_wp_error( $existing_user_id ) && ! is_wp_error( $new_user_id ), 'Email test users must be created' );

$deliveries = array();
$transport  = static function ( string $to, string $subject, string $body ) use ( &$deliveries ): bool {
	$deliveries[] = array(
		'to'      => $to,
		'subject' => $subject,
		'body'    => $body,
	);
	return true;
};
$mailer     = new Mailer( null, $transport );
$subscriber = new WebhookNotificationSubscriber( $mailer );

$subscriber->purchase_completed( 'existing-mail@example.test', 'ODPH-ABCD-EFGH-JKLM-NPQR', false, (int) $existing_user_id );
odph_email_assert( 1 === count( $deliveries ), 'Existing users must receive only the purchase email' );
odph_email_assert( str_starts_with( $deliveries[0]['subject'], 'Custom ' ), 'Configured purchase template must be used' );

$before_new = count( $deliveries );
$subscriber->purchase_completed( 'new-mail@example.test', 'ODPH-BCDE-FGHJ-KLMN-PQRS', true, (int) $new_user_id );
$new_deliveries = array_slice( $deliveries, $before_new );
odph_email_assert( 2 === count( $new_deliveries ), 'New users must receive password setup and purchase emails' );
odph_email_assert( str_contains( $new_deliveries[0]['body'], 'action=rp' ) && str_contains( $new_deliveries[0]['body'], 'key=' ), 'New user email must use the standard password reset flow' );

$subscriber->payment_failed( (object) array( 'email' => 'existing-mail@example.test' ) );
$subscriber->processing_failed( 'invoice.paid', 'webhook_processing_failed' );
odph_email_assert( 5 === count( $deliveries ), 'Payment and administrator webhook notifications must be delivered' );
odph_email_assert( 'mailer@example.test' === $mailer->filter_from( 'wordpress@example.test' ), 'Configured sender address must be valid' );
odph_email_assert( 'OD Product Hub Test' === $mailer->filter_from_name( 'WordPress' ), 'Configured sender name must be used' );
odph_email_assert( false === has_filter( 'wp_mail_from', array( $mailer, 'filter_from' ) ), 'Sender address filter must not leak beyond one delivery' );
odph_email_assert( false === has_filter( 'wp_mail_from_name', array( $mailer, 'filter_from_name' ) ), 'Sender name filter must not leak beyond one delivery' );

$failed_mailer = new Mailer(
	null,
	static function ( string $to, string $subject, string $body ): bool {
		unset( $to, $subject, $body );
		return false;
	}
);
$failed_mailer->payment_failed( 'failed-recipient@example.test' );
$failures = ( new EmailLogRepository() )->search(
	array(
		'status'     => 'failed',
		'email_type' => 'payment_failed',
	),
	1,
	10
)->items;
odph_email_assert( 1 === count( $failures ), 'wp_mail failure must be written to the dedicated email log' );
odph_email_assert( 64 === strlen( (string) $failures[0]->recipient_hash ), 'Email log must contain only a salted recipient hash' );
odph_email_assert( ! str_contains( wp_json_encode( $failures[0] ), 'failed-recipient@example.test' ), 'Email log must not persist the recipient address' );

wp_delete_user( (int) $existing_user_id );
wp_delete_user( (int) $new_user_id );
update_option( 'odph_settings', array( 'delete_on_uninstall' => 1 ), false );
Installer::uninstall();
Installer::activate();
WP_CLI::success( 'Mailer templates, password flow, scoped filters, and failure logging passed.' );
