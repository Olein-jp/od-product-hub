<?php
/**
 * Paginated repository result.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Database;

final class RepositoryPage {
	/**
	 * @param list<object> $items Rows for the current page.
	 */
	public function __construct(
		public readonly array $items,
		public readonly int $total,
		public readonly int $page,
		public readonly int $per_page,
		public readonly int $total_pages
	) {}
}
