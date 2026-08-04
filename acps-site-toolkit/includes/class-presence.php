<?php
/**
 * Admin/staff presence.
 *
 * Logged-in admins are deliberately excluded from analytics, so their activity
 * never lands in the sessions/visits tables. This tiny, separate store tracks
 * where staff are on the site (name + current page), so an admin can see who
 * else is on a page before editing it. It lives in a single option, not the
 * analytics tables, and prunes itself.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Presence.
 */
class Presence {

	const OPTION = 'acps_st_admin_presence';

	/**
	 * Record the current admin's location (heartbeat from the front end).
	 *
	 * @param array $data title, url, post_id.
	 */
	public static function record( $data ) {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return;
		}

		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}

		$all[ $user->ID ] = array(
			'name'    => $user->display_name,
			'title'   => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
			'url'     => isset( $data['url'] ) ? esc_url_raw( $data['url'] ) : '',
			'post_id' => isset( $data['post_id'] ) ? absint( $data['post_id'] ) : 0,
			'time'    => time(),
		);

		// Prune anything older than 15 minutes so the option stays small.
		foreach ( $all as $id => $p ) {
			if ( empty( $p['time'] ) || ( time() - (int) $p['time'] ) > 15 * MINUTE_IN_SECONDS ) {
				unset( $all[ $id ] );
			}
		}

		update_option( self::OPTION, $all, false );
	}

	/**
	 * Staff active within the last N minutes.
	 *
	 * @param int $minutes Window.
	 * @return array[] Each: user_id, name, title, url, post_id, seconds_ago.
	 */
	public static function active( $minutes = 5 ) {
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) ) {
			return array();
		}
		$cutoff = time() - max( 1, (int) $minutes ) * MINUTE_IN_SECONDS;
		$out    = array();
		foreach ( $all as $id => $p ) {
			if ( ! empty( $p['time'] ) && (int) $p['time'] >= $cutoff ) {
				$p['user_id']     = (int) $id;
				$p['seconds_ago'] = time() - (int) $p['time'];
				$out[]            = $p;
			}
		}
		usort( $out, function ( $a, $b ) { return $a['seconds_ago'] <=> $b['seconds_ago']; } );
		return $out;
	}
}
