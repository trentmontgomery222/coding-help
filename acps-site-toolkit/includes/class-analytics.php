<?php
/**
 * Analytics queries (spec §6). All first-party, derived from the sessions and
 * visits tables. No external service.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analytics.
 */
class Analytics {

	/**
	 * Per-page metrics for the top pages (spec §6.1), joined with feedback
	 * counts to produce the feedback/traffic overlay (spec §6.4) — the default
	 * sort.
	 *
	 * @param array $args date_from, date_to, limit.
	 * @return array[] Each row: post_id, title, views, sessions, avg_time,
	 *                 entries (starts), exits, feedback_count, overlay_score.
	 */
	public static function top_pages( $args = array() ) {
		global $wpdb;
		$visits = Schema::table( 'visits' );

		$args   = wp_parse_args( $args, array( 'limit' => 50, 'date_from' => '', 'date_to' => '' ) );
		$limit  = max( 1, min( 500, (int) $args['limit'] ) );

		list( $date_sql, $date_params ) = self::date_clause( $args, 'visited_at' );

		$sql = "SELECT post_id,
					MAX(title) AS title,
					COUNT(*) AS views,
					COUNT(DISTINCT session_id) AS sessions,
					AVG(time_on_page) AS avg_time
				FROM {$visits}
				WHERE post_id IS NOT NULL {$date_sql}
				GROUP BY post_id
				ORDER BY views DESC
				LIMIT %d";

		$params = array_merge( $date_params, array( $limit ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$rows   = $rows ?: array();

		$entries = self::entry_exit_counts( $args );
		$feedback = self::feedback_counts_by_page();

		foreach ( $rows as &$r ) {
			$pid            = (int) $r['post_id'];
			$r['views']     = (int) $r['views'];
			$r['sessions']  = (int) $r['sessions'];
			$r['avg_time']  = round( (float) $r['avg_time'], 1 );
			$r['entries']   = isset( $entries['entry'][ $pid ] ) ? $entries['entry'][ $pid ] : 0;
			$r['exits']     = isset( $entries['exit'][ $pid ] ) ? $entries['exit'][ $pid ] : 0;
			$r['feedback_count'] = isset( $feedback[ $pid ] ) ? $feedback[ $pid ] : 0;
			// Overlay score: traffic × feedback density — surfaces high-traffic,
			// high-complaint pages to the top (spec §6.4).
			$r['overlay_score'] = $r['feedback_count'] * ( 1 + log( max( 1, $r['views'] ) ) );
			if ( ! $r['title'] ) {
				$r['title'] = get_the_title( $pid ) ?: ( '#' . $pid );
			}
		}
		unset( $r );

		// Default sort: the overlay (spec §6.4).
		usort(
			$rows,
			function ( $a, $b ) {
				if ( $b['overlay_score'] === $a['overlay_score'] ) {
					return $b['views'] <=> $a['views'];
				}
				return $b['overlay_score'] <=> $a['overlay_score'];
			}
		);

		return $rows;
	}

	/**
	 * Entry (session starts) and exit (session ends) counts per page.
	 *
	 * @param array $args Date args.
	 * @return array [ 'entry' => map, 'exit' => map ]
	 */
	public static function entry_exit_counts( $args = array() ) {
		global $wpdb;
		$visits = Schema::table( 'visits' );

		// Entry = seq_index 1. Exit = the max seq_index per session.
		$entry_rows = $wpdb->get_results( "SELECT post_id, COUNT(*) AS c FROM {$visits} WHERE seq_index = 1 AND post_id IS NOT NULL GROUP BY post_id", ARRAY_A ); // phpcs:ignore WordPress.DB
		$exit_rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT v.post_id, COUNT(*) AS c
			 FROM {$visits} v
			 INNER JOIN (SELECT session_id, MAX(seq_index) AS mx FROM {$visits} GROUP BY session_id) m
				 ON v.session_id = m.session_id AND v.seq_index = m.mx
			 WHERE v.post_id IS NOT NULL
			 GROUP BY v.post_id",
			ARRAY_A
		);

		$entry = array();
		foreach ( $entry_rows ?: array() as $r ) {
			$entry[ (int) $r['post_id'] ] = (int) $r['c'];
		}
		$exit = array();
		foreach ( $exit_rows ?: array() as $r ) {
			$exit[ (int) $r['post_id'] ] = (int) $r['c'];
		}
		return array( 'entry' => $entry, 'exit' => $exit );
	}

	/**
	 * Path analysis for a page: came-from and went-to (spec §6.2).
	 *
	 * @param int $post_id Page id.
	 * @param int $limit   Rows each direction.
	 * @return array [ 'from' => [ [post_id,title,count] ], 'to' => [...] ]
	 */
	public static function path_analysis( $post_id, $limit = 10 ) {
		global $wpdb;
		$visits  = Schema::table( 'visits' );
		$post_id = absint( $post_id );
		$limit   = max( 1, min( 50, (int) $limit ) );

		// Came from: prev_post_id of visits to this page.
		$from = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT prev_post_id AS pid, COUNT(*) AS c
				 FROM {$visits}
				 WHERE post_id = %d AND prev_post_id IS NOT NULL
				 GROUP BY prev_post_id ORDER BY c DESC LIMIT %d",
				$post_id,
				$limit
			),
			ARRAY_A
		);

