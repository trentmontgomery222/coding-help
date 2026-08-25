<?php
/**
 * Nightly automatic usage scan via WP-Cron.
 *
 * Runs the same scanner the "Scan now" button uses, but unattended. Each cron
 * tick works for a bounded number of seconds and, if the scan isn't finished,
 * schedules a one-off continuation a minute later — so even a very large library
 * completes across several ticks without ever timing out a request.
 *
 * @package ACPS_Media_Cleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACPS_MC_Cron {

	const DAILY_HOOK    = 'acps_mc_daily_scan';
	const CONTINUE_HOOK = 'acps_mc_continue_scan';
	const LOCK          = 'acps_mc_cron_lock';

	public function __construct() {
		add_action( self::DAILY_HOOK, array( $this, 'daily_run' ) );
		add_action( self::CONTINUE_HOOK, array( $this, 'continue_run' ) );
		add_action( 'init', array( $this, 'sync_schedule' ) );
	}

	/**
	 * The next 02:00 in the site's timezone, as a unix timestamp.
	 *
	 * @return int
	 */
	public static function next_2am() {
		try {
			$tz     = wp_timezone();
			$now    = new DateTime( 'now', $tz );
			$target = new DateTime( 'today 02:00', $tz );
			if ( $target <= $now ) {
				$target->modify( '+1 day' );
			}
			return $target->getTimestamp();
		} catch ( Exception $e ) {
			return time() + DAY_IN_SECONDS;
		}
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( self::next_2am(), 'daily', self::DAILY_HOOK );
		}
	}

	public static function unschedule() {
		$ts = wp_next_scheduled( self::DAILY_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::DAILY_HOOK );
		}
		wp_clear_scheduled_hook( self::DAILY_HOOK );
	}

	/**
	 * Keep the schedule in sync with the setting.
	 */
	public function sync_schedule() {
		$on = (bool) ACPS_MC_Settings::get( 'auto_nightly_scan' );
		if ( $on ) {
			self::schedule();
		} elseif ( wp_next_scheduled( self::DAILY_HOOK ) ) {
			self::unschedule();
		}
	}

	public function daily_run() {
		if ( ! ACPS_MC_Settings::get( 'auto_nightly_scan' ) ) {
			return;
		}
		$this->process( true );
	}

	public function continue_run() {
		$this->process( false );
	}

	/**
	 * Run the scan for a bounded slice of time.
	 *
	 * @param bool $fresh Start a new scan if none is in progress.
	 */
	protected function process( $fresh ) {
		if ( get_transient( self::LOCK ) ) {
			return; // Another tick is already working.
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		$scanner = new ACPS_MC_Scanner();
		$point   = $scanner->resume_point();
		if ( $point ) {
			$step   = $point['step'];
			$offset = (int) $point['offset'];
		} elseif ( $fresh ) {
			$scanner->start();
			$step   = ACPS_MC_Scanner::STEPS[0];
			$offset = 0;
		} else {
			delete_transient( self::LOCK );
			return; // Nothing to continue.
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$deadline = time() + 45;
		$done     = false;
		do {
			$r = $scanner->run_step( $step, $offset );
			if ( ! empty( $r['all_done'] ) ) {
				$done = true;
				break;
			}
			$step   = $r['next_step'];
			$offset = (int) $r['next_offset'];
		} while ( time() < $deadline );

		delete_transient( self::LOCK );

		if ( ! $done ) {
			wp_schedule_single_event( time() + 60, self::CONTINUE_HOOK );
		}
	}
}
