<?php
/**
 * Administration action handler unit tests.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Tests\Unit;

use OD_Product_Hub\Admin\AdminActionHandler;
use OD_Product_Hub\License\LicenseManager;
use OD_Product_Hub\Log\AdminLogRepository;
use OD_Product_Hub\Log\LogCleanupService;
use OD_Product_Hub\Product\ProductRepository;
use PHPUnit\Framework\TestCase;

final class AdminActionHandlerTest extends TestCase {
	public function test_product_input_is_sanitized_and_status_is_allowlisted(): void {
		$data = $this->handler()->normalize_product_input(
			array(
				'name'                => '  <b>Product</b>  ',
				'slug'                => 'Example_Plugin!',
				'stripe_product_id'   => 'prod_ABC123',
				'stripe_price_id'     => 'price_XYZ789',
				'description'         => '<b>Description</b>',
				'price_description'   => 'Monthly',
				'billing_description' => 'Recurring',
				'status'              => 'deleted',
			)
		);

		self::assertNotNull( $data );
		self::assertSame( 'Product', $data['name'] );
		self::assertSame( 'example_plugin', $data['slug'] );
		self::assertSame( 'Description', $data['description'] );
		self::assertSame( 'active', $data['status'] );
	}

	public function test_product_input_rejects_invalid_stripe_identifiers(): void {
		$data = $this->handler()->normalize_product_input(
			array(
				'name'              => 'Product',
				'slug'              => 'example-plugin',
				'stripe_product_id' => 'invalid',
				'stripe_price_id'   => 'price_XYZ789',
			)
		);

		self::assertNull( $data );
	}

	private function handler(): AdminActionHandler {
		return new AdminActionHandler( new ProductRepository(), new AdminLogRepository(), new LicenseManager(), new LogCleanupService(), static fn(): bool => true );
	}
}
