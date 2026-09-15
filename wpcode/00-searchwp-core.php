<?php
/**
 * WPCode snippet #0 — "SearchWP: core behaviour"
 *
 * Code Type:     PHP Snippet
 * Location:      Run Everywhere
 * Priority:      5   (must run before snippets #1 and #4)
 *
 * The three pieces that make the SearchWP results page work, consolidated
 * from the separate snippets that were breaking:
 *
 *   1. Redirect WordPress's own ?s= search to the SearchWP template page.
 *   2. Link attachment results straight at the file, not the attachment page.
 *   3. Push attachments below every other post type in the results.
 *
 * Each is independently guarded, so one failing can no longer take the others
 * down with it — the usual cause of "the search snippets broke".
 *
 * NOTE: do NOT paste the opening <?php tag into WPCode.
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/* =====================================================================
 * CONFIGURE ME
 * ===================================================================== */

// The SearchWP form id used on the results page.
if ( ! defined( 'ACPS_SWP_FORM_ID' ) ) {
	define( 'ACPS_SWP_FORM_ID', 5 );
}

// Path of the SearchWP results page, relative to the site root.
if ( ! defined( 'ACPS_SWP_RESULTS_PATH' ) ) {
	define( 'ACPS_SWP_RESULTS_PATH', '/search/' );
}

// The query parameter carrying the search term on that page.
if ( ! defined( 'ACPS_SWP_QUERY_PARAM' ) ) {
	define( 'ACPS_SWP_QUERY_PARAM', 'swps' );
}

// Point attachment links at the file across the whole front end, rather than
// only when the request "looks like" a search.
//
// This is deliberately blunt. The original snippet tried to detect a search
// context, which is fragile: a Beaver Builder results page isn't is_search(),
// and if the module renders through AJAX the search parameters aren't on the
// request at all — so the filter ran and silently did nothing. Attachment
// pages are near-useless on this site anyway, so linking media at the file
// everywhere is both simpler and what you actually want.
//
// Set false to go back to search-context-only behaviour.
if ( ! defined( 'ACPS_SWP_ALWAYS_DIRECT_MEDIA' ) ) {
	define( 'ACPS_SWP_ALWAYS_DIRECT_MEDIA', true );
}

/* =====================================================================
 * 1. Redirect the default WordPress search to the SearchWP page
 *
 * Anything pointing at /?s=term — the browser search bar, an old theme
 * search form, a bookmark, Google — lands on the real results page.
 * ===================================================================== */

