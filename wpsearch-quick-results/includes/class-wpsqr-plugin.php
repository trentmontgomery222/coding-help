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
		( new WPSQR_Warmer() )->hooks();
		( new WPSQR_Assets() )->hooks();

		if ( self::settings()['searchwp_compat'] ) {
			( new WPSQR_SearchWP() )->hooks();
		}

		if ( is_admin() ) {
			( new WPSQR_Admin() )->hooks();
		}

		add_shortcode( 'wpsqr_results', array( $this, 'shortcode' ) );

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

		$results = WPSQR_Engine::search(
			$term,
			array(
				'page'     => max( 1, (int) get_query_var( 'paged', 1 ) ),
				'per_page' => (int) $atts['per_page'],
				'engine'   => (string) $atts['engine'],
			)
		);

		return WPSQR_Renderer::render( $results, $term );
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
			// Cache
			'ttl'             => 6 * HOUR_IN_SECONDS,
			'per_page'        => 20,
			'warm_enabled'    => 1,
			'warm_count'      => 25,
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

			// Result rules
			'block_types'     => array(),
			'block_urls'      => array(),
			'block_titles'    => array(),
			'blocked_queries' => array(),
			'empty_message'   => 'No matching results. Try a different search term.',
		);
	}

	public static function settings() {
		static $cached = null;

		if ( null !== $cached ) {
			return $cached;
		}

		$cached = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );

		return $cached;
	}

	public static function update( $settings ) {
		update_option( self::OPTION, $settings );

		// Any setting change can alter which results are valid.
		WPSQR_Cache::flush();
		WPSQR_Hidden::flush();
	}
}
