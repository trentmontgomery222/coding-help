<?php
/**
 * The staff directory page, as a search result.
 *
 * The directory page is a single page whose indexed text contains every
 * person in it — including the hidden ones, because hiding controls what the
 * page *renders*, not what got indexed. That makes it an oracle: type the name
 * of someone who asked to be unlisted, and the directory page comes back. The
 * result does not name them, but its appearance confirms they exist, which is
 * most of what hiding was meant to prevent.
 *
 * So the directory page is handled as a special case rather than as an
 * ordinary result:
 *
 *   - Its description is always replaced. The automatic one is a run of
 *     employee names and admin interface text.
 *   - It is hidden when the search matches nobody visible. If a name search
 *     only reaches the page through hidden content, showing it leaks.
 *
 * Name matching comes only from the directory plugin's own data, never from
 * the page text — the page text is exactly what cannot be trusted here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Directory {

	const MODE_SMART  = 'smart';
	const MODE_STRICT = 'strict';
	const MODE_ALWAYS = 'always';
	const MODE_NEVER  = 'never';

	/**
	 * Which results are directory pages.
	 *
	 * @return array{ids:string[],paths:string[]}
	 */
	public static function pages() {
		static $cached = null;

		if ( null !== $cached ) {
			return $cached;
		}

		$settings = WPSQR_Plugin::settings();

		$ids   = array();
		$paths = array();

		foreach ( (array) $settings['directory_pages'] as $entry ) {
			$entry = trim( (string) $entry );

			if ( '' === $entry ) {
				continue;
			}

			if ( ctype_digit( $entry ) ) {
				$ids[] = $entry;

				$path = wp_parse_url( get_permalink( (int) $entry ), PHP_URL_PATH );
				if ( $path ) {
					$paths[] = self::normalize_path( $path );
				}

				continue;
			}

			// A full URL or a bare path both reduce to a path.
			$path = wp_parse_url( $entry, PHP_URL_PATH );
			$paths[] = self::normalize_path( $path ? $path : $entry );
		}

		// Nothing configured and no plugin naming its page leaves this
		// feature inert with no sign of it, which is indistinguishable from
		// broken. Fall back to anything else that already points at the
		// directory.
		foreach ( self::inferred() as $path ) {
			$paths[] = $path;
		}

		/**
		 * Let the directory plugin name its own page.
		 *
		 * It can find the page carrying its shortcode; a setting can only be
		 * told. Return entries as post IDs, paths or full URLs.
		 *
		 * @param string[] $entries
		 */
		$supplied = apply_filters( 'wpsqr_directory_pages', array() );

		foreach ( (array) $supplied as $entry ) {
			$entry = trim( (string) $entry );

			if ( '' === $entry ) {
				continue;
			}

			if ( ctype_digit( $entry ) ) {
				$ids[] = $entry;
				continue;
			}

			$path = wp_parse_url( $entry, PHP_URL_PATH );
			$paths[] = self::normalize_path( $path ? $path : $entry );
		}

		$cached = array(
			'ids'   => array_values( array_unique( array_filter( $ids ) ) ),
			'paths' => array_values( array_unique( array_filter( $paths ) ) ),
		);

		return $cached;
	}

	/**
	 * Paths that can be worked out without being told.
	 *
	 * The "search the full staff directory" link under the people results
	 * points at the directory page by definition — whether it came from the
	 * setting or from the directory plugin's own filter. If that is known,
	 * the directory page is known.
	 *
	 * @return string[]
	 */
	public static function inferred() {
		$paths = array();

		$more = WPSQR_People::more_url( '' );

		if ( '' !== $more ) {
			$path = wp_parse_url( $more, PHP_URL_PATH );

			if ( $path ) {
				$paths[] = self::normalize_path( $path );
			}
		}

		return array_values( array_filter( array_unique( $paths ) ) );
	}

	/** Where the identification came from, for the admin and debug panels. */
	public static function source() {
		$settings = WPSQR_Plugin::settings();

		if ( array_filter( (array) $settings['directory_pages'] ) ) {
			return 'settings';
		}

		if ( apply_filters( 'wpsqr_directory_pages', array() ) ) {
			return 'plugin';
		}

		if ( self::inferred() ) {
			return 'inferred';
		}

		return 'none';
	}

	/** Everything the debug panel and Status screen need, in one call. */
	public static function debug( $term ) {
		$pages = self::pages();

		return array(
			'configured' => self::is_configured(),
			'source'     => self::source(),
			'paths'      => $pages['paths'],
			'ids'        => $pages['ids'],
			'mode'       => WPSQR_Plugin::settings()['directory_mode'],
			'modeState'  => self::mode_state(),
			'provider'   => WPSQR_People::has_provider(),
			'hiding'     => self::is_configured() ? self::should_hide( $term ) : false,
			'desc'       => self::description( $term ),
		);
	}

	/** Lower-case, leading slash, trailing slash — matching the rule engine. */
	public static function normalize_path( $path ) {
		$path = strtolower( trim( (string) $path ) );

		if ( '' === $path ) {
			return '';
		}

		if ( '/' !== substr( $path, 0, 1 ) ) {
			$path = '/' . $path;
		}

		if ( '/' !== substr( $path, -1 ) ) {
			$path .= '/';
		}

		return $path;
	}

	public static function is_configured() {
		$pages = self::pages();

		return ( $pages['ids'] || $pages['paths'] );
	}

	/** Is this post one of the directory pages? */
	public static function is_directory( $post_id ) {
		$pages = self::pages();

		if ( in_array( (string) (int) $post_id, $pages['ids'], true ) ) {
			return true;
		}

		if ( ! $pages['paths'] ) {
			return false;
		}

		$path = self::normalize_path( (string) wp_parse_url( get_permalink( $post_id ), PHP_URL_PATH ) );

		return in_array( $path, $pages['paths'], true );
	}

	/**
	 * Should the directory page be hidden for this search?
	 *
	 * The distinction that matters is between a search for a hidden person's
	 * name and a topical search that simply matches nobody:
	 *
	 *   "aust"            names a hidden employee. The page must not appear.
	 *   "staff directory" names nobody at all. The page is what was wanted.
	 *
	 * Both return no visible people. Treating "no visible match" as proof of
	 * the first — which this did at first — hides the directory page from
	 * every topical search, so it effectively never appears. That is wrong in
	 * the ordinary case to defend against the rare one.
	 *
	 * So smart mode asks the directory plugin directly, and does nothing
	 * unless it can answer.
	 */
	public static function should_hide( $term ) {
		$mode = WPSQR_Plugin::settings()['directory_mode'];

		if ( self::MODE_NEVER === $mode ) {
			return true;
		}

		if ( self::MODE_ALWAYS === $mode ) {
			return false;
		}

		if ( ! WPSQR_People::has_provider() ) {
			return false;
		}

		if ( self::MODE_STRICT === $mode ) {
			// Hide whenever nobody visible matched. Safe, and blunt: topical
			// searches lose the directory page too.
			return ! WPSQR_People::search( $term );
		}

		// Smart. Hide only for a term that matches a hidden person and
		// nobody visible.
		$hidden = WPSQR_People::matches_hidden( $term );

		if ( null === $hidden ) {
			// No provider can tell us. Showing the page is the right default:
			// its description is replaced either way, so nothing leaks through
			// the excerpt, and the page's own presence is a far weaker signal
			// than the names its excerpt used to carry.
			return false;
		}

		return $hidden && ! WPSQR_People::search( $term );
	}

	/** Why the current mode is behaving as it is, for the admin screens. */
	public static function mode_state() {
		$mode = WPSQR_Plugin::settings()['directory_mode'];

		if ( self::MODE_SMART !== $mode ) {
			return $mode;
		}

		if ( ! WPSQR_People::has_provider() ) {
			return 'smart_no_provider';
		}

		if ( null === WPSQR_People::matches_hidden( 'x' ) ) {
			return 'smart_no_signal';
		}

		return 'smart';
	}

	/** The replacement description, with {query} expanded. */
	public static function description( $term ) {
		$text = (string) WPSQR_Plugin::settings()['directory_desc'];

		return trim( str_replace( '{query}', $term, $text ) );
	}

	/**
	 * The directory rules, in the shape the browser engine understands.
	 *
	 * Handing the browser rules rather than a special case means the page
	 * SearchWP renders itself gets the same treatment as the one this plugin
	 * renders, without a second implementation to keep in step.
	 */
	public static function browser_rules( $term ) {
		if ( ! self::is_configured() ) {
			return array();
		}

		$pages = self::pages();
		$hide  = self::should_hide( $term );
		$desc  = self::description( $term );

		if ( ! $hide && '' === $desc ) {
			return array();
		}

		$rules = array();

		$action = $hide
			? array( 'then' => 'hide' )
			: array( 'then' => 'setDesc', 'desc' => $desc );

		foreach ( $pages['paths'] as $path ) {
			$rules[] = array_merge( array( 'when' => 'url', 'op' => 'equals', 'value' => $path ), $action );
		}

		foreach ( $pages['ids'] as $id ) {
			$rules[] = array_merge( array( 'when' => 'id', 'op' => 'equals', 'value' => $id ), $action );
		}

		return $rules;
	}
}
