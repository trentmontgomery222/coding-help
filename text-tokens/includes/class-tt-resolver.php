<?php
/**
 * Resolves tokens to values and performs the string replacement.
 *
 * @package TextTokens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TT_Resolver
 *
 * Builds a map of CODE => resolved value (caching dynamic values for a short
 * window) and replaces [CODE] occurrences in arbitrary strings.
 *
 * Matching rules:
 *  - Token syntax is [CODE], case-insensitive.
 *  - Unknown tokens are left in place, displayed literally.
 *  - A doubled bracket [[TEXT]] is an escape: it renders as literal [TEXT]
 *    and its inner text is never treated as a token.
 */
class TT_Resolver {

	const CACHE_KEY = 'tt_resolved_map';

	/**
	 * In-request memoized resolution map.
	 *
	 * @var array|null
	 */
	private static $map = null;

	/**
	 * Configured cache lifetime in seconds.
	 *
	 * @return int
	 */
	public static function cache_ttl() {
		$settings = get_option( TT_OPTION_SETTINGS, array() );
		$ttl      = isset( $settings['cache_ttl'] ) ? (int) $settings['cache_ttl'] : HOUR_IN_SECONDS;
		/**
		 * Filter the resolved-value cache lifetime (seconds).
		 *
		 * @param int $ttl Cache lifetime.
		 */
		return (int) apply_filters( 'tt_cache_ttl', $ttl );
	}

	/**
	 * Build (or fetch from cache) the map of uppercase CODE => resolved value.
	 *
	 * Static token values are cheap and stored directly. Dynamic values are
	 * computed and cached together for cache_ttl() seconds so we do not
	 * recalculate on every request.
	 *
	 * @return array
	 */
	public static function get_map() {
		if ( null !== self::$map ) {
			return self::$map;
		}

		$ttl = self::cache_ttl();

		if ( $ttl > 0 ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				self::$map = $cached;
				return self::$map;
			}
		}

		$map = self::build_map();

		if ( $ttl > 0 ) {
			set_transient( self::CACHE_KEY, $map, $ttl );
		}

		self::$map = $map;
		return self::$map;
	}

	/**
	 * Compute resolved values for every stored token.
	 *
	 * @return array
	 */
	private static function build_map() {
		$map = array();

		foreach ( TT_Tokens::all() as $token ) {
			if ( empty( $token['code'] ) ) {
				continue;
			}

			$code = strtoupper( $token['code'] );
			$type = isset( $token['type'] ) ? $token['type'] : 'static';

			if ( 'dynamic' === $type ) {
				$rule   = isset( $token['rule'] ) ? $token['rule'] : '';
				$config = isset( $token['config'] ) && is_array( $token['config'] ) ? $token['config'] : array();
				$value  = TT_Rules::exists( $rule ) ? TT_Rules::evaluate( $rule, $config ) : '';
			} else {
				$value = isset( $token['value'] ) ? $token['value'] : '';
			}

			$map[ $code ] = $value;
		}

		return $map;
	}

	/**
	 * Resolve a single code to its value, or null if unknown.
	 *
	 * Used by the admin preview so it always reflects live (uncached) values.
	 *
	 * @param string $code    Raw or normalized code.
	 * @param bool   $bypass_cache Whether to compute a fresh value.
	 * @return string|null
	 */
	public static function resolve_code( $code, $bypass_cache = false ) {
		$code = strtoupper( TT_Tokens::normalize_code( $code ) );
		$map  = $bypass_cache ? self::build_map() : self::get_map();
		return array_key_exists( $code, $map ) ? $map[ $code ] : null;
	}

	/**
	 * Replace all tokens in a string.
	 *
	 * @param string $content The text to process.
	 * @return string
	 */
	public static function replace( $content ) {
		if ( ! is_string( $content ) || '' === $content || false === strpos( $content, '[' ) ) {
			return $content;
		}

		$map = self::get_map();

		// Match [CODE] or [[CODE]] (the doubled form is the literal escape).
		// CODE allows letters, digits, spaces, hyphens and underscores.
		$pattern = '/(\[{1,2})([A-Za-z0-9 _\-]+)(\]{1,2})/';

		return preg_replace_callback(
			$pattern,
			static function ( $matches ) use ( $map ) {
				$open  = $matches[1];
				$inner = $matches[2];
				$close = $matches[3];

				// Escape: [[TEXT]] -> literal [TEXT], never resolved.
				if ( '[[' === $open && ']]' === $close ) {
					return '[' . $inner . ']';
				}

				// Only single-bracket, balanced tokens are candidates.
				if ( '[' !== $open || ']' !== $close ) {
					return $matches[0];
				}

				$code = strtoupper( trim( $inner ) );

				if ( array_key_exists( $code, $map ) ) {
					return $map[ $code ];
				}

				// Unknown token: leave it exactly as written.
				return $matches[0];
			},
			$content
		);
	}

	/**
	 * Clear the cached resolution map.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$map = null;
		delete_transient( self::CACHE_KEY );
	}
}
