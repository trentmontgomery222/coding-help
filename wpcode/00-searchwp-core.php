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
function acps_swp_media_direct_link( $permalink, $post = null ) {
	if ( null === $post ) {
		return $permalink;
	}

	$in_search_context = is_search()
		|| doing_action( 'wp_ajax_searchwp_live_search' )
		|| doing_action( 'wp_ajax_nopriv_searchwp_live_search' )
		|| isset( $_REQUEST[ ACPS_SWP_QUERY_PARAM ] ) // phpcs:ignore WordPress.Security.NonceVerification
		|| isset( $_REQUEST['swp_form']['form_id'] ); // phpcs:ignore WordPress.Security.NonceVerification

	if ( ! $in_search_context ) {
		return $permalink;
	}

	if ( 'attachment' !== get_post_type( $post ) ) {
		return $permalink;
	}

	$file_url = wp_get_attachment_url( is_object( $post ) ? $post->ID : (int) $post );

	// A missing file would otherwise blank the link entirely.
	return $file_url ? esc_url( $file_url ) : $permalink;
}
add_filter( 'the_permalink', 'acps_swp_media_direct_link', 99, 2 );
add_filter( 'attachment_link', 'acps_swp_media_direct_link', 99, 2 );

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

function acps_swp_sink_attachments( $mods ) {
	// SearchWP inactive or mid-upgrade — leave the query untouched rather
	// than fataling on a missing class.
	if ( ! class_exists( '\SearchWP\Mod' ) || ! class_exists( '\SearchWP\Utils' ) ) {
		return $mods;
	}

	$source = \SearchWP\Utils::get_post_type_source_name( 'attachment' );

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

	return $mods;
}
add_filter( 'searchwp\query\mods', 'acps_swp_sink_attachments' );
