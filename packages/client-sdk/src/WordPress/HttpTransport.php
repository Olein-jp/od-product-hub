<?php

declare(strict_types=1);

namespace OD_Product_Hub_Client\WordPress;

use OD_Product_Hub_Client\Transport;
use OD_Product_Hub_Client\TransportException;

final class HttpTransport implements Transport {
	public function __construct( private readonly int $timeout = 10 ) {}

	public function post( string $url, array $payload ): array {
		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout' => $this->timeout,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new TransportException( 'Hub connection failed: ' . $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered; consumers escape it for their output context.
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			throw new TransportException( 'Hub returned HTTP ' . $code . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Integer status is exception context, not HTML output.
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			throw new TransportException( 'Hub returned invalid JSON.' );
		}
		return $data;
	}
}
