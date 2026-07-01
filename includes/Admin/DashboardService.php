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
	public function __construct(
		private readonly SubscriptionRepository $subscriptions,
		private readonly WebhookLogRepository $webhooks,
		private readonly LicenseRepository $licenses,
		private readonly ApiLogRepository $api_logs
	) {}

	/** @return array<string, int> */
	public function counts(): array {
		return array(
			'active_licenses'    => $this->licenses->count_by_status( 'active' ),
			'suspended_licenses' => $this->licenses->count_by_status( 'suspended' ),
			'payment_failures'   => $this->subscriptions->count_payment_failures(),
			'new_subscriptions'  => $this->subscriptions->count_created_since( $this->month_start_utc() ),
			'webhook_errors'     => $this->webhooks->count_errors(),
		);
	}

	/** @return array{webhooks: list<object>, api: list<object>} */
	public function recent(): array {
		return array(
			'webhooks' => $this->webhooks->recent( 5 ),
			'api'      => $this->api_logs->recent( 5 ),
		);
	}

	private function month_start_utc(): string {
		$start = new \DateTimeImmutable( 'first day of this month 00:00:00', wp_timezone() );
		return $start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
