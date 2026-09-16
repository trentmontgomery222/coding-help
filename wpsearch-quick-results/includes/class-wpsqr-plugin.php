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
