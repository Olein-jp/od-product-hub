<?php
/**
 * Activation and upgrades.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Database;

final class Installer {
	public static function activate(): void {
		self::migrate();
		add_option( 'odph_settings', self::defaults(), '', false );
		if ( ! wp_next_scheduled( 'odph_cleanup_logs' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'odph_cleanup_logs' );
		}
	}

	public static function maybe_upgrade(): void {
		if ( version_compare( (string) get_option( 'odph_schema_version', '0.0.0' ), Schema::VERSION, '<' ) ) {
			self::migrate();
		}
	}

	public static function migrate(): void {
		$installed = (string) get_option( 'odph_schema_version', '0.0.0' );
		foreach ( self::migrations() as $version => $callback ) {
			if ( version_compare( $installed, $version, '>=' ) ) {
				continue;
			}
			$callback();
			update_option( 'odph_schema_version', $version, false );
			$installed = $version;
		}
	}

	public static function uninstall(): void {
		$settings = get_option( 'odph_settings', array() );
		if ( empty( $settings['delete_on_uninstall'] ) ) {
			return;
		}
		global $wpdb;
		foreach ( Schema::table_suffixes() as $suffix ) {
			$table = $wpdb->prefix . 'odph_' . $suffix;
			if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Destructive schema changes are isolated to explicit uninstall.
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The suffix comes from the fixed schema table list.
				throw DatabaseException::from_last_error( 'uninstall ' . $suffix );
			}
		}
		wp_clear_scheduled_hook( 'odph_cleanup_logs' );
		delete_option( 'odph_settings' );
		delete_option( 'odph_schema_version' );
	}

	/** @return array<string, callable(): void> */
	private static function migrations(): array {
		return array(
			'1.0.0' => array( self::class, 'create_initial_schema' ),
			'1.1.0' => array( self::class, 'reconcile_repository_schema' ),
			'1.2.0' => array( self::class, 'reconcile_repository_schema' ),
			'1.3.0' => array( self::class, 'reconcile_repository_schema' ),
			'1.4.0' => array( self::class, 'reconcile_repository_schema' ),
			'1.5.0' => array( self::class, 'reconcile_repository_schema' ),
		);
	}

	public static function create_initial_schema(): void {
		self::apply_schema();
	}

	public static function reconcile_repository_schema(): void {
		self::apply_schema();
	}

	private static function apply_schema(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		foreach ( Schema::tables() as $sql ) {
			dbDelta( $sql );
			if ( '' !== (string) $wpdb->last_error ) {
				throw DatabaseException::from_last_error( 'schema migration' );
			}
		}
	}

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return array(
			'stripe_secret_key'      => '',
			'stripe_publishable_key' => '',
			'stripe_webhook_secret'  => '',
			'portal_enabled'         => 1,
			'success_url'            => '',
			'cancel_url'             => '',
			'account_page_id'        => 0,
			'email_from_name'        => get_bloginfo( 'name' ),
			'email_from_address'     => get_option( 'admin_email' ),
			'log_retention_days'     => 365,
			'api_rate_limit'         => 60,
			'api_trusted_proxies'    => '',
			'delete_on_uninstall'    => 0,
			'email_templates'        => \OD_Product_Hub\Email\Templates::defaults(),
		);
	}
}
