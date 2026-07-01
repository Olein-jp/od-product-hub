<?php

declare(strict_types=1);

namespace OD_Product_Hub_Client;

final class Config {
	public function __construct(
		public readonly string $hub_url,
		public readonly string $product_slug,
		public readonly string $plugin_version,
		public readonly string $site_url,
		public readonly int $cache_ttl = 86400,
		public readonly int $grace_ttl = 259200
	) {
		if ( '' === trim( $hub_url ) || '' === trim( $product_slug ) ) {
			throw new \InvalidArgumentException( 'Hub URL and product slug are required.' );
		}
	}
}
