<?php
/**
 * Uninstall cleanup: remove settings and cached data.
 *
 * @package ACPS_Sitemap
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'acps_sitemap_settings' );
delete_option( 'acps_sitemap_cache_buster' );

// Remove any leftover sitemap cache transients.
global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_acps_sm\_%'
	    OR option_name LIKE '\_transient\_timeout\_acps_sm\_%'"
);
