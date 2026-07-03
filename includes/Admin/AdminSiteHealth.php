<?php
/**
 * Production dependency diagnostics for WordPress Site Health.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Admin;

use OD_Product_Hub\Log\WebhookLogRepository;
use OD_Product_Hub\Release\ReleasePackageValidator;
use OD_Product_Hub\Release\ReleaseRepository;

final class AdminSiteHealth {
	private const STALE_WEBHOOK_SECONDS = 300;
	private const STALE_CLEANUP_SECONDS = 172800;

	public function configuration_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! str_starts_with( (string) get_current_screen()?->id, 'toplevel_page_od-product-hub' ) ) {
			return;
		}
		$result = $this->stripe_https_status();
		if ( 'good' !== $result['status'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $result['description'] ) . '</p></div>';
		}
	}

	/** @param array<string, mixed> $tests @return array<string, mixed> */
	public function tests( array $tests ): array {
		$definitions = array(
			'stripe_https'    => array( __( 'OD Product Hub: Stripe and HTTPS', 'od-product-hub' ), 'stripe_https_status' ),
			'webhook'         => array( __( 'OD Product Hub: Webhook health', 'od-product-hub' ), 'webhook_status' ),
			'cron'            => array( __( 'OD Product Hub: Log retention', 'od-product-hub' ), 'cron_status' ),
			'update_delivery' => array( __( 'OD Product Hub: Update delivery', 'od-product-hub' ), 'update_delivery_status' ),
			'customer_pages'  => array( __( 'OD Product Hub: Checkout and account pages', 'od-product-hub' ), 'customer_pages_status' ),
		);
		foreach ( $definitions as $key => $definition ) {
			$tests['direct'][ 'odph_' . $key ] = array(
				'label' => $definition[0],
				'test'  => array( $this, $definition[1] ),
			);
		}
		return $tests;
	}

	/** @param array<string, mixed> $info @return array<string, mixed> */
	public function debug_information( array $info ): array {
		$state                  = (array) get_option( 'odph_operational_state', array() );
		$settings               = (array) get_option( 'odph_settings', array() );
		$info['od-product-hub'] = array(
			'label'  => 'OD Product Hub',
			'fields' => array(
				'environment'          => array(
					'label' => __( 'Environment', 'od-product-hub' ),
					'value' => wp_get_environment_type(),
				),
				'stripe_configured'    => array(
					'label' => __( 'Stripe configured', 'od-product-hub' ),
					'value' => $this->stripe_configured( $settings ) ? __( 'Yes', 'od-product-hub' ) : __( 'No', 'od-product-hub' ),
				),
				'stripe_last_result'   => array(
					'label' => __( 'Last Stripe test result', 'od-product-hub' ),
					'value' => sanitize_key( (string) ( $state['stripe_last_result'] ?? 'not_run' ) ),
				),
				'stripe_last_success'  => array(
					'label' => __( 'Last successful Stripe test', 'od-product-hub' ),
					'value' => $this->safe_time( $state['stripe_last_success'] ?? null ),
				),
				'cleanup_scheduled'    => array(
					'label' => __( 'Log cleanup scheduled', 'od-product-hub' ),
					'value' => false !== wp_next_scheduled( 'odph_cleanup_logs' ) ? __( 'Yes', 'od-product-hub' ) : __( 'No', 'od-product-hub' ),
				),
				'cleanup_next_run'     => array(
					'label' => __( 'Next log cleanup', 'od-product-hub' ),
					'value' => $this->scheduled_time( wp_next_scheduled( 'odph_cleanup_logs' ) ),
				),
				'cleanup_last_success' => array(
					'label' => __( 'Last successful log cleanup', 'od-product-hub' ),
					'value' => $this->safe_time( $state['cleanup_last_success'] ?? null ),
				),
			),
		);
		return $info;
	}

	/** @return array<string, mixed> */
	public function configuration_status(): array {
		return $this->stripe_https_status();
	}

	/** @return array<string, mixed> */
	public function stripe_https_status(): array {
		$settings   = (array) get_option( 'odph_settings', array() );
		$state      = (array) get_option( 'odph_operational_state', array() );
		$configured = $this->stripe_configured( $settings );
		$https      = is_ssl() || in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
		$tested     = 'success' === (string) ( $state['stripe_last_result'] ?? '' );
		if ( ! $configured || ! $https ) {
			return $this->result( 'critical', __( 'Stripe or HTTPS configuration is incomplete', 'od-product-hub' ), __( 'Configure all three Stripe keys and enable HTTPS in production.', 'od-product-hub' ), 'odph_stripe_https', 'odph-settings' );
		}
		if ( ! $tested ) {
			return $this->result( 'recommended', __( 'Run the Stripe connection test', 'od-product-hub' ), __( 'The keys are configured, but a successful manual connection test has not been recorded.', 'od-product-hub' ), 'odph_stripe_https', 'odph-settings' );
		}
		/* translators: %s: UTC date and time of the last successful Stripe connection test. */
		$description = sprintf( __( 'The last successful manual Stripe test was recorded at %s.', 'od-product-hub' ), $this->safe_time( $state['stripe_last_success'] ?? null ) );
		return $this->result( 'good', __( 'Stripe and HTTPS are ready', 'od-product-hub' ), $description, 'odph_stripe_https' );
	}

	/** @return array<string, mixed> */
	public function webhook_status(): array {
		$summary = ( new WebhookLogRepository() )->health_summary( self::STALE_WEBHOOK_SECONDS );
		if ( 0 < $summary['stale_processing'] ) {
			return $this->result( 'critical', __( 'Webhook processing appears to be stuck', 'od-product-hub' ), __( 'A Webhook has remained in processing for more than five minutes. Inspect the Webhook log and resend the event after resolving the cause.', 'od-product-hub' ), 'odph_webhook', 'odph-logs&tab=webhook' );
		}
		if ( 3 <= $summary['consecutive_errors'] ) {
			/* translators: %d: number of consecutive Webhook failures. */
			$description = sprintf( __( '%d consecutive Webhook failures were detected. Inspect the latest errors and Stripe delivery history.', 'od-product-hub' ), $summary['consecutive_errors'] );
			return $this->result( 'critical', __( 'Webhook processing is failing repeatedly', 'od-product-hub' ), $description, 'odph_webhook', 'odph-logs&tab=webhook' );
		}
		if ( null === $summary['last_success'] ) {
			return $this->result( 'recommended', __( 'No successful Webhook has been recorded', 'od-product-hub' ), __( 'Send a Stripe test event and confirm that it completes successfully.', 'od-product-hub' ), 'odph_webhook', 'odph-logs&tab=webhook' );
		}
		/* translators: %s: UTC date and time of the latest successful Webhook. */
		$description = sprintf( __( 'The latest successful Webhook was recorded at %s.', 'od-product-hub' ), $this->safe_time( $summary['last_success'] ) );
		return $this->result( 'good', __( 'Webhook processing is healthy', 'od-product-hub' ), $description, 'odph_webhook' );
	}

	/** @return array<string, mixed> */
	public function cron_status(): array {
		$next  = wp_next_scheduled( 'odph_cleanup_logs' );
		$state = (array) get_option( 'odph_operational_state', array() );
		$last  = (string) ( $state['cleanup_last_success'] ?? '' );
		if ( false === $next ) {
			return $this->result( 'critical', __( 'The log cleanup schedule is missing', 'od-product-hub' ), __( 'Deactivate and reactivate the plugin, or load an OD Product Hub screen to repair the daily Cron event.', 'od-product-hub' ), 'odph_cron', 'odph-logs' );
		}
		if ( '' !== $last && strtotime( $last . ' UTC' ) < time() - self::STALE_CLEANUP_SECONDS ) {
			return $this->result( 'recommended', __( 'Log cleanup has not succeeded recently', 'od-product-hub' ), __( 'Run cleanup manually and verify that WP-Cron is invoked by the server.', 'od-product-hub' ), 'odph_cron', 'odph-logs' );
		}
		/* translators: %s: UTC date and time of the next scheduled log cleanup. */
		$description = sprintf( __( 'The daily cleanup is scheduled for %s. No successful run has been recorded yet.', 'od-product-hub' ), $this->scheduled_time( $next ) );
		if ( '' !== $last ) {
			/* translators: 1: UTC date and time of the next cleanup, 2: UTC date and time of the last successful cleanup. */
			$description = sprintf( __( 'The next daily cleanup is scheduled for %1$s and the last successful run was at %2$s.', 'od-product-hub' ), $this->scheduled_time( $next ), $this->safe_time( $last ) );
		}
		return $this->result( 'good', __( 'Log retention is scheduled', 'od-product-hub' ), $description, 'odph_cron' );
	}

	/** @return array<string, mixed> */
	public function update_delivery_status(): array {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) || ! class_exists( '\\ZipArchive' ) ) {
			return $this->result( 'critical', __( 'Update delivery PHP extensions are missing', 'od-product-hub' ), __( 'Enable both Sodium and Zip before publishing or serving releases.', 'od-product-hub' ), 'odph_update_delivery' );
		}
		$storage = $this->storage_status();
		if ( ! $storage['writable'] ) {
			return $this->result( 'critical', __( 'Release storage is not writable', 'od-product-hub' ), __( 'Configure a writable private release storage directory. The actual path is intentionally omitted.', 'od-product-hub' ), 'odph_update_delivery' );
		}
		$validator = new ReleasePackageValidator();
		foreach ( ( new ReleaseRepository() )->published( 100 ) as $release ) {
			$package_error = $validator->validate( $release );
			if ( ReleasePackageValidator::ERROR_MISSING === $package_error ) {
				return $this->result( 'critical', __( 'A published release package is missing', 'od-product-hub' ), __( 'Withdraw the affected release, confirm private storage availability, and publish a newly verified build.', 'od-product-hub' ), 'odph_update_delivery' );
			}
			if ( ReleasePackageValidator::ERROR_INTEGRITY_FAILED === $package_error ) {
				return $this->result( 'critical', __( 'A published release failed integrity verification', 'od-product-hub' ), __( 'Withdraw the affected release, isolate its package, and publish a newly verified build.', 'od-product-hub' ), 'odph_update_delivery' );
			}
		}
		if ( ! $storage['outside_web_root'] ) {
			return $this->result( 'recommended', __( 'Move release storage outside the Web root', 'od-product-hub' ), __( 'The storage is writable, but production releases should use ODPH_RELEASE_STORAGE_PATH outside the Web root.', 'od-product-hub' ), 'odph_update_delivery' );
		}
		return $this->result( 'good', __( 'Update delivery dependencies are ready', 'od-product-hub' ), __( 'Sodium, Zip, private storage, and published release signatures passed validation.', 'od-product-hub' ), 'odph_update_delivery' );
	}

	/** @return array<string, mixed> */
	public function customer_pages_status(): array {
		$settings = (array) get_option( 'odph_settings', array() );
		$checks   = array(
			array(
				'url'       => (string) ( $settings['success_url'] ?? '' ),
				'shortcode' => 'odph_checkout_success',
			),
			array(
				'url'       => (string) ( $settings['cancel_url'] ?? '' ),
				'shortcode' => 'odph_checkout_cancel',
			),
		);
		foreach ( $checks as $check ) {
			$page_id = url_to_postid( $check['url'] );
			$page    = $page_id ? get_post( $page_id ) : null;
			if ( ! $page || 'publish' !== $page->post_status || ! has_shortcode( (string) $page->post_content, $check['shortcode'] ) ) {
				return $this->result( 'critical', __( 'Checkout result pages are incomplete', 'od-product-hub' ), __( 'Use published pages for the success and cancel URLs and place the corresponding OD Product Hub shortcode on each page.', 'od-product-hub' ), 'odph_customer_pages', 'odph-settings' );
			}
		}
		$account = get_post( absint( $settings['account_page_id'] ?? 0 ) );
		if ( ! $account || 'publish' !== $account->post_status || ! has_shortcode( (string) $account->post_content, 'odph_my_account' ) ) {
			return $this->result( 'critical', __( 'The account page is incomplete', 'od-product-hub' ), __( 'Select a published page containing the odph_my_account shortcode.', 'od-product-hub' ), 'odph_customer_pages', 'odph-settings' );
		}
		return $this->result( 'good', __( 'Checkout and account pages are ready', 'od-product-hub' ), __( 'The configured pages are published and contain the required shortcodes.', 'od-product-hub' ), 'odph_customer_pages' );
	}

	/** @param array<string, mixed> $settings */
	private function stripe_configured( array $settings ): bool {
		return ! empty( $settings['stripe_secret_key'] ) && ! empty( $settings['stripe_publishable_key'] ) && ! empty( $settings['stripe_webhook_secret'] );
	}

	/** @return array{writable: bool, outside_web_root: bool} */
	private function storage_status(): array {
		$path     = defined( 'ODPH_RELEASE_STORAGE_PATH' ) ? (string) ODPH_RELEASE_STORAGE_PATH : WP_CONTENT_DIR . '/odph-private-releases';
		$probe    = is_dir( $path ) ? $path : dirname( $path );
		$resolved = realpath( $probe );
		$web_root = realpath( ABSPATH );
		return array(
			'writable'         => is_string( $resolved ) && is_writable( $resolved ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only Site Health probe; WP_Filesystem credentials must not be requested.
			'outside_web_root' => is_string( $resolved ) && is_string( $web_root ) && ! str_starts_with( $resolved . DIRECTORY_SEPARATOR, rtrim( $web_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ),
		);
	}

	private function safe_time( mixed $value ): string {
		$value = is_string( $value ) ? $value : '';
		return '' !== $value && false !== strtotime( $value . ' UTC' ) ? $value . ' UTC' : __( 'Not recorded', 'od-product-hub' );
	}

	private function scheduled_time( int|false $timestamp ): string {
		return false === $timestamp ? __( 'Not scheduled', 'od-product-hub' ) : gmdate( 'Y-m-d H:i:s', $timestamp ) . ' UTC';
	}

	/** @return array<string, mixed> */
	private function result( string $status, string $label, string $description, string $test, string $settings_page = '' ): array {
		$actions = '' === $settings_page ? '' : sprintf( '<p><a href="%s">%s</a></p>', esc_url( admin_url( 'admin.php?page=' . $settings_page ) ), esc_html__( 'Open the relevant OD Product Hub screen', 'od-product-hub' ) );
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => 'OD Product Hub',
				'color' => 'blue',
			),
			'description' => $description,
			'actions'     => $actions,
			'test'        => $test,
		);
	}
}
