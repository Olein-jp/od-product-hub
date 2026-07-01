<?php

declare(strict_types=1);

namespace OD_Product_Hub_Client;

interface Transport {
	/** @param array<string, scalar> $payload
	 *  @return array<string, mixed>
	 *  @throws TransportException
	 */
	public function post( string $url, array $payload ): array;
}
