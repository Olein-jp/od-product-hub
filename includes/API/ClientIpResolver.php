<?php
/**
 * Resolve client IP without trusting spoofable proxy headers by default.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\API;

final class ClientIpResolver {
	/** @param array<string, mixed>|null $server */
	public function resolve( ?array $server = null ): string {
		$server = $server ?? $_SERVER;
		$remote = $this->valid_ip( $server['REMOTE_ADDR'] ?? null );
		if ( null === $remote ) {
			return 'unknown';
		}
		$trusted = $this->trusted_proxies();
		if ( ! $this->is_trusted( $remote, $trusted ) ) {
			return $remote;
		}
		$forwarded = is_string( $server['HTTP_X_FORWARDED_FOR'] ?? null ) ? explode( ',', $server['HTTP_X_FORWARDED_FOR'] ) : array();
		$chain     = array();
		foreach ( $forwarded as $candidate ) {
			$ip = $this->valid_ip( trim( $candidate ) );
			if ( null === $ip ) {
				return $remote;
			}
			$chain[] = $ip;
		}
		$client = $remote;
		while ( $chain && $this->is_trusted( $client, $trusted ) ) {
			$client = (string) array_pop( $chain );
		}
		return $client;
	}

	/** @return list<string> */
	public static function normalize_trusted_proxies( string $value ): array {
		$entries = preg_split( '/[\s,]+/', trim( $value ) );
		$entries = is_array( $entries ) ? $entries : array();
		return array_values(
			array_unique(
				array_filter(
					array_map( 'trim', $entries ),
					static fn( string $entry ): bool => self::valid_network( $entry )
				)
			)
		);
	}

	/** @return list<string> */
	private function trusted_proxies(): array {
		$settings = (array) get_option( 'odph_settings', array() );
		$trusted  = self::normalize_trusted_proxies( (string) ( $settings['api_trusted_proxies'] ?? '' ) );
		$filtered = apply_filters( 'odph_trusted_proxy_cidrs', $trusted );
		return self::normalize_trusted_proxies( is_array( $filtered ) ? implode( ' ', $filtered ) : '' );
	}

	/** @param mixed $value */
	private function valid_ip( $value ): ?string {
		return is_string( $value ) && false !== filter_var( $value, FILTER_VALIDATE_IP ) ? $value : null;
	}

	/** @param list<string> $trusted */
	private function is_trusted( string $ip, array $trusted ): bool {
		foreach ( $trusted as $network ) {
			if ( self::contains( $network, $ip ) ) {
				return true;
			}
		}
		return false;
	}

	private static function valid_network( string $network ): bool {
		$parts  = explode( '/', $network, 2 );
		$packed = inet_pton( $parts[0] );
		if ( false === $packed ) {
			return false;
		}
		$maximum = 4 === strlen( $packed ) ? 32 : 128;
		$prefix  = isset( $parts[1] ) ? filter_var( $parts[1], FILTER_VALIDATE_INT ) : $maximum;
		return false !== $prefix && $prefix >= 0 && $prefix <= $maximum;
	}

	private static function contains( string $network, string $ip ): bool {
		$parts          = explode( '/', $network, 2 );
		$network_binary = inet_pton( $parts[0] );
		$ip_binary      = inet_pton( $ip );
		if ( false === $network_binary || false === $ip_binary || strlen( $network_binary ) !== strlen( $ip_binary ) ) {
			return false;
		}
		$prefix = isset( $parts[1] ) ? (int) $parts[1] : strlen( $network_binary ) * 8;
		$bytes  = intdiv( $prefix, 8 );
		$bits   = $prefix % 8;
		if ( substr( $network_binary, 0, $bytes ) !== substr( $ip_binary, 0, $bytes ) ) {
			return false;
		}
		if ( 0 === $bits ) {
			return true;
		}
		$mask = ( 0xff << ( 8 - $bits ) ) & 0xff;
		return ( ord( $network_binary[ $bytes ] ) & $mask ) === ( ord( $ip_binary[ $bytes ] ) & $mask );
	}
}
