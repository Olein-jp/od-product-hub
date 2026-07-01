<?php

declare(strict_types=1);

namespace OD_Product_Hub_Client\WordPress;

use OD_Product_Hub_Client\Config;

/** Connects signed OD Product Hub releases to WordPress's standard plugin updater. */
final class Updater {
	/** @var array<string, mixed>|null */
	private ?array $release = null;

	public function __construct(
		private readonly Config $config,
		private readonly string $license_key,
		private readonly int $timeout = 15
	) {}

	public function register(): void {
		if ( '' === $this->config->plugin_file ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'verified_download' ), 10, 4 );
	}

	/** @param mixed $transient @return mixed */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$release = $this->fetch_release();
		if ( null === $release || empty( $release['update_available'] ) || empty( $release['release'] ) || ! is_array( $release['release'] ) ) {
			return $transient;
		}
		$item              = (object) $this->update_item( $release['release'] );
		$transient->response[ $this->config->plugin_file ] = $item;
		return $transient;
	}

	/** @param mixed $result @param mixed $action @param mixed $args @return mixed */
	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || $this->config->product_slug !== (string) ( $args->slug ?? '' ) ) {
			return $result;
		}
		$response = $this->fetch_release();
		$release  = is_array( $response['release'] ?? null ) ? $response['release'] : null;
		if ( null === $release ) {
			return $result;
		}
		return (object) array_merge(
			$this->update_item( $release ),
			array(
				'name'     => $this->config->product_slug,
				'sections' => array( 'changelog' => (string) ( $release['release_notes'] ?? '' ) ),
			)
		);
	}

	/** @param mixed $reply @param mixed $package @param mixed $upgrader @param mixed $hook_extra @return mixed */
	public function verified_download( $reply, $package, $upgrader, $hook_extra ) {
		unset( $upgrader, $hook_extra );
		$release = $this->release;
		if ( ! is_string( $package ) || null === $release || $package !== (string) ( $release['download_url'] ?? '' ) ) {
			return $reply;
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( $package, $this->timeout );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! $this->verify_file( $file, $release ) ) {
			wp_delete_file( $file );
			return new \WP_Error( 'odph_update_integrity', 'Downloaded update failed signature or checksum verification.' );
		}
		return $file;
	}

	/** @return array<string, mixed>|null */
	private function fetch_release(): ?array {
		$response = wp_safe_remote_post(
			rtrim( $this->config->hub_url, '/' ) . '/wp-json/od-product-hub/v1/updates/check',
			array(
				'timeout' => $this->timeout,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'license_key'    => strtoupper( trim( $this->license_key ) ),
						'product_slug'   => $this->config->product_slug,
						'site_url'       => $this->config->site_url,
						'plugin_version' => $this->config->plugin_version,
						'wp_version'     => (string) get_bloginfo( 'version' ),
						'php_version'    => PHP_VERSION,
						'channel'        => $this->config->channel,
					)
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || true !== ( $data['success'] ?? false ) ) {
			return null;
		}
		$this->release = is_array( $data['release'] ?? null ) ? $data['release'] : null;
		return $data;
	}

	/** @param array<string, mixed> $release @return array<string, mixed> */
	private function update_item( array $release ): array {
		return array(
			'id'           => $this->config->product_slug,
			'slug'         => $this->config->product_slug,
			'plugin'       => $this->config->plugin_file,
			'new_version'  => (string) ( $release['version'] ?? '' ),
			'version'      => (string) ( $release['version'] ?? '' ),
			'package'      => (string) ( $release['download_url'] ?? '' ),
			'requires'     => (string) ( $release['requires_wp'] ?? '' ),
			'requires_php' => (string) ( $release['requires_php'] ?? '' ),
		);
	}

	/** @param array<string, mixed> $release */
	private function verify_file( string $file, array $release ): bool {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}
		$hash      = hash_file( 'sha256', $file );
		$expected  = (string) ( $release['sha256'] ?? '' );
		$signature = base64_decode( (string) ( $release['signature'] ?? '' ), true );
		$advertised = (string) ( $release['public_key'] ?? '' );
		if ( ! hash_equals( $this->config->release_public_key, $advertised ) ) {
			return false;
		}
		$public    = base64_decode( $this->config->release_public_key, true );
		return is_string( $hash ) && hash_equals( $expected, $hash ) && is_string( $signature ) && is_string( $public )
			&& SODIUM_CRYPTO_SIGN_BYTES === strlen( $signature ) && SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $public )
			&& sodium_crypto_sign_verify_detached( $signature, $hash, $public );
	}
}
