<?php
/**
 * Signed, short-lived, one-time download grants.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Release;

use OD_Product_Hub\Database\UtcDateTime;

final class DownloadTokenService {
	public function __construct( private ?DownloadRepository $downloads = null ) {
		$this->downloads = $this->downloads ?? new DownloadRepository();
	}

	/** @return array{token: string, expires_at: int} */
	public function issue( int $release_id, int $license_id, string $site_url ): array {
		$settings = get_option( 'odph_settings', array() );
		$ttl      = max( 60, min( 900, (int) ( $settings['download_url_ttl'] ?? 300 ) ) );
		$expires  = time() + $ttl;
		$payload  = $this->encode(
			wp_json_encode(
				array(
					'r' => $release_id,
					'l' => $license_id,
					'e' => $expires,
					'n' => bin2hex( random_bytes( 16 ) ),
				)
			)
		);
		$token    = $payload . '.' . $this->encode( hash_hmac( 'sha256', $payload, $this->secret(), true ) );
		$this->downloads->create(
			array(
				'release_id' => $release_id,
				'license_id' => $license_id,
				'token_hash' => hash( 'sha256', $token ),
				'site_url'   => substr( $site_url, 0, 255 ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', $expires ),
				'result'     => 'issued',
			)
		);
		return array(
			'token'      => $token,
			'expires_at' => $expires,
		);
	}

	/** @return array{grant: object, release_id: int, license_id: int}|null */
	public function validate( string $token ): ?array {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		$expected = $this->encode( hash_hmac( 'sha256', $parts[0], $this->secret(), true ) );
		if ( ! hash_equals( $expected, $parts[1] ) ) {
			return null;
		}
		$json = $this->decode( $parts[0] );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) || empty( $data['r'] ) || empty( $data['l'] ) || (int) ( $data['e'] ?? 0 ) < time() ) {
			return null;
		}
		$grant = $this->downloads->find_by_token_hash( hash( 'sha256', $token ) );
		if ( ! $grant || null !== $grant->used_at || UtcDateTime::now() > (string) $grant->expires_at
			|| (int) $grant->release_id !== (int) $data['r'] || (int) $grant->license_id !== (int) $data['l'] ) {
			return null;
		}
		return array(
			'grant'      => $grant,
			'release_id' => (int) $data['r'],
			'license_id' => (int) $data['l'],
		);
	}

	private function secret(): string {
		return function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_SALT' ) ? (string) AUTH_SALT : '' );
	}

	private function encode( string|false $value ): string {
		return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe token encoding, not code obfuscation.
	}

	private function decode( string $value ): string|false {
		return base64_decode( strtr( $value, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- URL-safe token decoding, not code obfuscation.
	}
}
