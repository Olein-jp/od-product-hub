<?php
/**
 * Privacy integration registration tests.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Tests\Unit;

use OD_Product_Hub\Privacy\PrivacyService;
use PHPUnit\Framework\TestCase;

final class PrivacyServiceTest extends TestCase {
	public function test_registers_wordpress_exporter_and_eraser_callbacks(): void {
		$service   = new PrivacyService();
		$exporters = $service->register_exporter( array() );
		$erasers   = $service->register_eraser( array() );

		$this->assertSame( 'OD Product Hub', $exporters['od-product-hub']['exporter_friendly_name'] );
		$this->assertSame( array( $service, 'export_personal_data' ), $exporters['od-product-hub']['callback'] );
		$this->assertSame( 'OD Product Hub', $erasers['od-product-hub']['eraser_friendly_name'] );
		$this->assertSame( array( $service, 'erase_personal_data' ), $erasers['od-product-hub']['callback'] );
	}

	public function test_invalid_email_finishes_without_data_or_changes(): void {
		$service = new PrivacyService();

		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			$service->export_personal_data( 'invalid', 1 )
		);
		$this->assertSame(
			array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			),
			$service->erase_personal_data( 'invalid', 1 )
		);
	}
}
