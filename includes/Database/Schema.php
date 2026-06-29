<?php
/**
 * Database schema.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Database;

final class Schema {
	public const VERSION = '1.1.0';

	/** @return list<string> */
	public static function table_suffixes(): array {
		return array( 'products', 'customers', 'subscriptions', 'licenses', 'webhook_logs', 'api_logs', 'admin_logs' );
	}

	/** @return array<string, string> */
	public static function tables(): array {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix . 'odph_';

		return array(
			'products'      => "CREATE TABLE {$p}products (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				slug varchar(191) NOT NULL,
				description longtext NULL,
				stripe_product_id varchar(191) NOT NULL,
				stripe_price_id varchar(191) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY stripe_price_id (stripe_price_id),
				KEY status (status)
			) {$charset};",
			'customers'     => "CREATE TABLE {$p}customers (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				wp_user_id bigint unsigned NOT NULL,
				stripe_customer_id varchar(191) NOT NULL,
				email varchar(191) NOT NULL,
				name varchar(191) NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY stripe_customer_id (stripe_customer_id),
				KEY wp_user_id (wp_user_id),
				KEY email (email)
			) {$charset};",
			'subscriptions' => "CREATE TABLE {$p}subscriptions (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				customer_id bigint unsigned NOT NULL,
				product_id bigint unsigned NOT NULL,
				stripe_subscription_id varchar(191) NOT NULL,
				stripe_status varchar(50) NOT NULL,
				current_period_start datetime NULL,
				current_period_end datetime NULL,
				cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0,
				payment_failed_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY stripe_subscription_id (stripe_subscription_id),
				KEY customer_id (customer_id),
				KEY product_id (product_id),
				KEY stripe_status (stripe_status)
			) {$charset};",
			'licenses'      => "CREATE TABLE {$p}licenses (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				product_id bigint unsigned NOT NULL,
				customer_id bigint unsigned NOT NULL,
				subscription_id bigint unsigned NULL,
				license_key varchar(191) NOT NULL,
				license_key_hash varchar(255) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'inactive',
				issued_at datetime NOT NULL,
				expires_at datetime NULL,
				last_verified_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY license_key (license_key),
				KEY license_key_hash (license_key_hash(191)),
				KEY product_id (product_id),
				KEY customer_id (customer_id),
				KEY subscription_id (subscription_id),
				KEY status (status)
			) {$charset};",
			'webhook_logs'  => "CREATE TABLE {$p}webhook_logs (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				stripe_event_id varchar(191) NOT NULL,
				event_type varchar(191) NOT NULL,
				payload longtext NOT NULL,
				result varchar(20) NOT NULL,
				error_message text NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY stripe_event_id (stripe_event_id),
				KEY result (result),
				KEY created_at (created_at)
			) {$charset};",
			'api_logs'      => "CREATE TABLE {$p}api_logs (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				license_id bigint unsigned NULL,
				product_id bigint unsigned NULL,
				action varchar(50) NOT NULL,
				result varchar(20) NOT NULL,
				site_url varchar(255) NULL,
				ip_address varchar(100) NULL,
				user_agent text NULL,
				error_code varchar(100) NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY license_id (license_id),
				KEY product_id (product_id),
				KEY action (action),
				KEY result (result),
				KEY created_at (created_at)
			) {$charset};",
			'admin_logs'    => "CREATE TABLE {$p}admin_logs (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint unsigned NOT NULL,
				action varchar(100) NOT NULL,
				object_type varchar(50) NULL,
				object_id bigint unsigned NULL,
				details text NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY action (action),
				KEY created_at (created_at)
			) {$charset};",
		);
	}
}
