<?php
/**
 * Activation and upgrades.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Database;

final class Installer {
	public static function activate(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( Schema::tables() as $sql ) {
			dbDelta( $sql );
		}
		update_option( 'odph_schema_version', Schema::VERSION, false );
		add_option( 'odph_settings', self::defaults(), '', false );
		if ( ! wp_next_scheduled( 'odph_cleanup_logs' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'odph_cleanup_logs' );
		}
	}

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return array(
			'portal_enabled'      => 1,
			'success_url'         => '',
			'cancel_url'          => '',
			'account_page_id'     => 0,
			'email_from_name'     => get_bloginfo( 'name' ),
			'email_from_address'  => get_option( 'admin_email' ),
			'log_retention_days'  => 365,
			'api_rate_limit'      => 60,
			'delete_on_uninstall' => 0,
		);
	}
}
