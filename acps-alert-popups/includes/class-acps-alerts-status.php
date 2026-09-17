<?php
/**
 * The status board: levels, the current status, the archive, and the daily
 * cut-off that takes entries down on their own.
 *
 * An alert and a status entry are the same post. The board shows the live one
 * as a banner and everything past as an archive list; the popup shows the same
 * entry to the whole site. One thing to write, two places it appears.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * Status board logic.
 */
class ACPS_Alerts_Status {

	/** Daily archive cron hook. */
	const CRON_HOOK = 'acps_alerts_daily_archive';

	/**
	 * The status levels, worst last.
	 *
	 * @return array
	 */
	public static function levels() {
		$levels = array(
			'normal'    => array(
				'label'    => __( 'Normal', 'acps-alert-popups' ),
				'banner'   => __( 'NORMAL', 'acps-alert-popups' ),
				'severity' => 'success',
				'rank'     => 0,
			),
			'advisory'  => array(
				'label'    => __( 'Advisory', 'acps-alert-popups' ),
				'banner'   => __( 'ADVISORY', 'acps-alert-popups' ),
				'severity' => 'info',
				'rank'     => 1,
			),
			'warning'   => array(
				'label'    => __( 'Warning', 'acps-alert-popups' ),
				'banner'   => __( 'WARNING', 'acps-alert-popups' ),
				'severity' => 'warning',
				'rank'     => 2,
			),
			'closure'   => array(
				'label'    => __( 'Closure', 'acps-alert-popups' ),
				'banner'   => __( 'CLOSED', 'acps-alert-popups' ),
				'severity' => 'critical',
				'rank'     => 3,
			),
			'emergency' => array(
				'label'    => __( 'Emergency', 'acps-alert-popups' ),
				'banner'   => __( 'EMERGENCY', 'acps-alert-popups' ),
				'severity' => 'critical',
				'rank'     => 4,
			),
		);

		/**
		 * Filters the status levels.
		 *
		 * @param array $levels Level definitions.
		 */
		return (array) apply_filters( 'acps_alerts_status_levels', $levels );
	}

	/**
	 * One level's definition, falling back to advisory.
	 *
	 * @param string $key Level key.
	 * @return array
	 */
	public static function level( $key ) {
		$levels = self::levels();

		return isset( $levels[ $key ] ) ? $levels[ $key ] : $levels['advisory'];
	}

	/**
	 * Choices for a select control.
	 *
	 * @return array key => label.
	 */
	public static function level_choices() {
		$choices = array();

		foreach ( self::levels() as $key => $level ) {
			$choices[ $key ] = $level['label'];
		}

		return $choices;
	}

	/* ------------------------------------------------------------------ *
	 * The daily cut-off.
	 * ------------------------------------------------------------------ */

