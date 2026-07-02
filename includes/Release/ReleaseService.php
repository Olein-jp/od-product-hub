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
		private ?PackageSigner $signer = null,
		private ?DownloadRepository $downloads = null
	) {
		$this->releases  = $this->releases ?? new ReleaseRepository();
		$this->signer    = $this->signer ?? new PackageSigner();
		$this->downloads = $this->downloads ?? new DownloadRepository();
	}

	/** @param array<string, string|int|null> $metadata */
	public function publish( string $source_zip, array $metadata, string $private_key ): int {
		$metadata = $this->validate_metadata( $metadata );
		if ( $this->releases->find_by_identity( (int) $metadata['product_id'], (string) $metadata['version'], (string) $metadata['channel'] ) ) {
			throw new \DomainException( 'This product version and channel already exists.' );
		}
		$previous = $this->releases->most_recent_for_product( (int) $metadata['product_id'], (string) $metadata['channel'] );
		if ( $previous && ! version_compare( (string) $metadata['version'], (string) $previous->version, '>' ) ) {
			throw new \DomainException( 'Release version must be newer than the previously recorded version in this channel.' );
		}
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
		try {
			$this->validate_archive( $archive, (string) $metadata['plugin_file'] );
		} finally {
			$archive->close();
		}
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
		try {
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
		} catch ( \Throwable $error ) {
			if ( is_file( $destination ) ) {
				unlink( $destination ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Roll back an unpublished private package after signing or persistence failure.
			}
			throw $error;
		}
	}

	public function withdraw( int $release_id ): int {
		$release = $this->releases->find( $release_id );
		if ( ! $release ) {
			throw new \DomainException( 'Release not found.' );
		}
		if ( ! in_array( (string) $release->status, array( 'published', 'withdrawn' ), true ) ) {
			throw new \DomainException( 'Only a published or withdrawn release can be withdrawn.' );
		}
		if ( 'published' === (string) $release->status && ! $this->releases->withdraw( $release_id ) ) {
			throw new \RuntimeException( 'Unable to withdraw release.' );
		}
		return $this->downloads->reject_issued_for_release( $release_id );
	}

	/** @param array<string, string|int|null> $metadata @return array<string, string|int|null> */
	private function validate_metadata( array $metadata ): array {
		$product_id   = absint( $metadata['product_id'] ?? 0 );
		$version      = trim( (string) ( $metadata['version'] ?? '' ) );
		$channel      = (string) ( $metadata['channel'] ?? 'stable' );
		$plugin_file  = trim( (string) ( $metadata['plugin_file'] ?? '' ), '/' );
		$requires_wp  = trim( (string) ( $metadata['requires_wp'] ?? '' ) );
		$requires_php = trim( (string) ( $metadata['requires_php'] ?? '' ) );
		$semver       = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/';
		$wp_version   = '/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/';
		if ( 0 === $product_id || ! preg_match( $semver, $version ) ) {
			throw new \InvalidArgumentException( 'A product and valid semantic version are required.' );
		}
		if ( ! in_array( $channel, array( 'stable', 'beta' ), true ) ) {
			throw new \InvalidArgumentException( 'Release channel must be stable or beta.' );
		}
		if ( ! preg_match( '#^[a-z0-9][a-z0-9._-]*/[A-Za-z0-9._-]+\.php$#', $plugin_file ) || str_contains( $plugin_file, '..' ) ) {
			throw new \InvalidArgumentException( 'plugin_file must be a safe plugin-directory/file.php path.' );
		}
		if ( '' !== $requires_wp && ! preg_match( $wp_version, $requires_wp ) ) {
			throw new \InvalidArgumentException( 'requires_wp must be a version number.' );
		}
		if ( '' !== $requires_php && ! preg_match( $wp_version, $requires_php ) ) {
			throw new \InvalidArgumentException( 'requires_php must be a version number.' );
		}
		$metadata['product_id']    = $product_id;
		$metadata['version']       = $version;
		$metadata['channel']       = $channel;
		$metadata['plugin_file']   = $plugin_file;
		$metadata['requires_wp']   = '' === $requires_wp ? null : $requires_wp;
		$metadata['requires_php']  = '' === $requires_php ? null : $requires_php;
		$metadata['release_notes'] = sanitize_textarea_field( (string) ( $metadata['release_notes'] ?? '' ) );
		return $metadata;
	}

	private function validate_archive( \ZipArchive $archive, string $plugin_file ): void {
		if ( false === $archive->locateName( $plugin_file ) ) {
			throw new \InvalidArgumentException( 'The ZIP does not contain plugin_file.' );
		}
		for ( $index = 0; $index < $archive->numFiles; $index++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive property.
			$name = (string) $archive->getNameIndex( $index );
			if ( '' === $name || str_starts_with( $name, '/' ) || str_contains( $name, '../' ) || str_contains( $name, "\0" ) ) {
				throw new \InvalidArgumentException( 'The ZIP contains an unsafe path.' );
			}
		}
	}
}
