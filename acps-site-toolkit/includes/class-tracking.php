<?php
/**
 * Journey tracking write/read layer.
 *
 * CRITICAL (spec §4.2 / §13): visits are recorded ONLY via the client-side
 * beacon that hits the REST endpoint. Nothing here is called during a normal
 * cached page render, because a PHP write never fires for edge-cached HTML.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracking.
 */
class Tracking {

	/**
	 * Record a single page visit for a session.
	 *
	 * @param int   $session_id Session row id.
	 * @param array $payload    Small beacon payload: post_id, url, title, viewport.
	 * @return int|false Visit id or false on failure.
	 */
	public static function record_visit( $session_id, $payload ) {
		global $wpdb;
		$visits = Schema::table( 'visits' );
		$now    = current_time( 'mysql' );

		$post_id = isset( $payload['post_id'] ) ? absint( $payload['post_id'] ) : 0;
		$url     = isset( $payload['url'] ) ? esc_url_raw( $payload['url'] ) : '';
		$title   = isset( $payload['title'] ) ? sanitize_text_field( $payload['title'] ) : '';
		$title   = mb_substr( $title, 0, 255 );

		// Find the previous visit in this session to set seq + prev pointer, and
		// to backfill its time_on_page.
		$prev = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT id, post_id, seq_index, visited_at, time_on_page FROM {$visits} WHERE session_id = %d ORDER BY seq_index DESC LIMIT 1",
				$session_id
			)
		);

		$seq          = $prev ? ( (int) $prev->seq_index + 1 ) : 1;
		$prev_post_id = $prev ? $prev->post_id : null;

		if ( $prev && null === $prev->time_on_page && Settings::get( 'track_time_on_page' ) ) {
			// Time on the previous page = now - its visited_at, written on this
			// next visit (spec §3.2). We already know time_on_page is unset from
			// the row above, so no extra SELECT is needed. Skipped entirely when
			// time-on-page tracking is turned off (one fewer UPDATE per beacon).
			$elapsed = time() - strtotime( $prev->visited_at . ' GMT' );
			if ( $elapsed >= 0 && $elapsed < DAY_IN_SECONDS ) {
				$wpdb->update( $visits, array( 'time_on_page' => $elapsed ), array( 'id' => $prev->id ) ); // phpcs:ignore WordPress.DB
			}
		}

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB
			$visits,
			array(
				'session_id'   => $session_id,
				'post_id'      => $post_id ?: null,
				'url'          => $url,
				'title'        => $title,
				'visited_at'   => $now,
				'seq_index'    => $seq,
				'prev_post_id' => $prev_post_id,
			)
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Directly set time_on_page for a visit (used by the unload beacon).
	 *
	 * @param int $session_id Session id.
	 * @param int $seconds    Seconds on page.
	 */
	public static function record_unload( $session_id, $seconds ) {
		global $wpdb;
		$visits  = Schema::table( 'visits' );
		$seconds = max( 0, min( DAY_IN_SECONDS, absint( $seconds ) ) );

		$last_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$visits} WHERE session_id = %d ORDER BY seq_index DESC LIMIT 1", $session_id ) ); // phpcs:ignore WordPress.DB
		if ( $last_id ) {
			$wpdb->update( $visits, array( 'time_on_page' => $seconds ), array( 'id' => $last_id ) ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * The most recent distinct pages for a session, newest first — powers the
	 * feedback page picker pre-fill (spec §5.3).
	 *
	 * @param int $session_id Session id.
	 * @param int $limit      How many to return.
	 * @return array[] Each: post_id, url, title.
	 */
	public static function recent_pages( $session_id, $limit = 3 ) {
		global $wpdb;
		$visits = Schema::table( 'visits' );
		$limit  = max( 1, min( 20, (int) $limit ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT post_id, url, title, MAX(seq_index) AS last_seq
				 FROM {$visits}
				 WHERE session_id = %d
				 GROUP BY post_id, url, title
				 ORDER BY last_seq DESC
				 LIMIT %d",
				$session_id,
				$limit
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}
}
