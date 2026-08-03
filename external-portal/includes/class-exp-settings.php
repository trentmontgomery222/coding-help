<?php
/**
 * Typed access to plugin settings.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the single settings option.
 */
class EXP_Settings {

	/**
	 * In-request cache.
	 *
	 * @var array<string,mixed>|null
	 */
	protected static $cache = null;

	/**
	 * Get all settings merged over defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( EXP_OPT_SETTINGS, array() );
			self::$cache = wp_parse_args( is_array( $stored ) ? $stored : array(), EXP_Install::default_settings() );
		}
		return self::$cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Persist a set of settings (merged over the current values).
	 *
	 * @param array<string,mixed> $values New values.
	 */
	public static function update( array $values ) {
		$merged = array_merge( self::all(), $values );
		update_option( EXP_OPT_SETTINGS, $merged );
		self::$cache = $merged;
	}

	/**
	 * Reset the in-request cache (mainly for tests).
	 */
	public static function flush() {
		self::$cache = null;
	}
}
