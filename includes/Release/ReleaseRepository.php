<?php
/**
 * Release persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Release;

use OD_Product_Hub\Database\AbstractRepository;

final class ReleaseRepository extends AbstractRepository {
	protected function table_suffix(): string {
		return 'releases';
	}

	protected function writable_columns(): array {
		return array( 'product_id', 'version', 'channel', 'plugin_file', 'package_path', 'sha256', 'signature', 'public_key', 'release_notes', 'requires_wp', 'requires_php', 'status', 'published_at', 'created_at', 'updated_at' );
	}

	public function latest_for_product( int $product_id, string $channel = 'stable' ): ?object {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE product_id = %d AND channel = %s AND status = 'published' ORDER BY published_at DESC, id DESC LIMIT 1",
				$this->table(),
				$product_id,
				$channel
			)
		);
		$this->assert_read( 'find latest release' );
		return is_object( $row ) ? $row : null;
	}
}
