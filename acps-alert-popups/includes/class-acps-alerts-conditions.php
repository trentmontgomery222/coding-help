<?php
/**
 * Decides which alerts may show on the current request.
 *
 * Everything that can be answered on the server (schedule, audience, page
 * targeting) is answered here, so the front end only ships the alerts that
 * are actually eligible. Triggers and frequency are handled in JavaScript.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * Display rule evaluation.
 */
class ACPS_Alerts_Conditions {

	/**
	 * Whether an alert should be sent to the current page.
	 *
	 * @param ACPS_Alerts_Alert $alert Alert to test.
	 * @return bool
	 */
	public static function passes( ACPS_Alerts_Alert $alert ) {
		// Click-only alerts ignore page targeting: the trigger decides where they
		// live, so the markup has to be available wherever a trigger is placed.
		// Exclusions still apply.
		$location = ( 'click' === $alert->get( 'trigger' ) )
			? self::passes_exclusions( $alert )
			: self::passes_location( $alert );

		$passes = $alert->get( 'enabled' )
			&& self::passes_status( $alert )
			&& self::passes_visibility( $alert )
			&& self::passes_schedule( $alert )
			&& self::passes_audience( $alert )
			&& $location;

		/**
		 * Filters whether an alert is eligible for the current request.
		 *
		 * @param bool              $passes Whether the alert may show.
		 * @param ACPS_Alerts_Alert $alert  The alert being tested.
		 */
		return (bool) apply_filters( 'acps_alerts_alert_passes', $passes, $alert );
	}

