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
	 * The status levels, least urgent first.
	 *
	 * The five actions are the Standard Response Protocol from the "I Love U
	 * Guys" Foundation — the same vocabulary schools already train staff and
	 * students on, so the website says exactly what the drill says. The
	 * directives are the published SRP wording; check them against your own
	 * district's training materials before going live, and adjust with the
	 * acps_alerts_status_levels filter if your wording differs.
	 *
	 * Two non-SRP levels sit alongside them: Normal for business as usual, and
	 * Information for a notice that is not a response action at all (a phishing
	 * write-up, an open house). Marking those as SRP actions would water the
	 * protocol down, which is the opposite of the point.
	 *
	 * @return array
	 */
	public static function levels() {
		$levels = array(
			'normal'    => array(
				'label'     => __( 'Normal', 'acps-alert-popups' ),
				'banner'    => __( 'NORMAL', 'acps-alert-popups' ),
				'directive' => '',
				'color'     => '#1b2f5e',
				'severity'  => 'success',
				'icon'      => 'M4 12.5l5 5L20 6.5',
				'srp'       => false,
				'rank'      => 0,
			),
			'info'      => array(
				'label'     => __( 'Information', 'acps-alert-popups' ),
				'banner'    => __( 'INFORMATION', 'acps-alert-popups' ),
				'directive' => '',
				'color'     => '#1b2f5e',
				'severity'  => 'info',
				'icon'      => 'M12 7.5v.01M12 11v6.5',
				'srp'       => false,
				'rank'      => 1,
			),
			'bus'       => array(
				'label'     => __( 'Bus', 'acps-alert-popups' ),
				'banner'    => __( 'BUS', 'acps-alert-popups' ),
				'directive' => '',
				'color'     => '#c47f00',
				'severity'  => 'warning',
				'icon'      => 'M5 7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v8H5z M5 10h14 M6 16.5a1.5 1.5 0 1 0 3 0a1.5 1.5 0 1 0-3 0 M15 16.5a1.5 1.5 0 1 0 3 0a1.5 1.5 0 1 0-3 0',
				'srp'       => false,
				'rank'      => 1,
			),
			'hold'      => array(
				'label'     => __( 'Hold', 'acps-alert-popups' ),
				'banner'    => __( 'HOLD', 'acps-alert-popups' ),
				'directive' => __( 'In Your Classroom or Area', 'acps-alert-popups' ),
				'color'     => '#7a1c82',
				'severity'  => 'warning',
				'icon'      => 'M7 11.5V6a1.5 1.5 0 0 1 3 0v5M10 11V4.5a1.5 1.5 0 0 1 3 0V11M13 11.5V6.5a1.5 1.5 0 0 1 3 0V13M16 12.5v-1a1.5 1.5 0 0 1 3 0V15a6 6 0 0 1-6 6h-1.4a5 5 0 0 1-3.9-1.9l-3.2-4.1a1.6 1.6 0 0 1 2.4-2.1L7 14.4',
				'srp'       => true,
				'rank'      => 2,
			),
			'secure'    => array(
				'label'     => __( 'Secure', 'acps-alert-popups' ),
				'banner'    => __( 'SECURE', 'acps-alert-popups' ),
				'directive' => __( 'Get Inside. Lock Outside Doors', 'acps-alert-popups' ),
				'color'     => '#4f6fd4',
				'severity'  => 'warning',
				'icon'      => 'M12 3l7.5 3v5.2c0 4.6-3.1 8.5-7.5 10.3-4.4-1.8-7.5-5.7-7.5-10.3V6z',
				'srp'       => true,
				'rank'      => 3,
			),
			'shelter'   => array(
				'label'     => __( 'Shelter', 'acps-alert-popups' ),
				'banner'    => __( 'SHELTER', 'acps-alert-popups' ),
				'directive' => __( 'State Hazard &amp; Safety Strategy', 'acps-alert-popups' ),
				'color'     => '#e8762c',
				'severity'  => 'warning',
				'icon'      => 'M3 11.5L12 4l9 7.5M5.8 12.6V20h12.4v-7.4',
				'srp'       => true,
				'rank'      => 4,
			),
			'evacuate'  => array(
				'label'     => __( 'Evacuate', 'acps-alert-popups' ),
				'banner'    => __( 'EVACUATE', 'acps-alert-popups' ),
				'directive' => __( 'To a Location', 'acps-alert-popups' ),
				'color'     => '#1e8a3c',
				'severity'  => 'critical',
				'icon'      => 'M9.5 4h-5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h5M14 8.2l4 3.8-4 3.8M18 12H8.5',
				'srp'       => true,
				'rank'      => 5,
			),
			'lockdown'  => array(
				'label'     => __( 'Lockdown', 'acps-alert-popups' ),
				'banner'    => __( 'LOCKDOWN', 'acps-alert-popups' ),
				'directive' => __( 'Locks, Lights, Out of Sight', 'acps-alert-popups' ),
				'color'     => '#d81440',
				'severity'  => 'critical',
				'icon'      => 'M6.2 11h11.6a1 1 0 0 1 1 1v7.8a1 1 0 0 1-1 1H6.2a1 1 0 0 1-1-1V12a1 1 0 0 1 1-1zM8.4 11V7.4a3.6 3.6 0 0 1 7.2 0V11',
				'srp'       => true,
				'rank'      => 6,
			),

			/*
			 * Retired wording, kept so entries written before the move to SRP
			 * still render properly. Hidden from the picker by level_choices().
			 */
			'advisory'  => array(
				'label'     => __( 'Advisory (old)', 'acps-alert-popups' ),
				'banner'    => __( 'ADVISORY', 'acps-alert-popups' ),
				'directive' => '',
				'color'     => '#1b2f5e',
				'severity'  => 'info',
				'srp'       => false,
				'rank'      => 1,
				'legacy'    => true,
			),
			'warning'   => array(
				'label'     => __( 'Warning (old)', 'acps-alert-popups' ),
				'banner'    => __( 'WARNING', 'acps-alert-popups' ),
				'directive' => '',
				'color'     => '#8a6400',
				'severity'  => 'warning',
				'srp'       => false,
				'rank'      => 2,
				'legacy'    => true,
			),
			'closure'   => array(
				'label'     => __( 'Closure (old)', 'acps-alert-popups' ),
				'banner'    => __( 'CLOSED', 'acps-alert-popups' ),
				'directive' => '',
				'color'     => '#9b1c1f',
				'severity'  => 'critical',
				'srp'       => false,
				'rank'      => 5,
				'legacy'    => true,
			),
			'emergency' => array(
				'label'     => __( 'Emergency (old)', 'acps-alert-popups' ),
				'banner'    => __( 'EMERGENCY', 'acps-alert-popups' ),
				'directive' => '',
				'color'     => '#9b1c1f',
				'severity'  => 'critical',
				'srp'       => false,
				'rank'      => 6,
				'legacy'    => true,
			),
		);

		// Wording typed on the admin Wording screen wins over the built-in
		// words, so a district can say exactly what its drill says without a
		// developer. A blank banner falls back to the default (a banner must
		// never be empty); a blank directive is an intentional "no directive".
		$overrides = self::level_word_overrides();

		foreach ( $overrides as $key => $words ) {
			if ( ! isset( $levels[ $key ] ) ) {
				continue;
			}

			if ( isset( $words['banner'] ) && '' !== trim( (string) $words['banner'] ) ) {
				$levels[ $key ]['banner'] = (string) $words['banner'];
			}

			if ( array_key_exists( 'directive', $words ) ) {
				$levels[ $key ]['directive'] = (string) $words['directive'];
			}
		}

		/**
		 * Filters the status levels.
		 *
		 * Use this to match your district's own training wording, or to add a
		 * level. Each entry needs: label, banner, directive, color, severity
		 * (info|success|warning|critical), srp (bool) and rank (higher is more
		 * urgent, and wins the banner when several updates are live).
		 *
		 * A developer filter runs last, so it still has the final say over
		 * anything typed on the Wording screen.
		 *
		 * @param array $levels Level definitions.
		 */
		return (array) apply_filters( 'acps_alerts_status_levels', $levels );
	}

	/**
	 * Per-level word overrides typed on the admin Wording screen.
	 *
	 * @return array key => { banner, directive }
	 */
	public static function level_word_overrides() {
		$stored = get_option( 'acps_alerts_level_words', array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Every level key, including the retired ones.
	 *
	 * @return string[]
	 */
	public static function level_keys() {
		return array_keys( self::levels() );
	}

	/**
	 * One level's definition, falling back to advisory.
	 *
	 * @param string $key Level key.
	 * @return array
	 */
	public static function level( $key ) {
		$levels = self::levels();

		$level = isset( $levels[ $key ] ) ? $levels[ $key ] : ( isset( $levels['info'] ) ? $levels['info'] : reset( $levels ) );

		// Fill in anything a filtered level left out, so callers can read every
		// key without checking first.
		return array_merge(
			array(
				'label'     => '',
				'banner'    => '',
				'directive' => '',
				'color'     => '#1b2f5e',
				'severity'  => 'info',
				'icon'      => '',
				'srp'       => false,
				'rank'      => 0,
				'legacy'    => false,
			),
			(array) $level
		);
	}

	/**
	 * The badge for a status level: its glyph in a disc of its own colour.
	 *
	 * Drawn inline rather than loaded as an image so it cannot 404, cannot be
	 * blocked, and takes the level's colour without a second request. A level a
	 * site has filtered in without a glyph simply gets no badge.
	 *
	 * @param string $key  Level key.
	 * @param int    $size Disc size in pixels.
	 * @return string Markup, or an empty string when the level has no glyph.
	 */
	public static function level_icon( $key, $size = 64, $color = '' ) {
		$level = self::level( $key );
		$path  = isset( $level['icon'] ) ? (string) $level['icon'] : '';

		// Only ever emit path data that looks like path data, since it is
		// printed into an attribute unescaped-looking markup would break.
		if ( '' === $path || ! preg_match( '/^[0-9A-Za-z\s.,\-]+$/', $path ) ) {
			return '';
		}

		$color = self::colour( $color );

		if ( '' === $color ) {
			$color = self::level_color( $key );
		}

		$size  = max( 16, min( 160, (int) $size ) );
		$glyph = (int) round( $size * 0.55 );

		/*
		 * The whole box is described inline, and the SVG is given real width and
		 * height attributes rather than being left to CSS.
		 *
		 * An SVG with no dimensions falls back to 300x150, so a badge that loses
		 * its stylesheet does not come out slightly wrong — it comes out as a
		 * giant square block of colour across the page. The badge is printed on
		 * pages that may carry neither the board stylesheet nor a freshly built
		 * module stylesheet, so it has to stand up with no CSS at all.
		 */
		$style = sprintf(
			'display:inline-flex;align-items:center;justify-content:center;'
				. 'box-sizing:border-box;width:%1$dpx;height:%1$dpx;max-width:100%%;'
				. 'border-radius:50%%;background:%2$s;color:#fff;line-height:0;flex:0 0 auto;vertical-align:middle',
			$size,
			$color
		);

		return sprintf(
			'<span class="acps-level-icon acps-level-icon--%1$s" style="%2$s" aria-hidden="true">'
				. '<svg width="%3$d" height="%3$d" viewBox="0 0 24 24" focusable="false" style="display:block;width:%3$dpx;height:%3$dpx">'
				. '<path d="%4$s" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />'
				. '</svg>'
			. '</span>',
			esc_attr( sanitize_html_class( $key ) ),
			esc_attr( $style ),
			$glyph,
			esc_attr( $path )
		);
	}

	/**
	 * A colour, if that is what it is.
	 *
	 * Beaver Builder's colour fields store a bare hex with no #, WordPress
	 * stores one with, and either can hold an rgb/rgba string instead. Anything
	 * else is refused rather than escaped and hoped for, because these end up
	 * in style attributes.
	 *
	 * @param mixed $value Candidate colour.
	 * @return string A usable CSS colour, or an empty string.
	 */
	public static function colour( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^#[0-9a-f]{3,8}$/i', $value ) ) {
			return $value;
		}

		if ( preg_match( '/^[0-9a-f]{3,8}$/i', $value ) ) {
			return '#' . $value;
		}

		if ( preg_match( '/^rgba?\(\s*[0-9.]+\s*,\s*[0-9.]+\s*,\s*[0-9.]+\s*(?:,\s*[0-9.]+\s*)?\)$/i', $value ) ) {
			return $value;
		}

		return '';
	}

	/**
	 * The colour to draw a level in.
	 *
	 * The SRP colours are the ones staff are trained on, so they are the
	 * default everywhere. But a level's colour is chosen to read on white, and
	 * the same colour on a dark banner can be nearly invisible — so each place
	 * that draws a level may override it.
	 *
	 * @param string $key       Level key.
	 * @param string $context   Where it is being drawn: 'board', 'shortcode', 'popup'.
	 * @param array  $overrides Level key => colour, from whatever is drawing it.
	 * @return string
	 */
	public static function level_color( $key, $context = '', array $overrides = array() ) {
		$key   = sanitize_key( $key );
		$level = self::level( $key );
		$color = self::colour( isset( $level['color'] ) ? $level['color'] : '' );

		if ( isset( $overrides[ $key ] ) ) {
			$chosen = self::colour( $overrides[ $key ] );

			if ( '' !== $chosen ) {
				$color = $chosen;
			}
		}

		if ( '' === $color ) {
			$color = '#1b2f5e';
		}

		/**
		 * Filters the colour a status level is drawn in.
		 *
		 * @param string $color   The colour so far.
		 * @param string $key     Level key.
		 * @param string $context Where it is being drawn.
		 */
		$filtered = self::colour( apply_filters( 'acps_alerts_level_color', $color, $key, $context ) );

		return '' !== $filtered ? $filtered : $color;
	}

	/**
	 * Choices for a select control, retired wording left out.
	 *
	 * The SRP actions are grouped after the everyday ones, so the picker reads
	 * in the order someone reaches for them.
	 *
	 * @return array key => label.
	 */
	public static function level_choices() {
		$everyday = array();
		$srp      = array();

		foreach ( self::levels() as $key => $level ) {
			if ( ! empty( $level['legacy'] ) ) {
				continue;
			}

			$label = isset( $level['label'] ) ? $level['label'] : $key;

			if ( ! empty( $level['srp'] ) ) {
				$directive = isset( $level['directive'] ) ? wp_strip_all_tags( $level['directive'] ) : '';

				$srp[ $key ] = '' !== $directive
					/* translators: 1: SRP action, e.g. Lockdown. 2: its directive. */
					? sprintf( __( '%1$s — %2$s', 'acps-alert-popups' ), $label, $directive )
					: $label;

				continue;
			}

			$everyday[ $key ] = $label;
		}

		return $everyday + $srp;
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

		// Switched off in Settings → Features: nothing comes down by itself;
		// alerts stay up until someone takes them down.
		if ( ! ACPS_Alerts_Settings::feature( 'auto_archive' ) ) {
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
	 * The Current Alert: the one entry that is switched on, edited and archived.
	 *
	 * @return ACPS_Alerts_Alert|null
	 */
	public static function current_alert() {
		$id = ACPS_Alerts_Post_Type::get_alert( ACPS_Alerts_Post_Type::ROLE_CURRENT );

		if ( ! $id ) {
			return null;
		}

		$alert = new ACPS_Alerts_Alert( $id );

		return $alert->is_valid() ? $alert : null;
	}

	/**
	 * The Normal Alert: the resting state, shown when nothing is happening.
	 *
	 * @return ACPS_Alerts_Alert|null
	 */
	public static function normal_alert() {
		$id = ACPS_Alerts_Post_Type::get_alert( ACPS_Alerts_Post_Type::ROLE_NORMAL );

		if ( ! $id ) {
			return null;
		}

		$alert = new ACPS_Alerts_Alert( $id );

		return $alert->is_valid() ? $alert : null;
	}

	/**
	 * Whether the Current Alert is showing right now.
	 *
	 * @return bool
	 */
	public static function current_is_active() {
		$alert = self::current_alert();

		if ( ! $alert ) {
			return false;
		}

		return self::is_current( $alert ) && self::viewer_may_see( $alert );
	}

	/**
	 * The page the status board and the Current Alert live on.
	 *
	 * @return int Post ID, or 0 when the board has never been placed.
	 */
	public static function board_page() {
		return (int) get_option( 'acps_alerts_board_page', 0 );
	}

	/**
	 * Where to send somebody who wants to change the Current Alert.
	 *
	 * The Current Alert is edited on the status page and nowhere else, so every
	 * link that used to open an admin form points here instead. Falls back to
	 * the plain WordPress editor when Beaver Builder is not available, and to
	 * an empty string when the board has not been placed at all — callers are
	 * expected to check.
	 *
	 * @return string
	 */
	public static function board_edit_url() {
		$page_id = self::board_page();

		if ( ! $page_id || ! get_post_status( $page_id ) ) {
			return '';
		}

		if ( class_exists( 'ACPS_Alerts_Source' ) && method_exists( 'ACPS_Alerts_Source', 'builder_edit_url' ) ) {
			$url = (string) ACPS_Alerts_Source::builder_edit_url( $page_id );

			if ( '' !== $url ) {
				return $url;
			}
		}

		return (string) get_edit_post_link( $page_id, 'raw' );
	}

	/**
	 * The entry the board should show as its banner, or null for the resting
	 * state.
	 *
	 * @return ACPS_Alerts_Alert|null
	 */
	public static function board_entry() {
		return self::current_is_active() ? self::current_alert() : null;
	}

	/* ------------------------------------------------------------------ *
	 * The archive.
	 *
	 * Past updates are stored as records rather than posts. With only two
	 * alerts on the site, a year of closures cannot be a year of posts — and a
	 * record is all the board needs to list one.
	 * ------------------------------------------------------------------ */

	/** Option holding the archive records, oldest first. */
	const ARCHIVE_OPTION = 'acps_alerts_archive';

	/** How many records to keep. */
	const ARCHIVE_MAX = 200;

	/** How long a record is kept before it is dropped, in days. */
	const ARCHIVE_TTL_DAYS = 270;

	/**
	 * Drops records older than the retention window.
	 *
	 * @param array $records Archive records.
	 * @return array Records still within the window.
	 */
	protected static function within_ttl( array $records ) {
		$cutoff = time() - ( self::ARCHIVE_TTL_DAYS * DAY_IN_SECONDS );

		return array_values(
			array_filter(
				$records,
				static function ( $record ) use ( $cutoff ) {
					$date = isset( $record['date'] ) ? (int) $record['date'] : 0;

					return $date <= 0 || $date >= $cutoff;
				}
			)
		);
	}

	/**
	 * The archive, newest first.
	 *
	 * @param int $limit How many.
	 * @return array[] Each { id, title, level, message, date }.
	 */
	public static function archive( $limit = 10 ) {
		$records = get_option( self::ARCHIVE_OPTION, array() );

		if ( ! is_array( $records ) || empty( $records ) ) {
			return array();
		}

		// Records past the retention window are treated as gone even before the
		// next write prunes them from storage.
		$records = self::within_ttl( $records );

		if ( empty( $records ) ) {
			return array();
		}

		usort(
			$records,
			static function ( $a, $b ) {
				$a_date = isset( $a['date'] ) ? (int) $a['date'] : 0;
				$b_date = isset( $b['date'] ) ? (int) $b['date'] : 0;

				return $b_date - $a_date;
			}
		);

		return array_slice( $records, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Adds a record to the archive.
	 *
	 * @param array $data { title, level, message, date }.
	 * @return string The new record id, or '' when there was nothing to store.
	 */
	public static function add_archive_record( array $data ) {
		$title = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';

		if ( '' === trim( $title ) ) {
			return '';
		}

		$records = get_option( self::ARCHIVE_OPTION, array() );

		if ( ! is_array( $records ) ) {
			$records = array();
		}

		$date = 0;

		if ( ! empty( $data['date'] ) ) {
			$date = is_numeric( $data['date'] ) ? (int) $data['date'] : self::parse_date( $data['date'] );
		}

		if ( $date <= 0 ) {
			$date = time();
		}

		$level = isset( $data['level'] ) ? sanitize_key( $data['level'] ) : 'info';

		$record = array(
			'id'      => uniqid( 'acps', true ),
			'title'   => $title,
			'level'   => $level,
			'message' => isset( $data['message'] ) ? wp_strip_all_tags( (string) $data['message'] ) : '',
			'date'    => $date,
		);

		$records[] = $record;

		// Drop anything past the retention window on every write, so the store
		// never carries records older than the site is meant to keep.
		$records = self::within_ttl( $records );

		// Keep the newest, so a long-running site cannot grow this without end.
		if ( count( $records ) > self::ARCHIVE_MAX ) {
			usort(
				$records,
				static function ( $a, $b ) {
					return ( isset( $a['date'] ) ? (int) $a['date'] : 0 ) - ( isset( $b['date'] ) ? (int) $b['date'] : 0 );
				}
			);

			$records = array_slice( $records, -self::ARCHIVE_MAX );
		}

		update_option( self::ARCHIVE_OPTION, $records, false );

		/**
		 * Fires after an entry is written to the archive.
		 *
		 * @param array $record The stored record.
		 */
		do_action( 'acps_alerts_archived_record', $record );

		return $record['id'];
	}

	/**
	 * Removes one archive record.
	 *
	 * @param string $id Record id.
	 * @return bool
	 */
	public static function delete_archive_record( $id ) {
		$records = get_option( self::ARCHIVE_OPTION, array() );

		if ( ! is_array( $records ) ) {
			return false;
		}

		$before = count( $records );

		$records = array_values(
			array_filter(
				$records,
				static function ( $record ) use ( $id ) {
					return ! isset( $record['id'] ) || $record['id'] !== $id;
				}
			)
		);

		if ( count( $records ) === $before ) {
			return false;
		}

		update_option( self::ARCHIVE_OPTION, $records, false );

		return true;
	}

	/**
	 * Files the Current Alert in the archive and switches it off.
	 *
	 * The alert itself is never replaced or emptied — only deactivated. Its
	 * wording stays put so whoever comes in next starts from what was last said
	 * rather than a blank box.
	 *
	 * @return bool Whether anything was archived.
	 */
	public static function archive_current() {
		$alert = self::current_alert();

		if ( ! $alert || ! $alert->get( 'enabled' ) ) {
			return false;
		}

		self::add_archive_record(
			array(
				'title'   => $alert->get_title(),
				'level'   => $alert->get( 'status_level' ),
				'message' => $alert->get( 'status_message' ),
				'date'    => self::posted_time( $alert ),
			)
		);

		update_post_meta( $alert->get_id(), ACPS_Alerts_Alert::META_PREFIX . 'enabled', 0 );

		do_action( 'acps_alerts_current_archived', $alert->get_id() );

		// Taking it down changes what visitors see, so cached pages have to be
		// rebuilt just as they are when one is posted.
		self::flush_page_caches();

		return true;
	}

	/**
	 * When an alert was posted, falling back to the post date.
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
	 * Reads a date typed by a person into a timestamp.
	 *
	 * Used for backfilled archive entries, where the date is the whole point:
	 * it decides where the entry sits in the list of past updates.
	 *
	 * A bare date is treated as midday, so converting between timezones can
	 * never nudge it onto the day before or after.
	 *
	 * @param string $value Anything strtotime understands, e.g. 2026-09-04.
	 * @return int Unix timestamp, or 0 when it cannot be read.
	 */
	public static function parse_date( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return 0;
		}

		if ( preg_match( '/^\d{4}-\d{1,2}-\d{1,2}$/', $value ) ) {
			$value .= ' 12:00:00';
		}

		$timestamp = strtotime( $value );

		return $timestamp ? (int) $timestamp : 0;
	}

	/**
	 * Rewrites an existing entry in place.
	 *
	 * This is what an edit on the status board does. Correcting a typo has to
	 * change the update people are reading, not publish a second one next to it.
	 *
	 * The date it was posted is deliberately left alone, so an edit does not
	 * restart the daily cut-off — an update fixed at noon still comes down at
	 * the usual time rather than running an extra day.
	 *
	 * @param int   $post_id Entry to rewrite.
	 * @param array $data    Same shape as update_entry().
	 * @return bool Whether it was updated.
	 */
	public static function update_entry( $post_id, array $data ) {
		$post_id = (int) $post_id;
		$title   = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';

		if ( ! $post_id || '' === trim( $title ) || ! get_post_status( $post_id ) ) {
			return false;
		}

		$message = isset( $data['message'] ) ? wp_kses_post( $data['message'] ) : '';

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => $title,
				'post_content' => $message,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			ACPS_Alerts_Failsafe::record( 'status/update', $result->get_error_message() );

			return false;
		}

		$level = isset( $data['level'] ) ? sanitize_key( $data['level'] ) : 'info';

		$changes = array(
			'status_level'   => $level,
			'status_message' => wp_strip_all_tags( $message ),
			'as_popup'       => empty( $data['as_popup'] ) ? 0 : 1,
			'expires_mode'   => isset( $data['expires'] ) ? sanitize_key( $data['expires'] ) : 'daily',
			'visibility'     => isset( $data['visibility'] ) ? sanitize_key( $data['visibility'] ) : 'public',
		);

		foreach ( $changes as $key => $value ) {
			update_post_meta( $post_id, ACPS_Alerts_Alert::META_PREFIX . $key, $value );
		}

		/**
		 * Fires after a status entry is rewritten in place.
		 *
		 * @param int   $post_id The entry.
		 * @param array $changes What changed.
		 */
		do_action( 'acps_alerts_status_updated', $post_id, $changes );

		return true;
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
	 * The daily sweep: files the Current Alert and switches it off.
	 *
	 * Only ever touches the Current Alert. Its wording is left exactly as it
	 * was, so tomorrow starts from what was last said rather than a blank box.
	 *
	 * @return int How many entries were archived (0 or 1).
	 */
	public static function run_daily_archive() {
		$alert = self::current_alert();

		if ( ! $alert || ! $alert->get( 'enabled' ) ) {
			return 0;
		}

		// "Keep it up until I archive it" opts out of the daily sweep entirely.
		if ( 'daily' !== $alert->get( 'expires_mode' ) ) {
			return 0;
		}

		if ( ! self::past_cutoff( $alert ) ) {
			return 0;
		}

		return self::archive_current() ? 1 : 0;
	}

	/**
	 * Records the first time the Current Alert is actually put up.
	 *
	 * The setup checklist's last step is "use it once, on a quiet day". Read
	 * from the live state alone, that step would tick while an alert is up and
	 * untick the moment it comes down — so the first real use is remembered.
	 *
	 * Hooked to both a full save (settings array) and the plain on/off switch
	 * (a bool), so it takes either.
	 *
	 * @param int        $post_id Alert post ID.
	 * @param array|bool $state   The settings just saved, or the new on/off.
	 * @return void
	 */
	public static function note_first_use( $post_id, $state = array() ) {
		$on = is_array( $state ) ? ! empty( $state['enabled'] ) : (bool) $state;

		if ( ! $on || get_option( 'acps_alerts_used_once' ) ) {
			return;
		}

		if ( class_exists( 'ACPS_Alerts_Post_Type' ) && 'current' !== ACPS_Alerts_Post_Type::role_of( (int) $post_id ) ) {
			return;
		}

		update_option( 'acps_alerts_used_once', time(), false );
	}

	/**
	 * Rebuilds every cached copy of the site after the status changes.
	 *
	 * An alert can appear on any page, so when a new status is posted, taken
	 * down or edited, a page cache holding the old HTML would keep showing the
	 * old thing (or nothing) until it expired on its own. Posting an alert has
	 * to behave as if every page were edited at once, so this purges the whole
	 * of whatever full-page cache the site runs, plus this plugin's own render
	 * of the popup.
	 *
	 * Everything here is optional and guarded: each cache is purged only if its
	 * plugin is installed, and a site with no page cache simply does nothing.
	 *
	 * @return void
	 */
	public static function flush_page_caches() {
		// This plugin's own cached render of the popup, so the new wording is
		// rebuilt from the current layout rather than served from the old one.
		if ( class_exists( 'ACPS_Alerts_Popup_Source' ) ) {
			ACPS_Alerts_Popup_Source::forget();
		}

		// Switched off in Settings → Features: this plugin's own popup cache is
		// still cleared above (or the popup would show stale wording), but the
		// site's caching plugin is left alone.
		if ( ! ACPS_Alerts_Settings::feature( 'cache_purge' ) ) {
			return;
		}

		// Full-page caches, purged whole rather than per-post: the alert is in
		// the footer of every page, so a single-post purge would miss almost
		// all of them. Each call is made only when that plugin is present.
		$callables = array(
			'wp_cache_clear_cache',    // WP Super Cache.
			'rocket_clean_domain',     // WP Rocket.
			'w3tc_flush_all',          // W3 Total Cache.
			'wpfc_clear_all_cache',    // WP Fastest Cache.
			'sg_cachepress_purge_cache', // SiteGround Optimizer.
		);

		foreach ( $callables as $fn ) {
			if ( function_exists( $fn ) ) {
				ACPS_Alerts_Failsafe::guard( $fn, array(), 'status/flush-' . $fn );
			}
		}

		// Caches that clear on an action rather than a function call.
		foreach ( array( 'litespeed_purge_all', 'cache_enabler_clear_complete_cache', 'breeze_clear_all_cache', 'swcfpc_purge_everything' ) as $purge_hook ) {
			do_action( $purge_hook );
		}

		/**
		 * Fires when the plugin wants every cached page rebuilt, e.g. after a
		 * new status is posted. Hook it to purge a CDN or any bespoke cache the
		 * list above does not cover.
		 */
		do_action( 'acps_alerts_flush_caches' );
	}
}
