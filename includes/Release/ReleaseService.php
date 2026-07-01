<?php
/**
 * Safe release publication into private storage.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Release;

use OD_Product_Hub\Database\UtcDateTime;

final class ReleaseService {
	public function __construct(
		private ?ReleaseRepository $releases = null,
		private ?PackageSigner $signer = null
	) {
		$this->releases = $this->releases ?? new ReleaseRepository();
		$this->signer   = $this->signer ?? new PackageSigner();
	}

	/** @param array<string, string|int|null> $metadata */
	public function publish( string $source_zip, array $metadata, string $private_key ): int {
		if ( ! is_file( $source_zip ) || 'zip' !== strtolower( pathinfo( $source_zip, PATHINFO_EXTENSION ) ) ) {
			throw new \InvalidArgumentException( 'A readable ZIP package is required.' );
		}
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \RuntimeException( 'The Zip extension is required to publish releases.' );
		}
		$archive = new \ZipArchive();
		if ( true !== $archive->open( $source_zip ) ) {
			throw new \InvalidArgumentException( 'The release ZIP is invalid.' );
		}
		if ( false === $archive->locateName( (string) ( $metadata['plugin_file'] ?? '' ) ) ) {
			$archive->close();
			throw new \InvalidArgumentException( 'The ZIP does not contain plugin_file.' );
		}
		$archive->close();
		$storage = defined( 'ODPH_RELEASE_STORAGE_PATH' ) ? (string) ODPH_RELEASE_STORAGE_PATH : WP_CONTENT_DIR . '/odph-private-releases';
		if ( ! wp_mkdir_p( $storage ) ) {
			throw new \RuntimeException( 'Unable to create private release storage.' );
		}
		file_put_contents( $storage . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Private release storage is intentionally outside the media library.
		file_put_contents( $storage . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Defense in depth for Apache deployments.
		$name        = sanitize_file_name( (string) $metadata['plugin_file'] . '-' . (string) $metadata['version'] . '.zip' );
		$destination = trailingslashit( $storage ) . wp_generate_uuid4() . '-' . $name;
		if ( ! copy( $source_zip, $destination ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copying into explicitly configured private storage.
			throw new \RuntimeException( 'Unable to store release package.' );
		}
		$signed = $this->signer->sign( $destination, $private_key );
		return $this->releases->create(
			array_merge(
				$metadata,
				$signed,
				array(
					'package_path' => $destination,
					'status'       => 'published',
					'published_at' => UtcDateTime::now(),
				)
			)
		);
	}
}
