<?php
/**
 * Uninstall cleanup: remove settings and cached data.
 *
 * @package ACPS_Sitemap
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Settings and internal options.
delete_option( 'acps_sitemap_settings' );
delete_option( 'acps_sitemap_cache_buster' );
delete_option( 'acps_sitemap_verified' );
delete_option( 'acps_sitemap_update_failed' );
delete_option( 'acps_sitemap_safe_mode' );

// Update-lookup transients.
delete_transient( 'acps_sitemap_update_remote' );
delete_transient( 'acps_sitemap_devstatus' );

// Remove any leftover sitemap cache transients.
global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_acps_sm\_%'
	    OR option_name LIKE '\_transient\_timeout\_acps_sm\_%'"
);
