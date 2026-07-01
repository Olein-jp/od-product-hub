<?php
/**
 * Verifies that the sample plugin resolves the client SDK through Composer.
 *
 * @package OD_Product_Hub
 */

$autoload = dirname( __DIR__ ) . '/examples/sample-plugin/vendor/autoload.php';
if ( ! is_file( $autoload ) ) {
	fwrite( STDERR, "Sample plugin autoload file is missing.\n" );
	exit( 1 );
}
require $autoload;
if ( ! class_exists( 'OD_Product_Hub_Client\\Client' ) || ! class_exists( 'OD_Product_Hub_Client\\WordPress\\Updater' ) ) {
	fwrite( STDERR, "Client SDK classes could not be autoloaded.\n" );
	exit( 1 );
}
fwrite( STDOUT, "Sample plugin Composer autoload passed.\n" );
