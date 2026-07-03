<?php
/**
 * Published release package integrity validation.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Release;

final class ReleasePackageValidator {
	public const ERROR_MISSING          = 'release_package_missing';
	public const ERROR_INTEGRITY_FAILED = 'release_package_integrity_failed';

	public function __construct( private ?PackageSigner $signer = null ) {
		$this->signer = $this->signer ?? new PackageSigner();
	}

	/** @return self::ERROR_MISSING|self::ERROR_INTEGRITY_FAILED|null */
	public function validate( object $release ): ?string {
		$path = (string) ( $release->package_path ?? '' );
		if ( '' === $path || ! is_file( $path ) ) {
			return self::ERROR_MISSING;
		}
		try {
			$verified = $this->signer->verify( $path, (string) ( $release->sha256 ?? '' ), (string) ( $release->signature ?? '' ), (string) ( $release->public_key ?? '' ) );
		} catch ( \Throwable $error ) {
			unset( $error );
			$verified = false;
		}
		if ( ! $verified ) {
			return self::ERROR_INTEGRITY_FAILED;
		}
		return null;
	}
}
