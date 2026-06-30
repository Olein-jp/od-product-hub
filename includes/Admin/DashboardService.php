<?php
/**
 * Efficient dashboard summaries and recent operational activity.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Admin;

use OD_Product_Hub\License\LicenseRepository;
use OD_Product_Hub\Log\ApiLogRepository;
use OD_Product_Hub\Log\WebhookLogRepository;
use OD_Product_Hub\Subscription\SubscriptionRepository;

final class DashboardService {
	/** @return array<string, int> */
	public function counts(): array {
		$subscriptions = new SubscriptionRepository();
		$webhooks      = new WebhookLogRepository();
		$licenses      = new LicenseRepository();
		return array(
			'active_licenses'    => $licenses->count_by_status( 'active' ),
			'suspended_licenses' => $licenses->count_by_status( 'suspended' ),
			'payment_failures'   => $subscriptions->count_payment_failures(),
			'new_subscriptions'  => $subscriptions->count_created_since( $this->month_start_utc() ),
			'webhook_errors'     => $webhooks->count_errors(),
		);
	}

	/** @return array{webhooks: list<object>, api: list<object>} */
	public function recent(): array {
		return array(
			'webhooks' => ( new WebhookLogRepository() )->recent( 5 ),
			'api'      => ( new ApiLogRepository() )->recent( 5 ),
		);
	}

	private function month_start_utc(): string {
		$start = new \DateTimeImmutable( 'first day of this month 00:00:00', wp_timezone() );
		return $start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
