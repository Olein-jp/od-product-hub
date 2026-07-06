<?php
/**
 * Product vendor-license integration checks.
 *
 * @package OD_Product_Hub
 */

use OD_Product_Hub\VendorLicense\ProductLicenseService;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This integration check must run via WP-CLI.' );
}

/** @param mixed $actual */
function odph_vendor_assert( bool $condition, string $message, $actual = null ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message . ( null === $actual ? '' : ': ' . wp_json_encode( $actual ) ) );
	}
}

delete_option( ProductLicenseService::KEY_OPTION );
delete_option( ProductLicenseService::STATE_OPTION );
$GLOBALS['odph_vendor_requests']  = array();
$GLOBALS['odph_vendor_response']  = array(
	'success' => true,
	'status'  => 'active',
	'message' => 'License is active.',
);
$GLOBALS['odph_vendor_transport'] = true;

$hub_filter = static fn(): string => 'https://vendor.example.test';
add_filter( 'odph_vendor_hub_url', $hub_filter );
add_filter(
	'pre_http_request',
	static function ( $preempt, array $args, string $url ) {
		if ( ! str_starts_with( $url, 'https://vendor.example.test/' ) ) {
			return $preempt;
		}
		$GLOBALS['odph_vendor_requests'][] = array(
			'url'  => $url,
			'body' => json_decode( (string) $args['body'], true ),
		);
		if ( ! $GLOBALS['odph_vendor_transport'] ) {
			return new WP_Error( 'transport_error', 'Vendor Hub unavailable.' );
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $GLOBALS['odph_vendor_response'] ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

$service   = new ProductLicenseService();
$activated = $service->activate( ' abcd-efgh-jklm-npqr ' );
odph_vendor_assert( $activated->is_active(), 'Activation must accept a valid vendor key' );
odph_vendor_assert( 'ABCD-EFGH-JKLM-NPQR' === get_option( ProductLicenseService::KEY_OPTION ), 'The stored key must be normalized' );
odph_vendor_assert( 'ABCD-EFGH-JKLM-NPQR' === $GLOBALS['odph_vendor_requests'][0]['body']['license_key'], 'Activation must send the normalized key to the vendor Hub' );
odph_vendor_assert( 'od-product-hub' === $GLOBALS['odph_vendor_requests'][0]['body']['product_slug'], 'The vendor product slug must be fixed' );
$serialized_state = (string) wp_json_encode( get_option( ProductLicenseService::STATE_OPTION ) );
odph_vendor_assert( ! str_contains( $serialized_state, 'ABCD-EFGH-JKLM-NPQR' ), 'Cached state must not contain the plaintext key', $serialized_state );

$GLOBALS['odph_vendor_response'] = array(
	'success'    => false,
	'status'     => 'inactive',
	'error_code' => 'payment_failed',
	'message'    => 'Payment failed.',
);
$payment_failed                  = $service->verify( true );
odph_vendor_assert( 'inactive' === $payment_failed->status && 'payment_failed' === $payment_failed->error_code, 'Payment failure must remain distinguishable' );

$GLOBALS['odph_vendor_response'] = array(
	'success' => true,
	'status'  => 'active',
	'message' => 'License is active.',
);
$service->activate( 'MYAPP-ABCD-EFGH-JKLM-NPQR' );
$GLOBALS['odph_vendor_transport'] = false;
$grace                            = $service->verify( true );
odph_vendor_assert( $grace->is_grace_period() && $grace->is_active(), 'A transport failure must use the SDK grace period after an active verification' );

$GLOBALS['odph_vendor_transport'] = true;
$GLOBALS['odph_vendor_response']  = array(
	'success' => true,
	'status'  => 'active',
	'message' => 'Deactivated.',
);
$deactivated                      = $service->deactivate();
odph_vendor_assert( 'deactivated' === $deactivated->status, 'Deactivation must use the SDK deactivate operation' );
odph_vendor_assert( '' === get_option( ProductLicenseService::KEY_OPTION, '' ) && false === get_option( ProductLicenseService::STATE_OPTION, false ), 'Successful deactivation must remove local key and cached state' );

if ( ! defined( 'ODPH_VENDOR_RELEASE_PUBLIC_KEY' ) ) {
	define( 'ODPH_VENDOR_RELEASE_PUBLIC_KEY', base64_encode( str_repeat( 'k', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixture for the pinned binary public key.
}
update_option( ProductLicenseService::KEY_OPTION, 'ABCD-EFGH-JKLM-NPQR', false );
$service->register_updater();
odph_vendor_assert( has_filter( 'pre_set_site_transient_update_plugins' ), 'A configured license and pinned public key must register the WordPress updater' );
odph_vendor_assert( has_filter( 'upgrader_pre_download' ), 'The updater must register pre-download integrity verification' );

$request_count  = count( $GLOBALS['odph_vendor_requests'] );
$self_filter    = static fn(): string => home_url( '/' );
add_filter( 'odph_vendor_hub_url', $self_filter, 999 );
$self_reference = ( new ProductLicenseService() )->verify( true );
odph_vendor_assert( 'vendor_hub_misconfigured' === $self_reference->error_code, 'A self-referencing vendor Hub must be rejected as configuration error' );
odph_vendor_assert( count( $GLOBALS['odph_vendor_requests'] ) === $request_count, 'Self-reference rejection must happen before an HTTP request' );
remove_filter( 'odph_vendor_hub_url', $self_filter, 999 );

odph_vendor_assert( false !== wp_next_scheduled( 'odph_verify_vendor_license' ), 'Daily vendor license verification must be scheduled' );

\OD_Product_Hub\Database\Installer::deactivate();
odph_vendor_assert( false === wp_next_scheduled( 'odph_verify_vendor_license' ), 'Plugin deactivation must clear vendor license verification Cron' );
\OD_Product_Hub\Database\Installer::activate();

delete_option( ProductLicenseService::KEY_OPTION );
delete_option( ProductLicenseService::STATE_OPTION );
WP_CLI::success( 'Vendor license activation, status, grace, deactivation, self-reference, secrecy, and Cron checks passed.' );
