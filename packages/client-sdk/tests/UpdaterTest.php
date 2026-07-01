<?php

declare(strict_types=1);

namespace OD_Product_Hub_Client\Tests;

use OD_Product_Hub_Client\Config;
use OD_Product_Hub_Client\WordPress\Updater;
use PHPUnit\Framework\TestCase;

final class UpdaterTest extends TestCase {
	protected function setUp(): void {
		odph_client_test_reset_wordpress();
	}

	public function test_register_adds_update_hooks_only_when_updates_are_configured(): void {
		( new Updater( new Config( 'https://hub.example.test', 'example', '1.0.0', 'https://site.example.test' ), 'license' ) )->register();
		self::assertSame( array(), $GLOBALS['odph_client_test_filters'] );

		( new Updater( $this->config( 'pinned-key' ), 'license' ) )->register();
		self::assertSame(
			array( 'pre_set_site_transient_update_plugins', 'plugins_api', 'upgrader_pre_download' ),
			array_column( $GLOBALS['odph_client_test_filters'], 'hook' )
		);
	}

	public function test_inject_update_preserves_transient_when_no_update_is_available(): void {
		$GLOBALS['odph_client_test_responses'][] = $this->response(
			array(
				'success'          => true,
				'update_available' => false,
			)
		);
		$transient = (object) array( 'response' => array() );

		$result = ( new Updater( $this->config( 'pinned-key' ), 'license' ) )->inject_update( $transient );

		self::assertSame( $transient, $result );
		self::assertSame( array(), $transient->response );
	}

	public function test_inject_update_maps_a_signed_release_to_wordpress(): void {
		$GLOBALS['odph_client_test_responses'][] = $this->response( $this->update_response() );
		$transient = (object) array( 'response' => array() );

		$result = ( new Updater( $this->config( 'pinned-key' ), 'license' ) )->inject_update( $transient );
		$item   = $result->response['example/example.php'];

		self::assertSame( '2.0.0', $item->new_version );
		self::assertSame( 'https://hub.example.test/download/token', $item->package );
		self::assertSame( 'stable', json_decode( $GLOBALS['odph_client_test_requests'][0]['args']['body'], true )['channel'] );
	}

	public function test_verified_download_accepts_valid_signature_and_rejects_tampering(): void {
		$keypair    = sodium_crypto_sign_keypair();
		$private    = sodium_crypto_sign_secretkey( $keypair );
		$public     = sodium_crypto_sign_publickey( $keypair );
		$public_b64 = base64_encode( $public );
		$file       = tempnam( sys_get_temp_dir(), 'odph-sdk-' );
		self::assertIsString( $file );
		file_put_contents( $file, 'signed package' );
		$hash      = hash_file( 'sha256', $file );
		$signature = base64_encode( sodium_crypto_sign_detached( $hash, $private ) );
		$release   = array_merge(
			$this->update_response()['release'],
			array(
				'sha256'     => $hash,
				'signature'  => $signature,
				'public_key' => $public_b64,
			)
		);
		$GLOBALS['odph_client_test_responses'][] = $this->response( array( 'success' => true, 'update_available' => true, 'release' => $release ) );
		$GLOBALS['odph_client_test_download']    = $file;
		$updater                                 = new Updater( $this->config( $public_b64 ), 'license' );
		$updater->inject_update( (object) array( 'response' => array() ) );

		self::assertSame( $file, $updater->verified_download( false, $release['download_url'], null, null ) );
		file_put_contents( $file, 'tampered package' );
		$error = $updater->verified_download( false, $release['download_url'], null, null );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertContains( $file, $GLOBALS['odph_client_test_deleted'] );
		self::assertFileDoesNotExist( $file );
	}

	private function config( string $public_key ): Config {
		return new Config( 'https://hub.example.test', 'example', '1.0.0', 'https://site.example.test', 86400, 259200, 'example/example.php', 'stable', $public_key );
	}

	/** @param array<string, mixed> $body @return array<string, mixed> */
	private function response( array $body ): array {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $body, JSON_THROW_ON_ERROR ) );
	}

	/** @return array<string, mixed> */
	private function update_response(): array {
		return array(
			'success'          => true,
			'update_available' => true,
			'release'          => array(
				'version'       => '2.0.0',
				'download_url'  => 'https://hub.example.test/download/token',
				'requires_wp'   => '6.9',
				'requires_php'  => '8.1',
				'release_notes' => 'Changes',
			),
		);
	}
}
