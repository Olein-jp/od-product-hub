<?php
/**
 * Optional data cleanup.
 *
 * @package OD_Product_Hub
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

OD_Product_Hub\Database\Installer::uninstall();
