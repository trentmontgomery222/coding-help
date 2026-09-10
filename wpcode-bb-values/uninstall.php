<?php
/**
 * Removes everything this plugin stored, when it is deleted from the
 * Plugins screen. Deactivating leaves it all alone.
 *
 * @package WPCodeBBV
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Site-wide values and the scan cache.
delete_option( 'wpcodebbv_global_values' );
delete_transient( 'wpcodebbv_settings_index' );

// Update system.
delete_option( 'wpcodebbv_settings' );
delete_option( 'wpcodebbv_update_failed' );
delete_option( 'wpcodebbv_verified' );
delete_option( 'wpcodebbv_safe_mode' );
delete_transient( 'wpcodebbv_update_remote' );
delete_transient( 'wpcodebbv_devstatus' );
