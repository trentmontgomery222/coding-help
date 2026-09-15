<?php
/**
 * The cache itself: store and retrieve the ordered result IDs for a search.
 *
 * Reads go through the object cache first, so on a site with Redis or
 * Memcached a warm search costs no database query at all. Without a
 * persistent object cache it is a single indexed lookup on a CHAR(64) unique
 * key, which is still far cheaper than scoring an index.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Cache {

	const GROUP = 'wpsqr';

	/**
	 * Object-cache key for an entry.
	 *
	 * Prefixed with a salt that changes on every flush. wp_cache_flush_group()
	 * only exists from WP 6.1 and only does anything if the installed object
	 * cache implements it — without the salt, a flush would clear the table
	 * while Redis carried on serving the old results.
	 */
	protected static function object_key( $cache_key ) {
		return self::salt() . ':' . $cache_key;
	}

	protected static function salt() {
		static $salt = null;

		if ( null === $salt ) {
			$salt = (string) get_option( 'wpsqr_cache_salt', '0' );
		}

		return $salt;
	}

	/**
	 * Look up a cached result set.
	 *
	 * @return array|null { post_ids: int[], total: int, build_ms: int } or null on a miss.
	 */
	public static function get( $cache_key ) {
		$cached = wp_cache_get( self::object_key( $cache_key ), self::GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT post_ids, total, build_ms, expires_at FROM ' . WPSQR_Schema::cache_table() . ' WHERE cache_key = %s LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cache_key
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		// Expired entries are left for the sweeper rather than deleted on a
		// read — a read should never turn into a write on a busy page.
		if ( strtotime( $row['expires_at'] ) < time() ) {
			return null;
		}

		$ids = json_decode( $row['post_ids'], true );
		if ( ! is_array( $ids ) ) {
			return null;
		}

		$result = array(
			'post_ids' => array_map( 'intval', $ids ),
			'total'    => (int) $row['total'],
			'build_ms' => (int) $row['build_ms'],
		);

		wp_cache_set( self::object_key( $cache_key ), $result, self::GROUP, self::ttl() );

		return $result;
	}

	/**
	 * Store a result set.
	 */
	public static function set( $cache_key, $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'term'     => '',
				'viewer'   => 'visitor',
				'page'     => 1,
				'post_ids' => array(),
				'total'    => 0,
				'build_ms' => 0,
			)
		);

		// Both timestamps are in site-local time, matching current_time(),
		// so the expiry comparison in get() compares like with like.
		$now     = current_time( 'mysql' );
		$expires = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + self::ttl() );

		$data = array(
			'cache_key'  => $cache_key,
			'term'       => substr( (string) $args['term'], 0, 191 ),
			'viewer'     => (string) $args['viewer'],
			'page'       => (int) $args['page'],
			'post_ids'   => wp_json_encode( array_map( 'intval', (array) $args['post_ids'] ) ),
			'total'      => (int) $args['total'],
			'build_ms'   => (int) $args['build_ms'],
			'created_at' => $now,
			'expires_at' => $expires,
		);

		// REPLACE rather than INSERT: a warm-up and a live miss can race on
		// the same key, and the loser should overwrite rather than error.
		$wpdb->replace( WPSQR_Schema::cache_table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		wp_cache_set(
			self::object_key( $cache_key ),
			array(
				'post_ids' => array_map( 'intval', (array) $args['post_ids'] ),
				'total'    => (int) $args['total'],
				'build_ms' => (int) $args['build_ms'],
			),
			self::GROUP,
			self::ttl()
		);
	}

	public static function record_hit( $cache_key ) {
		global $wpdb;

		// Fire and forget. A lost increment under load is not worth a lock.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'UPDATE ' . WPSQR_Schema::cache_table() . ' SET hits = hits + 1 WHERE cache_key = %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cache_key
			)
		);
	}

	/** Seconds a cache entry stays valid. */
	public static function ttl() {
		$settings = WPSQR_Plugin::settings();

		return (int) apply_filters( 'wpsqr_cache_ttl', max( 60, (int) $settings['ttl'] ) );
	}

	/**
	 * Drop everything.
	 *
	 * Called whenever a post is saved. Coarse on purpose: working out which
	 * cached searches a given edit affects means re-running those searches,
	 * which costs exactly what the cache exists to avoid. Flushing and letting
	 * the warmer refill is cheaper and never serves a stale result.
	 */
	public static function flush() {
		global $wpdb;

		$wpdb->query( 'TRUNCATE TABLE ' . WPSQR_Schema::cache_table() ); // phpcs:ignore

		// Bumping the salt is what actually invalidates the object cache:
		// flush_group is 6.1+ and is a no-op on caches that don't implement it.
		update_option( 'wpsqr_cache_salt', (string) time(), false );

		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( self::GROUP );
		}
	}

	/** Remove expired rows. Runs on cron, not on a page view. */
	public static function sweep() {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'DELETE FROM ' . WPSQR_Schema::cache_table() . ' WHERE expires_at < %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql' )
			)
		);
	}

	public static function stats() {
		global $wpdb;

		$table = WPSQR_Schema::cache_table();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT COUNT(*) AS entries, COALESCE(SUM(hits),0) AS hits, COALESCE(AVG(build_ms),0) AS avg_build
			 FROM ' . $table, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array(
			'entries'   => isset( $row['entries'] ) ? (int) $row['entries'] : 0,
			'hits'      => isset( $row['hits'] ) ? (int) $row['hits'] : 0,
			'avg_build' => isset( $row['avg_build'] ) ? (int) round( $row['avg_build'] ) : 0,
		);
	}
}
