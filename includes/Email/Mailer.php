<?php
/**
 * Sends product emails and scopes WordPress mail filters to each delivery.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Email;

use OD_Product_Hub\Log\EmailLogRepository;

final class Mailer {
	private Templates $templates;

	/** @var callable(string, string, string): bool */
	private $transport;

	/** @var callable(string, string, string): void */
	private $failure_logger;

	/**
	 * @param null|callable(string, string, string): bool $transport
	 * @param null|callable(string, string, string): void $failure_logger
	 */
	public function __construct( ?Templates $templates = null, ?callable $transport = null, ?callable $failure_logger = null ) {
		$this->templates      = $templates ?? new Templates();
		$this->transport      = $transport ?? static fn( string $to, string $subject, string $body ): bool => wp_mail( $to, $subject, $body );
		$this->failure_logger = $failure_logger ?? array( $this, 'persist_failure' );
	}

	public function purchase_completed( string $email, string $license_key ): bool {
		return $this->send_template(
			'purchase_completed',
			$email,
			array(
				'site_name'   => get_bloginfo( 'name' ),
				'license_key' => $license_key,
				'account_url' => $this->account_url(),
			)
		);
	}

	public function new_user( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			$this->log_failure( 'new_user', '', 'user_not_found' );
			return false;
		}
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			$this->log_failure( 'new_user', (string) $user->user_email, 'password_key_failed' );
			return false;
		}
		$password_url = add_query_arg(
			array(
				'action' => 'rp',
				'key'    => $key,
				'login'  => $user->user_login,
			),
			network_site_url( 'wp-login.php', 'login' )
		);
		return $this->send_template(
			'new_user',
			(string) $user->user_email,
			array(
				'site_name'    => get_bloginfo( 'name' ),
				'user_login'   => $user->user_login,
				'password_url' => $password_url,
			)
		);
	}

	public function payment_failed( string $email ): bool {
		return $this->send_template(
			'payment_failed',
			$email,
			array(
				'site_name'   => get_bloginfo( 'name' ),
				'account_url' => $this->account_url(),
			)
		);
	}

	public function webhook_failed( string $event_type, string $error_code ): bool {
		return $this->send_template(
			'webhook_failed',
			(string) get_option( 'admin_email' ),
			array(
				'site_name'  => get_bloginfo( 'name' ),
				'event_type' => $event_type,
				'error_code' => $error_code,
			)
		);
	}

	public function filter_from( string $from ): string {
		$settings = (array) get_option( 'odph_settings', array() );
		$custom   = sanitize_email( (string) ( $settings['email_from_address'] ?? '' ) );
		return is_email( $custom ) ? $custom : $from;
	}

	public function filter_from_name( string $name ): string {
		$settings = (array) get_option( 'odph_settings', array() );
		$custom   = sanitize_text_field( (string) ( $settings['email_from_name'] ?? '' ) );
		return '' !== $custom ? $custom : $name;
	}

	/** @param array<string, scalar|null> $values */
	private function send_template( string $type, string $email, array $values ): bool {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			$this->log_failure( $type, $email, 'invalid_recipient' );
			return false;
		}
		$template = $this->templates->render( $type, $values );
		add_filter( 'wp_mail_from', array( $this, 'filter_from' ) );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ) );
		$failure_logged = false;
		try {
			$sent = ( $this->transport )( $email, $template['subject'], $template['body'] );
		} catch ( \Throwable $error ) {
			unset( $error );
			$sent = false;
			$this->log_failure( $type, $email, 'transport_exception' );
			$failure_logged = true;
		} finally {
			remove_filter( 'wp_mail_from', array( $this, 'filter_from' ) );
			remove_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ) );
		}
		if ( ! $sent && ! $failure_logged ) {
			$this->log_failure( $type, $email, 'wp_mail_failed' );
		}
		return $sent;
	}

	private function account_url(): string {
		$settings = (array) get_option( 'odph_settings', array() );
		$page_id  = absint( $settings['account_page_id'] ?? 0 );
		$url      = $page_id ? get_permalink( $page_id ) : false;
		return $url ? (string) $url : home_url( '/' );
	}

	private function log_failure( string $type, string $email, string $error_code ): void {
		try {
			( $this->failure_logger )( $type, $email, $error_code );
		} catch ( \Throwable $error ) {
			unset( $error );
			error_log( 'OD Product Hub could not persist an email delivery failure.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Last-resort operational signal without personal data.
		}
	}

	public function persist_failure( string $type, string $email, string $error_code ): void {
		( new EmailLogRepository() )->create(
			array(
				'email_type'     => sanitize_key( $type ),
				'recipient_hash' => hash_hmac( 'sha256', strtolower( trim( $email ) ), wp_salt( 'auth' ) ),
				'status'         => 'failed',
				'error_code'     => sanitize_key( $error_code ),
			)
		);
	}
}