function acps_swp_redirect_default_search() {
	// Never interfere with admin, AJAX, REST, cron or feeds.
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() ) {
		return;
	}

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}

	if ( ! is_search() || ! isset( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}

	// Already carrying the SearchWP form — nothing to do.
	if ( isset( $_GET['swp_form']['form_id'] ) || isset( $_GET[ ACPS_SWP_QUERY_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}

	$target_path = untrailingslashit( ACPS_SWP_RESULTS_PATH );
	$current_path = untrailingslashit( (string) wp_parse_url( add_query_arg( array() ), PHP_URL_PATH ) );

	// Loop guard: if we are somehow already on the results page, stop.
	if ( $current_path === $target_path ) {
		return;
	}

	$term = get_search_query();

	// An empty search has nothing to redirect to usefully.
	if ( '' === trim( $term ) ) {
		return;
	}

	$url = add_query_arg(
		array(
			'swp_form[form_id]'     => (int) ACPS_SWP_FORM_ID,
			ACPS_SWP_QUERY_PARAM    => rawurlencode( $term ),
		),
		home_url( trailingslashit( ACPS_SWP_RESULTS_PATH ) )
	);

	wp_safe_redirect( $url, 302 );
	exit;
}
add_action( 'template_redirect', 'acps_swp_redirect_default_search' );

/* =====================================================================
 * 2. Link media results straight at the file
 *
 * Without this an indexed PDF sends the visitor to a bare attachment page
 * instead of the document they searched for.
 *
 * @link https://searchwp.com/documentation/knowledge-base/link-file-pdf/
 * ===================================================================== */

/**
 * @param string     $permalink The permalink being filtered.
 * @param int|WP_Post $post     Post object on `the_permalink`, post ID on
 *                              `attachment_link`. get_post_type() takes either.
 */
/** Counters, surfaced by ?acpsdebug=1 so this is observable rather than guessed at. */
$GLOBALS['acps_swp_link_stats'] = array(
	'calls'       => 0,
	'attachments' => 0,
	'rewritten'   => 0,
);

function acps_swp_in_search_context() {
	if ( is_search() ) {
		return true;
	}

	// SearchWP's live search and results endpoints.
	if ( wp_doing_ajax() ) {
		$action = isset( $_REQUEST['action'] ) ? (string) $_REQUEST['action'] : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( false !== strpos( $action, 'searchwp' ) || false !== strpos( $action, 'swp' ) ) {
			return true;
		}
	}

	foreach ( array( ACPS_SWP_QUERY_PARAM, 'swps', 'swpquery', 'swp_form' ) as $param ) {
		if ( isset( $_REQUEST[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return true;
		}
	}

	// The results page itself, reached with no parameters (a paged result).
	$current = untrailingslashit( (string) wp_parse_url( add_query_arg( array() ), PHP_URL_PATH ) );
	if ( $current === untrailingslashit( ACPS_SWP_RESULTS_PATH ) ) {
		return true;
	}

	return false;
}

function acps_swp_media_direct_link( $permalink, $post = null ) {
	$GLOBALS['acps_swp_link_stats']['calls']++;

	// Never touch links in wp-admin — the media library needs the real ones.
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $permalink;
	}

	// get_post_type( null ) and ( 0 ) both fall back to the global post, which
	// is what a bare the_permalink() call inside a loop relies on.
	if ( 'attachment' !== get_post_type( $post ) ) {
		return $permalink;
	}

	$GLOBALS['acps_swp_link_stats']['attachments']++;

	if ( ! ACPS_SWP_ALWAYS_DIRECT_MEDIA && ! acps_swp_in_search_context() ) {
		return $permalink;
	}

	$attachment = get_post( $post );
	if ( ! $attachment ) {
		return $permalink;
	}

	$file_url = wp_get_attachment_url( $attachment->ID );

	// A missing file would otherwise blank the link entirely.
	if ( ! $file_url ) {
		return $permalink;
	}

	$GLOBALS['acps_swp_link_stats']['rewritten']++;

	return esc_url( $file_url );
}
add_filter( 'the_permalink', 'acps_swp_media_direct_link', 99, 2 );
add_filter( 'attachment_link', 'acps_swp_media_direct_link', 99, 2 );
// SearchWP result objects and some templates go through post_type_link too.
add_filter( 'post_type_link', 'acps_swp_media_direct_link', 99, 2 );

/* =====================================================================
 * 3. Sink attachments below every other post type
 *
 * A broad search ("staff") otherwise returns a wall of indexed PDFs ahead
 * of the pages people are actually looking for. This weights every other
 * source far above attachments so pages and posts come first.
 *
 * Complementary to, not a replacement for, the front-end rules: this one
 * reorders, `{ when: 'type', value: 'attachment', then: 'hide' }` removes.
 *
 * @link https://searchwp.com/documentation/knowledge-base/post-type-first-top/
 * ===================================================================== */

$GLOBALS['acps_swp_mod_stats'] = array(
	'filter_ran' => false,
	'classes'    => false,
	'source'     => '',
	'applied'    => false,
);

function acps_swp_sink_attachments( $mods ) {
	$GLOBALS['acps_swp_mod_stats']['filter_ran'] = true;

	// SearchWP inactive or mid-upgrade — leave the query untouched rather
	// than fataling on a missing class.
	if ( ! class_exists( '\SearchWP\Mod' ) || ! class_exists( '\SearchWP\Utils' ) ) {
		return $mods;
	}

	$GLOBALS['acps_swp_mod_stats']['classes'] = true;

	$source = \SearchWP\Utils::get_post_type_source_name( 'attachment' );
	$GLOBALS['acps_swp_mod_stats']['source'] = (string) $source;

	if ( empty( $source ) ) {
		return $mods;
	}

	$mod = new \SearchWP\Mod( $source );

	$mod->relevance(
		function ( $runtime ) use ( $source ) {
			global $wpdb;

			return $wpdb->prepare(
				"IF( {$runtime->get_foreign_alias()}.source != %s, '9999999', '0' )",
				$source
			);
		}
	);

	$mods[] = $mod;
	$GLOBALS['acps_swp_mod_stats']['applied'] = true;

	return $mods;
}
add_filter( 'searchwp\query\mods', 'acps_swp_sink_attachments' );

/* =====================================================================
 * Diagnostics
 *
 * Printed in the footer (after the results have rendered) when the page
 * carries ?acpsdebug=1. The JS panel reads it. This is the only way to tell
 * "the filter never ran" apart from "the filter ran and did nothing" —
 * the two look identical on the page and need opposite fixes.
 * ===================================================================== */

function acps_swp_print_debug() {
	if ( ! isset( $_GET['acpsdebug'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}

	$data = array(
		'links'          => $GLOBALS['acps_swp_link_stats'],
		'mods'           => $GLOBALS['acps_swp_mod_stats'],
		'alwaysDirect'   => (bool) ACPS_SWP_ALWAYS_DIRECT_MEDIA,
		'searchContext'  => acps_swp_in_search_context(),
		'searchwpActive' => class_exists( '\SearchWP\Mod' ),
	);

	printf(
		'<script id="acps-swp-debug">window.ACPS_SWP_DEBUG=%s;</script>' . "\n",
		wp_json_encode( $data )
	);
}
add_action( 'wp_footer', 'acps_swp_print_debug', 999 );
