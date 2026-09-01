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
	 * The server-side visitor fingerprint: a hash of the anonymized IP + parsed
	 * browser/OS summary — the SAME signal the spam rate-limiter uses. Because
	 * it's derived server-side from the request, clearing cookies/cache/storage
	 * cannot create a "new" visitor. Trade-off: people behind the same network
	 * on the same browser look like one visitor (dedupe over over-count).
	 *
	 * @return string 32-char hex id.
	 */
	public static function fingerprint() {
		$ip = Session::anonymize_ip( Session::client_ip() );
		$ua = Session::user_agent_summary();
		return md5( 'acps_v|' . $ip . '|' . $ua );
	}

	/**
	 * Register a visitor (idempotent). With no argument it uses the server-side
	 * fingerprint; an explicit id is used as-is (e.g. to attach a name). First
	 * sight inserts a row; later sights only bump last_seen. UNIQUE(uid) makes
	 * this dupe-proof even under concurrent beacons.
	 *
	 * @param string|null $uid     Explicit id, or null to use the fingerprint.
	 * @param string|null $ip      Client IP to store (front-end sightings only).
	 * @param int|null    $user_id Logged-in WordPress user id, if any.
	 */
	public static function record( $uid = null, $ip = null, $user_id = null ) {
		$uid = ( null === $uid || '' === $uid ) ? self::fingerprint() : self::sanitize( $uid );
		if ( '' === $uid ) {
			return;
		}
		global $wpdb;
		$t   = Schema::table( 'visitors' );
		$now = current_time( 'mysql' );

		// Base upsert: create the row on first sight, bump last_seen thereafter.
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"INSERT INTO {$t} (uid, first_seen, last_seen) VALUES (%s, %s, %s)
				 ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)",
				$uid,
				$now,
				$now
			)
		);

		// Extra identifiers, stored only on real front-end sightings (an IP or
		// user id was passed) — never overwritten from an admin ensure-row call.
		$updates = array();
		$ip      = ( null === $ip ) ? '' : trim( (string) $ip );
		if ( '' !== $ip && self::has_column( 'visitors', 'last_ip' ) ) {
			$updates['last_ip'] = $ip;
		}
		if ( $user_id && (int) $user_id > 0 && self::has_column( 'visitors', 'user_id' ) ) {
			$updates['user_id'] = (int) $user_id;
		}
		if ( $updates ) {
			$wpdb->update( $t, $updates, array( 'uid' => $uid ) ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * The pages a visitor has navigated, newest first, across all their sessions
	 * (sessions are tied to the visitor by the same fingerprint). Requires page
	 * tracking to be on.
	 *
	 * @param string $uid   Visitor id.
	 * @param int    $limit Max rows.
	 * @return array[] title, url, post_id, visited_at.
	 */
	public static function navigation( $uid, $limit = 200 ) {
		$uid = self::sanitize( $uid );
		if ( '' === $uid || ! self::has_column( 'sessions', 'visitor_uid' ) ) {
			return array();
		}
		global $wpdb;
		$vi    = Schema::table( 'visits' );
		$se    = Schema::table( 'sessions' );
		$limit = max( 1, min( 1000, (int) $limit ) );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT vi.title, vi.url, vi.post_id, vi.visited_at, vi.time_on_page
				 FROM {$vi} vi JOIN {$se} se ON vi.session_id = se.id
				 WHERE se.visitor_uid = %s
				 ORDER BY vi.visited_at DESC
				 LIMIT %d",
				$uid,
				$limit
			),
			ARRAY_A
		);
		return $rows ? $rows : array();
	}

	/**
	 * All of a visitor's sessions with their device/environment context, newest
	 * first — the same signals a form submission captures (device, browser/OS,
	 * viewport, referrer, entry page, IP).
	 *
	 * @param string $uid   Visitor id.
	 * @param int    $limit Max rows.
	 * @return array[] session rows.
	 */
	public static function sessions( $uid, $limit = 100 ) {
		$uid = self::sanitize( $uid );
		if ( '' === $uid || ! self::has_column( 'sessions', 'visitor_uid' ) ) {
			return array();
		}
		global $wpdb;
		$se    = Schema::table( 'sessions' );
		$limit = max( 1, min( 500, (int) $limit ) );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT started_at, last_activity_at, device_type, user_agent_summary, viewport, referrer, entry_url, entry_page_id, ip_anon
				 FROM {$se}
				 WHERE visitor_uid = %s
				 ORDER BY started_at DESC
				 LIMIT %d",
				$uid,
				$limit
			),
			ARRAY_A
		);
		return $rows ? $rows : array();
	}

	/**
	 * The visitor's most recent session (device/environment summary), or null.
	 *
	 * @param string $uid Visitor id.
	 * @return array|null
	 */
	public static function latest_session( $uid ) {
		$rows = self::sessions( $uid, 1 );
		return $rows ? $rows[0] : null;
	}

	/**
	 * Whether a table has a column (cached per request). Guards writes/reads
	 * against a schema that hasn't finished upgrading yet.
	 *
	 * @param string $table Logical table key.
	 * @param string $col   Column name.
	 * @return bool
	 */
	private static function has_column( $table, $col ) {
		static $cache = array();
		if ( ! isset( $cache[ $table ] ) ) {
			global $wpdb;
			$found            = $wpdb->get_col( 'SHOW COLUMNS FROM ' . Schema::table( $table ) ); // phpcs:ignore WordPress.DB
			$cache[ $table ]  = is_array( $found ) ? array_map( 'strtolower', $found ) : array();
		}
		return in_array( strtolower( $col ), $cache[ $table ], true );
	}

	/**
	 * Set (or clear) a visitor's display name — e.g. from an "accname" form
	 * field. Creates the visitor row if it doesn't exist yet.
	 *
	 * @param string $uid  Visitor id.
	 * @param string $name Name.
	 */
	public static function set_name( $uid, $name ) {
		$uid = self::sanitize( $uid );
		if ( '' === $uid ) {
			return;
		}
		self::record( $uid ); // ensure the row exists.
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::table( 'visitors' ),
			array( 'name' => $name ? sanitize_text_field( $name ) : null ),
			array( 'uid' => $uid )
		);
	}

	/**
	 * Set a visitor's internal notes.
	 *
	 * @param string $uid   Visitor id.
	 * @param string $notes Notes.
	 */
	public static function set_notes( $uid, $notes ) {
		$uid = self::sanitize( $uid );
		if ( '' === $uid ) {
			return;
		}
		self::record( $uid );
		global $wpdb;
		$wpdb->update( Schema::table( 'visitors' ), array( 'notes' => sanitize_textarea_field( $notes ) ), array( 'uid' => $uid ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Fetch a visitor row by uid.
	 *
	 * @param string $uid Visitor id.
	 * @return object|null
	 */
	public static function get( $uid ) {
		$uid = self::sanitize( $uid );
		if ( '' === $uid ) {
			return null;
		}
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'visitors' ) . ' WHERE uid = %s', $uid ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * List / search visitors with their submission counts.
	 *
	 * @param array $args search, per_page, paged.
	 * @return array [ rows => object[], total => int ]
	 */
	public static function query( $args = array() ) {
		global $wpdb;
		$v = Schema::table( 'visitors' );
		$e = Schema::table( 'entries' );

		$args = wp_parse_args( $args, array( 'search' => '', 'per_page' => 50, 'paged' => 1, 'orderby' => 'last_seen', 'order' => 'desc' ) );

		// Whitelist sort columns (map friendly keys → safe SQL).
		$order_cols = array(
			'name'        => 'vv.name',
			'uid'         => 'vv.uid',
			'last_ip'     => 'vv.last_ip',
			'entry_count' => 'entry_count',
			'first_seen'  => 'vv.first_seen',
			'last_seen'   => 'vv.last_seen',
		);
		$orderby = isset( $order_cols[ $args['orderby'] ] ) ? $order_cols[ $args['orderby'] ] : 'vv.last_seen';
		if ( 'vv.last_ip' === $orderby && ! self::has_column( 'visitors', 'last_ip' ) ) {
			$orderby = 'vv.last_seen';
		}
		$order = ( 'asc' === strtolower( (string) $args['order'] ) ) ? 'ASC' : 'DESC';

		$where  = array( '1=1' );
		$params = array();
		if ( '' !== $args['search'] ) {
			$like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			if ( self::has_column( 'visitors', 'last_ip' ) ) {
				$where[]  = '(uid LIKE %s OR name LIKE %s OR last_ip LIKE %s)';
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
			} else {
				$where[]  = '(uid LIKE %s OR name LIKE %s)';
				$params[] = $like;
				$params[] = $like;
			}
		}
		$where_sql = implode( ' AND ', $where );

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['paged'] ) - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$v} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql ); // phpcs:ignore WordPress.DB

		// $orderby/$order come from a fixed whitelist above, never from raw input.
		$sql  = "SELECT vv.*, ( SELECT COUNT(*) FROM {$e} en WHERE en.visitor_uid = vv.uid AND en.status NOT IN ('spam','trashed') ) AS entry_count
				 FROM {$v} vv WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, $offset ) ) ) ); // phpcs:ignore WordPress.DB
		return array( 'rows' => $rows ? $rows : array(), 'total' => $total );
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
