<?php
/**
 * What people actually search for.
 *
 * This is the part that justifies the whole plugin. If the top 20 terms
 * account for most searches, caching them is a large win; if traffic is
 * evenly spread across thousands of unique terms, it isn't, and the Popular
 * Searches screen will say so plainly rather than letting you assume.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Stats {

	/**
	 * Record one search.
	 *
	 * @param string $normalized Normalized term (the cache key basis).
	 * @param string $display    What the visitor actually typed.
	 * @param int    $results    Number of results found.
	 * @param int    $ms         Time spent, 0 when served from cache.
	 * @param bool   $was_cached Whether this request hit the cache.
	 */
	public static function record( $normalized, $display, $results, $ms, $was_cached ) {
		if ( '' === $normalized ) {
			return;
		}

		global $wpdb;

		$table = WPSQR_Schema::terms_table();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$table} (term, display, searches, results, total_ms, uncached_hits, cached_hits, last_searched)
				 VALUES (%s, %s, 1, %d, %d, %d, %d, %s)
				 ON DUPLICATE KEY UPDATE
					searches = searches + 1,
					results = VALUES(results),
					total_ms = total_ms + VALUES(total_ms),
					uncached_hits = uncached_hits + VALUES(uncached_hits),
					cached_hits = cached_hits + VALUES(cached_hits),
					display = VALUES(display),
					last_searched = VALUES(last_searched)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				substr( $normalized, 0, 191 ),
				substr( (string) $display, 0, 191 ),
				(int) $results,
				$was_cached ? 0 : (int) $ms,
				$was_cached ? 0 : 1,
				$was_cached ? 1 : 0,
				current_time( 'mysql' )
			)
		);
	}

	/**
	 * The most searched terms.
	 *
	 * @param int $limit
	 * @param int $days Only count terms searched within this many days. 0 = all time.
	 */
	public static function popular( $limit = 25, $days = 30 ) {
		global $wpdb;

		$table = WPSQR_Schema::terms_table();
		$limit = max( 1, (int) $limit );

		if ( $days > 0 ) {
			$since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( $days * DAY_IN_SECONDS ) );

			return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE last_searched >= %s ORDER BY searches DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$since,
					$limit
				),
				ARRAY_A
			);
		}

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY searches DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Searches that returned nothing — the most useful report here.
	 * Every row is someone who wanted something and left empty-handed.
	 */
	public static function zero_result_terms( $limit = 25 ) {
		global $wpdb;

		$table = WPSQR_Schema::terms_table();

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE results = 0 ORDER BY searches DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				max( 1, (int) $limit )
			),
			ARRAY_A
		);
	}

	/** Headline numbers for the dashboard. */
	public static function summary() {
		global $wpdb;

		$table = WPSQR_Schema::terms_table();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT
				COUNT(*) AS unique_terms,
				COALESCE(SUM(searches),0) AS searches,
				COALESCE(SUM(cached_hits),0) AS cached,
				COALESCE(SUM(uncached_hits),0) AS uncached,
				COALESCE(SUM(total_ms),0) AS total_ms
			 FROM {$table}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$searches = isset( $row['searches'] ) ? (int) $row['searches'] : 0;
		$cached   = isset( $row['cached'] ) ? (int) $row['cached'] : 0;
		$uncached = isset( $row['uncached'] ) ? (int) $row['uncached'] : 0;
		$total_ms = isset( $row['total_ms'] ) ? (int) $row['total_ms'] : 0;

		return array(
			'unique_terms' => isset( $row['unique_terms'] ) ? (int) $row['unique_terms'] : 0,
			'searches'     => $searches,
			'cached'       => $cached,
			'uncached'     => $uncached,
			'hit_rate'     => $searches > 0 ? round( ( $cached / $searches ) * 100 ) : 0,
			'avg_uncached' => $uncached > 0 ? (int) round( $total_ms / $uncached ) : 0,
		);
	}

	public static function reset() {
		global $wpdb;

		$wpdb->query( 'TRUNCATE TABLE ' . WPSQR_Schema::terms_table() ); // phpcs:ignore
	}
}
