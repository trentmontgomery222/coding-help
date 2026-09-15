<?php
/**
 * WPCode snippet #1 â "Search: hidden-content bridge"
 *
 * Code Type:     PHP Snippet
 * Location:      Run Everywhere  (or "Site Wide Header" is fine too)
 * Priority:      10
 *
 * WHAT IT DOES
 * ------------
 * Your hide-plugin marks posts as hidden, but admins (and the search index)
 * still see them. JS on the search page has no idea which results are hidden,
 * so this snippet hands it a list: post IDs + URL paths of everything flagged
 * hidden, printed as a single `window.ACPS_SEARCH` object on search pages only.
 *
 * Nothing here changes the query. The actual hiding/restyling happens in
 * snippet #2 (JS) and #3 (CSS).
 *
 * NOTE: do NOT paste the opening <?php tag into WPCode â it adds its own.
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/* =====================================================================
 * CONFIGURE ME
 * ===================================================================== */

// The post meta key your hide-plugin writes when something is hidden.
if ( ! defined( 'ACPS_HIDE_META_KEY' ) ) {
	define( 'ACPS_HIDE_META_KEY', '_acps_hidden' );
}

// The meta value that means "hidden". Use '' to mean "any non-empty value".
if ( ! defined( 'ACPS_HIDE_META_VALUE' ) ) {
	define( 'ACPS_HIDE_META_VALUE', '1' );
}

// Post types your hide-plugin can flag.
if ( ! defined( 'ACPS_HIDE_POST_TYPES' ) ) {
	define( 'ACPS_HIDE_POST_TYPES', 'post,page' );
}

// Cache the lookup this many seconds. 0 = no cache (slower, always fresh).
if ( ! defined( 'ACPS_HIDE_CACHE_TTL' ) ) {
	define( 'ACPS_HIDE_CACHE_TTL', 10 * MINUTE_IN_SECONDS );
}

/* =====================================================================
 * Collect the hidden posts
 * ===================================================================== */

/**
 * @return array{ids:int[],paths:string[]}
 */
function acps_search_get_hidden_map() {
	$cache_key = 'acps_hidden_map_v1';

	if ( ACPS_HIDE_CACHE_TTL > 0 ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$meta_query = ( '' === ACPS_HIDE_META_VALUE )
		? array(
			array(
				'key'     => ACPS_HIDE_META_KEY,
				'compare' => 'EXISTS',
			),
		)
		: array(
			array(
				'key'     => ACPS_HIDE_META_KEY,
				'value'   => ACPS_HIDE_META_VALUE,
				'compare' => '=',
			),
		);

	$ids = get_posts(
		array(
			'post_type'              => array_map( 'trim', explode( ',', ACPS_HIDE_POST_TYPES ) ),
			'post_status'            => array( 'publish', 'private' ),
			'posts_per_page'         => 500,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'suppress_filters'       => false,
			'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);

	$paths = array();
	foreach ( $ids as $id ) {
		$path = wp_parse_url( get_permalink( $id ), PHP_URL_PATH );
		if ( $path ) {
			$paths[] = untrailingslashit( $path );
		}
	}

	$map = array(
		'ids'   => array_values( array_map( 'intval', $ids ) ),
		'paths' => array_values( array_unique( $paths ) ),
	);

	/**
	 * Filter the hidden map before it reaches the front-end.
	 * Use this to fold in rules your hide-plugin stores somewhere other
	 * than post meta (options, taxonomy terms, a custom table, etc).
	 */
	$map = apply_filters( 'acps_search_hidden_map', $map );

	if ( ACPS_HIDE_CACHE_TTL > 0 ) {
		set_transient( $cache_key, $map, ACPS_HIDE_CACHE_TTL );
	}

	return $map;
}

/* Bust the cache whenever a post is saved or the hide flag changes. */
function acps_search_flush_hidden_map() {
	delete_transient( 'acps_hidden_map_v1' );
}
add_action( 'save_post', 'acps_search_flush_hidden_map' );
add_action( 'deleted_post', 'acps_search_flush_hidden_map' );
add_action( 'updated_post_meta', 'acps_search_flush_hidden_map' );
add_action( 'added_post_meta', 'acps_search_flush_hidden_map' );
add_action( 'deleted_post_meta', 'acps_search_flush_hidden_map' );

/* =====================================================================
 * Print the data on search pages
 * ===================================================================== */

function acps_search_print_bridge() {
	// Only on the search results page. Add your plugin's own results page
	// here if it uses a dedicated URL, e.g.:
	//   || is_page( 'search-results' )
	if ( ! is_search() ) {
		return;
	}

	$map = acps_search_get_hidden_map();

	$data = array(
		'hiddenIds'   => $map['ids'],
		'hiddenPaths' => $map['paths'],
		'isAdmin'     => current_user_can( 'edit_posts' ),
		'query'       => get_search_query(),
		'homePath'    => untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) ),
		'rules'       => array(),
	);

	/**
	 * Filter the whole payload before it is printed. Snippet #4 uses this to
	 * inject the rules managed from Settings → Search Filters.
	 */
	$data = apply_filters( 'acps_search_bridge_data', $data );

	printf(
		'<script id="acps-search-bridge">window.ACPS_SEARCH=%s;</script>' . "\n",
		wp_json_encode( $data )
	);
}
add_action( 'wp_head', 'acps_search_print_bridge', 1 );

/* =====================================================================
 * OPTIONAL but recommended: stamp the post ID onto each result.
 *
 * Most themes run search results through post_class(), which gives you a
 * `post-123` class the JS can match on exactly instead of guessing from the
 * link URL. If your theme already does this, you get it for free.
 * ===================================================================== */

function acps_search_mark_results( $classes, $class, $post_id ) {
	if ( ! is_search() ) {
		return $classes;
	}

	$map = acps_search_get_hidden_map();
	if ( in_array( (int) $post_id, $map['ids'], true ) ) {
		$classes[] = 'acps-hidden-result';
	}

	$classes[] = 'acps-result';

	return $classes;
}
add_filter( 'post_class', 'acps_search_mark_results', 10, 3 );
