<?php
/**
 * The bridge to the hide-plugin, ported from WPCode snippet #1.
 *
 * Difference from the snippet: filtering now happens server-side, before the
 * results are rendered, so hidden items never reach the browser at all. The
 * JavaScript layer stays as a second pass for things only it can do
 * (rewriting titles, reacting to live search), but it is no longer the only
 * thing standing between a visitor and hidden content.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Hidden {

	const CACHE_KEY = 'wpsqr_hidden_map';

	public function hooks() {
		foreach ( array( 'save_post', 'deleted_post', 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}
	}

	/**
	 * Post IDs and permalink paths that should not appear in results.
	 *
	 * @return array{ids:int[],paths:string[]}
	 */
	public static function map() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$settings = WPSQR_Plugin::settings();
		$meta_key = $settings['hide_meta_key'];
		$ids      = array();

		if ( '' !== $meta_key ) {
			$meta_value = $settings['hide_meta_value'];

			$meta_query = ( '' === $meta_value )
				? array( array( 'key' => $meta_key, 'compare' => 'EXISTS' ) )
				: array( array( 'key' => $meta_key, 'value' => $meta_value, 'compare' => '=' ) );

			$ids = get_posts(
				array(
					'post_type'              => 'any',
					'post_status'            => array( 'publish', 'private' ),
					'posts_per_page'         => 2000,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
		}

		// Anything added by hand on the settings screen.
		$ids = array_merge( $ids, (array) $settings['manual_ids'] );
		$ids = array_values( array_unique( array_map( 'intval', array_filter( $ids ) ) ) );

		$paths = array();
		foreach ( $ids as $id ) {
			$path = wp_parse_url( get_permalink( $id ), PHP_URL_PATH );
			if ( $path ) {
				$paths[] = untrailingslashit( $path );
			}
		}

		$map = apply_filters(
			'wpsqr_hidden_map',
			array(
				'ids'   => $ids,
				'paths' => array_values( array_unique( $paths ) ),
			)
		);

		set_transient( self::CACHE_KEY, $map, 10 * MINUTE_IN_SECONDS );

		return $map;
	}

	/**
	 * Custom search descriptions, keyed by post ID.
	 *
	 * Needed by the browser for pages SearchWP renders itself, where this
	 * plugin never sees the markup. Only posts that actually have one are
	 * included, so on a normal site this is a handful of entries rather than
	 * a copy of the content.
	 */
	public static function descriptions() {
		$key = WPSQR_Plugin::settings()['desc_meta_key'];

		if ( '' === $key ) {
			return array();
		}

		$cached = get_transient( self::CACHE_KEY . '_desc' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ids = get_posts(
			array(
				'post_type'              => 'any',
				'post_status'            => array( 'publish', 'private' ),
				'posts_per_page'         => 500,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => $key,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$map = array();

		foreach ( $ids as $id ) {
			$text = trim( (string) get_post_meta( $id, $key, true ) );

			if ( '' !== $text ) {
				$map[ (string) $id ] = $text;
			}
		}

		set_transient( self::CACHE_KEY . '_desc', $map, 10 * MINUTE_IN_SECONDS );

		return $map;
	}

	/** True if this post should never be shown to a visitor. */
	public static function is_hidden( $post_id ) {
		$map = self::map();

		return in_array( (int) $post_id, $map['ids'], true );
	}

	public static function flush() {
		delete_transient( self::CACHE_KEY );
		delete_transient( self::CACHE_KEY . '_desc' );
		WPSQR_Cache::flush();
	}
}
