<?php
/**
 * WP-CLI release operations.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\CLI;

use OD_Product_Hub\Log\AdminLogRepository;
use OD_Product_Hub\Product\ProductRepository;
use OD_Product_Hub\Release\ReleaseKeyProvider;
use OD_Product_Hub\Release\ReleaseRepository;
use OD_Product_Hub\Release\ReleaseService;

final class ReleaseCommand {
	public function __construct(
		private ?ReleaseRepository $releases = null,
		private ?ReleaseService $service = null,
		private ?ProductRepository $products = null,
		private ?AdminLogRepository $logs = null,
		private ?ReleaseKeyProvider $keys = null
	) {
		$this->releases = $this->releases ?? new ReleaseRepository();
		$this->service  = $this->service ?? new ReleaseService( $this->releases );
		$this->products = $this->products ?? new ProductRepository();
		$this->logs     = $this->logs ?? new AdminLogRepository();
		$this->keys     = $this->keys ?? new ReleaseKeyProvider();
	}

	public static function register(): void {
		\WP_CLI::add_command( 'odph release', self::class );
	}

	/**
	 * Publishes a signed release package.
	 *
	 * ## OPTIONS
	 *
	 * <zip>
	 * : Path to the release ZIP.
	 *
	 * --product=<id-or-slug>
	 * : Product ID or slug.
	 *
	 * --version=<version>
	 * : Semantic version.
	 *
	 * --plugin-file=<path>
	 * : Plugin entry path inside the ZIP.
	 *
	 * [--channel=<channel>]
	 * : stable or beta. Default: stable.
	 *
	 * [--requires-wp=<version>]
	 * [--requires-php=<version>]
	 * [--notes=<text>]
	 * [--notes-file=<path>]
	 *
	 * @subcommand publish
	 * @param list<string> $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function publish( array $args, array $assoc_args ): void {
		$product = null;
		try {
			$zip     = (string) ( $args[0] ?? '' );
			$product = $this->product( (string) ( $assoc_args['product'] ?? '' ) );
			$notes   = $this->release_notes( $assoc_args );
			$id      = $this->service->publish(
				$zip,
				array(
					'product_id'    => (int) $product->id,
					'version'       => (string) ( $assoc_args['version'] ?? '' ),
					'channel'       => (string) ( $assoc_args['channel'] ?? 'stable' ),
					'plugin_file'   => (string) ( $assoc_args['plugin-file'] ?? '' ),
					'release_notes' => $notes,
					'requires_wp'   => (string) ( $assoc_args['requires-wp'] ?? '' ),
					'requires_php'  => (string) ( $assoc_args['requires-php'] ?? '' ),
				),
				$this->keys->resolve()
			);
			$release = $this->releases->find( $id );
			$this->audit( 'release_published', $id, (int) $product->id, 'success', $release );
			\WP_CLI::success( sprintf( 'Published release #%d.', $id ) );
		} catch ( \Throwable $error ) {
			$this->audit( 'release_publish_failed', 0, (int) ( $product->id ?? 0 ), 'failure', null, $error );
			\WP_CLI::error( $error->getMessage() );
		}
	}

	/**
	 * Lists releases without exposing storage paths or signatures.
	 *
	 * [--product=<id-or-slug>]
	 * [--status=<status>]
	 * [--channel=<channel>]
	 * [--format=<format>]
	 *
	 * @subcommand list
	 * @param list<string> $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function list_( array $args, array $assoc_args ): void {
		unset( $args );
		$filters = array();
		try {
			if ( isset( $assoc_args['product'] ) ) {
				$filters['product_id'] = (int) $this->product( (string) $assoc_args['product'] )->id;
			}
			foreach ( array( 'status', 'channel' ) as $filter ) {
				if ( isset( $assoc_args[ $filter ] ) ) {
					$filters[ $filter ] = sanitize_key( (string) $assoc_args[ $filter ] );
				}
			}
			$rows = $this->releases->search( $filters, 1, 100, 'id', 'DESC' )->items;
			\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), array_map( array( $this, 'format_release' ), $rows ), $this->fields() );
			$this->audit( 'releases_listed', 0, (int) ( $filters['product_id'] ?? 0 ), 'success' );
		} catch ( \Throwable $error ) {
			$this->audit( 'releases_list_failed', 0, (int) ( $filters['product_id'] ?? 0 ), 'failure', null, $error );
			\WP_CLI::error( $error->getMessage() );
		}
	}

	/**
	 * Shows one release without exposing its storage path or signature.
	 *
	 * <id>
	 * [--format=<format>]
	 *
	 * @subcommand show
	 * @param list<string> $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function show( array $args, array $assoc_args ): void {
		$id      = absint( $args[0] ?? 0 );
		$release = null;
		try {
			$release = $this->releases->find( $id );
			if ( ! $release ) {
				throw new \DomainException( 'Release not found.' );
			}
			\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), array( $this->format_release( $release ) ), $this->fields() );
			$this->audit( 'release_viewed', (int) $release->id, (int) $release->product_id, 'success', $release );
		} catch ( \Throwable $error ) {
			$this->audit( 'release_view_failed', $id, (int) ( $release->product_id ?? 0 ), 'failure', $release, $error );
			\WP_CLI::error( $error->getMessage() );
		}
	}

	/**
	 * Withdraws a published release and revokes unused download grants.
	 *
	 * <id>
	 *
	 * @subcommand withdraw
	 * @param list<string> $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function withdraw( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$id      = absint( $args[0] ?? 0 );
		$release = $this->releases->find( $id );
		try {
			$revoked = $this->service->withdraw( $id );
			$this->audit( 'release_withdrawn', $id, (int) ( $release->product_id ?? 0 ), 'success', $release, null, $revoked );
			\WP_CLI::success( sprintf( 'Withdrawn release #%d and revoked %d unused download grant(s).', $id, $revoked ) );
		} catch ( \Throwable $error ) {
			$this->audit( 'release_withdraw_failed', $id, (int) ( $release->product_id ?? 0 ), 'failure', $release, $error );
			\WP_CLI::error( $error->getMessage() );
		}
	}

	private function product( string $value ): object {
		$product = ctype_digit( $value ) ? $this->products->find( (int) $value ) : $this->products->find_by_slug( sanitize_key( $value ) );
		if ( ! $product ) {
			throw new \DomainException( 'Product not found.' );
		}
		return $product;
	}

	/** @param array<string, mixed> $assoc_args */
	private function release_notes( array $assoc_args ): string {
		if ( isset( $assoc_args['notes'], $assoc_args['notes-file'] ) ) {
			throw new \InvalidArgumentException( 'Use either --notes or --notes-file, not both.' );
		}
		if ( ! isset( $assoc_args['notes-file'] ) ) {
			return (string) ( $assoc_args['notes'] ?? '' );
		}
		$file = (string) $assoc_args['notes-file'];
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			throw new \InvalidArgumentException( 'Release notes file is not readable.' );
		}
		$notes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Explicit local CLI input file.
		if ( false === $notes ) {
			throw new \RuntimeException( 'Unable to read release notes file.' );
		}
		return $notes;
	}

	/** @return list<string> */
	private function fields(): array {
		return array( 'id', 'product_id', 'version', 'channel', 'plugin_file', 'status', 'requires_wp', 'requires_php', 'sha256', 'key_fingerprint', 'published_at' );
	}

	/** @return array<string, scalar|null> */
	private function format_release( object $release ): array {
		$public_key = base64_decode( (string) $release->public_key, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Public-key fingerprint display.
		return array(
			'id'              => (int) $release->id,
			'product_id'      => (int) $release->product_id,
			'version'         => (string) $release->version,
			'channel'         => (string) $release->channel,
			'plugin_file'     => (string) $release->plugin_file,
			'status'          => (string) $release->status,
			'requires_wp'     => (string) $release->requires_wp,
			'requires_php'    => (string) $release->requires_php,
			'sha256'          => (string) $release->sha256,
			'key_fingerprint' => is_string( $public_key ) ? hash( 'sha256', $public_key ) : '',
			'published_at'    => (string) $release->published_at,
		);
	}

	private function audit( string $action, int $release_id, int $product_id, string $result, ?object $release = null, ?\Throwable $error = null, int $revoked = 0 ): void {
		$details = array(
			'result'     => $result,
			'product_id' => $product_id,
			'version'    => (string) ( $release->version ?? '' ),
			'channel'    => (string) ( $release->channel ?? '' ),
			'revoked'    => $revoked,
		);
		if ( $error ) {
			$details['error_type'] = get_class( $error );
		}
		$this->logs->create(
			array(
				'user_id'     => get_current_user_id(),
				'action'      => $action,
				'object_type' => 'release',
				'object_id'   => 0 === $release_id ? null : $release_id,
				'details'     => wp_json_encode( $details ),
			)
		);
	}
}
