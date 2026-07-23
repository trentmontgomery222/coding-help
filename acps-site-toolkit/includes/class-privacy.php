<?php
/**
 * Privacy: scheduled retention purge + WordPress GDPR export/erase hooks
 * (spec §4.5). Registering the core privacy hooks means requests handled
 * through Tools → Export/Erase Personal Data include this plugin's data.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Privacy.
 */
class Privacy {

	const PURGE_HOOK = 'acps_st_daily_purge';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( self::PURGE_HOOK, array( $this, 'purge' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Delete visit rows older than the retention window, and prune sessions with
	 * no remaining visits (spec §4.5). Runs daily via WP-Cron.
	 */
	public function purge() {
		$months = (int) Settings::get( 'retention_months', 12 );
		if ( $months <= 0 ) {
			return; // 0 = keep forever.
		}

		global $wpdb;
		$visits   = Schema::table( 'visits' );
		$sessions = Schema::table( 'sessions' );
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - $months * MONTH_IN_SECONDS );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$visits} WHERE visited_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB

		// Remove sessions that no longer have any visits and are past the window.
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"DELETE s FROM {$sessions} s
				 LEFT JOIN {$visits} v ON v.session_id = s.id
				 WHERE v.id IS NULL AND s.last_activity_at < %s",
				$cutoff
			)
		);
	}

	/**
	 * Register the personal-data exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['acps-site-toolkit'] = array(
			'exporter_friendly_name' => __( 'ACPS Site Toolkit', 'acps-site-toolkit' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the personal-data eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['acps-site-toolkit'] = array(
			'eraser_friendly_name' => __( 'ACPS Site Toolkit', 'acps-site-toolkit' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export form entries associated with an email address.
	 *
	 * @param string $email Email being exported.
	 * @param int    $page  Page (unused; single page).
	 * @return array
	 */
	public function export( $email, $page = 1 ) {
		global $wpdb;
		$data = array();

		$values  = Schema::table( 'entry_values' );
		$entries = Schema::table( 'entries' );

		// Entries whose values contain this email.
		$like       = '%' . $wpdb->esc_like( $email ) . '%';
		$entry_ids  = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT entry_id FROM {$values} WHERE value LIKE %s", $like ) ); // phpcs:ignore WordPress.DB

		foreach ( $entry_ids as $eid ) {
			$row = Entries::get( (int) $eid );
			if ( ! $row ) {
				continue;
			}
			$items = array();
			foreach ( $row['values'] as $k => $v ) {
				$items[] = array( 'name' => $k, 'value' => is_array( $v ) ? implode( ', ', $v ) : $v );
			}
			$items[] = array( 'name' => 'submitted_at', 'value' => $row['entry']->submitted_at );
			$data[]  = array(
				'group_id'    => 'acps_entries',
				'group_label' => __( 'Form submissions', 'acps-site-toolkit' ),
				'item_id'     => 'acps-entry-' . $eid,
				'data'        => $items,
			);
		}

		return array( 'data' => $data, 'done' => true );
	}

	/**
	 * Erase entries associated with an email address.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page (unused).
	 * @return array
	 */
	public function erase( $email, $page = 1 ) {
		global $wpdb;
		$values  = Schema::table( 'entry_values' );
		$notes   = Schema::table( 'entry_notes' );
		$entries = Schema::table( 'entries' );

		$like      = '%' . $wpdb->esc_like( $email ) . '%';
		$entry_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT entry_id FROM {$values} WHERE value LIKE %s", $like ) ); // phpcs:ignore WordPress.DB

		$removed = 0;
		if ( $entry_ids ) {
			$in = implode( ',', array_map( 'absint', $entry_ids ) );
			$wpdb->query( "DELETE FROM {$values} WHERE entry_id IN ({$in})" ); // phpcs:ignore WordPress.DB
			$wpdb->query( "DELETE FROM {$notes} WHERE entry_id IN ({$in})" ); // phpcs:ignore WordPress.DB
			$removed = $wpdb->query( "DELETE FROM {$entries} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB
		}

		return array(
			'items_removed'  => (int) $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
