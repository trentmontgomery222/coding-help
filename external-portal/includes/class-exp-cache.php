<?php
/**
 * Caching guard (spec Section 1).
 *
 * WP Engine's Global Edge Security / full-page cache must never serve a cached
 * portal page, or one user's session/data could leak to another. We can't fully
 * control the edge from PHP, so we do everything we can from here and document
 * the required host-side exclusion in the plugin docs/settings.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits no-cache signals for pages that render the portal shortcodes.
 */
class EXP_Cache {

	/**
	 * @var bool Guard so headers are only sent once per request.
	 */
	protected static $done = false;

	/**
	 * Mark the current response as non-cacheable. Safe to call multiple times.
	 */
	public static function prevent_page_cache() {
		if ( self::$done ) {
			return;
		}
		self::$done = true;

		// Signals recognised by many WP caching layers / plugins.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}
		if ( ! defined( 'DONOTCACHEDB' ) ) {
			define( 'DONOTCACHEDB', true );
		}

		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private', true );
			header( 'Pragma: no-cache', true );
			// A hint some CDNs honour to bypass edge caching for this response.
			header( 'X-Accel-Expires: 0', true );
		}

		/**
		 * Fires when the plugin declares the current page uncacheable, so a
		 * host-specific integration can add its own bypass if needed.
		 */
		do_action( 'exp_prevent_page_cache' );
	}

	/**
	 * Whether the current singular page contains one of our shortcodes.
	 * Used to proactively bust caching even before the shortcode renders.
	 *
	 * @return bool
	 */
	public static function current_page_has_portal() {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post ) {
			return false;
		}
		return has_shortcode( $post->post_content, 'external_portal_login' )
			|| has_shortcode( $post->post_content, 'external_portal_dashboard' );
	}
}
