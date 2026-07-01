<?php
/**
 * Immutable paginated query definition.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Database;

final readonly class PaginatedQuery {
	/**
	 * @param list<SqlFragment> $conditions Shared COUNT and SELECT conditions.
	 */
	public function __construct(
		public string $select,
		public SqlFragment $count_from,
		public SqlFragment $select_from,
		public array $conditions,
		public SqlFragment $order_by
	) {
	}

	public function where_sql(): string {
		return $this->conditions ? ' WHERE ' . implode( ' AND ', array_map( static fn ( SqlFragment $condition ): string => $condition->sql, $this->conditions ) ) : '';
	}

	/** @return list<int|float|string|null> */
	public function condition_values(): array {
		return array_merge( ...array_map( static fn ( SqlFragment $condition ): array => $condition->values, $this->conditions ) );
	}
}
