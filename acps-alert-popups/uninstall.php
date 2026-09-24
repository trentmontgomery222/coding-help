<?php
/**
 * Removes plugin data when the plugin is deleted.
 *
 * The status posts themselves are site content and are kept; everything the
 * plugin stores about them, and all of its own options, caches and per-user
 * state, is removed.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Uninstall runs outside the plugin's own failsafe, so it carries its own: a
// delete that fails must not leave the user staring at a fatal on the Plugins
// screen with the plugin half-removed. Each step is guarded on its own, so one
// failure never skips the rest.
$acps_alerts_cleanup = array(
	'options'   => function () {
		foreach ( array(
			'acps_alerts_settings',
			'acps_alerts_update_failed',
			'acps_alerts_update_verified',
			'acps_alerts_health',
			'acps_alerts_safe_mode',
			'acps_alerts_panel_last_edit',
			'acps_alerts_problems',
			'acps_alerts_tripped',
			'acps_alerts_missing_notified',
			'acps_alerts_board_page',
			'acps_alerts_level_words',
			'acps_alerts_archive',
			'acps_alerts_popup_node',
			'acps_alerts_used_once',
		) as $option ) {
			delete_option( $option );
		}
	},
	'cron'      => function () {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'acps_alerts_daily_archive' );
		}
	},
	'transients' => function () {
		delete_transient( 'acps_alerts_update_remote' );
		delete_transient( 'acps_alerts_update_devstatus' );
	},
	'database'  => function () {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( '_acps_alert_' ) . '%'
			)
		);

		// Console, breaker and failure counters (acps_ap_*) and the rendered
		// popup cache (acps_alerts_*), with their timeouts.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_acps_ap_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_acps_ap_' ) . '%',
				$wpdb->esc_like( '_transient_acps_alerts_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_acps_alerts_' ) . '%'
			)
		);

		// Per-user tour progress and the dismissed welcome panel.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ( %s, %s )",
				'acps_alerts_tours',
				'acps_alerts_welcome_dismissed'
			)
		);
	},
);

foreach ( $acps_alerts_cleanup as $acps_alerts_step => $acps_alerts_run ) {
	try {
		$acps_alerts_run();
	} catch ( \Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
			error_log( '[ACPS Alert Popups] Uninstall step "' . $acps_alerts_step . '" failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
