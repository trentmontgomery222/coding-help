<?php
/**
 * Removes plugin data when the plugin is deleted.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Uninstall runs outside the plugin's own failsafe, so it carries its own:
// a delete that fails must not leave the user staring at a fatal on the
// Plugins screen with the plugin half-removed.
try {
	delete_option( 'acps_alerts_settings' );
	delete_option( 'acps_alerts_update_failed' );
	delete_option( 'acps_alerts_health' );
	delete_option( 'acps_alerts_safe_mode' );
	delete_option( 'acps_alerts_panel_last_edit' );
	delete_option( 'acps_alerts_problems' );
	delete_transient( 'acps_alerts_update_remote' );

	global $wpdb;

	if ( isset( $wpdb ) ) {
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( '_acps_alert_' ) . '%'
			)
		);

		// Per-address console transients plus breaker and failure counters.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_acps_ap_%',
				'_transient_timeout_acps_ap_%'
			)
		);
	}
} catch ( \Throwable $e ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
		error_log( '[ACPS Alert Popups] Uninstall cleanup failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}
}
