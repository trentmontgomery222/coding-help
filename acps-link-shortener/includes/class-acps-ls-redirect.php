<?php
/**
 * Redirect handler.
 *
 * Supports two modes depending on ACPS_LS_SLUG_PREFIX:
 *
 *   Prefixed mode ('link'): a rewrite rule routes /link/{slug} to our query
 *   var, which we detect on parse_request.
 *
 *   Bare mode (''): no rewrite rule. We hook template_redirect and only act
 *   when WordPress has already decided the request is a 404 — i.e. no real
 *   page/post/term matched. That single top-level segment is then looked up as
 *   a short-link slug. This guarantees real content always wins and short links
 *   never shadow an existing URL.
 *
 * @package ACPS_Link_Shortener
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects a short-link request, records the click, and redirects.
 */
class ACPS_LS_Redirect {

	/**
	 * Hook into WordPress based on the configured prefix mode.
	 */
	public function register() {
		if ( '' === ACPS_LS_SLUG_PREFIX ) {
			// Bare mode: only fire on would-be 404s so pages/posts always win.
			add_action( 'template_redirect', array( $this, 'maybe_redirect_bare' ), 5 );
		} else {
			// Prefixed mode: our rewrite rule sets a query var.
			add_action( 'parse_request', array( $this, 'maybe_redirect' ) );
		}
	}

	/**
	 * Prefixed mode: handle the request if our query var is present.
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

		if ( ! $link ) {
			$this->send_404();
			return;
		}

		$this->do_redirect( $link );
	}

	/**
	 * Bare mode: on a would-be 404, try to resolve the path as a short-link
	 * slug. Real content never reaches here because it is not a 404.
	 */
	public function maybe_redirect_bare() {
		if ( is_admin() || ! is_404() ) {
			return;
		}

		global $wp;
		$request = isset( $wp->request ) ? trim( (string) $wp->request, '/' ) : '';

		// Only single-segment paths are candidate slugs (e.g. "open-house").
		if ( '' === $request || false !== strpos( $request, '/' ) ) {
			return;
		}

		$slug = sanitize_title( $request );
		if ( '' === $slug ) {
			return;
		}

		$link = ACPS_LS_DB::get_active_by_slug( $slug );
		if ( ! $link ) {
			// Leave the natural WordPress 404 in place.
			return;
		}

		$this->do_redirect( $link );
	}

	/**
	 * Count the click and issue the redirect with the stored status code.
	 *
	 * Note: if the edge (Cloudflare / WP Engine GES) serves this response from
	 * cache, PHP is never reached and the click is not counted — a documented,
	 * accepted limitation.
	 *
	 * @param object $link Link row.
	 */
	private function do_redirect( $link ) {
		ACPS_LS_DB::increment_clicks( (int) $link->id );

		$status = ( 302 === (int) $link->redirect_type ) ? 302 : 301;

		$this->send_cache_headers();

		// External destinations are expected; the URL was validated to
		// http/https on save, so wp_redirect (not wp_safe_redirect) is correct.
		wp_redirect( $link->destination, $status );
		exit;
	}

	/**
	 * Send headers that prevent the edge from caching the redirect.
	 *
	 * Because WP Engine Global Edge Security puts Cloudflare in front of the
	 * site, a cached 301 would keep sending users to a stale destination after
	 * an edit. Telling the edge not to store the redirect keeps link edits
	 * effective immediately. (Belt-and-suspenders: also add a cache-bypass rule
	 * at the edge — see readme.txt.)
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
	 * Emit a proper WordPress 404 (prefixed mode only).
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
