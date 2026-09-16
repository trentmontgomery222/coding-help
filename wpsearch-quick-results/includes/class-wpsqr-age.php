<?php
/**
 * Filtering results by age.
 *
 * A school site accumulates news. "Winter Operations" from 2019 and the
 * current one are equally good matches for the same search, and the old one
 * is worse than useless — it is actively misleading, because nothing on it
 * says it is out of date.
 *
 * This is deliberately per post type and defaults to posts only. Pages do not
 * go stale the way news does: the Transportation page written in 2018 and
 * never touched since is still the Transportation page, and expiring it would
 * lose content nothing replaces.
 *
 * The default action is to demote rather than hide, because "old" is a guess
 * about relevance and a wrong guess that reorders is recoverable, while a
 * wrong guess that hides is not.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Age {

	const MODE_OFF    = 'off';
	const MODE_DEMOTE = 'demote';
	const MODE_HIDE   = 'hide';

	/**
	 * How many expired IDs to send to the browser.
	 *
	 * Only needed for pages SearchWP renders, where the markup carries no
	 * date and the browser has nothing else to go on. Past this the list
	 * stops being worth its weight in the page; server-rendered results are
	 * unaffected either way.
	 */
	const BROWSER_CAP = 1500;

	public function hooks() {
		foreach ( array( 'save_post', 'deleted_post' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}

		// The cutoff moves on its own, so yesterday's list is wrong today.
		add_action( 'wpsqr_sweep_cache', array( __CLASS__, 'flush' ) );
	}

	public static function mode() {
		$mode = WPSQR_Plugin::settings()['age_mode'];

		return in_array( $mode, array( self::MODE_OFF, self::MODE_DEMOTE, self::MODE_HIDE ), true )
			? $mode
			: self::MODE_OFF;
	}

	public static function is_active() {
		return self::MODE_OFF !== self::mode() && self::days() > 0 && self::post_types();
	}

	public static function days() {
		return max( 0, (int) WPSQR_Plugin::settings()['age_days'] );
	}

	public static function post_types() {
		return array_values( array_filter( (array) WPSQR_Plugin::settings()['age_types'] ) );
	}

	/** The date a post has to beat, as a MySQL datetime. */
	public static function cutoff() {
		$days = self::days();

		if ( $days < 1 ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( $days * DAY_IN_SECONDS ) );
	}

	/** Is this post past the cutoff? */
	public static function is_expired( $post_id ) {
		if ( ! self::is_active() ) {
			return false;
		}

		$post = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return false;
		}

		$cutoff = self::cutoff();

		if ( '' === $cutoff ) {
			return false;
		}

		// post_modified, not post_date: a 2019 news post edited last month has
		// been looked at recently, and treating it as stale ignores the one
		// signal anybody actually gave about it.
		$stamp = $post->post_modified > $post->post_date ? $post->post_modified : $post->post_date;

		return $stamp < $cutoff;
	}

	/**
	 * Expired post IDs, for the browser.
	 *
	 * @return array{ids:int[],capped:bool,total:int}
	 */
	public static function expired_ids() {
		if ( ! self::is_active() ) {
			return array( 'ids' => array(), 'capped' => false, 'total' => 0 );
		}

		$key    = 'wpsqr_expired_' . md5( self::cutoff() . '|' . implode( ',', self::post_types() ) );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$types = self::post_types();
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		$params = array_merge( $types, array( self::cutoff(), self::cutoff(), self::BROWSER_CAP + 1 ) );

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_status = 'publish'
				   AND post_type IN ({$in})
				   AND post_date < %s
				   AND post_modified < %s
				 ORDER BY post_date DESC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$params
			)
		);

		$ids    = array_map( 'intval', (array) $ids );
		$capped = count( $ids ) > self::BROWSER_CAP;

		$result = array(
			'ids'    => $capped ? array_slice( $ids, 0, self::BROWSER_CAP ) : $ids,
			'capped' => $capped,
			'total'  => count( $ids ),
		);

		set_transient( $key, $result, HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Apply the age rule to a list of results.
	 *
	 * Demoting keeps the relative order within each group, so an old result
	 * still ranks against other old results rather than being shuffled.
	 *
	 * @param int[] $post_ids
	 * @param int[] $protected Results an explicit Keep rule has exempted.
	 * @return int[]
	 */
	public static function apply( $post_ids, $protected = array() ) {
		if ( ! self::is_active() ) {
			return $post_ids;
		}

		$mode     = self::mode();
		$exempt   = array_flip( array_map( 'intval', $protected ) );
		$current  = array();
		$old      = array();

		foreach ( $post_ids as $post_id ) {
			if ( isset( $exempt[ (int) $post_id ] ) || ! self::is_expired( $post_id ) ) {
				$current[] = $post_id;
				continue;
			}

			if ( self::MODE_DEMOTE === $mode ) {
				$old[] = $post_id;
			}
		}

		return array_merge( $current, $old );
	}

	/** The rule handed to the browser, for results this plugin does not render. */
	public static function browser_rules() {
		if ( ! self::is_active() ) {
			return array();
		}

		$expired = self::expired_ids();

		if ( ! $expired['ids'] ) {
			return array();
		}

		return array(
			array(
				'when'  => 'id',
				'op'    => 'in',
				'value' => array_map( 'strval', $expired['ids'] ),
				'then'  => self::MODE_HIDE === self::mode() ? 'hide' : 'bottom',
			),
		);
	}

	public static function flush() {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_wpsqr_expired_%'
			    OR option_name LIKE '_transient_timeout_wpsqr_expired_%'"
		);
	}
}
