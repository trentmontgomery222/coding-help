<?php
/**
 * Record searches even when this plugin isn't rendering them.
 *
 * Without this the dashboard only fills up once you've replaced the SearchWP
 * module with [wpsqr_results] — which is backwards, because the numbers are
 * how you decide whether replacing it is worth doing. The observer watches
 * every search on the results page and logs the term regardless of who
 * renders the results.
 *
 * It records the term and nothing else. It does not know how many results
 * SearchWP found or how long it took, and says so rather than logging a zero
 * that would show up as "this search found nothing".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Observer {

	/** Set by the shortcode, so we don't count the same search twice. */
	public static $handled = false;

	public function hooks() {
		add_action( 'template_redirect', array( $this, 'observe' ), 100 );
	}

	public function observe() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		if ( empty( WPSQR_Plugin::settings()['observe'] ) ) {
			return;
		}

		if ( ! $this->is_results_request() ) {
			return;
		}

		if ( $this->looks_like_a_bot() ) {
			return;
		}

		$term = WPSQR_Plugin::current_term();
		if ( '' === trim( $term ) ) {
			return;
		}

		// Decide at the end of the request: if the shortcode ran it has
		// already recorded a real count, and this would double-count.
		add_action(
			'shutdown',
			function () use ( $term ) {
				if ( WPSQR_Observer::$handled ) {
					return;
				}

				WPSQR_Stats::record(
					WPSQR_Normalizer::normalize( $term ),
					$term,
					0,
					0,
					false,
					false // the result count is genuinely unknown here
				);
			}
		);
	}

	protected function is_results_request() {
		if ( is_search() ) {
			return true;
		}

		foreach ( WPSQR_Plugin::query_params() as $param ) {
			if ( isset( $_GET[ $param ] ) && '' !== $_GET[ $param ] ) { // phpcs:ignore WordPress.Security.NonceVerification
				return true;
			}
		}

		$path    = untrailingslashit( (string) wp_parse_url( add_query_arg( array() ), PHP_URL_PATH ) );
		$results = untrailingslashit( WPSQR_Plugin::settings()['results_path'] );

		return $path === $results;
	}

	/**
	 * Keep crawlers out of the popularity numbers.
	 *
	 * Cheap and imperfect on purpose — a user-agent check catches the honest
	 * crawlers, which is most of the noise, and anything more involved is not
	 * worth the cost on every search.
	 */
	protected function looks_like_a_bot() {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';

		if ( '' === $agent ) {
			return true;
		}

		foreach ( array( 'bot', 'crawl', 'spider', 'slurp', 'curl', 'wget', 'headless', 'python-requests', 'lighthouse', 'pingdom', 'uptime' ) as $needle ) {
			if ( false !== strpos( $agent, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
