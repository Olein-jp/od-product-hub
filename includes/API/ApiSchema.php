<?php
/**
 * JSON Schema definitions for public API request arguments.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\API;

final class ApiSchema {
	/** @return array<string, array<string, mixed>> */
	public static function license_args(): array {
		return array(
			'license_key'    => array(
				'description' => 'Uppercase OD Product Hub license key.',
				'required'    => true,
				'type'        => 'string',
				'pattern'     => '^ODPH-[A-HJ-NP-Z2-9]{4}(?:-[A-HJ-NP-Z2-9]{4}){3}$',
				'minLength'   => 24,
				'maxLength'   => 24,
			),
			'product_slug'   => self::product_slug( true ),
			'site_url'       => array(
				'description'       => 'WordPress site URL using HTTP or HTTPS without embedded credentials.',
				'required'          => false,
				'type'              => 'string',
				'format'            => 'uri',
				'pattern'           => '^https?://[^\\s]+$',
				'maxLength'         => 255,
				'validate_callback' => array( self::class, 'validate_site_url' ),
				'sanitize_callback' => static fn( $value ): string => esc_url_raw( (string) $value, array( 'http', 'https' ) ),
			),
			'plugin_version' => self::version( 'Plugin version.' ),
			'wp_version'     => self::version( 'WordPress version.' ),
			'php_version'    => self::version( 'PHP version.' ),
		);
	}

	/** @return array<string, mixed> */
	public static function product_slug( bool $required = true ): array {
		return array(
			'description' => 'Public product slug.',
			'required'    => $required,
			'type'        => 'string',
			'pattern'     => '^[a-z0-9]+(?:-[a-z0-9]+)*$',
			'minLength'   => 1,
			'maxLength'   => 191,
		);
	}

	/** @return array<string, mixed> */
	private static function version( string $description ): array {
		return array(
			'description' => $description,
			'required'    => false,
			'type'        => 'string',
			'pattern'     => '^[0-9]+(?:\\.[0-9]+){1,3}(?:[-+][0-9A-Za-z.-]+)?$',
			'minLength'   => 3,
			'maxLength'   => 32,
		);
	}

	/** @param mixed $value @param mixed $request @return true|\WP_Error */
	public static function validate_site_url( $value, $request = null, string $param = 'site_url' ) {
		unset( $request );
		$schema = self::license_args()['site_url'];
		unset( $schema['validate_callback'], $schema['sanitize_callback'], $schema['required'] );
		$valid = rest_validate_value_from_schema( $value, $schema, $param );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$parts = wp_parse_url( (string) $value );
		if ( false === $parts || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new \WP_Error( 'rest_invalid_param', 'site_url must be an HTTP(S) URL without embedded credentials.' );
		}
		return true;
	}
}
