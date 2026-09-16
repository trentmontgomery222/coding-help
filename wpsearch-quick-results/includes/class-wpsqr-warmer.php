<?php
/**
 * Keep the popular searches warm.
 *
 * Without this, every cache flush hands the next visitor who searches
 * "staff" the full uncached cost. With it, that cost is paid by cron in the
 * background and visitors mostly never see it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Warmer {

	const HOOK   = 'wpsqr_warm_cache';
	const SWEEP  = 'wpsqr_sweep_cache';
	const REFILL = 'wpsqr_refill_cache';

	/**
	 * How long a refill waits after the cache is emptied.
	 *
	 * Long enough to coalesce: saving fifteen pages in a row, or running an
	 * import, empties the cache fifteen times, and re-warming after each one
	 * would mean re-running the top searches fifteen times for nothing.
	 */
	const REFILL_DELAY = 60;

	/**
	 * But never defer past this, however long the saving goes on.
	 *
	 * Without a cap, a long import would keep pushing the refill out and the
	 * cache would stay cold for the whole of it — exactly when the site is
	 * least able to afford uncached searches.
	 */
	const REFILL_MAX_DEFER = 300;

	public function hooks() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::REFILL, array( __CLASS__, 'run_refill' ) );
		add_action( self::SWEEP, array( 'WPSQR_Cache', 'sweep' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval

		// A deploy or a settings change can leave the events missing.
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule();
		}
	}

	public static function add_schedule( $schedules ) {
		if ( ! isset( $schedules['wpsqr_15min'] ) ) {
			$schedules['wpsqr_15min'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 minutes (WPSearch Quick Results)', 'wpsqr' ),
			);
		}

		return $schedules;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'wpsqr_15min', self::HOOK );
		}

		if ( ! wp_next_scheduled( self::SWEEP ) ) {
			wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), 'hourly', self::SWEEP );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::SWEEP );
		wp_clear_scheduled_hook( self::REFILL );
	}

	/**
	 * Queue a refill because the cache was just emptied.
	 *
	 * Debounced rather than throttled: each flush pushes the run back, so a
	 * burst of saves produces one refill after the burst rather than one per
	 * save. The cap above stops a long import deferring it indefinitely.
	 */
	public static function schedule_refill() {
		$settings = WPSQR_Plugin::settings();

		if ( empty( $settings['warm_enabled'] ) || empty( $settings['warm_on_flush'] ) ) {
			return;
		}

		$now     = time();
		$pending = wp_next_scheduled( self::REFILL );
		$first   = (int) get_option( 'wpsqr_refill_since', 0 );

		if ( ! $pending ) {
			// Nothing queued: start a fresh debounce window.
			update_option( 'wpsqr_refill_since', $now, false );
			wp_schedule_single_event( $now + self::REFILL_DELAY, self::REFILL );
			return;
		}

		// Already waiting. Push it back unless it has waited long enough.
		if ( $first && ( $now - $first ) >= self::REFILL_MAX_DEFER ) {
			return;
		}

		wp_unschedule_event( $pending, self::REFILL );
		wp_schedule_single_event( $now + self::REFILL_DELAY, self::REFILL );
	}

	/** The refill itself. */
	public static function run_refill() {
		delete_option( 'wpsqr_refill_since' );

		self::run( null, 'refill' );
	}

	/**
	 * Re-run the top terms that aren't currently cached.
	 *
	 * @param int|null $limit Override the configured number of terms.
	 * @return array{warmed:int,skipped:int,terms:string[]}
	 */
	public static function run( $limit = null, $trigger = 'cron' ) {
		$settings = WPSQR_Plugin::settings();

		if ( empty( $settings['warm_enabled'] ) ) {
			return array( 'warmed' => 0, 'skipped' => 0, 'terms' => array() );
		}

		$limit = null === $limit ? (int) $settings['warm_count'] : (int) $limit;
		$terms = WPSQR_Stats::popular( $limit, 30 );

		$warmed  = 0;
		$skipped = 0;
		$done    = array();

		// Warming runs as nobody in particular, so it fills the visitor
		// bucket — the one that matters. An editor's first search after a
		// flush is uncached, which is the right trade.
		foreach ( $terms as $row ) {
			$term = $row['display'] ? $row['display'] : $row['term'];

			$key = WPSQR_Normalizer::key(
				$term,
				array(
					'page'     => 1,
					'per_page' => (int) $settings['per_page'],
					'viewer'   => 'visitor',
				)
			);

			if ( null !== WPSQR_Cache::get( $key ) ) {
				$skipped++;
				continue;
			}

			WPSQR_Engine::search( $term, array( 'page' => 1 ) );

			$warmed++;
			$done[] = $term;

			// Cron runs on a real request; don't hold it open indefinitely.
			if ( $warmed >= 25 ) {
				break;
			}
		}

		update_option(
			'wpsqr_last_warm',
			array(
				'time'    => current_time( 'mysql' ),
				'warmed'  => $warmed,
				'skipped' => $skipped,
				'trigger' => $trigger,
			),
			false
		);

		return array( 'warmed' => $warmed, 'skipped' => $skipped, 'terms' => $done );
	}
}
