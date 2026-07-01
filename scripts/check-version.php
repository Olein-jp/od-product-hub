<?php
/**
 * Verifies version synchronization across release metadata.
 *
 * @package OD_Product_Hub
 */

$root = dirname( __DIR__ );

/** @return array<string, mixed> */
function odph_read_json( string $path ): array {
	$data = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $data ) ) {
		throw new RuntimeException( 'Invalid JSON: ' . $path );
	}
	return $data;
}

$plugin = (string) file_get_contents( $root . '/od-product-hub.php' );
$readme = (string) file_get_contents( $root . '/readme.txt' );

if ( ! preg_match( '/^ \* Version:\s+([^\s]+)$/m', $plugin, $plugin_match ) ) {
	throw new RuntimeException( 'Plugin header version was not found.' );
}
if ( ! preg_match( "/define\( 'OD_PRODUCT_HUB_VERSION', '([^']+)' \);/", $plugin, $constant_match ) ) {
	throw new RuntimeException( 'OD_PRODUCT_HUB_VERSION was not found.' );
}
if ( ! preg_match( '/^Stable tag:\s+([^\s]+)$/m', $readme, $readme_match ) ) {
	throw new RuntimeException( 'Stable tag was not found.' );
}

$versions = array(
	'plugin_header' => $plugin_match[1],
	'constant'      => $constant_match[1],
	'package_json'  => (string) ( odph_read_json( $root . '/package.json' )['version'] ?? '' ),
	'readme'        => $readme_match[1],
);

if ( 1 !== count( array_unique( $versions ) ) || '' === $versions['plugin_header'] ) {
	throw new RuntimeException( 'Version mismatch: ' . json_encode( $versions, JSON_UNESCAPED_SLASHES ) );
}

if ( in_array( '--print', $argv, true ) ) {
	echo $versions['plugin_header'];
} else {
	echo 'Version synchronized: ' . $versions['plugin_header'] . PHP_EOL;
}
