<?php
/**
 * Unique visitors ("users").
 *
 * A unique user = a persistent first-party ID cookie (per browser). Designed to
 * over-count rather than miss: a UNIQUE(uid) index kills duplicates, every
 * active visitor is registered on their first page load, and a cleared cookie
 * simply mints a new ID. Multi-browser counting as separate users is accepted.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Visitors.
 */
class Visitors {

	/**
	 * Register a visitor id (idempotent). First sight inserts a row; later
	 * sights only bump last_seen. UNIQUE(uid) makes this dupe-proof even under
	 * concurrent beacons.
	 *
	 * @param string $uid Persistent visitor id.
	 */
	public static function record( $uid ) {
		$uid = self::sanitize( $uid );
		if ( '' === $uid ) {
			return;
		}
		global $wpdb;
		$t   = Schema::table( 'visitors' );
		$now = current_time( 'mysql' );
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"INSERT INTO {$t} (uid, first_seen, last_seen) VALUES (%s, %s, %s)
				 ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)",
				$uid,
				$now,
				$now
			)
		);
	}

	/**
	 * Total unique users, all time.
	 *
	 * @return int
	 */
	public static function total() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'visitors' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * New users first seen on/after a date (local Y-m-d).
	 *
	 * @param string $date Y-m-d.
	 * @return int
	 */
	public static function new_since( $date ) {
		global $wpdb;
		$t = Schema::table( 'visitors' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE first_seen >= %s", $date . ' 00:00:00' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * New users still active (last_seen) within a window — a rough "recent
	 * unique users" figure.
	 *
	 * @param int $days Days back.
	 * @return int
	 */
	public static function active_within( $days ) {
		global $wpdb;
		$t      = Schema::table( 'visitors' );
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - max( 1, (int) $days ) * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE last_seen >= %s", $cutoff ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * New-users-per-day trend.
	 *
	 * @param int $days Days back.
	 * @return array[] d => count.
	 */
	public static function new_trend( $days = 30 ) {
		global $wpdb;
		$t    = Schema::table( 'visitors' );
		$days = max( 1, min( 365, (int) $days ) );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT DATE(first_seen) AS d, COUNT(*) AS c FROM {$t} WHERE first_seen >= %s GROUP BY DATE(first_seen) ORDER BY d ASC",
				gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ) // phpcs:ignore WordPress.DateTime
			),
			ARRAY_A
		);
		return $rows ? $rows : array();
	}

	/**
	 * Validate a visitor id (hex/uuid-ish, 16–64 chars).
	 *
	 * @param string $uid Raw.
	 * @return string
	 */
	public static function sanitize( $uid ) {
		$uid = is_string( $uid ) ? strtolower( trim( $uid ) ) : '';
		return preg_match( '/^[a-f0-9\-]{16,64}$/', $uid ) ? $uid : '';
	}
}
