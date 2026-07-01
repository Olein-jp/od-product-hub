<?php

declare(strict_types=1);

namespace OD_Product_Hub_Client;

final class Client {
	public function __construct(
		private readonly Config $config,
		private readonly Transport $transport,
		private readonly StateStore $store,
		private readonly Clock $clock = new SystemClock()
	) {}

	public function activate( string $license_key ): ContractResult {
		return $this->request( 'activate', $license_key );
	}

	public function verify( string $license_key, bool $force = false ): ContractResult {
		$cached = $this->stored_result( $license_key );
		if ( ! $force && null !== $cached && ( $this->clock->now() - $cached->checked_at ) < $this->config->cache_ttl ) {
			return $this->with_source( $cached, 'cache' );
		}
		return $this->request( 'verify', $license_key );
	}

	public function deactivate( string $license_key ): ContractResult {
		try {
			$this->transport->post( $this->endpoint( 'deactivate' ), $this->payload( $license_key ) );
			$this->store->delete();
			return new ContractResult( false, 'deactivated', null, 'Site deactivation was recorded.', 'remote', $this->clock->now() );
		} catch ( TransportException $exception ) {
			return new ContractResult( false, 'unavailable', 'transport_error', $exception->getMessage(), 'error', $this->clock->now() );
		}
	}

	public function current( string $license_key = '' ): ContractResult {
		return $this->stored_result( $license_key ) ?? new ContractResult( false, 'unverified', 'not_verified', 'The contract has not been verified.', 'local', 0 );
	}

	private function request( string $action, string $license_key ): ContractResult {
		try {
			$response = $this->transport->post( $this->endpoint( $action ), $this->payload( $license_key ) );
			$result   = $this->parse_response( $response );
			$this->store->set( array_merge( $result->to_array(), array( 'license_fingerprint' => $this->fingerprint( $license_key ) ) ) );
			return $result;
		} catch ( TransportException $exception ) {
			return $this->transport_failure( $exception, $license_key );
		}
	}

	/** @param array<string, mixed> $response */
	private function parse_response( array $response ): ContractResult {
		if ( ! isset( $response['success'], $response['status'] ) || ! is_bool( $response['success'] ) ) {
			throw new TransportException( 'Hub returned an invalid response.' );
		}
		return new ContractResult(
			true === $response['success'] && 'active' === $response['status'],
			(string) $response['status'],
			isset( $response['error_code'] ) ? (string) $response['error_code'] : null,
			(string) ( $response['message'] ?? '' ),
			'remote',
			$this->clock->now(),
			$response
		);
	}

	private function transport_failure( TransportException $exception, string $license_key ): ContractResult {
		$cached = $this->stored_result( $license_key );
		if ( null !== $cached && $cached->active && ( $this->clock->now() - $cached->checked_at ) < $this->config->grace_ttl ) {
			return new ContractResult( true, 'grace', 'transport_error', 'Hub is unavailable; the last active verification is temporarily accepted.', 'grace', $cached->checked_at, $cached->data );
		}
		return new ContractResult( false, 'unavailable', 'transport_error', $exception->getMessage(), 'error', $this->clock->now() );
	}

	private function stored_result( string $license_key = '' ): ?ContractResult {
		$state = $this->store->get();
		if ( null !== $state && '' !== $license_key && ! hash_equals( (string) ( $state['license_fingerprint'] ?? '' ), $this->fingerprint( $license_key ) ) ) {
			return null;
		}
		return null === $state ? null : ContractResult::from_array( $state );
	}

	private function fingerprint( string $license_key ): string {
		return hash( 'sha256', strtoupper( trim( $license_key ) ) );
	}

	private function with_source( ContractResult $result, string $source ): ContractResult {
		return new ContractResult( $result->active, $result->status, $result->error_code, $result->message, $source, $result->checked_at, $result->data );
	}

	private function endpoint( string $action ): string {
		return rtrim( $this->config->hub_url, '/' ) . '/wp-json/od-product-hub/v1/' . $action;
	}

	/** @return array<string, scalar> */
	private function payload( string $license_key ): array {
		return array(
			'license_key'    => strtoupper( trim( $license_key ) ),
			'product_slug'   => $this->config->product_slug,
			'site_url'       => $this->config->site_url,
			'plugin_version' => $this->config->plugin_version,
			'wp_version'     => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '',
			'php_version'    => PHP_VERSION,
		);
	}
}
