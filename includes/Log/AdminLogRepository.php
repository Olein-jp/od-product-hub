<?php
/**
 * Admin log persistence.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Log;

use OD_Product_Hub\Database\AbstractRepository;
use OD_Product_Hub\Database\PaginatedQuery;
use OD_Product_Hub\Database\RepositoryPage;
use OD_Product_Hub\Database\SqlFragment;

final class AdminLogRepository extends AbstractRepository {
	protected function table_suffix(): string {
		return 'admin_logs';
	}

	protected function writable_columns(): array {
		return array( 'user_id', 'action', 'object_type', 'object_id', 'details', 'created_at' );
	}

	public function search_admin( string $action, string $object_type, int $user_id, int $page = 1, int $per_page = 20 ): RepositoryPage {
		global $wpdb;
		$action      = sanitize_key( $action );
		$object_type = sanitize_key( $object_type );
		$conditions  = array();
		foreach ( array(
			'action'      => $action,
			'object_type' => $object_type,
		) as $column => $value ) {
			if ( '' !== $value ) {
				$conditions[] = new SqlFragment( '%i = %s', array( $column, $value ) );
			}
		}
		if ( 0 < $user_id ) {
			$conditions[] = new SqlFragment( 'user_id = %d', array( $user_id ) );
		}
		$table = new SqlFragment( '%i', array( $this->table() ) );
		return $this->paginate( new PaginatedQuery( 'id, user_id, action, object_type, object_id, details, created_at', $table, $table, $conditions, new SqlFragment( 'id DESC' ) ), $page, $per_page, 'admin logs' );
	}
}
