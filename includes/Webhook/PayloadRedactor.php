<?php
/**
 * Redacts personal and payment data before webhook payload persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Webhook;

final class PayloadRedactor {
	private const SENSITIVE_KEYS = array(
		'address',
		'billing_details',
		'card',
		'client_secret',
		'customer_details',
		'email',
		'name',
		'payment_method',
		'payment_intent',
		'phone',
		'receipt_url',
		'routing_number',
		'shipping',
		'source',
		'bank_account',
		'account_number',
		'iban',
		'tax_id',
	);

	public function redact_json( string $payload ): string {
		$data = json_decode( $payload, true );
		if ( ! is_array( $data ) ) {
			return wp_json_encode(
				array(
					'redacted'     => true,
					'invalid_json' => true,
				)
			);
		}
		return (string) wp_json_encode( $this->redact( $data ) );
	}

	/** @param array<string|int, mixed> $data @return array<string|int, mixed> */
	private function redact( array $data ): array {
		foreach ( $data as $key => $value ) {
			$key_name = strtolower( (string) $key );
			if ( in_array( $key_name, self::SENSITIVE_KEYS, true ) || str_contains( $key_name, 'secret' ) || str_contains( $key_name, 'payment' ) ) {
				$data[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = $this->redact( $value );
			}
		}
		return $data;
	}
}
