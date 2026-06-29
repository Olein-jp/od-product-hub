<?php
/**
 * Shared repository CRUD and pagination.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Database;

abstract class AbstractRepository {
	abstract protected function table_suffix(): string;

	/** @return list<string> */
	abstract protected function writable_columns(): array;

	/** @return list<string> */
	protected function filterable_columns(): array {
		return $this->writable_columns();
	}

	protected function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'odph_' . $this->table_suffix();
	}

	public function find( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table(), $id ) );
		$this->assert_read( 'find' );
		return is_object( $row ) ? $row : null;
	}

	/** @param array<string, mixed> $data */
	public function create( array $data ): int {
		global $wpdb;
		$data = $this->normalize_data( $data );
		$now  = UtcDateTime::now();
		if ( in_array( 'created_at', $this->writable_columns(), true ) && ! isset( $data['created_at'] ) ) {
			$data['created_at'] = $now;
		}
		if ( in_array( 'updated_at', $this->writable_columns(), true ) && ! isset( $data['updated_at'] ) ) {
			$data['updated_at'] = $now;
		}
		if ( false === $wpdb->insert( $this->table(), $data ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The operation label is internal and only written to the server log.
			throw DatabaseException::from_last_error( 'create ' . $this->table_suffix() );
		}
		return (int) $wpdb->insert_id;
	}

	/** @param array<string, mixed> $data */
	public function update( int $id, array $data ): bool {
		global $wpdb;
		$data = $this->normalize_data( $data );
		if ( in_array( 'updated_at', $this->writable_columns(), true ) && ! isset( $data['updated_at'] ) ) {
			$data['updated_at'] = UtcDateTime::now();
		}
		$result = $wpdb->update( $this->table(), $data, array( 'id' => $id ) );
		if ( false === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The operation label is internal and only written to the server log.
			throw DatabaseException::from_last_error( 'update ' . $this->table_suffix() );
		}
		return 0 < $result;
	}

	public function delete( int $id ): bool {
		global $wpdb;
		$result = $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
		if ( false === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The operation label is internal and only written to the server log.
			throw DatabaseException::from_last_error( 'delete ' . $this->table_suffix() );
		}
		return 0 < $result;
	}

	/**
	 * @param array<string, scalar|null> $filters Exact-match filters.
	 */
	public function search( array $filters = array(), int $page = 1, int $per_page = 20, string $order_by = 'id', string $order = 'DESC' ): RepositoryPage {
		global $wpdb;
		$page       = max( 1, $page );
		$per_page   = max( 1, min( 100, $per_page ) );
		$order_by   = in_array( $order_by, array_merge( array( 'id' ), $this->writable_columns() ), true ) ? $order_by : 'id';
		$order      = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
		$conditions = array();
		$values     = array( $this->table() );
		foreach ( $filters as $column => $value ) {
			if ( ! in_array( $column, $this->filterable_columns(), true ) ) {
				continue;
			}
			$conditions[] = null === $value ? '%i IS NULL' : '%i = %s';
			$values[]     = $column;
			if ( null !== $value ) {
				$values[] = (string) $value;
			}
		}
		$where     = $conditions ? ' WHERE ' . implode( ' AND ', $conditions ) : '';
		$count_sql = 'SELECT COUNT(*) FROM %i' . $where;
		$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL fragments are generated only from repository allowlists.
		$this->assert_read( 'count ' . $this->table_suffix() );
		$rows_sql = 'SELECT * FROM %i' . $where . ' ORDER BY %i ' . $order . ' LIMIT %d OFFSET %d';
		$rows     = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...array_merge( $values, array( $order_by, $per_page, ( $page - 1 ) * $per_page ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL fragments are generated only from repository allowlists.
		$this->assert_read( 'search ' . $this->table_suffix() );
		$items = is_array( $rows ) ? array_values( array_filter( $rows, 'is_object' ) ) : array();
		return new RepositoryPage( $items, $total, $page, $per_page, (int) ceil( $total / $per_page ) );
	}

	/** @param array<string, mixed> $data @return array<string, mixed> */
	private function normalize_data( array $data ): array {
		return array_intersect_key( $data, array_flip( $this->writable_columns() ) );
	}

	protected function assert_read( string $operation ): void {
		global $wpdb;
		if ( '' !== (string) $wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The operation label is internal and only written to the server log.
			throw DatabaseException::from_last_error( $operation );
		}
	}
}
