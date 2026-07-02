<?php
/**
 * Editable plain-text email templates with strict placeholders.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Email;

final class Templates {
	/** @var array<string, mixed> */
	private array $settings;

	/** @param null|array<string, mixed> $settings */
	public function __construct( ?array $settings = null ) {
		$this->settings = $settings ?? (array) get_option( 'odph_settings', array() );
	}

	/** @return array<string, array{label: string, subject: string, body: string, placeholders: list<string>}> */
	public static function definitions(): array {
		return array(
			'purchase_completed' => array(
				'label'        => __( 'Purchase completed email', 'od-product-hub' ),
				'subject'      => __( '[{site_name}] Thank you for your purchase', 'od-product-hub' ),
				'body'         => __( "Your subscription is now active.\nLicense key: {license_key}\nAccount: {account_url}", 'od-product-hub' ),
				'placeholders' => array( 'site_name', 'license_key', 'account_url' ),
			),
			'new_user'           => array(
				'label'        => __( 'New user password setup email', 'od-product-hub' ),
				'subject'      => __( '[{site_name}] Set your password', 'od-product-hub' ),
				'body'         => __( "Your account has been created.\nUsername: {user_login}\nSet your password at the following URL.\n{password_url}", 'od-product-hub' ),
				'placeholders' => array( 'site_name', 'user_login', 'password_url' ),
			),
			'payment_failed'     => array(
				'label'        => __( 'Payment failed email', 'od-product-hub' ),
				'subject'      => __( '[{site_name}] Please check your payment method', 'od-product-hub' ),
				'body'         => __( "We could not confirm your payment.\nOpen the Stripe Customer Portal from your account page and check your payment method.\n{account_url}", 'od-product-hub' ),
				'placeholders' => array( 'site_name', 'account_url' ),
			),
			'webhook_failed'     => array(
				'label'        => __( 'Webhook failure administrator email', 'od-product-hub' ),
				'subject'      => __( '[{site_name}] Webhook processing failed', 'od-product-hub' ),
				'body'         => __( "Webhook processing failed.\nEvent: {event_type}\nError code: {error_code}", 'od-product-hub' ),
				'placeholders' => array( 'site_name', 'event_type', 'error_code' ),
			),
		);
	}

	/** @return array<string, array{subject: string, body: string}> */
	public static function defaults(): array {
		$defaults = array();
		foreach ( self::definitions() as $type => $definition ) {
			$defaults[ $type ] = array(
				'subject' => $definition['subject'],
				'body'    => $definition['body'],
			);
		}
		return $defaults;
	}

	public static function is_valid( string $type, string $subject, string $body ): bool {
		$definitions = self::definitions();
		if ( ! isset( $definitions[ $type ] ) || '' === trim( $subject ) || '' === trim( $body ) ) {
			return false;
		}
		preg_match_all( '/\{([a-z_]+)\}/', $subject . "\n" . $body, $matches );
		foreach ( array_unique( $matches[1] ) as $placeholder ) {
			if ( ! in_array( $placeholder, $definitions[ $type ]['placeholders'], true ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param array<string, scalar|null> $values @return array{subject: string, body: string} */
	public function render( string $type, array $values ): array {
		$defaults  = self::defaults();
		$templates = is_array( $this->settings['email_templates'] ?? null ) ? $this->settings['email_templates'] : array();
		$template  = is_array( $templates[ $type ] ?? null ) ? $templates[ $type ] : array();
		$subject   = (string) ( $template['subject'] ?? $defaults[ $type ]['subject'] ?? '' );
		$body      = (string) ( $template['body'] ?? $defaults[ $type ]['body'] ?? '' );
		if ( ! self::is_valid( $type, $subject, $body ) ) {
			$subject = $defaults[ $type ]['subject'];
			$body    = $defaults[ $type ]['body'];
		}
		$replacements = array();
		foreach ( $values as $key => $value ) {
			$replacements[ '{' . $key . '}' ] = trim( wp_strip_all_tags( (string) $value ) );
		}
		return array(
			'subject' => str_replace( array( "\r", "\n" ), ' ', strtr( $subject, $replacements ) ),
			'body'    => strtr( $body, $replacements ),
		);
	}
}
