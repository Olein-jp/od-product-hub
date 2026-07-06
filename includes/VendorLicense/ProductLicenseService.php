<?php
/**
 * Verifies this OD Product Hub installation against its vendor Hub.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\VendorLicense;

use OD_Product_Hub_Client\Client;
use OD_Product_Hub_Client\Config;
use OD_Product_Hub_Client\ContractResult;
use OD_Product_Hub_Client\WordPress\HttpTransport;
use OD_Product_Hub_Client\WordPress\OptionStateStore;
use OD_Product_Hub_Client\WordPress\Updater;

final class ProductLicenseService {
	public const KEY_OPTION   = 'odph_vendor_license_key';
	public const STATE_OPTION = 'odph_vendor_license_state';
	public const PRODUCT_SLUG = 'od-product-hub';

	public function hub_url(): string {
		$url = defined( 'ODPH_VENDOR_HUB_URL' ) ? (string) ODPH_VENDOR_HUB_URL : '';
		/** This filter is intended only for development and staging environments. */
		return untrailingslashit( (string) apply_filters( 'odph_vendor_hub_url', $url ) );
	}

	public function public_key(): string {
		return defined( 'ODPH_VENDOR_RELEASE_PUBLIC_KEY' ) ? trim( (string) ODPH_VENDOR_RELEASE_PUBLIC_KEY ) : '';
	}

	public function is_configured(): bool {
		return '' !== $this->hub_url() && ! $this->is_self_reference();
	}

	public function is_self_reference(): bool {
		$hub  = wp_parse_url( $this->hub_url() );
		$site = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $hub ) || ! is_array( $site ) || empty( $hub['host'] ) || empty( $site['host'] ) ) {
			return false;
		}
		$hub_port  = (int) ( $hub['port'] ?? ( 'https' === ( $hub['scheme'] ?? '' ) ? 443 : 80 ) );
		$site_port = (int) ( $site['port'] ?? ( 'https' === ( $site['scheme'] ?? '' ) ? 443 : 80 ) );
		return strtolower( (string) $hub['host'] ) === strtolower( (string) $site['host'] ) && $hub_port === $site_port;
	}

	public function license_key(): string {
		return (string) get_option( self::KEY_OPTION, '' );
	}

	public function activate( string $key ): ContractResult {
		$key = strtoupper( trim( $key ) );
		if ( '' === $key ) {
			return $this->configuration_result( 'license_key_required', 'A license key is required.' );
		}
		if ( ! $this->is_configured() ) {
			return $this->configuration_result();
		}
		update_option( self::KEY_OPTION, $key, false );
		return $this->client()->activate( $key );
	}

	public function verify( bool $force = true ): ContractResult {
		$key = $this->license_key();
		if ( '' === $key ) {
			return $this->configuration_result( 'not_verified', 'No product license is configured.', 'unverified' );
		}
		if ( ! $this->is_configured() ) {
			return $this->configuration_result();
		}
		return $this->client()->verify( $key, $force );
	}

	public function deactivate(): ContractResult {
		$key = $this->license_key();
		if ( '' === $key ) {
			return $this->configuration_result( 'not_verified', 'No product license is configured.', 'unverified' );
		}
		if ( ! $this->is_configured() ) {
			return $this->configuration_result();
		}
		$result = $this->client()->deactivate( $key );
		if ( 'deactivated' === $result->status ) {
			delete_option( self::KEY_OPTION );
		}
		return $result;
	}

	public function current(): ContractResult {
		$key = $this->license_key();
		if ( '' === $key ) {
			return $this->configuration_result( 'not_verified', 'No product license is configured.', 'unverified' );
		}
		return $this->is_configured() ? $this->client()->current( $key ) : $this->configuration_result();
	}

	public function run_scheduled(): void {
		if ( '' !== $this->license_key() ) {
			$this->verify( true );
		}
	}

	public function register_updater(): void {
		if ( ! $this->is_configured() || '' === $this->license_key() || '' === $this->public_key() ) {
			return;
		}
		( new Updater( $this->config( true ), $this->license_key() ) )->register();
	}

	private function client(): Client {
		return new Client( $this->config(), new HttpTransport(), new OptionStateStore( self::STATE_OPTION ) );
	}

	private function config( bool $updates = false ): Config {
		return new Config(
			$this->hub_url(),
			self::PRODUCT_SLUG,
			OD_PRODUCT_HUB_VERSION,
			home_url( '/' ),
			86400,
			259200,
			$updates ? plugin_basename( OD_PRODUCT_HUB_FILE ) : '',
			'stable',
			$updates ? $this->public_key() : ''
		);
	}

	private function configuration_result( string $code = 'vendor_hub_misconfigured', string $message = 'The vendor Hub is not configured or points to this site.', string $status = 'unavailable' ): ContractResult {
		return new ContractResult( false, $status, $code, $message, 'local', time() );
	}
}
