<?php
/**
 * Optional data cleanup.
 *
 * @package OD_Product_Hub
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; }
$settings = get_option( 'odph_settings', array() );
if ( empty( $settings['delete_on_uninstall'] ) ) {
	return; }
global $wpdb;
foreach ( array( 'products', 'customers', 'licenses', 'subscriptions', 'webhook_logs', 'api_logs', 'admin_logs' ) as $table ) {
	$table_name = $wpdb->prefix . 'odph_' . $table;
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) );
}
delete_option( 'odph_settings' );
delete_option( 'odph_schema_version' );
