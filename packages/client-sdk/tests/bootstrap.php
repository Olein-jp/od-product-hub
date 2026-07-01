<?php

declare(strict_types=1);

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'OD_Product_Hub_Client\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$file = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);