	/**
	 * Status check: archived entries, entries past the daily cut-off, and
	 * entries not meant to pop up, never reach the front end.
	 *
	 * The cut-off is enforced here as well as by cron, because WordPress cron
	 * only fires when somebody visits the site. An entry must come down on time
	 * even on a quiet evening when cron has not run.
	 *
	 * @param ACPS_Alerts_Alert $alert Alert to test.
	 * @return bool
	 */
	public static function passes_status( ACPS_Alerts_Alert $alert ) {
		if ( $alert->get( 'archived' ) ) {
			return false;
		}

		if ( ! $alert->get( 'as_popup' ) ) {
			return false; // Status board only.
		}

		if ( class_exists( 'ACPS_Alerts_Status' ) && ACPS_Alerts_Status::past_cutoff( $alert ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Visibility check: a staged entry is for staff only.
	 *
	 * @param ACPS_Alerts_Alert $alert Alert to test.
	 * @return bool
	 */
	public static function passes_visibility( ACPS_Alerts_Alert $alert ) {
		if ( ! class_exists( 'ACPS_Alerts_Status' ) ) {
			return true;
		}

		return ACPS_Alerts_Status::viewer_may_see( $alert );
	}

	/**
	 * Schedule window check, in site time.
	 *
	 * @param ACPS_Alerts_Alert $alert Alert to test.
	 * @return bool
	 */
	public static function passes_schedule( ACPS_Alerts_Alert $alert ) {
		$now   = current_time( 'Y-m-d H:i' );
		$start = $alert->get( 'start' );
		$end   = $alert->get( 'end' );

		if ( '' !== $start && $now < $start ) {
			return false;
		}

		if ( '' !== $end && $now > $end ) {
			return false;
		}

		return true;
	}

	/**
	 * Audience check.
	 *
	 * @param ACPS_Alerts_Alert $alert Alert to test.
	 * @return bool
	 */
	public static function passes_audience( ACPS_Alerts_Alert $alert ) {
		$audience = $alert->get( 'audience' );

		switch ( $audience ) {
			case 'logged_in':
				return is_user_logged_in();

			case 'logged_out':
				return ! is_user_logged_in();

			case 'roles':
				if ( ! is_user_logged_in() ) {
					return false;
				}

				$wanted = (array) $alert->get( 'roles' );

				if ( empty( $wanted ) ) {
					return true;
				}

				$user = wp_get_current_user();

				return (bool) array_intersect( $wanted, (array) $user->roles );

			case 'all':
			default:
				return true;
		}
	}

	/**
	 * Page targeting check.
	 *
	 * @param ACPS_Alerts_Alert $alert Alert to test.
	 * @return bool
	 */
	public static function passes_location( ACPS_Alerts_Alert $alert ) {
		if ( ! self::passes_exclusions( $alert ) ) {
			return false;
		}

		$display = $alert->get( 'display' );

		if ( 'entire' === $display ) {
			return true;
		}

		if ( 'front' === $display ) {
			return is_front_page();
		}

		// 'selected': any one of the selectors may match.
		$included = $alert->get( 'include_urls' );

		if ( '' !== $included && self::path_matches( self::current_path(), $included ) ) {
			return true;
		}

		$post_types = (array) $alert->get( 'post_types' );

		if ( ! empty( $post_types ) ) {
			if ( is_singular( $post_types ) ) {
				return true;
			}

			foreach ( $post_types as $post_type ) {
				if ( is_post_type_archive( $post_type ) ) {
					return true;
				}
			}
		}

		$post_ids = array_map( 'absint', (array) $alert->get( 'post_ids' ) );

		if ( ! empty( $post_ids ) ) {
			$queried = self::current_post_id();

			if ( $queried && in_array( $queried, $post_ids, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Exclusion check: whether the current path is on the alert's never-show list.
	 *
	 * @param ACPS_Alerts_Alert $alert Alert to test.
	 * @return bool
	 */
	public static function passes_exclusions( ACPS_Alerts_Alert $alert ) {
		$excluded = $alert->get( 'exclude_urls' );

		if ( '' === $excluded ) {
			return true;
		}

		return ! self::path_matches( self::current_path(), $excluded );
	}

	/**
	 * The path (and query string) of the current request.
	 *
	 * @return string Leading slash, no host.
	 */
	public static function current_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$uri = esc_url_raw( (string) $uri );
		$uri = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( '' === $uri ) {
			$uri = '/';
		}

		return '/' . ltrim( $uri, '/' );
	}

	/**
	 * The post ID for the current request, when there is one.
	 *
	 * @return int
	 */
	public static function current_post_id() {
		if ( is_front_page() && ! is_home() ) {
			return (int) get_option( 'page_on_front' );
		}

		if ( is_home() ) {
			return (int) get_option( 'page_for_posts' );
		}

		if ( is_singular() ) {
			return (int) get_queried_object_id();
		}

		return 0;
	}

	/**
	 * Matches a path against a newline separated pattern list.
	 *
	 * Patterns are matched against the path, may be absolute URLs, and may use
	 * `*` as a wildcard. A pattern ending in `*` matches anything beneath it.
	 *
	 * @param string $path     Request path.
	 * @param string $patterns Newline separated patterns.
	 * @return bool
	 */
	public static function path_matches( $path, $patterns ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $patterns );
		$path  = '/' . trim( (string) $path, '/' );

		foreach ( (array) $lines as $pattern ) {
			$pattern = trim( $pattern );

			if ( '' === $pattern ) {
				continue;
			}

			// Allow full URLs to be pasted in; only the path is compared.
			if ( preg_match( '#^https?://#i', $pattern ) ) {
				$pattern = (string) wp_parse_url( $pattern, PHP_URL_PATH );
			}

			$has_wildcard = ( false !== strpos( $pattern, '*' ) );
			$pattern      = '/' . trim( $pattern, '/' );

			if ( $has_wildcard ) {
				$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '/?$#i';

				// The pattern comes from a text field. preg_quote makes it safe,
				// but a malformed result must still never emit a warning or be
				// mistaken for a match: preg_match returns false on error.
				if ( true === (bool) @preg_match( $regex, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					return true;
				}

				continue;
			}

			if ( 0 === strcasecmp( $pattern, $path ) ) {
				return true;
			}
		}

		return false;
	}
}
