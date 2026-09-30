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
delete_option( 'acps_sitemap_issues' );
delete_option( 'acps_sitemap_remote_pw' );
delete_option( 'acps_sitemap_remote_last_edit' );
delete_option( 'acps_sitemap_selfheal' );
delete_option( 'acps_sitemap_staged_install' );
delete_option( 'acps_sitemap_staged_result' );
delete_option( 'acps_sitemap_rollback' );
delete_option( 'acps_sitemap_rollback_done' );
delete_option( 'acps_sitemap_should_be_active' );
delete_option( 'acps_sitemap_post_update_check' );
delete_option( 'acps_sitemap_pending_update' );
delete_option( 'acps_sitemap_pending_result' );

if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
	wp_clear_scheduled_hook( 'acps_sitemap_selfheal_cron' );
	wp_clear_scheduled_hook( 'acps_sitemap_apply_pending' );
}

// Best-effort: remove any leftover rollback backup directory.
if ( defined( 'WP_CONTENT_DIR' ) && is_dir( WP_CONTENT_DIR . '/acps-sitemap-rollback' ) ) {
	$dir = WP_CONTENT_DIR . '/acps-sitemap-rollback';
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $item ) {
		$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() ); // phpcs:ignore
	}
	@rmdir( $dir ); // phpcs:ignore
}

// Update-lookup transients.
delete_transient( 'acps_sitemap_update_remote' );
delete_transient( 'acps_sitemap_devstatus' );
delete_transient( 'acps_sitemap_pending_lock' );

// Remove any leftover sitemap cache + remote session/rate transients.
global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_acps_sm\_%'
	    OR option_name LIKE '\_transient\_timeout\_acps_sm\_%'
	    OR option_name LIKE '\_transient\_acps_sitemap_remote\_%'
	    OR option_name LIKE '\_transient\_timeout\_acps_sitemap_remote\_%'"
);
