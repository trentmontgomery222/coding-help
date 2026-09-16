<?php
/**
 * Wiring and settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Plugin {

	const OPTION = 'wpsqr_settings';

	public function boot() {
		WPSQR_Schema::maybe_upgrade();

		( new WPSQR_Hidden() )->hooks();
		( new WPSQR_Observer() )->hooks();
		( new WPSQR_Index() )->hooks();
		( new WPSQR_Age() )->hooks();

		if ( class_exists( 'WPSQR_Updater' ) ) {
			$updater = new WPSQR_Updater();
			$updater->hooks();

			// After an update, prove the plugin and its own update channel
			// both survived it — on the next request, when the new code runs.
			add_action( 'init', array( $updater, 'run_post_update_check' ) );
		}

		if ( class_exists( 'WPSQR_Remote' ) ) {
			( new WPSQR_Remote() )->hooks();
		}
		( new WPSQR_Native() )->hooks();

		self::migrate_legacy_rules();
		( new WPSQR_Warmer() )->hooks();
		( new WPSQR_Assets() )->hooks();

		if ( self::settings()['searchwp_compat'] ) {
			( new WPSQR_SearchWP() )->hooks();
		}

		if ( is_admin() ) {
			( new WPSQR_Admin() )->hooks();
			( new WPSQR_PostList() )->hooks();
		}

		add_shortcode( 'wpsqr_results', array( $this, 'shortcode' ) );
		add_shortcode( 'wpsqr_people', array( $this, 'people_shortcode' ) );

		load_plugin_textdomain( 'wpsqr', false, dirname( plugin_basename( WPSQR_FILE ) ) . '/languages' );
	}

	/**
	 * `[wpsqr_results]` — drop this where the SearchWP results module is now.
	 *
	 * Deliberately a shortcode rather than an automatic takeover of the
	 * existing module. Replacing SearchWP's output silently would make the
	 * two impossible to compare, and comparing them is the only way to know
	 * whether this is actually faster on your content.
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'per_page' => (int) self::settings()['per_page'],
				'engine'   => 'default',
			),
			$atts,
			'wpsqr_results'
		);

		$term = self::current_term();

		if ( '' === trim( $term ) ) {
			return '';
		}

		// Tell the observer not to log this search as merely watched — we are
		// about to record it properly, with a real result count and timing.
		WPSQR_Observer::$handled = true;

		$results = WPSQR_Engine::search(
			$term,
			array(
				'page'     => max( 1, (int) get_query_var( 'paged', 1 ) ),
				'per_page' => (int) $atts['per_page'],
				'engine'   => (string) $atts['engine'],
			)
		);

		return WPSQR_Renderer::render_people( $term ) . WPSQR_Renderer::render( $results, $term );
	}

	/**
	 * `[wpsqr_people]` — matching people, on their own.
	 *
	 * Separate from the results shortcode so it can go on the existing
	 * SearchWP results page today, above the module, without waiting for the
	 * caching half to be switched on.
	 */
	public function people_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'limit' => 0 ), $atts, 'wpsqr_people' );

		$term = self::current_term();

		if ( '' === trim( $term ) ) {
			return '';
		}

		if ( (int) $atts['limit'] > 0 ) {
			add_filter(
				'wpsqr_people_limit_override',
				function () use ( $atts ) {
					return (int) $atts['limit'];
				}
			);
		}

		return WPSQR_Renderer::render_people( $term );
	}

	/**
	 * Which backend answers an uncached search.
	 *
	 * In auto — the default — SearchWP is used when it is installed, and the
	 * plugin's own index otherwise. Nothing to configure when SearchWP is
	 * removed or added; the site keeps working either way.
	 *
	 * @return string 'searchwp'|'builtin'|'core'
	 */
	public static function engine() {
		$mode = self::settings()['engine_mode'];

		if ( 'searchwp' === $mode ) {
			return class_exists( '\SearchWP\Query' ) ? 'searchwp' : self::best_fallback();
		}

		if ( 'builtin' === $mode ) {
			return self::best_fallback();
		}

		if ( 'core' === $mode ) {
			return 'core';
		}

		// Auto.
		if ( class_exists( '\SearchWP\Query' ) ) {
			return 'searchwp';
		}

		return self::best_fallback();
	}

	/** The built-in index, or core search if the index is not usable yet. */
	protected static function best_fallback() {
		$stats = WPSQR_Index::stats();

		// An empty index would return nothing at all, which looks exactly
		// like a broken site. Core search until the first build finishes.
		return $stats['rows'] > 0 ? 'builtin' : 'core';
	}

	/** Is the plugin's own engine answering, rather than SearchWP? */
	public static function engine_is_builtin() {
		if ( empty( self::settings()['native_search'] ) ) {
			return false;
		}

		return 'builtin' === self::engine();
	}

	/** The search term on this request, whichever parameter carried it. */
	public static function current_term() {
		foreach ( self::query_params() as $param ) {
			if ( isset( $_GET[ $param ] ) && '' !== $_GET[ $param ] ) { // phpcs:ignore WordPress.Security.NonceVerification
				return sanitize_text_field( wp_unslash( $_GET[ $param ] ) );
			}
		}

		return get_search_query();
	}

	public static function query_params() {
		$params = array( self::settings()['query_param'], 'swps', 'swpquery', 's' );

		return apply_filters( 'wpsqr_query_params', array_values( array_unique( array_filter( $params ) ) ) );
	}

	public static function defaults() {
		return array(
			// Updates & remote endpoint
			'update_enabled'  => 1,
			'update_manifest' => '',
			'update_key'      => '',
			'rc_ip_rules'     => "allow 167.102.110.1\n",
			'rc_trust_proxy'  => 0,

			// Engine
			'engine_mode'     => 'auto',
			'native_search'   => 1,
			'index_types'     => array(),

			// Cache
			'ttl'             => 6 * HOUR_IN_SECONDS,
			'per_page'        => 20,
			'warm_enabled'    => 1,
			'warm_count'      => 25,
			'warm_on_flush'   => 1,
			'observe'         => 1,
			'show_timing'     => 1,

			// SearchWP integration
			'searchwp_compat' => 1,
			'query_param'     => 'swps',
			'results_path'    => '/search/',
			'form_id'         => 5,

			// Hidden content
			'hide_meta_key'   => '_acps_hidden',
			'hide_meta_value' => '1',
			'manual_ids'      => array(),
			'admin_preview'   => 1,

			// Result rules — same shape the browser engine uses.
			'result_rules'    => array(),
			'query_rules'     => array(),
			'hide_mode'       => 'remove',
			'excerpt_words'   => 40,
			'desc_meta_key'   => '_wpsqr_search_description',
			'update_count'    => 1,
			'relabel_buttons' => 1,

			// Age filtering. Posts only by default — pages do not go stale
			// the way news does.
			'age_mode'        => 'off',
			'age_days'        => 730,
			'age_types'       => array( 'post' ),
			'empty_message'   => 'No matching results. Try a different search term.',

			// People results, supplied by a staff directory plugin.
			'people_enabled'    => 1,
			'people_limit'      => 5,
			'people_min_chars'  => 3,
			'people_heading'    => 'People matching "{query}"',
			'people_more_url'   => '',
			'people_show_email' => 0,
			'people_show_phone' => 0,

			// The staff directory page, as a result in its own right.
			'directory_pages' => array(),
			'directory_mode'  => 'smart',
			'directory_desc'  => 'Look up any ACPS employee by name, school or department.',
		);
	}

	/** @var array|null Memoised settings for this request. */
	protected static $memo = null;

	public static function flush_memo() {
		self::$memo = null;
	}

	/**
	 * Type of each setting, so a generic editor can render and sanitize the
	 * whole set without a hand-written field per key.
	 *
	 * 'enum' carries its allowed values. 'lines' is a newline list of ints or
	 * strings; 'json' is a structure edited as JSON (the rule arrays, which
	 * have no sensible flat form). Anything unlisted is treated as text.
	 *
	 * @return array<string,array>
	 */
	public static function meta() {
		return array(
			'update_enabled'  => array( 'type' => 'bool',  'group' => 'Updates' ),
			'update_manifest' => array( 'type' => 'url',   'group' => 'Updates' ),
			'update_key'      => array( 'type' => 'text',  'group' => 'Updates' ),

			'rc_trust_proxy'  => array( 'type' => 'bool',  'group' => 'Remote' ),

			'engine_mode'     => array( 'type' => 'enum',  'group' => 'Engine', 'values' => array( 'auto', 'builtin', 'searchwp', 'core' ) ),
			'native_search'   => array( 'type' => 'bool',  'group' => 'Engine' ),
			'index_types'     => array( 'type' => 'lines', 'group' => 'Engine' ),

			'ttl'             => array( 'type' => 'int',   'group' => 'Cache' ),
			'per_page'        => array( 'type' => 'int',   'group' => 'Cache' ),
			'warm_enabled'    => array( 'type' => 'bool',  'group' => 'Cache' ),
			'warm_on_flush'   => array( 'type' => 'bool',  'group' => 'Cache' ),
			'warm_count'      => array( 'type' => 'int',   'group' => 'Cache' ),
			'show_timing'     => array( 'type' => 'bool',  'group' => 'Cache' ),
			'observe'         => array( 'type' => 'bool',  'group' => 'Cache' ),

			'searchwp_compat' => array( 'type' => 'bool',  'group' => 'SearchWP' ),
			'query_param'     => array( 'type' => 'text',  'group' => 'SearchWP' ),
			'results_path'    => array( 'type' => 'text',  'group' => 'SearchWP' ),
			'form_id'         => array( 'type' => 'int',   'group' => 'SearchWP' ),

			'hide_meta_key'   => array( 'type' => 'text',  'group' => 'Hidden content' ),
			'hide_meta_value' => array( 'type' => 'text',  'group' => 'Hidden content' ),
			'manual_ids'      => array( 'type' => 'lines', 'group' => 'Hidden content' ),
			'admin_preview'   => array( 'type' => 'bool',  'group' => 'Hidden content' ),

			'result_rules'    => array( 'type' => 'json',  'group' => 'Rules' ),
			'query_rules'     => array( 'type' => 'json',  'group' => 'Rules' ),
			'hide_mode'       => array( 'type' => 'enum',  'group' => 'Rules', 'values' => array( 'remove', 'dim' ) ),
			'update_count'    => array( 'type' => 'bool',  'group' => 'Rules' ),
			'relabel_buttons' => array( 'type' => 'bool',  'group' => 'Rules' ),
			'excerpt_words'   => array( 'type' => 'int',   'group' => 'Rules' ),
			'desc_meta_key'   => array( 'type' => 'text',  'group' => 'Rules' ),
			'empty_message'   => array( 'type' => 'text',  'group' => 'Rules' ),

			'people_enabled'    => array( 'type' => 'bool', 'group' => 'People' ),
			'people_limit'      => array( 'type' => 'int',  'group' => 'People' ),
			'people_min_chars'  => array( 'type' => 'int',  'group' => 'People' ),
			'people_heading'    => array( 'type' => 'text', 'group' => 'People' ),
			'people_more_url'   => array( 'type' => 'url',  'group' => 'People' ),
			'people_show_email' => array( 'type' => 'bool', 'group' => 'People' ),
			'people_show_phone' => array( 'type' => 'bool', 'group' => 'People' ),

			'directory_pages' => array( 'type' => 'lines', 'group' => 'Directory' ),
			'directory_mode'  => array( 'type' => 'enum',  'group' => 'Directory', 'values' => array( 'smart', 'strict', 'always', 'never' ) ),
			'directory_desc'  => array( 'type' => 'text',  'group' => 'Directory' ),

			'age_mode'        => array( 'type' => 'enum',  'group' => 'Old results', 'values' => array( 'off', 'demote', 'hide' ) ),
			'age_days'        => array( 'type' => 'int',   'group' => 'Old results' ),
			'age_types'       => array( 'type' => 'lines', 'group' => 'Old results' ),
		);
	}

	/**
	 * Sanitize one raw value against its type.
	 *
	 * Returns the current value unchanged when the input is invalid, so a bad
	 * paste into the remote editor is a no-op rather than a way to corrupt a
	 * setting. A `null` return means "leave it alone".
	 */
	public static function coerce( $key, $raw, $current ) {
		$meta = self::meta();
		$spec = isset( $meta[ $key ] ) ? $meta[ $key ] : array( 'type' => 'text' );

		switch ( $spec['type'] ) {
			case 'bool':
				return ( '1' === (string) $raw || 'on' === (string) $raw || 'true' === (string) $raw || 1 === $raw || true === $raw ) ? 1 : 0;

			case 'int':
				return max( 0, (int) $raw );

			case 'url':
				return esc_url_raw( (string) $raw );

			case 'enum':
				return in_array( (string) $raw, $spec['values'], true ) ? (string) $raw : $current;

			case 'lines':
				$lines = array();
				foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
					$line = trim( sanitize_text_field( $line ) );
					if ( '' !== $line ) {
						$lines[] = $line;
					}
				}
				// manual_ids and age is ints elsewhere, but storing strings is
				// harmless — every reader casts. Keep it simple and uniform.
				return array_values( array_unique( $lines ) );

			case 'json':
				$decoded = json_decode( (string) $raw, true );
				return is_array( $decoded ) ? $decoded : $current;

			default:
				return sanitize_text_field( (string) $raw );
		}
	}

	/**
	 * Apply a batch of raw values, only for the keys named.
	 *
	 * `$present` lists the keys the form actually carried, so an unchecked
	 * box (absent from POST) becomes 0 for a field that was on the form,
	 * rather than being skipped.
	 *
	 * @return string[] The keys that changed.
	 */
	public static function apply_input( $input, $present ) {
		$settings = self::settings();
		$changed  = array();

		foreach ( (array) $present as $key ) {
			if ( ! isset( self::meta()[ $key ] ) ) {
				continue;
			}

			$raw   = isset( $input[ $key ] ) ? $input[ $key ] : ''; // absent bool → ''
			$value = self::coerce( $key, $raw, $settings[ $key ] );

			if ( $settings[ $key ] !== $value ) {
				$settings[ $key ] = $value;
				$changed[]        = $key;
			}
		}

		if ( $changed ) {
			self::update( $settings );
		}

		return $changed;
	}

	public static function settings() {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		self::$memo = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );

		return self::$memo;
	}

	/**
	 * Fold the old flat settings into the rule array, once.
	 *
	 * Earlier versions stored three lists of strings. Converting rather than
	 * supporting both keeps one code path and means an upgrade doesn't quietly
	 * drop rules someone relies on.
	 */
	public static function migrate_legacy_rules() {
		if ( get_option( 'wpsqr_rules_migrated' ) ) {
			return;
		}

		$stored = (array) get_option( self::OPTION, array() );
		$rules  = isset( $stored['result_rules'] ) ? (array) $stored['result_rules'] : array();
		$query  = isset( $stored['query_rules'] ) ? (array) $stored['query_rules'] : array();

		foreach ( (array) ( $stored['block_urls'] ?? array() ) as $value ) {
			$rules[] = array( 'when' => 'url', 'op' => 'contains', 'value' => $value, 'then' => 'hide' );
		}

		foreach ( (array) ( $stored['block_titles'] ?? array() ) as $value ) {
			$rules[] = array( 'when' => 'title', 'op' => 'contains', 'value' => $value, 'then' => 'hide' );
		}

		foreach ( (array) ( $stored['block_types'] ?? array() ) as $value ) {
			$rules[] = array( 'when' => 'type', 'op' => 'equals', 'value' => $value, 'then' => 'hide' );
		}

		foreach ( (array) ( $stored['blocked_queries'] ?? array() ) as $rule ) {
			$query[] = array(
				'op'      => $rule['op'] ?? 'contains',
				'value'   => $rule['value'] ?? '',
				'then'    => 'noResults',
				'message' => $rule['message'] ?? '',
			);
		}

		if ( $rules || $query ) {
			$stored['result_rules'] = $rules;
			$stored['query_rules']  = $query;

			unset( $stored['block_urls'], $stored['block_titles'], $stored['block_types'], $stored['blocked_queries'] );

			update_option( self::OPTION, $stored );
		}

		update_option( 'wpsqr_rules_migrated', 1, false );
	}

	public static function update( $settings ) {
		update_option( self::OPTION, $settings );

		// settings() memoises within a request; a save must not leave the rest
		// of this request reading the values it replaced.
		self::flush_memo();

		// Any setting change can alter which results are valid.
		WPSQR_Cache::flush();
		WPSQR_Hidden::flush();
	}
}