	/**
	 * The configured cut-off, as an "H:i" string.
	 *
	 * @return string
	 */
	public static function cutoff_time() {
		$raw = (string) ACPS_Alerts_Settings::get( 'archive_time', '17:50' );

		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $raw ) ) {
			$raw = '17:50';
		}

		return $raw;
	}

	/**
	 * The moment an entry posted at $from should come down.
	 *
	 * The next cut-off strictly after it was posted — so something posted at
	 * 6pm runs until the following evening rather than vanishing immediately.
	 *
	 * @param int $from Unix timestamp the entry was posted.
	 * @return int Unix timestamp of the deadline.
	 */
	public static function deadline_for( $from ) {
		$from = (int) $from;

		if ( $from <= 0 ) {
			$from = time();
		}

		list( $hour, $minute ) = array_map( 'intval', explode( ':', self::cutoff_time() ) );

		// Work in site time, then convert the result back to a real timestamp.
		$offset   = (int) ( get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
		$local    = $from + $offset;
		$midnight = $local - ( $local % DAY_IN_SECONDS );
		$deadline = $midnight + $hour * HOUR_IN_SECONDS + $minute * MINUTE_IN_SECONDS;

		if ( $deadline <= $local ) {
			$deadline += DAY_IN_SECONDS;
		}

		return $deadline - $offset;
	}

	/**
	 * Whether an entry has passed its daily cut-off.
	 *
	 * Checked live on every request as well as by cron, because WordPress cron
	 * only fires when somebody visits the site — an entry must come down on
	 * time even if cron has not run.
	 *
	 * @param ACPS_Alerts_Alert $alert Alert.
	 * @return bool
	 */
	public static function past_cutoff( ACPS_Alerts_Alert $alert ) {
		if ( 'daily' !== $alert->get( 'expires_mode' ) ) {
			return false;
		}

		$posted = (int) $alert->get( 'posted_at' );

		if ( $posted <= 0 ) {
			$posted = (int) get_post_time( 'U', true, $alert->get_id() );
		}

		if ( $posted <= 0 ) {
			return false;
		}

		return time() >= self::deadline_for( $posted );
	}

	/**
	 * Whether an entry is still current: switched on, published, not archived,
	 * and not past its cut-off.
	 *
	 * @param ACPS_Alerts_Alert $alert Alert.
	 * @return bool
	 */
	public static function is_current( ACPS_Alerts_Alert $alert ) {
		if ( ! $alert->is_valid() || $alert->get( 'archived' ) ) {
			return false;
		}

		if ( ! $alert->get( 'enabled' ) || 'publish' !== get_post_status( $alert->get_id() ) ) {
			return false;
		}

		if ( self::past_cutoff( $alert ) ) {
			return false;
		}

		return ACPS_Alerts_Conditions::passes_schedule( $alert );
	}

	/* ------------------------------------------------------------------ *
	 * Reading the board.
	 * ------------------------------------------------------------------ */

	/**
	 * Every entry that is live right now, worst and highest priority first.
	 *
	 * @param bool $for_board Only entries flagged to show on the board.
	 * @return ACPS_Alerts_Alert[]
	 */
	public static function current_entries( $for_board = true ) {
		$entries = array();

		foreach ( ACPS_Alerts_Source::get_enabled_alerts() as $alert ) {
			if ( ! self::is_current( $alert ) ) {
				continue;
			}

			if ( $for_board && ! $alert->get( 'on_board' ) ) {
				continue;
			}

			if ( ! self::viewer_may_see( $alert ) ) {
				continue;
			}

			$entries[] = $alert;
		}

		usort(
			$entries,
			static function ( $a, $b ) {
				$rank = self::level( $b->get( 'status_level' ) )['rank'] - self::level( $a->get( 'status_level' ) )['rank'];

				if ( 0 !== $rank ) {
					return $rank;
				}

				return (int) $b->get( 'priority' ) - (int) $a->get( 'priority' );
			}
		);

		return $entries;
	}

	/**
	 * Past entries, newest first.
	 *
	 * @param int $limit How many.
	 * @return ACPS_Alerts_Alert[]
	 */
	public static function archive( $limit = 10 ) {
		$archive = array();

		foreach ( ACPS_Alerts_Source::get_popups() as $post ) {
			$alert = new ACPS_Alerts_Alert( $post );

			if ( ! $alert->is_valid() || ! $alert->get( 'on_board' ) ) {
				continue;
			}

			if ( 'publish' !== get_post_status( $post ) ) {
				continue;
			}

			// Anything no longer current belongs in the archive — whether it was
			// archived by hand, by the daily cut-off, or by its own end date.
			if ( self::is_current( $alert ) ) {
				continue;
			}

			// Staged entries never reach the archive for ordinary visitors.
			if ( ! self::viewer_may_see( $alert ) ) {
				continue;
			}

			$archive[] = $alert;
		}

		usort(
			$archive,
			static function ( $a, $b ) {
				return self::posted_time( $b ) - self::posted_time( $a );
			}
		);

		return array_slice( $archive, 0, max( 1, (int) $limit ) );
	}

	/**
	 * When an entry was posted, falling back to the post date.
	 *
	 * @param ACPS_Alerts_Alert $alert Alert.
	 * @return int
	 */
	public static function posted_time( ACPS_Alerts_Alert $alert ) {
		$posted = (int) $alert->get( 'posted_at' );

		if ( $posted > 0 ) {
			return $posted;
		}

		return (int) get_post_time( 'U', true, $alert->get_id() );
	}

	/* ------------------------------------------------------------------ *
	 * Visibility.
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the person looking at the page may see this entry at all.
	 *
	 * This sits in front of the audience rules and is the "admin only" switch:
	 * a staged entry is visible to people who can manage alerts, and to nobody
	 * else, so it can be checked on the real site before it goes out.
	 *
	 * @param ACPS_Alerts_Alert $alert Alert.
	 * @return bool
	 */
	public static function viewer_may_see( ACPS_Alerts_Alert $alert ) {
		$visibility = $alert->get( 'visibility' );

		if ( 'public' === $visibility ) {
			return true;
		}

		if ( 'admins' === $visibility ) {
			return self::viewer_is_staff();
		}

		// 'preview': only ever through the explicit preview link, which the
		// front end handles separately.
		return false;
	}

	/**
	 * Whether the current user may manage alerts, and so may see staged ones.
	 *
	 * @return bool
	 */
	public static function viewer_is_staff() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$capability = class_exists( 'ACPS_Alerts_Admin' ) ? ACPS_Alerts_Admin::capability() : 'edit_pages';

		/**
		 * Filters whether the current viewer counts as staff for staged entries.
		 *
		 * @param bool $is_staff Whether they may see admin-only entries.
		 */
		return (bool) apply_filters( 'acps_alerts_viewer_is_staff', current_user_can( $capability ) );
	}

	/* ------------------------------------------------------------------ *
	 * Posting and archiving.
	 * ------------------------------------------------------------------ */

	/**
	 * Creates a status entry.
	 *
	 * @param array $data {
	 *     @type string $title    Headline.
	 *     @type string $level    Status level key.
	 *     @type string $message  Plain text summary.
	 *     @type string $expires  daily | keep | custom.
	 *     @type bool   $as_popup Whether it also pops up site-wide.
	 *     @type string $visibility public | admins | preview.
	 *     @type array  $settings Extra alert settings to merge.
	 * }
	 * @return int The new post ID, or 0 on failure.
	 */
	public static function post_entry( array $data ) {
		$title = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';

		if ( '' === trim( $title ) ) {
			return 0;
		}

		$message = isset( $data['message'] ) ? wp_kses_post( $data['message'] ) : '';

		$post_id = wp_insert_post(
			array(
				'post_type'    => ACPS_Alerts_Post_Type::SLUG,
				'post_title'   => $title,
				'post_status'  => 'publish',
				'post_content' => $message,
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			ACPS_Alerts_Failsafe::record( 'status/post', is_wp_error( $post_id ) ? $post_id->get_error_message() : 'insert failed' );

			return 0;
		}

		$level = isset( $data['level'] ) ? sanitize_key( $data['level'] ) : 'advisory';

		$settings = array(
			'enabled'        => 1,
			'status_level'   => $level,
			'status_message' => wp_strip_all_tags( $message ),
			'severity'       => self::level( $level )['severity'],
			'on_board'       => 1,
			'as_popup'       => empty( $data['as_popup'] ) ? 0 : 1,
			'archived'       => 0,
			'posted_at'      => time(),
			'expires_mode'   => isset( $data['expires'] ) ? sanitize_key( $data['expires'] ) : 'daily',
			'visibility'     => isset( $data['visibility'] ) ? sanitize_key( $data['visibility'] ) : 'public',
		);

		if ( ! empty( $data['settings'] ) && is_array( $data['settings'] ) ) {
			$settings = array_merge( $settings, $data['settings'] );
		}

		$alert = new ACPS_Alerts_Alert( $post_id );
		$alert->save( array_merge( ACPS_Alerts_Alert::default_settings(), $settings ) );

		/**
		 * Fires after a status entry is posted.
		 *
		 * @param int   $post_id  The entry.
		 * @param array $settings Its settings.
		 */
		do_action( 'acps_alerts_status_posted', $post_id, $settings );

		return (int) $post_id;
	}

	/**
	 * Moves an entry to the archive.
	 *
	 * @param int $post_id Entry.
	 * @return void
	 */
	public static function archive_entry( $post_id ) {
		$alert = new ACPS_Alerts_Alert( $post_id );

		if ( ! $alert->is_valid() ) {
			return;
		}

		update_post_meta( $post_id, ACPS_Alerts_Alert::META_PREFIX . 'archived', 1 );

		do_action( 'acps_alerts_status_archived', (int) $post_id );
	}

	/**
	 * Brings an archived entry back.
	 *
	 * @param int $post_id Entry.
	 * @return void
	 */
	public static function restore_entry( $post_id ) {
		$alert = new ACPS_Alerts_Alert( $post_id );

		if ( ! $alert->is_valid() ) {
			return;
		}

		update_post_meta( $post_id, ACPS_Alerts_Alert::META_PREFIX . 'archived', 0 );

		// Restoring resets the clock, or a daily entry would archive itself
		// again on the next request.
		update_post_meta( $post_id, ACPS_Alerts_Alert::META_PREFIX . 'posted_at', time() );
	}

	/* ------------------------------------------------------------------ *
	 * The daily sweep.
	 * ------------------------------------------------------------------ */

	/**
	 * Hooks the cron up.
	 *
	 * @return void
	 */
	public function init() {
		ACPS_Alerts_Failsafe::action( self::CRON_HOOK, array( __CLASS__, 'run_daily_archive' ), 'status/cron' );
		ACPS_Alerts_Failsafe::action( 'init', array( __CLASS__, 'ensure_scheduled' ), 'status/schedule', 20 );
	}

	/**
	 * Makes sure the sweep is scheduled, and re-schedules it if the cut-off
	 * time has been changed.
	 *
	 * @return void
	 */
	public static function ensure_scheduled() {
		$next   = wp_next_scheduled( self::CRON_HOOK );
		$wanted = self::deadline_for( time() );

		// Allow a couple of minutes' drift before rescheduling, so this does not
		// churn on every page load.
		if ( $next && abs( $next - $wanted ) < 5 * MINUTE_IN_SECONDS ) {
			return;
		}

		if ( $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
		}

		wp_schedule_event( $wanted, 'daily', self::CRON_HOOK );
	}

	/**
	 * Clears the schedule. Deactivation only.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$next = wp_next_scheduled( self::CRON_HOOK );

		while ( $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
			$next = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Archives everything that has passed the cut-off.
	 *
	 * @return int How many entries were archived.
	 */
	public static function run_daily_archive() {
		$count = 0;

		foreach ( ACPS_Alerts_Source::get_popups() as $post ) {
			$alert = new ACPS_Alerts_Alert( $post );

			if ( ! $alert->is_valid() || $alert->get( 'archived' ) ) {
				continue;
			}

			if ( 'daily' !== $alert->get( 'expires_mode' ) ) {
				continue; // Kept on purpose, or on its own schedule.
			}

			if ( ! self::past_cutoff( $alert ) ) {
				continue;
			}

			self::archive_entry( $alert->get_id() );
			$count++;
		}

		return $count;
	}
}
