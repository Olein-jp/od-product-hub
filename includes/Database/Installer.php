<?php
/**
 * Activation and upgrades.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Database;

final class Installer {
	private const LOG_CLEANUP_HOOK          = 'odph_cleanup_logs';
	private const LOG_CLEANUP_RECURRENCE    = 'daily';
	private const LOG_CLEANUP_INITIAL_DELAY = HOUR_IN_SECONDS;
	private const VENDOR_LICENSE_HOOK       = 'odph_verify_vendor_license';

	public static function activate(): void {
		self::migrate();
		add_option( 'odph_settings', self::defaults(), '', false );
		self::ensure_scheduled_events();
	}

	public static function maybe_upgrade(): void {
		if ( version_compare( (string) get_option( 'odph_schema_version', '0.0.0' ), Schema::VERSION, '<' ) ) {
			self::migrate();
		}
		self::ensure_scheduled_events();
	}

	public static function ensure_scheduled_events(): void {
		if ( false !== wp_next_scheduled( self::LOG_CLEANUP_HOOK ) ) {
			self::ensure_vendor_license_event();
			return;
		}

		$result = wp_schedule_event(
			time() + self::LOG_CLEANUP_INITIAL_DELAY,
			self::LOG_CLEANUP_RECURRENCE,
			self::LOG_CLEANUP_HOOK,
			array(),
			true
		);
		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; retain the WP-Cron diagnostic.
			throw new \RuntimeException( 'Failed to schedule log cleanup: ' . $result->get_error_message() );
		}
		self::ensure_vendor_license_event();
	}

	private static function ensure_vendor_license_event(): void {
		if ( false === wp_next_scheduled( self::VENDOR_LICENSE_HOOK ) ) {
			$result = wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::VENDOR_LICENSE_HOOK, array(), true );
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( 'Failed to schedule vendor license verification: ' . esc_html( $result->get_error_message() ) );
			}
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::LOG_CLEANUP_HOOK );
		wp_clear_scheduled_hook( self::VENDOR_LICENSE_HOOK );
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
		self::deactivate();
		delete_option( 'odph_settings' );
		delete_option( 'odph_schema_version' );
		delete_option( 'odph_operational_state' );
		delete_option( 'odph_vendor_license_key' );
		delete_option( 'odph_vendor_license_state' );
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
			'1.6.0' => array( self::class, 'reconcile_repository_schema' ),
			'1.7.0' => array( self::class, 'reconcile_repository_schema' ),
			'1.8.0' => array( self::class, 'reconcile_repository_schema' ),
			'1.9.0' => array( self::class, 'add_product_license_key_prefix' ),
		);
	}

	public static function create_initial_schema(): void {
		self::apply_schema();
	}

	public static function reconcile_repository_schema(): void {
		self::apply_schema();
	}

	public static function add_product_license_key_prefix(): void {
		global $wpdb;
		$table  = $wpdb->prefix . 'odph_products';
		$column = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'license_key_prefix' ) );
		if ( null === $column ) {
			$result = $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD license_key_prefix varchar(12) NOT NULL DEFAULT '' AFTER billing_description", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Versioned product schema migration.
			if ( false === $result ) {
				throw DatabaseException::from_last_error( 'add product license key prefix' );
			}
			$result = $wpdb->query( $wpdb->prepare( "UPDATE %i SET license_key_prefix = 'ODPH' WHERE license_key_prefix = ''", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Existing products retain their legacy key format exactly once when the column is introduced.
			if ( false === $result ) {
				throw DatabaseException::from_last_error( 'backfill product license key prefix' );
			}
		}
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
			'update_rate_limit'      => 20,
			'download_url_ttl'       => 300,
			'delete_on_uninstall'    => 0,
			'email_templates'        => \OD_Product_Hub\Email\Templates::defaults(),
		);
	}
}
