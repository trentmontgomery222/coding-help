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
	 * How many times this visitor has visited the site (their session count).
	 *
	 * @param string $uid Visitor id.
	 * @return int
	 */
	public static function visit_count( $uid ) {
		$uid = self::sanitize( $uid );
		if ( '' === $uid || ! self::has_column( 'sessions', 'visitor_uid' ) ) {
			return 0;
		}
		global $wpdb;
		$se = Schema::table( 'sessions' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$se} WHERE visitor_uid = %s", $uid ) ); // phpcs:ignore WordPress.DB
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
	 * Attach a GPU/WebGL device hash + hardware profile to a visitor. Ensures the
	 * visitor row exists first. Stored only when the columns are present.
	 *
	 * @param string $uid  Visitor id.
	 * @param string $hash 64-char sha256 device hash.
	 * @param array  $info Capability profile (renderer, tier, score, cores, …).
	 */
	public static function set_device( $uid, $hash, $info = array() ) {
		$uid  = self::sanitize( $uid );
		$hash = is_string( $hash ) && preg_match( '/^[a-f0-9]{64}$/', $hash ) ? $hash : '';
		if ( '' === $uid || '' === $hash || ! self::has_column( 'visitors', 'device_hash' ) ) {
			return;
		}
		self::record( $uid ); // ensure the row exists.
		global $wpdb;
		$data = array( 'device_hash' => $hash );
		if ( self::has_column( 'visitors', 'device_info' ) ) {
			$data['device_info'] = is_array( $info ) && $info ? wp_json_encode( $info ) : null;
		}
		$wpdb->update( Schema::table( 'visitors' ), $data, array( 'uid' => $uid ) ); // phpcs:ignore WordPress.DB
	}

	/** Max mean per-component difference (normalised timing shares) to call two
	 *  timing profiles the "same" physical unit. Tunable; timing is noisy. */
	const TIMING_THRESHOLD = 0.03;

	/**
	 * Merge visitors that share a GPU device hash into ONE identity — but only
	 * when they also look like the same physical unit. An identical pixel hash
	 * means "same GPU model + driver", which many identical machines share (a
	 * cart of the same Chromebook, say). So a shared hash alone is not enough:
	 * we additionally require corroboration — a close coarse timing profile
	 * (per-unit-ish), or the same IP, or the same logged-in account. Uncorrobo-
	 * rated same-hash visitors are left separate. Returns the canonical uid.
	 *
	 * @param string $uid     Current request's fingerprint uid.
	 * @param string $hash    64-char device hash.
	 * @param array  $timing  Current timing vector (may be empty).
	 * @param string $ip      Current full client IP.
	 * @param int    $user_id Current logged-in user id (0 if none).
	 * @return string Canonical uid to attach data to.
	 */
	public static function merge_by_device( $uid, $hash, $timing = array(), $ip = '', $user_id = 0 ) {
		$uid  = self::sanitize( $uid );
		$hash = is_string( $hash ) && preg_match( '/^[a-f0-9]{64}$/', $hash ) ? $hash : '';
		if ( '' === $uid || '' === $hash || ! self::has_column( 'visitors', 'device_hash' ) ) {
			return $uid;
		}
		global $wpdb;
		$v = Schema::table( 'visitors' );

		// Same-hash rows (besides the current uid), with the fields we corroborate on.
		$others = $wpdb->get_results( $wpdb->prepare( "SELECT uid, last_ip, user_id, device_info FROM {$v} WHERE device_hash = %s AND uid <> %s", $hash, $uid ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( empty( $others ) ) {
			return $uid; // First device with this hash — nothing to merge.
		}

		$user_id = (int) $user_id;
		$corrob  = array();
		foreach ( $others as $row ) {
			$ok = false;
			if ( $timing ) {
				$info = ! empty( $row['device_info'] ) ? json_decode( $row['device_info'], true ) : array();
				if ( is_array( $info ) && ! empty( $info['timing'] ) && self::timing_close( $timing, $info['timing'] ) ) {
					$ok = true;
				}
			}
			if ( ! $ok && '' !== $ip && ! empty( $row['last_ip'] ) && $row['last_ip'] === $ip ) {
				$ok = true;
			}
			if ( ! $ok && $user_id > 0 && (int) $row['user_id'] === $user_id ) {
				$ok = true;
			}
			if ( $ok ) {
				$corrob[] = $row['uid'];
			}
		}

		if ( empty( $corrob ) ) {
			// Same model, but not confirmed the same physical unit — keep separate.
			return $uid;
		}

		$group        = array_merge( array( $uid ), $corrob );
		$placeholders = implode( ',', array_fill( 0, count( $group ), '%s' ) );
		$canonical    = $wpdb->get_var( $wpdb->prepare( "SELECT uid FROM {$v} WHERE uid IN ($placeholders) ORDER BY first_seen ASC LIMIT 1", $group ) ); // phpcs:ignore WordPress.DB
		if ( ! $canonical ) {
			$canonical = $uid;
		}
		foreach ( $group as $u ) {
			if ( $u !== $canonical ) {
				self::merge_rows( $u, $canonical );
			}
		}
		return $canonical;
	}

	/**
	 * Are two coarse timing vectors close enough to be the same physical GPU?
	 * Compares the SHAPE (each component's share of the total) so overall
	 * machine speed / thermal state doesn't dominate. Noisy by nature.
	 *
	 * @param array $a Vector A.
	 * @param array $b Vector B.
	 * @return bool
	 */
	private static function timing_close( $a, $b ) {
		$a = array_values( array_map( 'floatval', (array) $a ) );
		$b = array_values( array_map( 'floatval', (array) $b ) );
		$n = count( $a );
		if ( $n < 2 || $n !== count( $b ) ) {
			return false;
		}
		$sa = array_sum( $a );
		$sb = array_sum( $b );
		if ( $sa <= 0 || $sb <= 0 ) {
			return false;
		}
		$diff = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$diff += abs( ( $a[ $i ] / $sa ) - ( $b[ $i ] / $sb ) );
		}
		return ( $diff / $n ) < self::TIMING_THRESHOLD;
	}

	/**
	 * Fold one visitor row into another: repoint its entries + sessions, backfill
	 * any blank fields on the target, widen the seen-window, then delete the
	 * source row.
	 *
	 * @param string $from Source uid (removed).
	 * @param string $into Target uid (kept).
	 */
	private static function merge_rows( $from, $into ) {
		global $wpdb;
		$v  = Schema::table( 'visitors' );
		$e  = Schema::table( 'entries' );
		$se = Schema::table( 'sessions' );

		if ( self::has_column_generic( $e, 'visitor_uid' ) ) {
			$wpdb->update( $e, array( 'visitor_uid' => $into ), array( 'visitor_uid' => $from ) ); // phpcs:ignore WordPress.DB
		}
		if ( self::has_column( 'sessions', 'visitor_uid' ) ) {
			$wpdb->update( $se, array( 'visitor_uid' => $into ), array( 'visitor_uid' => $from ) ); // phpcs:ignore WordPress.DB
		}

		$f = self::get( $from );
		$t = self::get( $into );
		if ( $f && $t ) {
			$upd = array();
			if ( empty( $t->name ) && ! empty( $f->name ) ) {
				$upd['name'] = $f->name;
			}
			if ( empty( $t->notes ) && ! empty( $f->notes ) ) {
				$upd['notes'] = $f->notes;
			}
			if ( isset( $t->user_id ) && empty( $t->user_id ) && ! empty( $f->user_id ) ) {
				$upd['user_id'] = (int) $f->user_id;
			}
			if ( isset( $t->last_ip ) && empty( $t->last_ip ) && ! empty( $f->last_ip ) ) {
				$upd['last_ip'] = $f->last_ip;
			}
			if ( isset( $t->device_info ) && empty( $t->device_info ) && ! empty( $f->device_info ) ) {
				$upd['device_info'] = $f->device_info;
			}
			if ( ! empty( $f->first_seen ) && ( empty( $t->first_seen ) || $f->first_seen < $t->first_seen ) ) {
				$upd['first_seen'] = $f->first_seen;
			}
			if ( ! empty( $f->last_seen ) && $f->last_seen > $t->last_seen ) {
				$upd['last_seen'] = $f->last_seen;
			}
			if ( $upd ) {
				$wpdb->update( $v, $upd, array( 'uid' => $into ) ); // phpcs:ignore WordPress.DB
			}
		}
		$wpdb->delete( $v, array( 'uid' => $from ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Column check against a fully-qualified table name (for the entries table).
	 *
	 * @param string $table Full table name.
	 * @param string $col   Column.
	 * @return bool
	 */
	private static function has_column_generic( $table, $col ) {
		static $cache = array();
		if ( ! isset( $cache[ $table ] ) ) {
			global $wpdb;
			$found          = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB
			$cache[ $table ] = is_array( $found ) ? array_map( 'strtolower', $found ) : array();
		}
		return in_array( strtolower( $col ), $cache[ $table ], true );
	}

	/**
	 * Rows for the satellite export: one per visitor that has a device hash,
	 * mapped to the Device Bridge export shape so an external "main" install can
	 * pull them.
	 *
	 * @return array[]
	 */
	public static function export_devices() {
		if ( ! self::has_column( 'visitors', 'device_hash' ) ) {
			return array();
		}
		global $wpdb;
		$v  = Schema::table( 'visitors' );
		$se = Schema::table( 'sessions' );

		$visit_select = self::has_column( 'sessions', 'visitor_uid' )
			? "( SELECT COUNT(*) FROM {$se} se WHERE se.visitor_uid = vv.uid )"
			: '0';

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT vv.uid, vv.name, vv.user_id, vv.device_hash, vv.device_info, vv.first_seen, vv.last_seen,
			        {$visit_select} AS visit_count
			 FROM {$v} vv
			 WHERE vv.device_hash IS NOT NULL AND vv.device_hash <> ''",
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$info = ! empty( $r['device_info'] ) ? json_decode( $r['device_info'], true ) : array();
			$info = is_array( $info ) ? $info : array();
			$rend = null;
			if ( isset( $info['rendererInfo'] ) ) {
				$rend = $info['rendererInfo'];
			} elseif ( ! empty( $info['renderer'] ) ) {
				$rend = array( 'renderer' => $info['renderer'], 'vendor' => isset( $info['vendor'] ) ? $info['vendor'] : '' );
			}
			$out[] = array(
				'device_hash'   => $r['device_hash'],
				'wp_user_id'    => ! empty( $r['user_id'] ) ? (int) $r['user_id'] : null,
				'renderer_info' => $rend,
				'capabilities'  => $info ? $info : null,
				'gpu_score'     => isset( $info['benchmarkScore'] ) ? $info['benchmarkScore'] : null,
				'first_seen'    => $r['first_seen'],
				'last_seen'     => $r['last_seen'],
				'visit_count'   => (int) $r['visit_count'],
			);
		}
		return $out;
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
			'visit_count' => 'visit_count',
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
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$cols    = array( 'uid', 'name' );
			if ( self::has_column( 'visitors', 'last_ip' ) ) {
				$cols[] = 'last_ip';
			}
			if ( self::has_column( 'visitors', 'device_hash' ) ) {
				$cols[] = 'device_hash';
			}
			$clauses = array();
			foreach ( $cols as $c ) {
				$clauses[] = $c . ' LIKE %s';
				$params[]  = $like;
			}
			$where[] = '(' . implode( ' OR ', $clauses ) . ')';
		}
		$where_sql = implode( ' AND ', $where );

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['paged'] ) - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$v} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql ); // phpcs:ignore WordPress.DB

		// How many times this visitor has visited (their session count). Only
		// available once sessions carry the visitor fingerprint; else 0.
		$sess         = Schema::table( 'sessions' );
		$visit_select = self::has_column( 'sessions', 'visitor_uid' )
			? "( SELECT COUNT(*) FROM {$sess} se2 WHERE se2.visitor_uid = vv.uid )"
			: '0';
		if ( 'visit_count' === $orderby && '0' === $visit_select ) {
			$orderby = 'vv.last_seen';
		}

		// $orderby/$order come from a fixed whitelist above, never from raw input.
		$sql  = "SELECT vv.*, ( SELECT COUNT(*) FROM {$e} en WHERE en.visitor_uid = vv.uid AND en.status NOT IN ('spam','trashed') ) AS entry_count,
				 {$visit_select} AS visit_count
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
