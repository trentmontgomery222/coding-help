<?php
/**
 * Redirect handler.
 *
 * @package ACPS_Link_Shortener
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects a /link/{slug} request, records the click, and redirects.
 */
class ACPS_LS_Redirect {

	/**
	 * Hook into WordPress.
	 */
	public function register() {
		// parse_request runs before the main query, so we short-circuit early
		// and never load a template for these URLs.
		add_action( 'parse_request', array( $this, 'maybe_redirect' ) );
	}

	/**
	 * Handle the request if our query var is present.
	 *
	 * @param WP $wp Current WP environment.
	 */
	public function maybe_redirect( $wp ) {
		if ( empty( $wp->query_vars[ ACPS_LS_QUERY_VAR ] ) ) {
			return;
		}

		$slug = sanitize_title( wp_unslash( $wp->query_vars[ ACPS_LS_QUERY_VAR ] ) );
		if ( '' === $slug ) {
			return;
		}

		$link = ACPS_LS_DB::get_active_by_slug( $slug );

		// Not found or inactive: hand back a real 404 through WordPress.
		if ( ! $link ) {
			$this->send_404();
			return;
		}

		// Count the click. Note: if the edge (Cloudflare / WP Engine GES) serves
		// this response from cache, PHP is never reached and the click is not
		// counted. This is a documented, accepted limitation (see readme.txt).
		ACPS_LS_DB::increment_clicks( (int) $link->id );

		$status = ( 302 === (int) $link->redirect_type ) ? 302 : 301;

		$this->send_cache_headers();

		// External destinations are expected, so wp_redirect (not wp_safe_redirect)
		// is correct here; the URL was validated to http/https on save.
		wp_redirect( $link->destination, $status );
		exit;
	}

	/**
	 * Send headers that prevent the edge from caching the redirect.
	 *
	 * Because WP Engine Global Edge Security puts Cloudflare in front of the
	 * site, a cached 301 would keep sending users to a stale destination after
	 * an edit. Telling the edge not to store the redirect keeps link edits
	 * effective immediately. (Belt-and-suspenders: also add a /link/* cache
	 * bypass rule at the edge — see readme.txt.)
	 */
	private function send_cache_headers() {
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, max-age=0, must-revalidate' );
		header( 'Pragma: no-cache' );
	}

	/**
	 * Emit a proper WordPress 404.
	 */
	private function send_404() {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();

		$template = get_404_template();
		if ( $template ) {
			include $template;
		}
		exit;
	}
}
