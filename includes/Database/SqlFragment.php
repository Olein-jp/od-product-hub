<?php
/**
 * Prepared SQL fragment.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Database;

final readonly class SqlFragment {
	/**
	 * @param list<int|float|string|null> $values Placeholder values.
	 */
	public function __construct(
		public string $sql,
		public array $values = array()
	) {
	}
}