		// Went to: pages whose prev_post_id is this page.
		$to = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT post_id AS pid, COUNT(*) AS c
				 FROM {$visits}
				 WHERE prev_post_id = %d AND post_id IS NOT NULL
				 GROUP BY post_id ORDER BY c DESC LIMIT %d",
				$post_id,
				$limit
			),
			ARRAY_A
		);

		return array(
			'from' => self::label_path_rows( $from ),
			'to'   => self::label_path_rows( $to ),
		);
	}

	/**
	 * Dead ends: pages with a high exit-to-view ratio (spec §6.3).
	 *
	 * @param int $limit Rows.
	 * @return array[]
	 */
	public static function dead_ends( $limit = 10 ) {
		$pages = self::top_pages( array( 'limit' => 200 ) );
		$out   = array();
		foreach ( $pages as $p ) {
			if ( $p['views'] < 5 ) {
				continue;
			}
			$rate = $p['exits'] / max( 1, $p['sessions'] );
			if ( $rate >= 0.6 ) {
				$p['exit_rate'] = round( $rate * 100 );
				$out[]          = $p;
			}
		}
		usort( $out, function ( $a, $b ) { return $b['exit_rate'] <=> $a['exit_rate']; } );
		return array_slice( $out, 0, $limit );
	}

	/**
	 * The ordered page-title path for one session (spec §5.6 / notifications).
	 *
	 * @param int $session_id Session id.
	 * @return string[] Ordered titles.
	 */
	public static function session_path( $session_id ) {
		global $wpdb;
		$visits = Schema::table( 'visits' );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT title, url FROM {$visits} WHERE session_id = %d ORDER BY seq_index ASC", absint( $session_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out    = array();
		foreach ( $rows ?: array() as $r ) {
			$out[] = $r['title'] ? $r['title'] : $r['url'];
		}
		return $out;
	}

	/**
	 * Most common paths across the site (spec §6.5) — top from→to transitions.
	 *
	 * @param int $limit Rows.
	 * @return array[]
	 */
	public static function common_transitions( $limit = 15 ) {
		global $wpdb;
		$visits = Schema::table( 'visits' );
		$rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT prev_post_id AS from_id, post_id AS to_id, COUNT(*) AS c
				 FROM {$visits}
				 WHERE prev_post_id IS NOT NULL AND post_id IS NOT NULL
				 GROUP BY prev_post_id, post_id ORDER BY c DESC LIMIT %d",
				max( 1, (int) $limit )
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows ?: array() as $r ) {
			$out[] = array(
				'from'  => get_the_title( (int) $r['from_id'] ) ?: ( '#' . $r['from_id'] ),
				'to'    => get_the_title( (int) $r['to_id'] ) ?: ( '#' . $r['to_id'] ),
				'count' => (int) $r['c'],
			);
		}
		return $out;
	}

	/**
	 * Views-over-time trend (spec §6.5).
	 *
	 * @param int $days Days back.
	 * @return array[] date => count.
	 */
	public static function trend( $days = 30 ) {
		global $wpdb;
		$visits = Schema::table( 'visits' );
		$days   = max( 1, min( 365, (int) $days ) );
		$rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT DATE(visited_at) AS d, COUNT(*) AS c
				 FROM {$visits}
				 WHERE visited_at >= %s
				 GROUP BY DATE(visited_at) ORDER BY d ASC",
				gmdate( 'Y-m-d 00:00:00', time() - $days * DAY_IN_SECONDS )
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	/**
	 * Tracking-request volume, derived from recorded page visits (each visit is
	 * one beacon request). Lets an admin gauge the load the plugin generates
	 * without adding any extra writes.
	 *
	 * @return array total, today, hour.
	 */
	public static function requests_summary() {
		global $wpdb;
		$v     = Schema::table( 'visits' );
		$today = current_time( 'Y-m-d' ) . ' 00:00:00';
		$hour  = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - HOUR_IN_SECONDS ); // phpcs:ignore WordPress.DateTime

		return array(
			'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$v}" ), // phpcs:ignore WordPress.DB
			'today' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$v} WHERE visited_at >= %s", $today ) ), // phpcs:ignore WordPress.DB
			'hour'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$v} WHERE visited_at >= %s", $hour ) ), // phpcs:ignore WordPress.DB
		);
	}

	/**
	 * Sessions active within the last N minutes, each with the page they are
	 * currently viewing (their latest visit). Powers the live "who's on the
	 * site now" view (spec-adjacent; keeps admins from editing pages in use).
	 *
	 * @param int $minutes Activity window.
	 * @return array[] Each: title, url, post_id, device, seconds_ago.
	 */
	public static function active_sessions( $minutes = 5 ) {
		global $wpdb;
		$sessions = Schema::table( 'sessions' );
		$visits   = Schema::table( 'visits' );

		$minutes = max( 1, min( 60, (int) $minutes ) );
		$cutoff  = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $minutes * MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.DateTime

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT id, device_type, last_activity_at FROM {$sessions} WHERE last_activity_at >= %s ORDER BY last_activity_at DESC LIMIT 200",
				$cutoff
			)
		);
		if ( ! $rows ) {
			return array();
		}

		$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime
		$out = array();
		foreach ( $rows as $s ) {
			$visit = $wpdb->get_row( // phpcs:ignore WordPress.DB
				$wpdb->prepare( "SELECT title, url, post_id FROM {$visits} WHERE session_id = %d ORDER BY seq_index DESC LIMIT 1", $s->id )
			);
			$out[] = array(
				'title'       => $visit ? ( $visit->title ? $visit->title : $visit->url ) : __( '(unknown page)', 'acps-site-toolkit' ),
				'url'         => $visit ? $visit->url : '',
				'post_id'     => $visit ? (int) $visit->post_id : 0,
				'device'      => $s->device_type ? $s->device_type : '—',
				'seconds_ago' => max( 0, $now - strtotime( $s->last_activity_at ) ),
			);
		}
		return $out;
	}

	/**
	 * Count of currently-active sessions.
	 *
	 * @param int $minutes Window.
	 * @return int
	 */
	public static function active_count( $minutes = 5 ) {
		global $wpdb;
		$sessions = Schema::table( 'sessions' );
		$minutes  = max( 1, min( 60, (int) $minutes ) );
		$cutoff   = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $minutes * MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.DateTime
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sessions} WHERE last_activity_at >= %s", $cutoff ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Aggregate active viewers grouped by page — “N people are on this page”.
	 *
	 * @param int $minutes Window.
	 * @return array[] Each: title, url, post_id, count.
	 */
	public static function active_pages( $minutes = 5 ) {
		$sessions = self::active_sessions( $minutes );
		$grouped  = array();
		foreach ( $sessions as $s ) {
			$key = $s['post_id'] ? 'p' . $s['post_id'] : 'u' . md5( $s['url'] );
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array( 'title' => $s['title'], 'url' => $s['url'], 'post_id' => $s['post_id'], 'count' => 0 );
			}
			$grouped[ $key ]['count']++;
		}
		usort( $grouped, function ( $a, $b ) { return $b['count'] <=> $a['count']; } );
		return array_values( $grouped );
	}

	/**
	 * Breakdown by device type: sessions, views, and average time on page.
	 *
	 * @return array[] Each: label, sessions, views, avg_time.
	 */
	public static function device_breakdown() {
		global $wpdb;
		$s = Schema::table( 'sessions' );
		$v = Schema::table( 'visits' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT s.device_type AS k,
				COUNT(DISTINCT s.id) AS sessions,
				COUNT(v.id) AS views,
				AVG(v.time_on_page) AS avg_time
			 FROM {$s} s LEFT JOIN {$v} v ON v.session_id = s.id
			 GROUP BY s.device_type
			 ORDER BY sessions DESC",
			ARRAY_A
		);

		$out = array();
		foreach ( $rows ?: array() as $r ) {
			$out[] = array(
				'label'    => $r['k'] ? ucfirst( $r['k'] ) : __( 'Unknown', 'acps-site-toolkit' ),
				'sessions' => (int) $r['sessions'],
				'views'    => (int) $r['views'],
				'avg_time' => round( (float) $r['avg_time'], 1 ),
			);
		}
		return $out;
	}

	/**
	 * Breakdown by browser and operating system, derived from the stored
	 * "Browser / OS" session summary. Returns two lists with sessions, views,
	 * and average time on page.
	 *
	 * @return array [ 'browsers' => array[], 'os' => array[] ]
	 */
	public static function ua_breakdown() {
		global $wpdb;
		$s = Schema::table( 'sessions' );
		$v = Schema::table( 'visits' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT s.user_agent_summary AS ua,
				COUNT(DISTINCT s.id) AS sessions,
				COUNT(v.id) AS views,
				SUM(v.time_on_page) AS sum_time,
				COUNT(v.time_on_page) AS t_count
			 FROM {$s} s LEFT JOIN {$v} v ON v.session_id = s.id
			 GROUP BY s.user_agent_summary",
			ARRAY_A
		);

		$browsers = array();
		$oses     = array();
		$accumulate = function ( &$bucket, $key, $r ) {
			if ( ! isset( $bucket[ $key ] ) ) {
				$bucket[ $key ] = array( 'label' => $key, 'sessions' => 0, 'views' => 0, 'sum_time' => 0, 't_count' => 0 );
			}
			$bucket[ $key ]['sessions'] += (int) $r['sessions'];
			$bucket[ $key ]['views']    += (int) $r['views'];
			$bucket[ $key ]['sum_time'] += (float) $r['sum_time'];
			$bucket[ $key ]['t_count']  += (int) $r['t_count'];
		};

		foreach ( $rows ?: array() as $r ) {
			$ua = trim( (string) $r['ua'] );
			if ( '' === $ua ) {
				$browser = __( 'Unknown', 'acps-site-toolkit' );
				$os      = __( 'Unknown', 'acps-site-toolkit' );
			} elseif ( false !== strpos( $ua, ' / ' ) ) {
				list( $browser, $os ) = array_pad( explode( ' / ', $ua, 2 ), 2, __( 'Other', 'acps-site-toolkit' ) );
			} else {
				// Full UA stored — best-effort browser sniff, OS unknown.
				$browser = 'Other';
				foreach ( array( 'Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari' ) as $needle => $name ) {
					if ( false !== strpos( $ua, $needle ) ) { $browser = $name; break; }
				}
				$os = __( 'Other', 'acps-site-toolkit' );
			}
			$accumulate( $browsers, $browser, $r );
			$accumulate( $oses, $os, $r );
		}

		$finish = function ( $bucket ) {
			$list = array();
			foreach ( $bucket as $b ) {
				$b['avg_time'] = $b['t_count'] > 0 ? round( $b['sum_time'] / $b['t_count'], 1 ) : 0;
				unset( $b['sum_time'], $b['t_count'] );
				$list[] = $b;
			}
			usort( $list, function ( $a, $c ) { return $c['sessions'] <=> $a['sessions']; } );
			return $list;
		};

		return array( 'browsers' => $finish( $browsers ), 'os' => $finish( $oses ) );
	}

	/**
	 * Feedback counts per page (non-spam, non-trashed feedback entries).
	 *
	 * @return array Map post_id => count.
	 */
	public static function feedback_counts_by_page() {
		global $wpdb;
		$entries = Schema::table( 'entries' );
		$forms   = Schema::table( 'forms' );
		$rows    = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT e.page_id, COUNT(*) AS c
			 FROM {$entries} e
			 INNER JOIN {$forms} f ON e.form_id = f.id AND f.is_feedback = 1
			 WHERE e.status NOT IN ('spam','trashed')
			 GROUP BY e.page_id",
			ARRAY_A
		);
		$out = array();
		foreach ( $rows ?: array() as $r ) {
			$out[ (int) $r['page_id'] ] = (int) $r['c'];
		}
		return $out;
	}

	/**
	 * Resolve path rows to include titles.
	 */
	private static function label_path_rows( $rows ) {
		$out = array();
		foreach ( $rows ?: array() as $r ) {
			$pid   = (int) $r['pid'];
			$out[] = array(
				'post_id' => $pid,
				'title'   => get_the_title( $pid ) ?: ( '#' . $pid ),
				'count'   => (int) $r['c'],
			);
		}
		return $out;
	}

	/**
	 * Build a date WHERE fragment + params.
	 */
	private static function date_clause( $args, $column ) {
		$sql    = '';
		$params = array();
		if ( ! empty( $args['date_from'] ) ) {
			$sql     .= " AND {$column} >= %s";
			$params[] = $args['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $args['date_to'] ) ) {
			$sql     .= " AND {$column} <= %s";
			$params[] = $args['date_to'] . ' 23:59:59';
		}
		return array( $sql, $params );
	}
}
